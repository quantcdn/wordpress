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
    ) {
        $this->id = uniqid('', true);
        $this->createdAt = new DateTimeImmutable();
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
