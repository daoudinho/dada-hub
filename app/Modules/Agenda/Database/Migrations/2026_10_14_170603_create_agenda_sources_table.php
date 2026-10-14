<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('url', 500)->unique();
            $table->string('category', 12)->nullable(); // null = source généraliste (toutes catégories)
            $table->boolean('active')->default(true);
            $table->string('content_hash', 40)->nullable(); // empreinte de la dernière page analysée
            $table->string('last_status', 10)->nullable(); // ok, unchanged, failed
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('last_found')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
        });

        Schema::table('agenda_events', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('source')->constrained('agenda_sources')->nullOnDelete();
        });

        Schema::table('agenda_scans', function (Blueprint $table) {
            $table->string('mode', 10)->default('web')->after('filters'); // sources, web
            $table->boolean('force')->default(false)->after('mode');
            $table->unsignedSmallInteger('sources_total')->default(0)->after('found');
            $table->unsignedSmallInteger('sources_done')->default(0)->after('sources_total');
        });
    }

    public function down(): void
    {
        Schema::table('agenda_scans', function (Blueprint $table) {
            $table->dropColumn(['mode', 'force', 'sources_total', 'sources_done']);
        });

        Schema::table('agenda_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });

        Schema::dropIfExists('agenda_sources');
    }
};
