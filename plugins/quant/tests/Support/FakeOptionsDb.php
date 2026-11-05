<?php

declare(strict_types=1);

namespace Quant\Tests\Support;

/**
 * An in-memory options table for the SQL that RefreshLock and TokenStore run: INSERT IGNORE, SELECT
 * by name, UPDATE by name and value, and DELETE by name and value. prepare() hands the query and its
 * arguments through as JSON.
 */
final class FakeOptionsDb extends \wpdb
{
    public string $options = 'wp_options';

    /** @var array<string, string> option_name => option_value */
    public array $rows = [];

    /** Called with the option name before each SELECT, to act as another request would meanwhile. */
    public ?\Closure $beforeSelect = null;

    public function prepare(string $query, mixed ...$args): string|null
    {
        return (string) json_encode([$query, $args]);
    }

    public function query(string $query): int|bool
    {
        [$sql, $args] = json_decode($query, true);
        if (str_starts_with($sql, 'UPDATE')) {
            return $this->compareAndSwap($args[1], $args[2], $args[0]);
        }
        if (!str_starts_with($sql, 'INSERT IGNORE') || isset($this->rows[$args[0]])) {
            return 0;
        }

        $this->rows[$args[0]] = $args[1];

        return 1;
    }

    private function compareAndSwap(string $name, string $expected, string $value): int
    {
        if (($this->rows[$name] ?? null) !== $expected) {
            return 0;
        }

        $this->rows[$name] = $value;

        return 1;
    }

    public function get_var(?string $query = null, int $x = 0, int $y = 0): ?string
    {
        [, $args] = json_decode((string) $query, true);
        if ($this->beforeSelect !== null) {
            ($this->beforeSelect)($args[0]);
        }

        return $this->rows[$args[0]] ?? null;
    }

    public function delete(string $table, array $where, ?array $where_format = null): int|false
    {
        if (($this->rows[$where['option_name']] ?? null) !== $where['option_value']) {
            return 0;
        }

        unset($this->rows[$where['option_name']]);

        return 1;
    }
}
