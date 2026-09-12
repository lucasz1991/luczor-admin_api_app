<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->unsignedBigInteger('revision')->default(0));
        Schema::table('luczor_message_archives', function (Blueprint $table) {
            $table->foreignId('conversation_ref_id')->nullable()->constrained('conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_sequence')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unique(['conversation_ref_id', 'external_id'], 'archive_conversation_message_unique');
        });
        Schema::create('device_leaderships', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('leader_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('preferred_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->unsignedBigInteger('epoch')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('coordination_seen_at')->nullable();
            $table->boolean('coordination_available')->default(false);
            $table->boolean('coordination_busy')->default(false);
        });
        Schema::create('device_lan_identities', function (Blueprint $table) {
            $table->foreignId('device_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('cert_sha256', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
        });
        Schema::table('device_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('protocol_version')->default(1);
            $table->uuid('operation_id')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->foreignId('source_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->unsignedBigInteger('master_epoch')->nullable();
            $table->uuid('attempt_id')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedBigInteger('progress_sequence')->default(0);
            $table->longText('progress')->nullable();
            $table->string('conversation_external_id', 120)->nullable();
            $table->unique(['user_id', 'operation_id'], 'device_job_operation_unique');
        });
        Schema::create('device_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 30);
            $table->longText('data');
            $table->timestamp('created_at');
            $table->unique(['device_job_id', 'sequence']);
        });
        Schema::create('project_mirror_heads', function (Blueprint $table) {
            $table->foreignId('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
            $table->uuid('manifest_id')->nullable();
            $table->uuid('lease_id')->nullable();
            $table->unsignedBigInteger('master_epoch')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('project_mirror_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->string('storage_key');
            $table->timestamp('created_at');
            $table->unique(['project_id', 'sha256']);
        });
        Schema::create('project_mirror_manifests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('base_revision');
            $table->unsignedBigInteger('revision')->nullable();
            $table->unsignedBigInteger('master_epoch')->nullable();
            $table->uuid('job_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->char('manifest_hash', 64)->nullable();
            $table->uuid('merged_manifest_id')->nullable();
            $table->unsignedBigInteger('entry_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'device_id', 'operation_id'], 'mirror_manifest_operation_unique');
        });
        Schema::create('project_mirror_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('manifest_id');
            $table->foreign('manifest_id')->references('id')->on('project_mirror_manifests')->cascadeOnDelete();
            $table->char('path_hash', 64);
            $table->char('entry_hash', 64);
            $table->longText('entry');
            $table->unique(['manifest_id', 'path_hash']);
        });
        Schema::create('project_mirror_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('manifest_id');
            $table->foreign('manifest_id')->references('id')->on('project_mirror_manifests')->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->char('request_hash', 64);
            $table->string('action', 20);
            $table->unique(['manifest_id', 'operation_id']);
        });
        Schema::create('workflow_test_matrices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('definition_version');
            $table->json('test_ids');
            $table->timestamps();
            $table->unique(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('luczor_message_archives', function (Blueprint $table) {
            $table->dropUnique('archive_conversation_message_unique');
            $table->dropConstrainedForeignId('conversation_ref_id');
            $table->dropColumn(['conversation_sequence', 'content_hash']);
        });
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('revision'));
        foreach (['device_lan_identities', 'workflow_test_matrices', 'project_mirror_operations', 'project_mirror_entries', 'project_mirror_manifests', 'project_mirror_chunks', 'project_mirror_heads', 'device_job_events'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('device_jobs', function (Blueprint $table) {
            $table->dropUnique('device_job_operation_unique');
            $table->dropConstrainedForeignId('source_device_id');
            $table->dropColumn(['protocol_version', 'operation_id', 'request_hash', 'master_epoch', 'attempt_id', 'lease_expires_at', 'progress_sequence', 'progress', 'conversation_external_id']);
        });
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn(['coordination_seen_at', 'coordination_available', 'coordination_busy']));
        Schema::dropIfExists('device_leaderships');
    }
};
