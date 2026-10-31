<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for the Redirection plugin's classes.
 */
// phpcs:ignore
class Red_Item
{
    /** @var array<int, Red_Item> */
    public static array $items = [];
    public static int $flushed = 0;

    public function __construct(
        private int $id,
        private string $url,
        private string $destination,
        private string $code = '301',
        private bool $enabled = true,
        private bool $regex = false,
        private string $matchType = 'url',
        private string $actionType = 'url',
        private int $groupId = 1
    ) {
    }

    /** @return array<int, Red_Item> */
    public static function get_all(): array
    {
        return array_values(self::$items);
    }

    public static function get_by_id(int $id): Red_Item|false
    {
        return self::$items[$id] ?? false;
    }

    public function get_id(): int { return $this->id; }
    public function get_url(): string { return $this->url; }
    public function get_action_data(): mixed { return $this->destination; }
    public function get_action_code(): string { return $this->code; }
    public function is_enabled(): bool { return $this->enabled; }
    public function is_regex(): bool { return $this->regex; }
    public function get_match_type(): string { return $this->matchType; }
    public function get_action_type(): string { return $this->actionType; }
    public function get_group_id(): int { return $this->groupId; }
}

// phpcs:ignore
class Red_Module
{
    public static function flush(int $groupId): void
    {
        Red_Item::$flushed++;
    }
}
