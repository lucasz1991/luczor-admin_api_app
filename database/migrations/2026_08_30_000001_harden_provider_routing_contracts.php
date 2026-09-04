<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('model_use_cases', 'policy_version')) {
            Schema::table('model_use_cases', function (Blueprint $table): void {
                $table->unsignedInteger('policy_version')->default(1)->after('active');
            });
        }

        Schema::table('llm_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_runs', 'routing_policy_version')) {
                $table->unsignedInteger('routing_policy_version')->nullable()->after('selected_by');
            }
            if (! Schema::hasColumn('llm_runs', 'routing_reason_code')) {
                $table->string('routing_reason_code', 80)->nullable()->after('routing_policy_version');
            }
            if (! Schema::hasColumn('llm_runs', 'estimated_cost_usd')) {
                $table->decimal('estimated_cost_usd', 14, 8)->nullable()->after('calculated_cost');
            }
        });

        $this->backfillCredentialFormats();
    }

    public function down(): void
    {
        Schema::table('llm_runs', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['routing_policy_version', 'routing_reason_code', 'estimated_cost_usd'],
                static fn (string $column): bool => Schema::hasColumn('llm_runs', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        if (Schema::hasColumn('model_use_cases', 'policy_version')) {
            Schema::table('model_use_cases', function (Blueprint $table): void {
                $table->dropColumn('policy_version');
            });
        }
    }

    private function backfillCredentialFormats(): void
    {
        DB::table('provider_credentials')
            ->select(['id', 'provider', 'request_format', 'meta'])
            ->orderBy('id')
            ->each(function (object $credential): void {
                if (is_string($credential->request_format) && trim($credential->request_format) !== '') {
                    return;
                }

                $meta = is_string($credential->meta) ? json_decode($credential->meta, true) : $credential->meta;
                $legacyWire = is_array($meta) ? ($meta['wire'] ?? null) : null;
                if (! is_string($legacyWire) || trim($legacyWire) === '') {
                    return;
                }
                $allowed = match ((string) $credential->provider) {
                    'openrouter' => ['chat_completions'],
                    'openai' => ['responses', 'chat_completions'],
                    'anthropic' => ['messages'],
                    default => [],
                };

                if (in_array($legacyWire, $allowed, true)) {
                    DB::table('provider_credentials')->where('id', $credential->id)->update([
                        'request_format' => $legacyWire,
                    ]);
                }
            });
    }
};
