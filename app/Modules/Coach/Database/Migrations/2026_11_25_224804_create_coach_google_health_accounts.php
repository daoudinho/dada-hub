<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Health : compte Google relié par l'athlète (OAuth), jetons chiffrés en base.
 * Les courses de la montre (Samsung Health → Health Connect → Google Health) y sont lues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_google_health_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('health_user_id', 50)->unique(); // identifiant Google Health de l'athlète
            $t->text('access_token')->nullable(); // chiffré
            $t->text('refresh_token')->nullable(); // chiffré ; null quand Google l'a révoqué ou expiré (il faut se reconnecter)
            $t->timestamp('expires_at')->nullable();
            $t->string('scopes', 500)->nullable();
            $t->timestamp('backfilled_at')->nullable(); // historique importé
            $t->timestamp('last_synced_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_google_health_accounts');
    }
};
