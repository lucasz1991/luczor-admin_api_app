<?php

namespace App\Services\Proxy;

use App\Data\Proxy\ProxyChatInput;
use App\Data\Proxy\ProxyResponseLimits;
use App\Exceptions\RoutingPolicyException;
use App\Http\Requests\Api\V1\ProxyChatRequest;
use App\Services\ApiActor;
use App\Services\LlmTelemetryService;
use App\Services\ProviderPolicyService;
use Symfony\Component\HttpFoundation\Response;

final class ProxyChatService
{
    public function __construct(
        private ApiActor $actor,
        private ProxyPromptBuilder $promptBuilder,
        private ProviderPolicyService $providerPolicy,
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
        $run = $this->telemetry->startRun(
            $prepared->meta,
            $prepared->taskType,
            $prepared->payload,
            'policy_pending',
        );

        try {
            $routing = $this->providerPolicy->resolve(
                $prepared->taskType,
                $prepared->requiredCapabilities,
                $prepared->payload,
            );
        } catch (RoutingPolicyException $exception) {
            $run->update([
                'status' => 'policy_rejected',
                'success' => false,
                'selected_by' => 'policy_fail_closed',
                'routing_reason_code' => $exception->reasonCode,
            ]);

            return response()->json([
                'message' => 'External routing is unavailable under the active policy.',
                'code' => $exception->reasonCode,
                'request_id' => $run->request_id,
            ], $exception->httpStatus)
                ->header('X-Luczor-Request-Id', $run->request_id)
                ->header('X-Luczor-Routing-Class', 'external')
                ->header('X-Luczor-Routing-Reason', $exception->reasonCode);
        }

        $firstProfile = $routing->profiles[0];
        $run->update([
            'selected_by' => $routing->selectionSource,
            'network_policy_id' => $routing->networkPolicy->key,
            'routing_policy_version' => $routing->policyVersion,
            'routing_reason_code' => $routing->reasonCode,
            'estimated_cost_usd' => $routing->estimatedCost($firstProfile),
        ]);

        $estimatedInputTokens = $this->providerPolicy->estimatedInputTokens($prepared->payload);
        if ($routing->maxInputTokens !== null && $estimatedInputTokens > $routing->maxInputTokens) {
            $run->update([
                'status' => 'budget_rejected',
                'success' => false,
                'input_tokens' => $estimatedInputTokens,
                'routing_reason_code' => 'routing_input_budget_exceeded',
            ]);

            return response()->json([
                'message' => 'Context exceeds the server input-token budget.',
                'code' => 'routing_input_budget_exceeded',
                'request_id' => $run->request_id,
            ], 422)->withHeaders($routing->headers($firstProfile))
                ->header('X-Luczor-Request-Id', $run->request_id);
        }

        $limits = ProxyResponseLimits::fromConfig();
        $dispatch = $this->providerGateway->dispatch(
            $routing,
            $prepared,
            $run,
            $limits,
        );
        if ($dispatch->failureResponse) {
            $dispatch->failureResponse->headers->add($routing->headers());
            $dispatch->failureResponse->headers->set('X-Luczor-Request-Id', $run->request_id);

            return $dispatch->failureResponse;
        }

        if ($dispatch->profile) {
            $dispatchedEstimate = $dispatch->attempt?->routing_meta['estimated_cost_usd'] ?? null;
            $run->update([
                'estimated_cost_usd' => is_numeric($dispatchedEstimate)
                    ? (float) $dispatchedEstimate
                    : $routing->estimatedCost($dispatch->profile),
            ]);
        }

        return $this->responseFactory->make($prepared, $dispatch, $run, $limits, $routing);
    }
}
