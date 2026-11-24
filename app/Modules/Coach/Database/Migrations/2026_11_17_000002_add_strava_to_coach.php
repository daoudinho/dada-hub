<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strava : compte relié (jetons chiffrés), chaussures, meilleures performances, analyse des courses importées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_strava_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('athlete_id')->unique(); // identifiant Strava de l'athlète (owner_id des webhooks)
            $t->string('athlete_name', 150)->nullable();
            $t->text('access_token'); // chiffré
            $t->text('refresh_token'); // chiffré
            $t->timestamp('expires_at');
            $t->string('scopes', 150)->nullable();
            $t->timestamp('backfilled_at')->nullable(); // historique importé
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('coach_gear', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('strava_id', 30);
            $t->string('name', 150);
            $t->unsignedInteger('distance_m')->default(0);
            $t->boolean('primary')->default(false);
            $t->boolean('retired')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'strava_id']);
        });

        Schema::create('coach_best_efforts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('activity_id')->constrained('coach_running_activities')->cascadeOnDelete();
            $t->string('name', 30); // « 5K », « 10K », « Half-Marathon »…
            $t->unsignedInteger('distance_m');
            $t->unsignedInteger('elapsed_seconds');
            $t->date('achieved_on');
            $t->timestamps();
            $t->index(['user_id', 'name']);
        });

        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->string('sport_type', 30)->nullable(); // Run, TrailRun, VirtualRun (tapis)
            $t->string('gear_id', 30)->nullable();
            $t->json('analysis')->nullable(); // dérive cardiaque, temps par zone, écart à la cible, régularité des fractions
        });
    }

    public function down(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->dropColumn(['sport_type', 'gear_id', 'analysis']);
        });
        Schema::dropIfExists('coach_best_efforts');
        Schema::dropIfExists('coach_gear');
        Schema::dropIfExists('coach_strava_accounts');
    }
};
