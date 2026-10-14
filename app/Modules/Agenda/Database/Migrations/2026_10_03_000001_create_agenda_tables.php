<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_events', function (Blueprint $t) {
            $t->id();
            $t->string('title', 150);
            $t->date('starts_on')->index();
            $t->date('ends_on')->nullable();
            $t->string('time', 5)->nullable();
            $t->string('place', 120)->nullable();
            $t->string('url', 500)->nullable();
            $t->string('category', 12); // fete, cine, musique, scene, expo, photo, manga, sport, barca
            $t->string('source', 10)->default('manual'); // manual, scan, seed
            $t->string('dedupe_key', 40)->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('agenda_scans', function (Blueprint $t) {
            $t->id();
            $t->json('filters');
            $t->string('status', 10)->default('pending'); // pending, running, done, failed
            $t->unsignedSmallInteger('found')->default(0);
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_scans');
        Schema::dropIfExists('agenda_events');
    }
};
