<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zendesk_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('installation_id')->constrained('connector_installations')->cascadeOnDelete();
            $table->string('mode');
            $table->string('status')->default('queued');
            $table->json('checkpoint');
            $table->json('counts');
            $table->text('error')->nullable();
            $table->timestamp('scan_started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'installation_id', 'status']);
        });
        Schema::create('zendesk_source_states', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('installation_id')->constrained('connector_installations')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('remote_id', 100);
            $table->string('parent_id', 100)->nullable();
            $table->string('fingerprint', 64);
            $table->text('relative_path');
            $table->unsignedBigInteger('last_seen_run_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'installation_id', 'kind', 'remote_id'], 'zendesk_source_identity');
            $table->index(['tenant_id', 'installation_id', 'kind', 'parent_id'], 'zendesk_source_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zendesk_source_states');
        Schema::dropIfExists('zendesk_sync_runs');
    }
};
