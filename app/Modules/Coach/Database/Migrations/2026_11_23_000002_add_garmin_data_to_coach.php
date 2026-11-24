<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Données Garmin : résumé brut, dynamique de course et tracé GPS des courses ; zones cardiaques et VO2 max du profil ;
 * suivi quotidien de récupération (FC de repos, Body Battery, stress, sommeil, HRV).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->json('raw_summary')->nullable(); // résumé complet renvoyé par la source, pour un usage futur
            $t->json('dynamics')->nullable(); // puissance, foulée, oscillation, contact au sol, effet d'entraînement…
            $t->json('polyline')->nullable(); // tracé GPS simplifié : [[lat, lng], …]
        });

        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->unsignedSmallInteger('threshold_hr')->nullable(); // FC au seuil lactique
            $t->json('hr_zone_floors')->nullable(); // planchers des zones Z1 à Z5 (Garmin)
            $t->decimal('vo2max', 4, 1)->nullable(); // estimation Garmin
            $t->date('vo2max_on')->nullable();
        });

        Schema::table('coach_garmin_accounts', function (Blueprint $t) {
            $t->text('last_error')->nullable(); // dernière erreur de synchronisation, affichée dans le profil
        });

        Schema::create('coach_daily_wellness', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedSmallInteger('resting_hr')->nullable();
            $t->unsignedTinyInteger('body_battery_min')->nullable();
            $t->unsignedTinyInteger('body_battery_max')->nullable();
            $t->unsignedTinyInteger('body_battery_charged')->nullable();
            $t->unsignedTinyInteger('body_battery_drained')->nullable();
            $t->unsignedTinyInteger('stress_avg')->nullable();
            $t->unsignedInteger('sleep_seconds')->nullable();
            $t->unsignedInteger('deep_sleep_seconds')->nullable();
            $t->unsignedTinyInteger('sleep_score')->nullable();
            $t->unsignedSmallInteger('hrv_last_night')->nullable();
            $t->unsignedSmallInteger('hrv_weekly_avg')->nullable();
            $t->string('hrv_status', 20)->nullable(); // BALANCED, UNBALANCED, LOW…
            $t->timestamps();
            $t->unique(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_daily_wellness');
        Schema::table('coach_garmin_accounts', function (Blueprint $t) {
            $t->dropColumn('last_error');
        });
        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->dropColumn(['threshold_hr', 'hr_zone_floors', 'vo2max', 'vo2max_on']);
        });
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->dropColumn(['raw_summary', 'dynamics', 'polyline']);
        });
    }
};
