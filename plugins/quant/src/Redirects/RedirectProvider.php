<?php

declare(strict_types=1);

namespace Quant\Redirects;

use Quant\Sync\Paths;
use Quant\Sync\Redirect;

/**
 * Collects the redirects the site wants Quant to serve.
 *
 * Sources: the Redirection plugin (plain URL matches only), old post slugs
 * recorded by WordPress core, and the `quant_custom_redirects` filter.
 */
class RedirectProvider
{
    /**
     * @return array<Redirect>
     */
    public function all(): array
    {
        $redirects = array_merge(
            $this->fromRedirectionPlugin(),
            $this->fromOldSlugs(),
            $this->fromFilter()
        );

        $unique = [];
        foreach ($redirects as $redirect) {
            if ($this->isValid($redirect)) {
                $unique[$redirect->from] = $redirect;
            }
        }

        return array_values($unique);
    }

    public function redirectionPluginActive(): bool
    {
        return class_exists('Red_Item');
    }

    /**
     * Redirection plugin items that Quant can serve: enabled, plain URL match, URL action.
     *
     * @return array<Redirect>
     */
    public function fromRedirectionPlugin(): array
    {
        if (!$this->redirectionPluginActive()) {
            return [];
        }

        $redirects = [];
        foreach (\Red_Item::get_all() as $item) {
            $redirect = $this->fromRedirectionItem($item);
            if ($redirect !== null) {
                $redirects[] = $redirect;
            }
        }

        return $redirects;
    }

    /**
     * Convert a Redirection item, or null when Quant cannot serve it.
     */
    public function fromRedirectionItem(object $item): ?Redirect
    {
        if (!$this->isServableRedirectionItem($item)) {
            return null;
        }

        $code = (int) $item->get_action_code();

        return new Redirect(
            Paths::normalise((string) $item->get_url()),
            (string) $item->get_action_data(),
            in_array($code, [301, 302, 307, 308], true) ? $code : 301
        );
    }

    /**
     * Redirects from old slugs WordPress keeps in `_wp_old_slug`.
     *
     * @return array<Redirect>
     */
    public function fromOldSlugs(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value AS old_slug
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wp_old_slug' AND p.post_status = 'publish'
             LIMIT 5000",
            ARRAY_A
        ) ?: [];

        $redirects = [];
        foreach ($rows as $row) {
            $post = get_post((int) $row['post_id']);
            if (!$post) {
                continue;
            }

            $current = get_permalink($post);
            if (!is_string($current)) {
                continue;
            }

            $old = $this->oldPermalink($post, (string) $row['old_slug'], $current);
            if ($old !== null) {
                $redirects[] = new Redirect($old, Paths::normalise(Paths::fromUrl($current)), 301);
            }
        }

        return $redirects;
    }

    /**
     * @return array<Redirect>
     */
    public function fromFilter(): array
    {
        /** This filter is documented in v2.0.0; it adds custom redirects as Quant\Sync\Redirect objects. */
        $raw = apply_filters('quant_custom_redirects', []);

        return is_array($raw) ? array_values(array_filter($raw, static fn($r): bool => $r instanceof Redirect)) : [];
    }

    private function isServableRedirectionItem(object $item): bool
    {
        foreach (['is_enabled', 'is_regex', 'get_match_type', 'get_action_type', 'get_action_data', 'get_url', 'get_action_code'] as $method) {
            if (!method_exists($item, $method)) {
                return false;
            }
        }

        return $item->is_enabled()
            && !$item->is_regex()
            && $item->get_match_type() === 'url'
            && $item->get_action_type() === 'url'
            && is_string($item->get_action_data())
            && $item->get_action_data() !== '';
    }

    private function oldPermalink(\WP_Post $post, string $oldSlug, string $currentPermalink): ?string
    {
        if ($oldSlug === '' || $oldSlug === $post->post_name) {
            return null;
        }

        $currentPath = Paths::normalise(Paths::fromUrl($currentPermalink));
        $segment = '/' . $post->post_name;

        if (!str_ends_with($currentPath, $segment)) {
            return null;
        }

        $oldPath = substr($currentPath, 0, -strlen($segment)) . '/' . $oldSlug;

        return $oldPath === $currentPath ? null : $oldPath;
    }

    private function isValid(Redirect $redirect): bool
    {
        if ($redirect->from === '' || $redirect->from[0] !== '/' || $redirect->to === '') {
            return false;
        }

        $to = preg_match('#^https?://#i', $redirect->to) === 1 ? $redirect->to : Paths::normalise($redirect->to);

        return $to !== $redirect->from;
    }
}
