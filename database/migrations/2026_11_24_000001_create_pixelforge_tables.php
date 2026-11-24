<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // All money is stored as integer nanodollars (1 USD = 1,000,000,000) so nothing ever touches floats.
            $table->bigInteger('balance_nano')->default(0);
            $table->boolean('is_admin')->default(false);
        });

        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();            // also our Idempotency-Key and the metadata we send to Pixelforge
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pf_id')->nullable()->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();
            $table->string('status')->default('queued'); // queued, processing, succeeded, failed
            $table->bigInteger('estimate_nano');          // what we showed the user up front (user price, expected)
            $table->bigInteger('reserved_nano');          // hold taken at submit (user price, worst case)
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable(); // what Pixelforge charged us
            $table->bigInteger('charged_nano')->nullable();       // what the user was charged (cost + 25%)
            $table->timestamp('settled_at')->nullable();          // set exactly once, guards double charging
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();        // Pixelforge's completed_at (UTC), used for daily reports
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('completed_at');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();         // dedupe key
            $table->string('type');
            $table->string('pf_generation_id')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('received_at');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');                        // purchase, hold, release
            $table->bigInteger('amount_nano');             // signed: positive adds to balance
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['balance_nano', 'is_admin']);
        });
    }
};
