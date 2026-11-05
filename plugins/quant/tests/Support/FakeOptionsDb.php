<?php

declare(strict_types=1);

namespace Quant\Tests\Support;

/**
 * An in-memory options table for the SQL that RefreshLock runs: INSERT IGNORE, SELECT by name and
 * DELETE by name and value. prepare() hands the query and its arguments through as JSON.
 */
final class FakeOptionsDb extends \wpdb
{
    public string $options = 'wp_options';

    /** @var array<string, string> option_name => option_value */
    public array $rows = [];

    public function prepare(string $query, mixed ...$args): string|null
    {
        return (string) json_encode([$query, $args]);
    }

    public function query(string $query): int|bool
    {
        [$sql, $args] = json_decode($query, true);
        if (!str_starts_with($sql, 'INSERT IGNORE') || isset($this->rows[$args[0]])) {
            return 0;
        }

        $this->rows[$args[0]] = $args[1];

        return 1;
    }

    public function get_var(?string $query = null, int $x = 0, int $y = 0): ?string
    {
        [, $args] = json_decode((string) $query, true);

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
