<?php

namespace App\Data;

use App\Models\MemoryLink;
use App\Services\MemoryPriority;

final readonly class MemoryWriteResult
{
    /** @param array<int,string> $targets */
    public function __construct(
        public string $decision,
        public string $reason,
        public ?MemoryLink $link = null,
        public array $targets = [],
    ) {}

    public function persisted(): bool
    {
        return $this->link !== null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
            'targets' => $this->targets,
            'persisted' => $this->persisted(),
            'id' => $this->link?->logicalExternalId(),
            'memory_link_id' => $this->link?->id,
            'status' => $this->link?->status,
            'projection_status' => $this->link?->projection_status,
            'priority' => $this->link ? MemoryPriority::name((float) $this->link->importance) : null,
        ];
    }
}
