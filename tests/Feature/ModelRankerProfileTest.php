<?php

namespace Tests\Feature;

use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Services\ModelRanker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRankerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_rankings_are_profile_specific_and_leave_missing_evaluations_unknown(): void
    {
        $unknown = $this->profile('unknown-profile');
        $evaluated = $this->profile('evaluated-profile');

        foreach (range(1, 5) as $attemptNo) {
            $this->completedAttempt($unknown, $attemptNo);
            $this->completedAttempt($evaluated, $attemptNo, 0.8, true);
        }

        app(ModelRanker::class)->recompute('chat.general');

        $this->assertSame(2, ModelRanking::query()->count());
        $unknownRanking = ModelRanking::query()->where('model_profile_id', $unknown->id)->firstOrFail();
        $evaluatedRanking = ModelRanking::query()->where('model_profile_id', $evaluated->id)->firstOrFail();
        $this->assertNull($unknownRanking->quality_score);
        $this->assertNull($unknownRanking->test_pass_rate);
        $this->assertNull($unknownRanking->context_efficiency_score);
        $this->assertEqualsWithDelta(0.8, (float) $evaluatedRanking->quality_score, 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $evaluatedRanking->test_pass_rate, 0.0001);
    }

    private function profile(string $slug): ModelProfile
    {
        return ModelProfile::create([
            'name' => $slug,
            'slug' => $slug,
            'provider' => 'openrouter',
            'model_id' => 'shared/model',
            'temperature' => 0.1,
            'max_tokens' => 100,
            'active' => true,
        ]);
    }

    private function completedAttempt(
        ModelProfile $profile,
        int $sequence,
        ?float $quality = null,
        ?bool $testPassed = null,
    ): void {
        $run = LlmRun::create([
            'request_id' => $profile->slug.'-'.$sequence,
            'task_type' => 'chat.general',
            'model_id' => $profile->model_id,
            'provider_id' => $profile->provider,
            'status' => 'ok',
            'success' => true,
            'quality_score' => $quality,
            'test_passed' => $testPassed,
        ]);
        LlmAttempt::create([
            'llm_run_id' => $run->id,
            'model_profile_id' => $profile->id,
            'attempt_no' => 1,
            'provider_id' => $profile->provider,
            'model_id' => $profile->model_id,
            'status' => 'completed',
            'http_status' => 200,
            'total_ms' => 500,
            'input_tokens' => 100,
            'output_tokens' => 20,
            'effective_cost' => 0.001,
        ]);
    }
}
