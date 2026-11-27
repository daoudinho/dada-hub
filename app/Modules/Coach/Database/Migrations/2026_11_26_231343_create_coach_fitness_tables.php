<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitness : la salle de l'athlète (machines disponibles ou absentes, exercices), ses séances et leurs séries,
 * et ce que le coach doit savoir pour programmer (objectif, niveau, fréquence, durée d'une séance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->string('fitness_goal', 20)->nullable(); // voir Profile::FITNESS_GOALS
            $t->string('fitness_level', 15)->nullable(); // voir Profile::FITNESS_LEVELS
            $t->unsignedTinyInteger('fitness_sessions_per_week')->nullable();
            $t->unsignedSmallInteger('fitness_session_minutes')->nullable();
        });

        Schema::create('coach_equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100); // « Presse à cuisses », « Élastique »
            $t->string('status', 10)->default('available'); // available, missing (pas dans la salle)
            $t->decimal('load_step_kg', 5, 2)->nullable(); // plus petit écart de charge (5 kg sur une machine)
            $t->timestamps();
            $t->unique(['user_id', 'name']);
        });

        Schema::create('coach_exercises', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('equipment_id')->nullable()->constrained('coach_equipment')->nullOnDelete(); // null : poids du corps
            $t->string('name', 100);
            $t->string('measure', 10)->default('load_reps'); // voir Exercise::MEASURES
            $t->json('muscles'); // muscles principaux, clés de Exercise::MUSCLES
            $t->json('secondary_muscles')->nullable();
            $t->boolean('unilateral')->default(false); // séries faites de chaque côté
            $t->text('notes')->nullable(); // réglages, consignes d'exécution
            $t->timestamps();
            $t->unique(['user_id', 'name']);
        });

        Schema::create('coach_workouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('performed_on');
            $t->unsignedSmallInteger('duration_minutes')->nullable();
            $t->unsignedTinyInteger('rpe')->nullable(); // effort ressenti, 1 à 10
            $t->text('notes')->nullable();
            $t->string('source', 10)->default('form'); // form, chat
            $t->unsignedInteger('total_load_kg')->default(0); // poids total soulevé (répétitions × charge)
            $t->timestamps();
            $t->index(['user_id', 'performed_on']);
        });

        Schema::create('coach_workout_sets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workout_id')->constrained('coach_workouts')->cascadeOnDelete();
            $t->foreignId('exercise_id')->constrained('coach_exercises')->restrictOnDelete(); // un exercice déjà pratiqué reste dans l'historique
            $t->unsignedSmallInteger('position');
            $t->unsignedSmallInteger('reps')->nullable();
            $t->decimal('load_kg', 6, 2)->nullable();
            $t->unsignedSmallInteger('duration_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_workout_sets');
        Schema::dropIfExists('coach_workouts');
        Schema::dropIfExists('coach_exercises');
        Schema::dropIfExists('coach_equipment');

        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->dropColumn(['fitness_goal', 'fitness_level', 'fitness_sessions_per_week', 'fitness_session_minutes']);
        });
    }
};
