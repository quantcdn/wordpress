<?php

declare(strict_types=1);

namespace Quant\Core;

/**
 * Reads QUANT_* overrides from PHP constants or environment variables.
 *
 * The Quant Cloud `app-wordpress` template configures the plugin with
 * environment variables. A constant defined in wp-config.php wins over an
 * environment variable.
 */
final class Environment
{
    /**
     * Map of environment variable name to [option group, key, type].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const MAP = [
        'QUANT_API_ENDPOINT' => ['credentials', 'endpoint', 'string'],
        'QUANT_CUSTOMER' => ['credentials', 'organization', 'string'],
        'QUANT_PROJECT' => ['credentials', 'project', 'string'],
        'QUANT_TOKEN' => ['credentials', 'token', 'string'],
        'QUANT_DISABLE_TLS_VERIFY' => ['credentials', 'disable_tls_verify', 'bool'],
        'QUANT_HTTP_REQUEST_TIMEOUT' => ['credentials', 'http_request_timeout', 'int'],
        'QUANT_WEBSERVER_URL' => ['static', 'webserver_url', 'string'],
        'QUANT_WEBSERVER_HOST' => ['static', 'webserver_host', 'string'],
        'QUANT_ENABLED' => ['sync', 'auto_sync', 'bool'],
        'QUANT_PURGE_ON_SAVE' => ['sync', 'auto_purge', 'bool'],
        'QUANT_MODE' => ['mode', 'mode', 'string'],
    ];

    /**
     * Raw value of a variable, or null when it is not set.
     */
    public static function raw(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);
            return is_scalar($value) ? (string) $value : null;
        }

        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }

        foreach ([$_ENV, $_SERVER] as $source) {
            if (isset($source[$name]) && is_scalar($source[$name]) && (string) $source[$name] !== '') {
                return (string) $source[$name];
            }
        }

        return null;
    }

    /**
     * Overrides for one option group, cast to the declared type.
     *
     * @return array<string, mixed>
     */
    public static function overridesFor(string $group): array
    {
        $overrides = [];

        foreach (self::MAP as $name => [$mapGroup, $key, $type]) {
            if ($mapGroup !== $group) {
                continue;
            }

            $raw = self::raw($name);
            if ($raw === null) {
                continue;
            }

            $overrides[$key] = self::cast($raw, $type);
        }

        return $overrides;
    }

    /**
     * Names of the settings keys that are overridden, grouped for the UI.
     *
     * @return array<string, array<string>>
     */
    public static function overriddenKeys(): array
    {
        $keys = [];

        foreach (self::MAP as $name => [$group, $key]) {
            if (self::raw($name) !== null) {
                $keys[$group][] = $key;
            }
        }

        return $keys;
    }

    private static function cast(string $raw, string $type): mixed
    {
        return match ($type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            default => $raw,
        };
    }
}
