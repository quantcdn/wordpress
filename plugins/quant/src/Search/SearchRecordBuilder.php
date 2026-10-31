<?php

declare(strict_types=1);

namespace Quant\Search;

use Quant\Core\Settings;
use Quant\Sync\Paths;
use WP_Post;

/**
 * Builds the `search_record` object sent with each published page.
 *
 * Record shape follows the Drupal module so Quant Search pages built for
 * either CMS see the same attributes: title, summary, content, image, url,
 * lang_code, content_type, content_type_<lang>, language_<lang>, and one
 * `<taxonomy>_<lang>` array per public taxonomy.
 */
class SearchRecordBuilder
{
    private const MAX_CONTENT_LENGTH = 8000;
    private const MAX_SUMMARY_LENGTH = 500;

    public function __construct(
        private readonly Settings $settings
    ) {
    }

    /**
     * Whether search records are produced at all.
     */
    public function enabled(): bool
    {
        return !empty($this->settings->getSearchSettings()['enabled']);
    }

    /**
     * The record for a post, or ['skip' => true] when the post type is not indexed.
     *
     * @return array<string, mixed>
     */
    public function forPost(WP_Post $post): array
    {
        if (!$this->indexes($post->post_type)) {
            return ['skip' => true];
        }

        $lang = $this->languageCode($post);
        $record = [
            'title' => $this->text(get_the_title($post)),
            'summary' => $this->summary($post),
            'content' => $this->content($post),
            'url' => Paths::normalise(Paths::fromUrl((string) get_permalink($post))),
            'lang_code' => $lang,
            "language_{$lang}" => $this->languageLabel($lang),
            'content_type' => $post->post_type,
            "content_type_{$lang}" => $this->postTypeLabel($post->post_type),
        ];

        $image = $this->image($post);
        if ($image !== null) {
            $record['image'] = $image;
        }

        $record = array_merge($record, $this->terms($post, $lang));

        /** This filter is documented in v2.0.0; it adjusts the search record for a post. */
        return (array) apply_filters('quant_search_record', array_filter($record, static fn($v) => $v !== '' && $v !== null), $post);
    }

    /**
     * Post types whose records are indexed.
     *
     * @return array<string>
     */
    public function indexedPostTypes(): array
    {
        $search = $this->settings->getSearchSettings();
        $types = $search['post_types'];
        if ($types === []) {
            $types = array_values(get_post_types(['public' => true], 'names'));
        }

        return array_values(array_diff($types, $search['excluded_post_types'], ['attachment']));
    }

    public function indexes(string $postType): bool
    {
        return in_array($postType, $this->indexedPostTypes(), true);
    }

    /**
     * The site's default language code (for example `en`).
     */
    public function defaultLanguage(): string
    {
        /** This filter is documented in v2.0.0; it sets the language code of a post's search record. */
        $locale = (string) apply_filters('quant_search_record_language', get_locale(), null);

        return strtolower(strtok($locale, '_-') ?: 'en');
    }

    private function summary(WP_Post $post): string
    {
        $search = $this->settings->getSearchSettings();

        if (($search['summary_source'] ?? 'excerpt') === 'excerpt' && trim($post->post_excerpt) !== '') {
            return $this->text($post->post_excerpt, self::MAX_SUMMARY_LENGTH);
        }

        return $this->text(wp_trim_words($this->text($post->post_content), 55, '…'), self::MAX_SUMMARY_LENGTH);
    }

    private function content(WP_Post $post): string
    {
        $content = $post->post_content;

        if (has_blocks($content)) {
            $content = do_blocks($content);
        }

        return $this->text(strip_shortcodes($content), self::MAX_CONTENT_LENGTH);
    }

    private function image(WP_Post $post): ?string
    {
        $size = $this->settings->getSearchSettings()['image_size'] ?: 'thumbnail';
        $thumbnail = get_the_post_thumbnail_url($post, $size);

        if (!is_string($thumbnail) || $thumbnail === '') {
            return null;
        }

        return Paths::isLocal($thumbnail) ? Paths::fromUrl($thumbnail) : $thumbnail;
    }

    /**
     * @return array<string, array<string>>
     */
    private function terms(WP_Post $post, string $lang): array
    {
        $result = [];

        foreach (get_object_taxonomies($post, 'objects') as $taxonomy) {
            if (!$taxonomy->public) {
                continue;
            }

            $terms = get_the_terms($post, $taxonomy->name);
            if (!is_array($terms) || $terms === []) {
                continue;
            }

            $result["{$taxonomy->name}_{$lang}"] = array_values(array_map(
                static fn($term): string => html_entity_decode($term->name, ENT_QUOTES),
                $terms
            ));
        }

        return $result;
    }

    private function languageCode(WP_Post $post): string
    {
        $locale = function_exists('get_post_locale') ? get_post_locale($post) : get_locale();

        /** This filter is documented in v2.0.0; it sets the language code of a post's search record. */
        $locale = (string) apply_filters('quant_search_record_language', $locale, $post);

        return strtolower(strtok($locale, '_-') ?: 'en');
    }

    /**
     * Native name of a language, without a network call.
     */
    private function languageLabel(string $lang): string
    {
        $label = null;
        if (class_exists(\Locale::class)) {
            $display = \Locale::getDisplayLanguage($lang, $lang);
            $label = is_string($display) && $display !== '' && $display !== $lang ? ucfirst($display) : null;
        }

        $label ??= $lang === 'en' ? 'English' : strtoupper($lang);

        /** This filter is documented in v2.0.0; it sets the language label stored in search records. */
        return (string) apply_filters('quant_search_language_label', $label, $lang);
    }

    private function postTypeLabel(string $postType): string
    {
        $object = get_post_type_object($postType);

        return $object && isset($object->labels->singular_name) ? $object->labels->singular_name : ucfirst($postType);
    }

    private function text(string $html, ?int $limit = null): string
    {
        $text = html_entity_decode(wp_strip_all_tags($html, true), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($limit !== null && mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit)) . '…';
        }

        return $text;
    }
}
