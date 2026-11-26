<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source d'une course : 10 caractères ne suffisent pas pour « google_health ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->string('source', 20)->default('manual')->change(); // manual, strava, garmin, google_health
        });

        // Les courses Google Health n'ont pas pu être enregistrées avant : l'historique sera réimporté à la prochaine synchro.
        DB::table('coach_google_health_accounts')
            ->whereNotExists(fn ($query) => $query->from('coach_running_activities')
                ->whereColumn('coach_running_activities.user_id', 'coach_google_health_accounts.user_id')
                ->where('coach_running_activities.source', 'google_health'))
            ->update(['backfilled_at' => null, 'last_synced_at' => null]);
    }

    public function down(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->string('source', 10)->default('manual')->change();
        });
    }
};
