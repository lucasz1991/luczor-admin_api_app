<?php

namespace App\Services;

use App\Services\Cognee\CogneeClient;

/**
 * Server-side memory facade mirroring the desktop LuczorMemoryService.
 *
 * The rest of the Laravel app (controllers, jobs) uses this facade instead of
 * calling Cognee directly, so dataset namespacing, scope rules and graceful
 * degradation live in ONE place. When Cognee is not configured, remember/recall
 * degrade to no-ops (recall returns []) while the sync archive keeps working.
 */
class LuczorMemoryService
{
    public function __construct(private ?CogneeClient $cognee = null)
    {
        $this->cognee ??= CogneeClient::fromConfig();
    }

    public function enabled(): bool
    {
        return $this->cognee->enabled();
    }

    /**
     * Namespaced dataset key. Scopes: private | project | skill | agent | global.
     */
    public function datasetFor(string $scope, array $ids = []): string
    {
        $user = $ids['user_id'] ?? 'server';

        return match ($scope) {
            'project' => "user:{$user}:projects:".($ids['project_id'] ?? 'default'),
            'skill' => "user:{$user}:skills",
            'agent' => 'agent:'.($ids['agent_id'] ?? 'default').':runs',
            'global' => 'global:knowledge',
            default => "user:{$user}:private",
        };
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function remember(string $scope, string $content, array $ids = [], array $meta = []): void
    {
        $content = trim($content);
        if ($content === '' || ! $this->cognee->enabled()) {
            return;
        }

        $this->cognee->add($this->datasetFor($scope, $ids), $content, array_merge($meta, [
            'scope' => $scope,
            'user_id' => $ids['user_id'] ?? null,
            'project_id' => $ids['project_id'] ?? null,
        ]));
    }

    /**
     * @return array<int,mixed>
     */
    public function recall(string $scope, string $query, array $ids = [], int $topK = 6): array
    {
        if (! $this->cognee->enabled()) {
            return [];
        }

        return $this->cognee->search($this->datasetFor($scope, $ids), $query, $topK);
    }

    public function forget(string $scope, string $id, array $ids = []): void
    {
        if ($this->cognee->enabled()) {
            $this->cognee->delete($this->datasetFor($scope, $ids), $id);
        }
    }

    public function improve(string $scope, array $ids = []): void
    {
        if ($this->cognee->enabled()) {
            $this->cognee->cognify($this->datasetFor($scope, $ids));
        }
    }
}
