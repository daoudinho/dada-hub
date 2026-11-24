<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre de courses à importer lors de la première synchronisation Garmin, pour afficher l'avancement de l'import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_garmin_accounts', function (Blueprint $t) {
            $t->unsignedInteger('history_total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coach_garmin_accounts', function (Blueprint $t) {
            $t->dropColumn('history_total');
        });
    }
};
