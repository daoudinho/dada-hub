<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garmin : clé d'API personnelle de chaque athlète (Garmin Connect → Personal API Key), chiffrée en base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_garmin_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('api_key'); // chiffrée, jamais réaffichée
            $t->date('key_expires_on')->nullable(); // échéance choisie à la création de la clé (30 jours à 1 an)
            $t->text('access_token')->nullable(); // chiffré : jeton du jour obtenu avec la clé (24 h)
            $t->timestamp('token_expires_at')->nullable();
            $t->timestamp('backfilled_at')->nullable(); // historique de 2 ans importé
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_garmin_accounts');
    }
};
