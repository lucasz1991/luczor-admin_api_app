<?php

namespace App\Data;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\NetworkPolicy;

final readonly class ProviderRoutingDecision
{
    /**
     * @param  array<int,ModelProfile>  $profiles
     * @param  array<int,float|null>  $estimatedCostsByProfileId
     * @param  array<int,string>  $excludedReasonCodes
     */
    public function __construct(
        public ModelUseCase $useCase,
        public NetworkPolicy $networkPolicy,
        public array $profiles,
        public string $selectionSource,
        public string $reasonCode,
        public int $policyVersion,
        public int $maxAttempts,
        public ?int $maxInputTokens,
        public ?int $maxOutputTokens,
        public ?float $maxCostUsd,
        public float $reservedCostUsd,
        public array $estimatedCostsByProfileId,
        public array $excludedReasonCodes,
    ) {}

    public function estimatedCost(ModelProfile $profile): ?float
    {
        return $this->estimatedCostsByProfileId[$profile->id] ?? null;
    }

    /** @return array<string,string> */
    public function headers(?ModelProfile $profile = null): array
    {
        $headers = [
            'X-Luczor-Routing-Class' => 'external',
            'X-Luczor-Routing-Policy-Version' => (string) $this->policyVersion,
            'X-Luczor-Routing-Reason' => $this->reasonCode,
            'X-Luczor-Selection-Source' => $this->selectionSource,
            'X-Luczor-Network-Policy' => $this->networkPolicy->key,
            'X-Luczor-Attempt-Limit' => (string) $this->maxAttempts,
            'X-Luczor-Reserved-Cost-Usd' => number_format($this->reservedCostUsd, 8, '.', ''),
        ];
        $estimated = $profile ? $this->estimatedCost($profile) : null;
        if ($estimated !== null) {
            $headers['X-Luczor-Estimated-Cost-Usd'] = number_format($estimated, 8, '.', '');
        }

        return $headers;
    }
}
