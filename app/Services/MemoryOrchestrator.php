<?php

namespace App\Services;

use App\Data\MemoryWriteResult;
use App\Models\MemoryLink;
use App\Models\User;

/**
 * One policy boundary for every server-side memory read and write.
 * HTTP, workflows, skills and context retrieval must all enter here.
 */
class MemoryOrchestrator
{
    public function __construct(
        private LuczorMemoryService $store,
        private ContextCache $contextCache,
    ) {}

    /** @param array<string,mixed> $data */
    public function remember(array $data): MemoryWriteResult
    {
        $content = trim((string) ($data['content'] ?? ''));
        abort_if($content === '', 422, 'Memory content must not be empty.');

        $scope = (string) ($data['scope'] ?? 'project');
        $this->assertGlobalManagement($scope, $data['user_id'] ?? null);
        $sourceType = (string) ($data['source_type'] ?? ($data['source'] ?? 'user'));
        $sensitivity = (string) ($data['sensitivity'] ?? 'normal');
        $visibility = (string) ($data['visibility'] ?? 'syncable');
        $intent = (string) ($data['write_intent'] ?? 'explicit');
        $provenance = array_merge(
            is_array($data['provenance'] ?? null) ? $data['provenance'] : [],
            [
                'source_type' => $sourceType,
                'source_ref' => $data['source_ref'] ?? null,
                'actor_user_id' => $data['user_id'] ?? null,
                'captured_at' => now()->toIso8601String(),
                'policy_version' => 'memory-policy.v2',
            ]
        );
        $policyPayload = array_merge($data, [
            'source_type' => $sourceType,
            'provenance' => $provenance,
        ]);

        if (in_array($scope, ['device', 'private'], true)
            || $visibility === 'private'
            || MemoryDlp::containsLocalOnlySourceInMemoryPayload($policyPayload)
            || $sensitivity === 'secret'
            || MemoryDlp::containsSecretInMemoryPayload($data)) {
            return new MemoryWriteResult(
                'local_only',
                'privacy_or_repository_policy',
                null,
                ['desktop_local'],
            );
        }

        $tenantId = $data['tenant_id'] ?? $this->tenantId($data['user_id'] ?? null);
        $this->assertWorkspaceTenant($scope, $tenantId);

        $candidate = in_array($intent, ['automatic', 'inferred'], true);
        $retention = (string) ($data['retention'] ?? ($scope === 'session' ? 'session' : 'durable'));
        $status = $candidate ? 'candidate' : 'active';
        $cogneeEligible = ! $candidate
            && ! in_array($scope, ['session'], true)
            && in_array($retention, ['durable', 'permanent'], true)
            && MemoryDlp::allowsExternalSemanticContent(array_merge($policyPayload, [
                'content' => $content,
                'sensitivity' => $sensitivity,
            ]));
        $projectToCognee = $cogneeEligible && $this->store->cogneeEnabled();
        $deferCogneeProjection = $cogneeEligible && ! $projectToCognee;

        $link = $this->store->remember(array_merge($data, [
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'source_type' => $sourceType,
            'sensitivity' => $sensitivity,
            'visibility' => $visibility,
            'status' => $status,
            'retention' => $retention,
            'confidence' => max(0, min(1, (float) ($data['confidence'] ?? ($candidate ? 0.35 : 0.85)))),
            'write_reason' => $candidate ? 'awaiting_confirmation' : 'explicit_or_trusted_write',
            'project_to_cognee' => $projectToCognee,
            'defer_cognee_projection' => $deferCogneeProjection,
            'provenance' => $provenance,
        ]));

        $this->contextCache->invalidateMemory((int) ($data['user_id'] ?? 0), $data['project_id'] ?? null);

        $targets = ['sql'];
        if ($projectToCognee) {
            $targets[] = 'cognee_outbox';
        } elseif ($deferCogneeProjection) {
            $targets[] = 'cognee_deferred';
        }

        return new MemoryWriteResult(
            $candidate ? 'candidate' : 'accepted',
            $candidate ? 'confirmation_required' : 'policy_accepted',
            $link,
            $targets,
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recall(string $query, string $scope, array $ids = [], int $topK = 6): array
    {
        $this->assertGlobalManagement($scope, $ids['user_id'] ?? null);
        if (in_array($scope, ['device', 'private'], true)) {
            return [];
        }
        $ids['tenant_id'] ??= $this->tenantId($ids['user_id'] ?? null);
        $this->assertWorkspaceTenant($scope, $ids['tenant_id']);

        return $this->store->recall($query, $scope, $ids, $topK);
    }

    public function forget(string $scope, string $externalId, array $ids = []): bool
    {
        $this->assertGlobalManagement($scope, $ids['user_id'] ?? null);
        if (in_array($scope, ['device', 'private'], true)) {
            return false;
        }
        $ids['tenant_id'] ??= $this->tenantId($ids['user_id'] ?? null);
        $this->assertWorkspaceTenant($scope, $ids['tenant_id']);
        $deleted = $this->store->forget($scope, $externalId, $ids);
        if ($deleted) {
            $this->contextCache->invalidateMemory((int) ($ids['user_id'] ?? 0), $ids['project_id'] ?? null);
        }

        return $deleted;
    }

    public function promote(string $externalId, array $ids = []): ?MemoryLink
    {
        $scope = (string) ($ids['scope'] ?? 'project');
        $this->assertGlobalManagement($scope, $ids['user_id'] ?? null);
        if (in_array($scope, ['device', 'private'], true)) {
            return null;
        }
        $ids['tenant_id'] ??= $this->tenantId($ids['user_id'] ?? null);
        $this->assertWorkspaceTenant($scope, $ids['tenant_id']);
        $ids['scope'] = $scope;
        $link = $this->store->promote($externalId, $ids);
        if ($link) {
            $this->contextCache->invalidateMemory((int) ($ids['user_id'] ?? 0), $link->project_id);
        }

        return $link;
    }

    public function improve(string $scope, array $ids = []): bool
    {
        $this->assertGlobalManagement($scope, $ids['user_id'] ?? null);
        if (in_array($scope, ['device', 'private', 'session'], true)) {
            return false;
        }
        $ids['tenant_id'] ??= $this->tenantId($ids['user_id'] ?? null);
        $this->assertWorkspaceTenant($scope, $ids['tenant_id']);

        return $this->store->improve($scope, $ids);
    }

    private function tenantId(mixed $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        return User::query()->whereKey($userId)->value('tenant_id');
    }

    private function assertGlobalManagement(string $scope, mixed $userId): void
    {
        if ($scope !== 'global') {
            return;
        }

        $user = $userId ? User::query()->find($userId) : null;
        abort_if(! $user?->isAdmin(), 403, 'Global memory is administrator-managed.');
    }

    private function assertWorkspaceTenant(string $scope, mixed $tenantId): void
    {
        abort_if($scope === 'workspace' && ! $tenantId, 422, 'Workspace memory requires a tenant.');
    }
}
