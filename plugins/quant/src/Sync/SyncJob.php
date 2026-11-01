<?php

declare(strict_types=1);

namespace Quant\Sync;

use DateTimeImmutable;
use JsonSerializable;

final class SyncJob implements JsonSerializable
{
    public readonly string $id;
    public readonly DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly SyncType $type,
        public readonly string $objectId,
        public readonly SyncAction $action,
        public readonly array $meta = [],
        ?string $id = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? uniqid('', true);
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * Create a SyncJob from database row data.
     *
     * @param array{job_id: string, type: string, object_id: string, action: string, created_at: string, meta?: string|null} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            type: SyncType::from($row['type']),
            objectId: $row['object_id'],
            action: SyncAction::from($row['action']),
            meta: self::decodeMeta($row['meta'] ?? null),
            id: $row['job_id'],
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }

    /**
     * Identity of the work this job does, used to replace an older pending
     * job for the same thing. Publish and unpublish share a key so the most
     * recent request wins; a listing page number is part of the key.
     */
    public function dedupeKey(): string
    {
        $group = $this->action === SyncAction::Purge ? 'purge' : 'content';
        $page = (int) ($this->meta['page'] ?? 1);

        return md5($this->type->value . '|' . $this->objectId . '|' . $page . '|' . $group);
    }

    /**
     * Encode meta for storage. Empty meta is stored as NULL.
     */
    public function encodeMeta(): ?string
    {
        if ($this->meta === []) {
            return null;
        }

        $encoded = json_encode($this->meta);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeMeta(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{id: string, type: string, objectId: string, action: string, meta: array<string, mixed>, createdAt: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'objectId' => $this->objectId,
            'action' => $this->action->value,
            'meta' => $this->meta,
            'createdAt' => $this->createdAt->format('c'),
        ];
    }
}
