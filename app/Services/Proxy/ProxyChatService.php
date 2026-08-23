<?php

namespace App\Services\Proxy;

use App\Data\Proxy\ProxyChatInput;
use App\Data\Proxy\ProxyResponseLimits;
use App\Http\Requests\Api\V1\ProxyChatRequest;
use App\Services\ApiActor;
use App\Services\LlmTelemetryService;
use App\Services\NetworkOptimizer;
use App\Services\ProviderPolicyService;
use Symfony\Component\HttpFoundation\Response;

final class ProxyChatService
{
    public function __construct(
        private ApiActor $actor,
        private ProxyPromptBuilder $promptBuilder,
        private ProviderPolicyService $providerPolicy,
        private NetworkOptimizer $networkOptimizer,
        private LlmTelemetryService $telemetry,
        private ProxyProviderGateway $providerGateway,
        private ProxyResponseFactory $responseFactory,
    ) {}

    public function handle(ProxyChatRequest $request, ProxyChatInput $input): Response
    {
        $admittedUserId = $request->attributes->get('proxyUserId');
        $userId = is_int($admittedUserId) ? $admittedUserId : $this->actor->userId($request);

        $meta = $input->runMeta($request->user()?->id);
        $meta['user_id'] = $userId;
        $meta['client_id'] = $this->actor->deviceId($request, $input->clientId);
        $meta['project_ref_id'] = $this->actor->project($request, $input->projectId)?->id;
        $prepared = $this->promptBuilder->prepare($input, $meta);
        $networkPolicy = $this->networkOptimizer->policy('proxy.openrouter.default');
        $profiles = $this->providerPolicy->candidates(null, $prepared->taskType, $prepared->requiredCapabilities);
        $run = $this->telemetry->startRun(
            $prepared->meta,
            $prepared->taskType,
            $prepared->payload,
            $this->providerPolicy->selectionSource(),
        );

        $maxInputTokens = $this->tightestNumber(
            $networkPolicy->max_input_tokens,
            $prepared->useCase?->max_input_tokens,
        );
        $maxCostUsd = $this->tightestNumber(
            $networkPolicy->max_cost_usd,
            $prepared->useCase?->max_cost_usd,
        );
        $estimatedInputTokens = $this->providerPolicy->estimatedInputTokens($prepared->payload);
        if ($maxInputTokens && $estimatedInputTokens > $maxInputTokens) {
            $run->update([
                'status' => 'budget_rejected',
                'success' => false,
                'input_tokens' => $estimatedInputTokens,
            ]);

            return response()->json([
                'message' => 'Context exceeds the server input-token budget.',
                'request_id' => $run->request_id,
            ], 422);
        }

        $limits = ProxyResponseLimits::fromConfig();
        $dispatch = $this->providerGateway->dispatch(
            $profiles,
            $prepared,
            $run,
            $networkPolicy,
            $maxCostUsd,
            $limits,
        );
        if ($dispatch->failureResponse) {
            return $dispatch->failureResponse;
        }

        return $this->responseFactory->make($prepared, $dispatch, $run, $limits);
    }

    private function tightestNumber(mixed $first, mixed $second): ?float
    {
        $first = is_numeric($first) && (float) $first !== 0.0 ? (float) $first : null;
        $second = is_numeric($second) && (float) $second !== 0.0 ? (float) $second : null;

        return $first !== null && $second !== null ? min($first, $second) : ($first ?? $second);
    }
}
