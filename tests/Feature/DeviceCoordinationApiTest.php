<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use App\Services\DeviceJobSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceCoordinationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        Config::set('luczor.device_jobs.private_key', $private);
        Config::set('luczor.device_jobs.private_key_file', '');
    }

    public function test_failover_increments_fence_and_preferred_handback_waits_for_idle(): void
    {
        $user = User::factory()->create();
        $master = $this->device($user, 'windows');
        $worker = $this->device($user, 'linux');
        $this->heartbeat($master, true)->assertJsonPath('data.epoch', 1)->assertJsonPath('data.role', 'master');
        $this->heartbeat($worker)->assertJsonPath('data.role', 'assistant');
        $this->travel(46)->seconds();
        $this->heartbeat($worker, false, true)->assertJsonPath('data.epoch', 2)->assertJsonPath('data.leader_device_id', 'linux');
        $this->heartbeat($master, true)->assertJsonPath('data.epoch', 2)->assertJsonPath('data.handoff_pending', true);
        $this->heartbeat($worker)->assertJsonPath('data.epoch', 3)->assertJsonPath('data.leader_device_id', 'windows');
    }

    public function test_claim_is_once_per_attempt_completion_is_idempotent_and_legacy_routes_cannot_bypass_fence(): void
    {
        $user = User::factory()->create();
        $master = $this->device($user, 'master');
        $worker = $this->device($user, 'worker');
        $this->heartbeat($master, true);
        $body = $this->jobBody('worker');
        $job = $this->withHeader('X-Api-Key', $master)->postJson('/api/v1/coordination/jobs', $body)->assertCreated()->json('data');
        $this->withHeader('X-Api-Key', $master)->postJson('/api/v1/coordination/jobs', $body)->assertCreated()->assertJsonPath('data.id', $job['id']);
        $this->assertDatabaseCount('device_jobs', 1);
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        $url = '/api/v1/coordination/jobs/'.$job['id'];
        $this->withHeader('X-Api-Key', $worker)->postJson($url.'/claim', $attempt)->assertOk()->assertJsonPath('data.status', 'running');
        $this->postJson($url.'/claim', $attempt)->assertOk();
        $this->postJson($url.'/claim', ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1])->assertConflict();
        $this->postJson('/api/v1/devices/jobs/'.$job['id'].'/complete', ['client_id' => 'worker', 'ok' => true])->assertConflict();
        $progress = $attempt + ['sequence' => 1, 'summary' => 'Public progress'];
        $this->postJson($url.'/progress', $progress)->assertOk();
        $this->postJson($url.'/progress', $progress)->assertOk();
        $this->postJson($url.'/progress', $attempt + ['sequence' => 1, 'summary' => 'Different'])->assertConflict();
        $complete = $attempt + ['ok' => true, 'result' => ['text' => 'Exact result']];
        $this->postJson($url.'/complete', $complete)->assertOk();
        $this->postJson($url.'/complete', $complete)->assertOk();
        $this->postJson($url.'/complete', $attempt + ['ok' => true, 'result' => ['text' => 'Changed']])->assertConflict();
        $this->assertStringNotContainsString('Exact result', DB::table('device_jobs')->sole()->result);
        $model = DeviceJob::firstOrFail();
        $this->assertSame(1, openssl_verify(app(DeviceJobSigner::class)->canonical($model), base64_decode($model->signature), app(DeviceJobSigner::class)->publicKey(), OPENSSL_ALGO_SHA256));
    }

    public function test_old_epoch_cannot_execute_and_original_device_can_acknowledge_stop_after_failover(): void
    {
        $user = User::factory()->create();
        $master = $this->device($user, 'master');
        $worker = $this->device($user, 'worker');
        $this->heartbeat($master, true);
        $job = $this->withHeader('X-Api-Key', $master)->postJson('/api/v1/coordination/jobs', $this->jobBody('worker'))->json('data');
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        $url = '/api/v1/coordination/jobs/'.$job['id'];
        $this->withHeader('X-Api-Key', $worker)->postJson($url.'/claim', $attempt)->assertOk();
        $this->travel(46)->seconds();
        $this->heartbeat($worker)->assertJsonPath('data.epoch', 2);
        $this->getJson('/api/v1/coordination/jobs/pending')->assertOk()->assertJsonPath('data.0.cancel_requested', false)
            ->assertJsonPath('data.0.reconciliation_required', true);
        // Only an explicit stop, not a handoff, cancels the existing attempt.
        $this->postJson($url.'/cancel', ['master_epoch' => 2])->assertOk();
        $this->postJson($url.'/complete', $attempt + ['ok' => true])->assertConflict();
        $this->getJson('/api/v1/coordination/jobs/pending')->assertOk()->assertJsonPath('data.0.cancel_requested', true);
        $this->postJson($url.'/cancel-ack', $attempt)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->withHeader('X-Api-Key', $master)->postJson('/api/v1/coordination/jobs', $this->jobBody('worker'))->assertConflict();
    }

    public function test_failover_adopts_only_existing_attempt_without_changing_signature_or_restarting_effect(): void
    {
        $user = User::factory()->create();
        $master = $this->device($user, 'master');
        $worker = $this->device($user, 'worker');
        $observer = $this->device($user, 'observer');
        $this->heartbeat($master, true);
        $job = $this->postJson('/api/v1/coordination/jobs', $this->jobBody('worker'))->assertCreated()->json('data');
        $url = '/api/v1/coordination/jobs/'.$job['id'];
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        $claimed = $this->withHeader('X-Api-Key', $worker)->postJson($url.'/claim', $attempt)->assertOk()->json('data');
        $this->travel(46)->seconds();
        $this->heartbeat($worker, false, true)->assertJsonPath('data.epoch', 2);
        $this->postJson($url.'/progress', $attempt + ['sequence' => 1])->assertConflict();
        $this->withHeader('X-Api-Key', $observer)->postJson($url.'/adopt', ['master_epoch' => 2, 'attempt_id' => $attempt['attempt_id']])->assertForbidden();
        $this->withHeader('X-Api-Key', $worker)->postJson($url.'/adopt', ['master_epoch' => 2, 'attempt_id' => (string) Str::uuid()])->assertConflict();
        $adopted = $this->postJson($url.'/adopt', ['master_epoch' => 2, 'attempt_id' => $attempt['attempt_id']])->assertOk()
            ->assertJsonPath('data.master_epoch', 1)->assertJsonPath('data.authority_epoch', 2)
            ->assertJsonPath('data.reconciliation_required', false)->json('data');
        $this->assertSame($claimed['signature'], $adopted['signature']);
        $this->assertSame($claimed['attempt_id'], $adopted['attempt_id']);
        $this->postJson($url.'/progress', $attempt + ['sequence' => 1])->assertConflict();
        $this->postJson($url.'/progress', $attempt + ['authority_epoch' => 2, 'sequence' => 1])->assertOk();
        $this->postJson($url.'/claim', ['master_epoch' => 1, 'authority_epoch' => 2, 'attempt_id' => (string) Str::uuid()])->assertConflict();
        $result = $attempt + ['ok' => true, 'result' => ['effect_receipt' => 'exactly-once']];
        $this->postJson($url.'/complete', $result)->assertOk();
        $this->postJson($url.'/complete', $result)->assertOk();
        $this->assertDatabaseCount('device_jobs', 1);
        $this->assertSame($claimed['signature'], DeviceJob::sole()->signature);
    }

    public function test_late_original_attempt_evidence_survives_epoch_change_and_lost_ack_without_new_authority(): void
    {
        $user = User::factory()->create();
        $master = $this->device($user, 'master');
        $worker = $this->device($user, 'worker');
        $this->heartbeat($master, true);
        $job = $this->postJson('/api/v1/coordination/jobs', $this->jobBody('master'))->assertCreated()->json('data');
        $url = '/api/v1/coordination/jobs/'.$job['id'];
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        $this->postJson($url.'/claim', $attempt)->assertOk();
        $this->travel(46)->seconds();
        $this->heartbeat($worker)->assertJsonPath('data.epoch', 2);
        // An unavailable original master may report a finished effect, but cannot create or renew effects.
        $this->withHeader('X-Api-Key', $master)->postJson($url.'/progress', $attempt + ['authority_epoch' => 2, 'sequence' => 1])->assertConflict();
        $result = $attempt + ['ok' => true, 'result' => ['receipt' => 'effect completed before disconnect']];
        $this->postJson($url.'/complete', $result)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->travel(46)->seconds();
        $this->heartbeat($worker)->assertJsonPath('data.epoch', 3);
        $this->withHeader('X-Api-Key', $master)->postJson($url.'/complete', $result)->assertOk();
        $this->postJson('/api/v1/coordination/jobs', $this->jobBody('worker'))->assertConflict();
        $this->assertDatabaseCount('device_jobs', 1);
    }

    public function test_account_isolation_including_admin_and_device_bound_claim(): void
    {
        $user = User::factory()->create();
        $master = $this->device($user, 'master');
        $worker = $this->device($user, 'worker');
        $other = $this->device(User::factory()->create(['role' => 'admin']), 'other');
        $this->heartbeat($master, true);
        $job = $this->withHeader('X-Api-Key', $master)->postJson('/api/v1/coordination/jobs', $this->jobBody('worker'))->json('data');
        $this->withHeader('X-Api-Key', $other)->getJson('/api/v1/coordination/jobs/'.$job['id'])->assertNotFound();
        $this->getJson('/api/v1/coordination/jobs')->assertJsonCount(0, 'data');
        $this->withHeader('X-Api-Key', $master)->postJson('/api/v1/coordination/jobs/'.$job['id'].'/claim', ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1])->assertNotFound();
        $this->withHeader('X-Api-Key', $worker)->postJson('/api/v1/coordination/jobs', $this->jobBody('master'))->assertForbidden();
    }

    public function test_signed_lan_identity_attests_only_owned_devices_and_does_not_extend_leadership(): void
    {
        $owner = User::factory()->create();
        $key = $this->device($owner, 'tls');
        $other = $this->device(User::factory()->create(), 'foreign');
        $this->heartbeat($key, true);
        $signed = $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/coordination/identity', ['cert_sha256' => str_repeat('a', 64)])
            ->assertOk()->assertJsonPath('data.client_id', 'tls')->json('data');
        $signature = $signed['signature'];
        unset($signed['signature'], $signed['algorithm']);
        $this->assertSame(1, openssl_verify(json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), base64_decode($signature), app(DeviceJobSigner::class)->publicKey(), OPENSSL_ALGO_SHA256));
        $this->withHeader('X-Api-Key', $other)->getJson('/api/v1/coordination/identities')->assertJsonCount(0, 'data');
        $this->travel(46)->seconds();
        $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/coordination/jobs', $this->jobBody('tls'))->assertConflict();
    }

    public function test_observation_empty_payload_and_exact_text_profiles_are_supported_and_artifacts_remain_private(): void
    {
        Storage::fake('cloud-projects');
        $owner = User::factory()->create();
        $master = $this->device($owner, 'master');
        $worker = $this->device($owner, 'worker');
        $this->heartbeat($master, true);
        $body = $this->jobBody('worker');
        $body['tool_profile'] = 'desktop.observe';
        $body['payload'] = [];
        $job = $this->postJson('/api/v1/coordination/jobs', $body)->assertCreated()->json('data');
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        $this->withHeader('X-Api-Key', $worker)->postJson('/api/v1/coordination/jobs/'.$job['id'].'/claim', $attempt)->assertOk();
        $bytes = "\x89PNG\0private-screenshot";
        $sha = hash('sha256', $bytes);
        $url = '/api/v1/coordination/jobs/'.$job['id'].'/artifacts/'.$sha;
        $this->call('PUT', $url, [], [], [], ['HTTP_X_API_KEY' => $worker, 'CONTENT_TYPE' => 'application/octet-stream'], $bytes)->assertOk();
        $this->withHeader('X-Api-Key', $master)->get($url)->assertOk()->assertContent($bytes);
        $this->call('PUT', $url, [], [], [], ['HTTP_X_API_KEY' => $master, 'CONTENT_TYPE' => 'application/octet-stream'], $bytes)->assertForbidden();
        $this->withHeader('X-Api-Key', $this->device(User::factory()->create(), 'other'))->get($url)->assertNotFound();
        $this->assertStringNotContainsString('private-screenshot', Storage::disk('cloud-projects')->get(Storage::disk('cloud-projects')->allFiles()[0]));
        $body = $this->jobBody('worker');
        $body['tool_profile'] = 'desktop.input.type_text';
        $body['payload'] = ['text' => "  exact whitespace\n", 'observation_id' => 'observation-1'];
        $this->withHeader('X-Api-Key', $master)->postJson('/api/v1/coordination/jobs', $body)->assertCreated()
            ->assertJsonPath('data.payload.text', "  exact whitespace\n")->assertJsonPath('data.payload.observation_id', 'observation-1');
        unset($body['payload']['observation_id']);
        $body['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/coordination/jobs', $body)->assertUnprocessable();
    }

    public function test_late_progress_cannot_extend_expired_attempt_lease_and_error_results_are_encrypted(): void
    {
        $owner = User::factory()->create();
        $master = $this->device($owner, 'master');
        $worker = $this->device($owner, 'worker');
        $this->heartbeat($master, true);
        $job = $this->postJson('/api/v1/coordination/jobs', $this->jobBody('worker'))->assertCreated()->json('data');
        $url = '/api/v1/coordination/jobs/'.$job['id'];
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        $this->withHeader('X-Api-Key', $worker)->postJson($url.'/claim', $attempt)->assertOk();
        $this->travel(30)->seconds();
        $this->heartbeat($master, true);
        $this->travel(16)->seconds();
        $this->withHeader('X-Api-Key', $worker)->postJson($url.'/progress', $attempt + ['sequence' => 1])->assertConflict();
        $this->postJson($url.'/claim', $attempt)->assertConflict();
        $this->postJson($url.'/complete', $attempt + ['ok' => false, 'error' => 'Private path in failure'])->assertOk();
        $this->assertStringNotContainsString('Private path', DB::table('device_jobs')->sole()->error);
        $this->getJson($url)->assertJsonPath('data.error', 'Private path in failure');
    }

    private function jobBody(string $target): array
    {
        return ['operation_id' => (string) Str::uuid(), 'target_device_id' => $target, 'master_epoch' => 1,
            'tool_profile' => 'chat.turn', 'payload' => ['prompt' => 'Public task']];
    }

    private function device(User $user, string $id): string
    {
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => $id, 'device_id' => $id,
            'abilities' => ['device.connect', 'device.jobs.read', 'device.jobs.write', 'brain.read', 'brain.write'], 'active' => true]);
        Device::create(['user_id' => $user->id, 'device_id' => $id, 'name' => $id, 'status' => 'online']);

        return $key['plain'];
    }

    private function heartbeat(string $token, bool $preferred = false, bool $busy = false)
    {
        return $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/coordination/heartbeat', ['available' => true, 'busy' => $busy, 'preferred' => $preferred]);
    }
}
