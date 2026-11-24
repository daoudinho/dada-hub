<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilan de séance rédigé par le coach à la fin du débrief : verdict, points forts, points de vigilance, suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->json('review')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->dropColumn('review');
        });
    }
};
