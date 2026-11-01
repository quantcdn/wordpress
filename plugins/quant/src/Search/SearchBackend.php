<?php

declare(strict_types=1);

namespace Quant\Search;

use Quant\Api\ContentApiInterface;

/**
 * Resolves the hosted search backend the browser talks to.
 *
 * A project runs on Algolia (QuantCDN) or Typesense (QuantGov, platform_mode
 * "aws"). Both resolve to the same keys so the scripts stay backend-agnostic,
 * the same contract as the Drupal module and the dashboard search pages:
 *
 * - backend:         "algolia" or "typesense"
 * - application_id:  Algolia application ID ('' on Typesense)
 * - read_key:        public, search-only key
 * - index:           Algolia index name or Typesense collection
 * - endpoint:        Typesense URL ('' on Algolia), added at read time
 * - sort_by:         Typesense sort_by from the project's custom ranking
 * - facets_declared: fields Typesense can facet on ([] means unknown)
 */
final class SearchBackend
{
    public const ALGOLIA = 'algolia';
    public const TYPESENSE = 'typesense';
    public const DEFAULT_TYPESENSE_ENDPOINT = 'https://search.quantgov.cloud';

    private const RANKING_PATTERN = '/^(asc|desc)\(([A-Za-z_][A-Za-z0-9_]*)\)$/';

    /**
     * @return array<string, mixed>|null Null when search is unavailable.
     */
    public static function resolve(ContentApiInterface $api): ?array
    {
        $ping = $api->ping();
        $config = is_array($ping->data['config'] ?? null) ? $ping->data['config'] : [];
        if (!$ping->success || empty($config['search_enabled'])) {
            return null;
        }

        $ranking = (array) ($config['search_config']['algolia']['custom_ranking'] ?? []);

        if (($config['platform_mode'] ?? '') === 'aws') {
            return self::typesense($api, $ranking);
        }

        return self::algolia((array) ($config['search_index'] ?? []), $ranking);
    }

    /**
     * True when a resolved backend has everything the browser needs.
     *
     * @param array<string, mixed>|null $backend
     */
    public static function isComplete(?array $backend): bool
    {
        return $backend !== null && ($backend['read_key'] ?? '') !== '' && ($backend['index'] ?? '') !== '';
    }

    /**
     * Adds the Typesense endpoint, which is a local setting and never cached.
     *
     * @param array<string, mixed>|null $backend
     * @return array<string, mixed>|null
     */
    public static function withEndpoint(?array $backend): ?array
    {
        if ($backend !== null && $backend['backend'] === self::TYPESENSE) {
            $backend['endpoint'] = self::typesenseEndpoint();
        }

        return $backend;
    }

    /**
     * The Typesense host: QUANT_SEARCH_ENDPOINT (environment or constant),
     * then the quant_search_typesense_endpoint filter.
     */
    public static function typesenseEndpoint(): string
    {
        $endpoint = defined('QUANT_SEARCH_ENDPOINT') ? (string) constant('QUANT_SEARCH_ENDPOINT') : (string) getenv('QUANT_SEARCH_ENDPOINT');
        if ($endpoint === '') {
            $endpoint = self::DEFAULT_TYPESENSE_ENDPOINT;
        }

        return untrailingslashit((string) apply_filters('quant_search_typesense_endpoint', $endpoint));
    }

    /**
     * Typesense sort_by for the project's custom ranking ("asc(field)").
     *
     * Text relevance stays first, as on Algolia. Typesense accepts three sort
     * fields, one of which is _text_match.
     *
     * @param array<int, mixed> $ranking
     */
    public static function sortBy(array $ranking): string
    {
        $clauses = [];
        foreach ($ranking as $rule) {
            if (count($clauses) >= 2) {
                break;
            }
            if (preg_match(self::RANKING_PATTERN, trim((string) $rule), $m)) {
                $clauses[] = $m[2] . ':' . $m[1];
            }
        }

        return $clauses === [] ? '' : '_text_match:desc,' . implode(',', $clauses);
    }

    /**
     * Removes facets the backend cannot facet on.
     *
     * Typesense answers a query that facets on an undeclared field with a 404,
     * which fails the whole batched request and leaves the page empty. Algolia
     * ignores such a facet, so nothing is removed when the list is unknown.
     *
     * @param array<int, array<string, mixed>> $facets Each with an "attribute".
     * @param array<string, mixed>|null $backend
     * @return array<int, array<string, mixed>>
     */
    public static function declaredFacets(array $facets, ?array $backend): array
    {
        $declared = (array) ($backend['facets_declared'] ?? []);
        if ($declared === []) {
            return $facets;
        }

        $declared = array_flip($declared);

        return array_values(array_filter($facets, static fn(array $facet): bool => isset($declared[$facet['attribute']])));
    }

    /**
     * @param array<string, mixed> $index
     * @param array<int, mixed> $ranking
     * @return array<string, mixed>
     */
    private static function algolia(array $index, array $ranking): array
    {
        return [
            'backend' => self::ALGOLIA,
            'application_id' => (string) ($index['algolia_application_id'] ?? ''),
            'read_key' => (string) ($index['algolia_read_key'] ?? ''),
            'index' => (string) ($index['algolia_index'] ?? ''),
            'endpoint' => '',
            'sort_by' => '',
            'custom_ranking' => array_values($ranking),
            'facets_declared' => [],
        ];
    }

    /**
     * The read-only key and collection come from the index stats call.
     *
     * @param array<int, mixed> $ranking
     * @return array<string, mixed>
     */
    private static function typesense(ContentApiInterface $api, array $ranking): array
    {
        $response = $api->searchStatus();
        $stats = $response->success ? $response->data : [];
        $key = (array) ($stats['readOnlyKey'] ?? []);

        $collection = (string) ($key['collections'][0] ?? '');
        if ($collection === '') {
            $collection = (string) ($stats['index']['name'] ?? '');
        }

        // Auto-typed fields can be reported twice.
        $declared = array_values(array_unique(array_map('strval', (array) ($stats['settings']['attributesForFaceting'] ?? []))));

        return [
            'backend' => self::TYPESENSE,
            'application_id' => '',
            'read_key' => (string) ($key['value'] ?? ''),
            'index' => $collection,
            'endpoint' => '',
            'sort_by' => self::sortBy($ranking),
            'custom_ranking' => array_values($ranking),
            'facets_declared' => $declared,
        ];
    }
}
