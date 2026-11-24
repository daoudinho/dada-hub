<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles (admin, member) et accès de chaque compte aux modules du hub.
 *
 * Les comptes existants gardent l'accès aux modules déjà ouverts (agenda, recettes) ;
 * le compte HUB_ADMIN_EMAIL, ou à défaut le plus ancien, devient administrateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('member')->after('email');
        });

        Schema::create('module_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('module', 40); // slug du module
            $t->timestamps();
            $t->primary(['user_id', 'module']);
        });

        $userIds = DB::table('users')->pluck('id');
        $now = now();
        foreach ($userIds as $userId) {
            foreach (['agenda', 'recettes'] as $module) {
                DB::table('module_user')->insert(['user_id' => $userId, 'module' => $module, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $adminEmail = config('hub.admin.email');
        $adminId = ($adminEmail ? DB::table('users')->where('email', $adminEmail)->value('id') : null)
            ?? DB::table('users')->orderBy('id')->value('id');
        if ($adminId) {
            DB::table('users')->where('id', $adminId)->update(['role' => 'admin']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_user');

        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('role');
        });
    }
};
