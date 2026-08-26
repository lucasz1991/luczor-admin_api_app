<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memory_links', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('status', 24)->default('active')->after('staleness')->index();
            $table->string('retention', 24)->default('durable')->after('status');
            $table->string('sensitivity', 24)->default('normal')->after('retention');
            $table->decimal('confidence', 5, 4)->default(0.5)->after('importance');
            $table->char('content_hash', 64)->nullable()->after('summary')->index();
            $table->char('idempotency_key', 64)->nullable()->after('content_hash')->unique();
            $table->char('write_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->string('source_type', 60)->default('user')->after('write_fingerprint');
            $table->string('source_ref', 255)->nullable()->after('source_type');
            $table->json('provenance')->nullable()->after('source_ref');
            $table->timestamp('observed_at')->nullable()->after('provenance');
            $table->timestamp('valid_from')->nullable()->after('observed_at');
            $table->timestamp('valid_until')->nullable()->after('valid_from');
            $table->timestamp('recorded_at')->nullable()->after('valid_until');
            $table->timestamp('expires_at')->nullable()->after('recorded_at')->index();
            $table->foreignId('supersedes_id')->nullable()->after('expires_at')->constrained('memory_links')->nullOnDelete();
            $table->string('write_reason', 120)->nullable()->after('supersedes_id');
            // A row created outside the policy service must never become an
            // automatic Cognee projection merely because a reconciler sees it.
            $table->string('projection_status', 24)->default('legacy_review_required')->after('cognee_memory_id')->index();

            $table->index(['tenant_id', 'user_id', 'scope', 'status'], 'memory_links_tenant_user_scope_status_idx');
            $table->dropUnique('memory_links_client_id_external_id_unique');
            $table->unique(
                ['user_id', 'client_id', 'dataset', 'external_id'],
                'memory_links_user_client_dataset_external_unique'
            );
        });

        // All rows that predate this migration are unclassified legacy data.
        // Hashes are rebuilt in PHP so this remains portable across SQLite,
        // PostgreSQL and MySQL instead of relying on a vendor hash function.
        DB::table('memory_links')
            ->select(['id', 'summary'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $content = trim((string) $row->summary);
                    $normalized = preg_replace('/\s+/u', ' ', $content) ?? $content;
                    DB::table('memory_links')->where('id', $row->id)->update([
                        'content_hash' => hash('sha256', $normalized),
                        'projection_status' => 'legacy_review_required',
                    ]);
                }
            });

        Schema::create('memory_projection_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('memory_link_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 24);
            $table->string('dataset')->index();
            $table->string('dedupe_key', 96)->unique();
            $table->json('payload')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_projection_outbox');

        Schema::table('memory_links', function (Blueprint $table) {
            $table->dropUnique('memory_links_user_client_dataset_external_unique');
            $table->unique(['client_id', 'external_id'], 'memory_links_client_id_external_id_unique');
            $table->dropIndex('memory_links_tenant_user_scope_status_idx');
            $table->dropIndex(['status']);
            $table->dropIndex(['projection_status']);
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['content_hash']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn([
                'status', 'retention', 'sensitivity', 'confidence', 'content_hash', 'idempotency_key', 'write_fingerprint',
                'source_type', 'source_ref', 'provenance', 'observed_at', 'valid_from',
                'valid_until', 'recorded_at', 'expires_at', 'write_reason', 'projection_status',
            ]);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
