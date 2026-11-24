<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Coach : toutes les données appartiennent à un compte (user_id) et ne sont visibles que de lui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('sex', 10)->nullable(); // female, male, other
            $t->date('birth_date')->nullable();
            $t->unsignedSmallInteger('height_cm')->nullable();
            $t->unsignedSmallInteger('resting_hr')->nullable();
            $t->unsignedSmallInteger('max_hr')->nullable();
            $t->timestamps();
        });

        Schema::create('coach_weight_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('measured_on');
            $t->decimal('weight_kg', 5, 2);
            $t->timestamps();
            $t->unique(['user_id', 'measured_on']);
        });

        Schema::create('coach_goals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('sport', 20)->default('running');
            $t->string('title', 150);
            $t->string('event_name', 150)->nullable(); // « semi-marathon de Paris »
            $t->date('event_date')->nullable();
            $t->decimal('distance_km', 6, 2)->nullable();
            $t->unsignedInteger('target_seconds')->nullable(); // temps visé
            $t->text('notes')->nullable(); // motivation, objectif ambitieux, critères de réussite
            $t->string('status', 12)->default('active')->index(); // active, achieved, abandoned
            $t->timestamps();
        });

        Schema::create('coach_running_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goal_id')->nullable()->constrained('coach_goals')->nullOnDelete();
            $t->string('feasibility', 20)->nullable(); // realistic, ambitious, unrealistic
            $t->text('summary');
            $t->text('rationale')->nullable();
            $t->string('status', 12)->default('active')->index(); // active, archived
            $t->timestamps();
        });

        Schema::create('coach_running_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained('coach_running_plans')->nullOnDelete();
            $t->date('scheduled_on');
            $t->string('type', 20); // voir RunningSession::TYPES
            $t->string('title', 150);
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('duration_minutes')->nullable();
            $t->decimal('distance_km', 6, 2)->nullable();
            $t->string('target_pace', 40)->nullable(); // « 5:30-5:45 /km »
            $t->string('target_hr', 40)->nullable(); // « Z2 (135-150) »
            $t->string('status', 12)->default('planned'); // planned, done, cancelled
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'scheduled_on']);
        });

        Schema::create('coach_running_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('session_id')->nullable()->constrained('coach_running_sessions')->nullOnDelete();
            $t->string('source', 10)->default('manual'); // manual, strava
            $t->string('external_id', 40)->nullable(); // identifiant Strava
            $t->string('name', 150)->nullable();
            $t->dateTime('started_at');
            $t->unsignedInteger('distance_m');
            $t->unsignedInteger('moving_seconds');
            $t->unsignedInteger('elapsed_seconds')->nullable();
            $t->unsignedSmallInteger('avg_hr')->nullable();
            $t->unsignedSmallInteger('max_hr')->nullable();
            $t->unsignedSmallInteger('avg_cadence')->nullable();
            $t->unsignedSmallInteger('elevation_gain_m')->nullable();
            $t->json('laps')->nullable(); // tours / splits (Strava)
            $t->json('streams')->nullable(); // flux seconde par seconde (Strava)
            $t->unsignedTinyInteger('rpe')->nullable(); // effort ressenti, 1 à 10
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'started_at']);
            $t->unique(['user_id', 'source', 'external_id']);
        });

        Schema::create('coach_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 15); // onboarding, debrief, cancellation, chat
            $t->foreignId('session_id')->nullable()->constrained('coach_running_sessions')->nullOnDelete();
            $t->foreignId('activity_id')->nullable()->constrained('coach_running_activities')->nullOnDelete();
            $t->string('title', 150);
            $t->string('status', 10)->default('pending')->index(); // pending (pas encore ouverte), open, closed
            $t->string('context_hash', 64)->nullable(); // empreinte du dernier contexte envoyé au coach
            $t->timestamps();
        });

        Schema::create('coach_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('coach_conversations')->cascadeOnDelete();
            $t->string('role', 10); // user, assistant, system (format de l'API Claude)
            $t->json('content'); // blocs de contenu, renvoyés tels quels à l'API
            $t->text('display_text')->nullable(); // texte affiché dans le chat ; null : message technique masqué
            $t->string('model', 60)->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->timestamps();
        });

        Schema::create('coach_proposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained('coach_conversations')->cascadeOnDelete();
            $t->string('kind', 12); // plan, adjustment
            $t->text('reason');
            $t->json('payload');
            $t->string('status', 10)->default('pending'); // pending, accepted, refused
            $t->timestamps();
        });

        Schema::create('coach_feedbacks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->nullable()->constrained('coach_conversations')->nullOnDelete();
            $t->foreignId('session_id')->nullable()->constrained('coach_running_sessions')->nullOnDelete();
            $t->foreignId('activity_id')->nullable()->constrained('coach_running_activities')->nullOnDelete();
            $t->unsignedTinyInteger('rpe')->nullable();
            $t->unsignedTinyInteger('mood')->nullable(); // 1 à 5
            $t->unsignedTinyInteger('sleep')->nullable(); // 1 à 5
            $t->json('pains')->nullable(); // [{zone, intensity 0-10, note}]
            $t->text('summary');
            $t->timestamps();
        });

        Schema::create('coach_memories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('content');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['coach_memories', 'coach_feedbacks', 'coach_proposals', 'coach_messages', 'coach_conversations',
            'coach_running_activities', 'coach_running_sessions', 'coach_running_plans', 'coach_goals',
            'coach_weight_entries', 'coach_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
