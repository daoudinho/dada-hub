<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sous-modules ouverts à chaque compte (par exemple Running et Fitness dans le module Coach).
 * Les comptes qui ont déjà le Coach, et les administrateurs, reçoivent Running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_feature_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('module', 40);
            $t->string('feature', 40);
            $t->timestamps();
            $t->primary(['user_id', 'module', 'feature']);
        });

        $now = now();
        $userIds = DB::table('module_user')->where('module', 'coach')->pluck('user_id')
            ->merge(DB::table('users')->where('role', 'admin')->pluck('id'))
            ->unique();
        foreach ($userIds as $userId) {
            DB::table('module_feature_user')->insert(['user_id' => $userId, 'module' => 'coach', 'feature' => 'running', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_feature_user');
    }
};
