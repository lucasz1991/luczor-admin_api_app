<?php

namespace App\Services\Proxy;

use App\Data\Proxy\PreparedProxyRequest;
use App\Data\Proxy\ProxyDispatchResult;
use App\Data\Proxy\ProxyResponseLimits;
use App\Http\Requests\Api\V1\WorkflowVisionRequest;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\LlmRun;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowOperation;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\AutomationGrantService;
use App\Services\DeviceJobSigner;
use App\Services\LlmTelemetryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Image bytes stay ephemeral; one approval and one workflow execution permit one dispatch. */
final class WorkflowVisionService
{
    public function __construct(
        private ApiActor $actor,
        private WorkflowVisionPolicy $policy,
        private ProxyProviderGateway $gateway,
        private LlmTelemetryService $telemetry,
        private BoundedBodyReader $reader,
        private ProxyRequestAdmissionService $admission,
    ) {}

    public function infer(WorkflowVisionRequest $request): array
    {
        $data = $request->validated();
        $userId = $this->actor->userId($request);
        $deviceId = $this->actor->deviceId($request, $data['client_id'], true);
        $device = Device::where('user_id', $userId)->where('device_id', $deviceId)->whereNull('revoked_at')->firstOrFail();
        $project = Project::where('user_id', $userId)->where('external_id', $data['project_id'])->firstOrFail();
        $this->execution($data, $userId, $device, (int) $project->id);
        $this->admission->admit($request);
        $capability = $this->policy->capabilities();
        abort_unless(hash_equals($capability['revision'], $data['policy_revision']), 409, 'workflow_vision_policy_changed');
        abort_unless($capability['ready'], 409, $capability['reason_code']);
        $this->image($data['image'], $capability);
        $approval = $data['approval'];
        unset($data['approval']);
        $bodyHash = self::approvalHash($data);
        abort_unless(hash_equals($bodyHash, $approval['hash']), 409, 'workflow_vision_approval_hash_mismatch');
        $expires = Carbon::parse($approval['expires_at']);
        abort_unless($expires->isFuture() && $expires->lessThanOrEqualTo(now()->addSeconds(120)), 409, 'workflow_vision_approval_expired');
        [$routing, $budget] = $this->policy->resolve($capability, $data['instruction'], $data['output_format']);

        // Commit consumption before external I/O. A crash or unknown result must never make the nonce reusable.
        $operation = DB::transaction(function () use ($userId, $device, $project, $data, $approval, $bodyHash) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->execution($data, $userId, $device->fresh(), (int) $project->id, true);
            $action = 'vision.'.str_replace('-', '', strtolower($data['workflow_execution_id']));
            abort_if(WorkflowOperation::where('user_id', $userId)->where(fn ($query) => $query->where('operation_id', strtolower($approval['token']))->orWhere('action', $action))->exists(), 409, 'workflow_vision_approval_consumed');

            return WorkflowOperation::create(['user_id' => $userId, 'operation_id' => strtolower($approval['token']), 'action' => $action,
                'request_hash' => $bodyHash, 'status' => 'running', 'response' => ['status' => 'consumed']]);
        }, 3);

        $messages = $this->policy->messages($data['instruction'], $data['output_format']);
        $messages[1]['content'] = [['type' => 'text', 'text' => $data['instruction']],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.$data['image']['base64']]]];
        $payload = ['messages' => $messages, 'stream' => false, 'max_tokens' => $capability['max_output_tokens']];
        if ($data['output_format'] === 'json') {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $meta = ['user_id' => $userId, 'client_id' => $deviceId, 'project_id' => $project->external_id,
            'project_ref_id' => $project->id, 'workflow_id' => $data['workflow_id'], 'task_id' => $data['workflow_execution_id'], 'feature_key' => 'workflow.image.vision'];
        $run = $this->telemetry->startRun($meta, WorkflowVisionPolicy::TASK,
            ['approval_hash' => $bodyHash, 'artifact_sha256' => $data['image']['sha256'], 'policy_revision' => $data['policy_revision']], $routing->selectionSource);
        $run->update(['request_hash' => $bodyHash, 'network_policy_id' => $routing->networkPolicy->key,
            'routing_policy_version' => $routing->policyVersion, 'routing_reason_code' => $routing->reasonCode,
            'estimated_cost_usd' => $routing->estimatedCost($routing->profiles[0])]);
        try {
            abort_unless($expires->isFuture(), 409, 'workflow_vision_approval_expired');
            $this->execution($data, $userId, $device->fresh(), (int) $project->id);
            $prepared = new PreparedProxyRequest($payload, $meta, WorkflowVisionPolicy::TASK, $routing->useCase, ['vision'],
                budgetPayload: $budget, workflowVisionPolicyRevision: $data['policy_revision'], beforeDispatch: function () use ($expires, $data, $userId, $device, $project): void {
                    abort_unless($expires->isFuture(), 409, 'workflow_vision_approval_expired');
                    $this->execution($data, $userId, $device->fresh(), (int) $project->id);
                });
            $configured = ProxyResponseLimits::fromConfig();
            $limits = new ProxyResponseLimits(min(262144, $configured->bodyBytes), $configured->streamBytes, $configured->streamFrameBytes);
            $dispatch = $this->gateway->dispatch($routing, $prepared, $run, $limits);
            $result = $this->result($dispatch, $run, $data, $limits);
            $operation->update(['status' => 'completed', 'response' => $result]);

            return $result;
        } catch (\Throwable $error) {
            $operation->update(['status' => 'failed', 'response' => ['code' => 'workflow_vision_attempt_failed', 'request_id' => $run->request_id]]);
            if ($run->fresh()->status === 'running') {
                $attempt = $run->attempts()->where('status', 'started')->first();
                if ($attempt) {
                    $attempt = $this->telemetry->failAttempt($attempt, 'workflow_vision_attempt_failed', 'The approved vision attempt did not complete.', 0);
                    $this->telemetry->finishRun($run, $attempt);
                }
                $run->update(['status' => 'error', 'success' => false]);
            }
            // Do not let transport messages containing URLs, credentials or request bodies reach logs or callers.
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            abort(502, 'workflow_vision_attempt_failed');
        }
    }

    public static function approvalHash(array $body): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    private function execution(array $data, int $userId, Device $device, int $projectId, bool $lock = false): void
    {
        abort_if($device->revoked_at !== null, 403, 'workflow_vision_device_revoked');
        $root = WorkflowRun::where('user_id', $userId)->where('project_id', $projectId)->where('public_id', $data['workflow_id'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($root->root_workflow_run_id === null && $root->status === 'running' && ! $root->sandbox, 409, 'workflow_vision_run_unavailable');
        $step = WorkflowStep::where('user_id', $userId)->where('execution_id', $data['workflow_execution_id'])->where('type', 'image.vision')
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $run = $step->run;
        abort_unless($run->status === 'running' && ! $run->sandbox && $step->status === 'running'
            && (int) $run->user_id === $userId && (int) $run->project_id === $projectId
            && (int) ($run->root_workflow_run_id ?: $run->id) === (int) $root->id, 409, 'workflow_vision_execution_unavailable');
        $job = DeviceJob::where('user_id', $userId)->where('device_id', $device->id)->where('public_id', $step->external_run_id)
            ->where('workflow_execution_id', $step->execution_id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($step->external_run_type === 'device_job' && $job->status === 'running' && ! $job->cancel_requested_at
            && ! $job->expires_at?->isPast() && $job->tool_profile === 'workflow.task' && ($job->payload['task_key'] ?? null) === 'image.vision', 409, 'workflow_vision_job_unavailable');
        $signer = app(DeviceJobSigner::class);
        $key = $signer->publicKey();
        $signature = is_string($job->signature) ? base64_decode($job->signature, true) : false;
        abort_unless($key && $signature && hash_equals($job->payload_hash, app(AuditLogger::class)->hash($job->payload))
            && openssl_verify($signer->canonical($job), $signature, $key, OPENSSL_ALGO_SHA256) === 1, 409, 'workflow_vision_job_signature_invalid');
        $params = $step->resolved_payload ?? $step->payload;
        abort_unless(($params['artifact_id'] ?? null) === $data['image']['artifact_id'] && ($params['instruction'] ?? null) === $data['instruction']
            && ($params['output_format'] ?? 'text') === $data['output_format'] && ($params['inference'] ?? null) === 'external'
            && ($params['max_output_chars'] ?? 12000) === $data['max_output_chars'], 409, 'workflow_vision_task_mismatch');
        abort_unless(($job->payload['params']['artifact_id'] ?? null) === $data['image']['artifact_id']
            && ($job->payload['params']['instruction'] ?? null) === $data['instruction']
            && ($job->payload['params']['output_format'] ?? 'text') === $data['output_format']
            && ($job->payload['params']['inference'] ?? null) === 'external'
            && ($job->payload['params']['max_output_chars'] ?? 12000) === $data['max_output_chars'], 409, 'workflow_vision_signed_task_mismatch');
        if ($run->context['_execution']['automatic'] ?? false) {
            app(AutomationGrantService::class)->authorizeTask($run, $step->type, $params, $step);
        }
    }

    private function image(array $image, array $capability): void
    {
        abort_if($image['bytes'] > $capability['max_image_bytes'] || $capability['max_pixels'] < $image['width'] * $image['height'], 422, 'workflow_vision_image_budget_exceeded');
        $bytes = base64_decode($image['base64'], true);
        abort_unless(is_string($bytes) && base64_encode($bytes) === $image['base64'] && strlen($bytes) === $image['bytes']
            && hash_equals($image['sha256'], hash('sha256', $bytes)) && str_starts_with($bytes, "\x89PNG\r\n\x1a\n"), 422, 'workflow_vision_image_integrity_failed');
        $size = @getimagesizefromstring($bytes);
        abort_unless(is_array($size) && $size[2] === IMAGETYPE_PNG && $size[0] === $image['width'] && $size[1] === $image['height'], 422, 'workflow_vision_image_dimensions_invalid');
        $decoded = @imagecreatefromstring($bytes);
        abort_unless($decoded !== false, 422, 'workflow_vision_png_invalid');
        imagedestroy($decoded);
    }

    private function result(ProxyDispatchResult $dispatch, LlmRun $run, array $data, ProxyResponseLimits $limits): array
    {
        if ($dispatch->failureResponse !== null) {
            abort($dispatch->failureResponse->getStatusCode(), 'workflow_vision_provider_unavailable');
        }
        abort_unless($dispatch->succeeded(), 502, 'workflow_vision_dispatch_invalid');
        $read = $this->reader->read($dispatch->upstream->getBody(), $limits->bodyBytes);
        $status = $dispatch->upstream->getStatusCode();
        $valid = $status >= 200 && $status < 300 && ! $read->limitExceeded && ! $read->readFailed;
        $json = $valid ? json_decode($read->contents, true) : null;
        $message = is_array($json) ? ($json['choices'][0]['message'] ?? []) : [];
        $text = $message['content'] ?? null;
        $finish = $json['choices'][0]['finish_reason'] ?? null;
        $valid = $valid && is_string($text) && trim($text) !== '' && mb_strlen($text) <= $data['max_output_chars']
            && empty($message['tool_calls']) && $finish === 'stop' && ! str_contains($text, 'data:image/')
            && ! str_contains($text, $data['image']['base64']) && ! preg_match('/<(?:think|analysis|reasoning)\b/i', $text);
        $output = null;
        if ($valid && $data['output_format'] === 'json') {
            $output = json_decode($text);
            $valid = $output instanceof \stdClass;
        }
        $usage = [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (is_int($json['usage'][$key] ?? null) && $json['usage'][$key] >= 0 && $json['usage'][$key] <= 10000000) {
                $usage[$key] = $json['usage'][$key];
            }
        }
        foreach (['cost', 'total_cost'] as $key) {
            $value = $json['usage'][$key] ?? null;
            if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1000000) {
                $usage[$key] = $value;
            }
        }
        $attempt = $this->telemetry->finishAttempt($dispatch->attempt, $status, max(0, (int) round((microtime(true) - $dispatch->startedAt) * 1000)), $usage,
            ['finish_reason' => is_string($finish) ? mb_substr($finish, 0, 40) : null, 'connect_ms' => $dispatch->connectMs,
                'response_hash' => $read->sha256, 'error_type' => $valid ? null : 'workflow_vision_response_invalid']);
        $this->telemetry->finishRun($run, $attempt);
        abort_unless($valid, 502, 'workflow_vision_response_invalid');
        $reported = isset($usage['prompt_tokens'], $usage['completion_tokens']);

        return ['ok' => true, ...($data['output_format'] === 'json' ? ['data' => $output] : ['text' => $text]),
            'model' => $dispatch->profile->model_id, 'provider' => $dispatch->profile->provider, 'request_id' => $run->request_id,
            'usage' => ['input_tokens' => $usage['prompt_tokens'] ?? null, 'output_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $reported ? $usage['prompt_tokens'] + $usage['completion_tokens'] : null],
            'usage_source' => $reported ? 'reported' : 'unavailable', 'finish_reason' => 'stop',
            'policy_revision' => $data['policy_revision'], 'artifact_sha256' => $data['image']['sha256'], 'thinking_application' => 'provider_not_confirmed'];
    }
}
