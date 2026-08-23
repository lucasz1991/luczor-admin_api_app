<?php

namespace App\Data;

use App\Models\MemoryLink;

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
            'id' => $this->link?->external_id,
            'memory_link_id' => $this->link?->id,
            'status' => $this->link?->status,
            'projection_status' => $this->link?->projection_status,
        ];
    }
}
