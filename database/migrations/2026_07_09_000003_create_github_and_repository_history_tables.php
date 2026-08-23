<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oauth_connections')) {
            Schema::create('oauth_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 60);
                $table->string('provider_user_id', 120)->nullable();
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->json('scopes')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'provider']);
                $table->index(['provider', 'provider_user_id']);
            });
        }

        if (! Schema::hasTable('repository_branches')) {
            Schema::create('repository_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('head_sha', 80)->nullable();
                $table->boolean('protected')->default(false);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique(['repository_id', 'name']);
            });
        }

        if (! Schema::hasTable('repository_commits')) {
            Schema::create('repository_commits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
                $table->string('sha', 80);
                $table->string('branch', 160)->nullable();
                $table->string('message', 1000)->nullable();
                $table->string('author_name', 255)->nullable();
                $table->timestamp('committed_at')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->unique(['repository_id', 'sha']);
                $table->index(['repository_id', 'branch', 'committed_at']);
            });
        }

        if (! Schema::hasTable('repository_changed_files')) {
            Schema::create('repository_changed_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('repository_commit_id')->constrained()->cascadeOnDelete();
                $table->string('path', 1000);
                $table->string('status', 30)->nullable();
                $table->integer('additions')->nullable();
                $table->integer('deletions')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
            // utf8mb4 makes the full (commit_id, path) key 4008 bytes — over InnoDB's
            // 3072-byte cap — so MySQL/MariaDB indexes a 500-char path prefix instead.
            // Name matches the fluent auto-name so existing databases stay consistent.
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('alter table `repository_changed_files` add unique `repository_changed_files_repository_commit_id_path_unique`(`repository_commit_id`, `path`(500))');
            } else {
                Schema::table('repository_changed_files', function (Blueprint $table) {
                    $table->unique(['repository_commit_id', 'path']);
                });
            }
        }

        if (! Schema::hasTable('github_webhook_deliveries')) {
            Schema::create('github_webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->string('delivery_id', 120)->unique();
                $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event', 80);
                $table->string('signature')->nullable();
                $table->string('status', 30)->default('received');
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
        Schema::dropIfExists('repository_changed_files');
        Schema::dropIfExists('repository_commits');
        Schema::dropIfExists('repository_branches');
        Schema::dropIfExists('oauth_connections');
    }
};
