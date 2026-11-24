<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil complet de l'athlète : historique, blessures, disponibilités, indisponibilités, cycle menstruel ;
 * objectifs principaux et intermédiaires ; cibles de séance chiffrées ; fatigue dans le ressenti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->unsignedSmallInteger('running_since')->nullable(); // année des débuts en course à pied
            $t->json('terrains')->nullable(); // flat, hills, track, treadmill, trail
            $t->text('other_activities')->nullable(); // autres sports, travail physique
            $t->json('availability')->nullable(); // {mon: {available, max_minutes, slot}, …}
            $t->string('long_run_day', 3)->nullable(); // mon … sun
            $t->boolean('cycle_tracking')->default(false);
            $t->boolean('hormonal_contraception')->default(false);
        });

        Schema::create('coach_past_races', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('raced_on');
            $t->string('name', 150)->nullable();
            $t->decimal('distance_km', 6, 2);
            $t->unsignedInteger('time_seconds');
            $t->timestamps();
        });

        Schema::create('coach_injuries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('zone', 80); // genou, tibia, tendon d'Achille…
            $t->text('description')->nullable();
            $t->string('period', 40)->nullable(); // « 2024 », « printemps dernier »
            $t->boolean('recurrent')->default(false);
            $t->boolean('current')->default(false); // encore gênante aujourd'hui
            $t->timestamps();
        });

        Schema::create('coach_unavailabilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('kind', 10)->default('none'); // none : pas de course ; reduced : semaine chargée, volume réduit
            $t->string('reason', 150)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'ends_on']);
        });

        Schema::create('coach_cycle_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('started_on'); // premier jour des règles
            $t->timestamps();
            $t->unique(['user_id', 'started_on']);
        });

        Schema::table('coach_goals', function (Blueprint $t) {
            $t->string('priority', 12)->default('main'); // main, intermediate
            $t->boolean('finish_only')->default(false); // objectif « finir », sans temps visé
        });

        Schema::table('coach_running_sessions', function (Blueprint $t) {
            $t->unsignedSmallInteger('pace_from')->nullable(); // allure cible, secondes par km (la plus rapide)
            $t->unsignedSmallInteger('pace_to')->nullable(); // (la plus lente)
            $t->unsignedSmallInteger('hr_from')->nullable(); // FC cible, bpm
            $t->unsignedSmallInteger('hr_to')->nullable();
        });

        Schema::table('coach_feedbacks', function (Blueprint $t) {
            $t->unsignedTinyInteger('fatigue')->nullable(); // 1 (en forme) à 5 (épuisé)
            $t->string('source', 10)->default('coach'); // form : saisie rapide ; coach : débrief
            $t->text('summary')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('coach_feedbacks', function (Blueprint $t) {
            $t->dropColumn(['fatigue', 'source']);
        });
        Schema::table('coach_running_sessions', function (Blueprint $t) {
            $t->dropColumn(['pace_from', 'pace_to', 'hr_from', 'hr_to']);
        });
        Schema::table('coach_goals', function (Blueprint $t) {
            $t->dropColumn(['priority', 'finish_only']);
        });
        foreach (['coach_cycle_entries', 'coach_unavailabilities', 'coach_injuries', 'coach_past_races'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->dropColumn(['running_since', 'terrains', 'other_activities', 'availability', 'long_run_day', 'cycle_tracking', 'hormonal_contraception']);
        });
    }
};
