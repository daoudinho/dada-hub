<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records battus par une course : notés une fois à l'import, ils quittent l'analyse (recalculée à volonté) pour leur propre colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->json('new_records')->nullable();
        });

        DB::table('coach_running_activities')->where('analysis', 'like', '%new_records%')->orderBy('id')->each(function (object $row) {
            $analysis = json_decode((string) $row->analysis, true) ?: [];
            $records = $analysis['new_records'] ?? null;
            unset($analysis['new_records']);
            DB::table('coach_running_activities')->where('id', $row->id)->update([
                'new_records' => $records ? json_encode($records) : null,
                'analysis' => $analysis ? json_encode($analysis) : null,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('coach_running_activities')->whereNotNull('new_records')->orderBy('id')->each(function (object $row) {
            $analysis = json_decode((string) $row->analysis, true) ?: [];
            $analysis['new_records'] = json_decode($row->new_records, true);
            DB::table('coach_running_activities')->where('id', $row->id)->update(['analysis' => json_encode($analysis)]);
        });

        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->dropColumn('new_records');
        });
    }
};
