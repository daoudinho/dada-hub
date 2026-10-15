<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_events', function (Blueprint $table) {
            $table->boolean('favorite')->default(false)->after('category');
            $table->timestamp('hidden_at')->nullable()->after('favorite'); // retiré par l'utilisateur : gardé pour que le scan ne le recrée pas
        });
    }

    public function down(): void
    {
        Schema::table('agenda_events', function (Blueprint $table) {
            $table->dropColumn(['favorite', 'hidden_at']);
        });
    }
};
