<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per signature request: links your record (any model) to an eContract contract
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('signable');
            $table->string('provider')->default('econtract');
            $table->string('external_ref', 128)->index(); // sent as externalRef, comes back in every webhook
            $table->string('provider_contract_id')->nullable()->unique();
            $table->string('provider_code')->nullable();
            $table->string('status', 32)->default('processing');
            $table->json('signers')->nullable();
            $table->json('signing_links')->nullable();
            $table->string('signed_path')->nullable();
            $table->string('signed_sha256', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Event ids already applied: eContract retries, so the same event can arrive twice
        Schema::create('econtract_webhook_events', function (Blueprint $table) {
            $table->string('event_id')->primary();
            $table->string('event');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('econtract_webhook_events');
        Schema::dropIfExists('signature_requests');
    }
};
