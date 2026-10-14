<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_sources', function (Blueprint $table) {
            $table->unsignedSmallInteger('scan_every_days')->default(7)->after('active');
            $table->unsignedTinyInteger('failures')->default(0)->after('last_error'); // échecs consécutifs
            $table->unsignedInteger('last_input_tokens')->default(0)->after('last_found');
            $table->unsignedInteger('last_output_tokens')->default(0)->after('last_input_tokens');
        });

        Schema::table('agenda_scans', function (Blueprint $table) {
            $table->boolean('batch')->default(false)->after('force'); // extraction via l'API Batch d'OpenAI
            $table->string('ai_batch_id', 100)->nullable()->after('batch');
            $table->unsignedSmallInteger('sources_skipped')->default(0)->after('sources_done'); // trop récentes
            $table->unsignedSmallInteger('ai_calls')->default(0)->after('sources_skipped');
            $table->unsignedInteger('input_tokens')->default(0)->after('ai_calls');
            $table->unsignedInteger('output_tokens')->default(0)->after('input_tokens');
            $table->unsignedSmallInteger('firecrawl_credits')->default(0)->after('output_tokens');
            $table->decimal('cost_usd', 10, 6)->nullable()->after('firecrawl_credits'); // null : prix du modèle inconnu
        });

        // Requêtes d'extraction en attente de l'API Batch (une par source dont la page a changé).
        Schema::create('agenda_ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('agenda_scans')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('agenda_sources')->cascadeOnDelete();
            $table->string('content_hash', 40);
            $table->json('categories');
            $table->longText('prompt');
            $table->string('status', 10)->default('pending'); // pending, submitted, done, failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_ai_requests');

        Schema::table('agenda_scans', function (Blueprint $table) {
            $table->dropColumn(['batch', 'ai_batch_id', 'sources_skipped', 'ai_calls', 'input_tokens', 'output_tokens', 'firecrawl_credits', 'cost_usd']);
        });

        Schema::table('agenda_sources', function (Blueprint $table) {
            $table->dropColumn(['scan_every_days', 'failures', 'last_input_tokens', 'last_output_tokens']);
        });
    }
};
