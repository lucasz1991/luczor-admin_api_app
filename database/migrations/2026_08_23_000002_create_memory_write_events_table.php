<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_write_events', function (Blueprint $table) {
            $table->id();
            $table->char('idempotency_key', 64)->unique();
            $table->char('write_fingerprint', 64);
            $table->unsignedBigInteger('memory_link_id')->nullable()->index();
            $table->foreign('memory_link_id')->references('id')->on('memory_links')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('dataset')->index();
            $table->string('state', 24)->default('committed')->index();
            $table->timestamp('forgotten_at')->nullable();
            $table->timestamps();
        });

        // Deployments which already accepted writes with the previous schema
        // gain durable event records before the new code can delete a link.
        DB::table('memory_links')
            ->whereNotNull('idempotency_key')
            ->whereNotNull('write_fingerprint')
            ->select(['id', 'user_id', 'dataset', 'idempotency_key', 'write_fingerprint', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('memory_write_events')->insertOrIgnore([
                        'idempotency_key' => $row->idempotency_key,
                        'write_fingerprint' => $row->write_fingerprint,
                        'memory_link_id' => $row->id,
                        'user_id' => $row->user_id,
                        'dataset' => $row->dataset,
                        'state' => 'committed',
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_write_events');
    }
};
