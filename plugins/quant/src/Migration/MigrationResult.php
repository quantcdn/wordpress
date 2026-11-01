<?php

declare(strict_types=1);

namespace Quant\Migration;

/**
 * Result of a v1 to v2 migration operation.
 */
final class MigrationResult
{
    /**
     * @param bool $success Whether the migration was successful
     * @param array<string, mixed> $migrated Items that were migrated
     * @param array<string> $errors Any errors encountered during migration
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $migrated,
        public readonly array $errors
    ) {}

    /**
     * Create a successful result.
     *
     * @param array<string, mixed> $migrated
     */
    public static function success(array $migrated = []): self
    {
        return new self(true, $migrated, []);
    }

    /**
     * Create a failed result.
     *
     * @param array<string> $errors
     * @param array<string, mixed> $migrated Items that were migrated before failure
     */
    public static function failure(array $errors, array $migrated = []): self
    {
        return new self(false, $migrated, $errors);
    }

    /**
     * Convert to array for JSON response.
     *
     * @return array{success: bool, migrated: array<string, mixed>, errors: array<string>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'migrated' => $this->migrated,
            'errors' => $this->errors,
        ];
    }
}
