<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil : les terrains disparaissent ; une info complémentaire libre accompagne les disponibilités.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->dropColumn('terrains');
            $t->text('availability_notes')->nullable(); // contexte utile au programme : horaires, garde d'enfants, déplacements…
        });
    }

    public function down(): void
    {
        Schema::table('coach_profiles', function (Blueprint $t) {
            $t->dropColumn('availability_notes');
            $t->json('terrains')->nullable();
        });
    }
};
