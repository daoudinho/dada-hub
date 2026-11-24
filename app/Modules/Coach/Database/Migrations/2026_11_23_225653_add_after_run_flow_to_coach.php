<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Après une course : séance du programme proposée quand la course n'est pas tombée le jour prévu,
 * et nombre de courses de la dernière synchronisation Garmin (pour savoir quand elle est terminée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->foreignId('suggested_session_id')->nullable()->constrained('coach_running_sessions')->nullOnDelete();
        });

        Schema::table('coach_garmin_accounts', function (Blueprint $t) {
            $t->unsignedInteger('last_sync_runs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->dropConstrainedForeignId('suggested_session_id');
        });

        Schema::table('coach_garmin_accounts', function (Blueprint $t) {
            $t->dropColumn('last_sync_runs');
        });
    }
};
