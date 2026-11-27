<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dada Sync : l'app Android de l'athlète lit Samsung Health sur son téléphone et envoie ses courses au hub,
 * avec une clé personnelle dont seule l'empreinte est gardée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_sync_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique(); // SHA-256 de la clé
            $t->string('token_hint', 8); // 4 derniers caractères, pour la reconnaître
            $t->string('app_version', 30)->nullable();
            $t->timestamp('last_seen_at')->nullable(); // dernier appel de l'app
            $t->timestamp('last_synced_at')->nullable(); // dernier envoi de courses
            $t->unsignedInteger('last_sync_runs')->nullable();
            $t->timestamps();
        });

        Schema::table('coach_running_activities', function (Blueprint $t) {
            // Course reçue (ou complétée) par Dada Sync : les autres connecteurs ne la réécrivent plus.
            $t->timestamp('device_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coach_running_activities', function (Blueprint $t) {
            $t->dropColumn('device_synced_at');
        });
        Schema::dropIfExists('coach_sync_accounts');
    }
};
