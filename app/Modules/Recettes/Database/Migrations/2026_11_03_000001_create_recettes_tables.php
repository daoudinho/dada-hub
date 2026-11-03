<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('servings')->default(2);
            $t->unsignedSmallInteger('total_minutes')->nullable();
            $t->json('steps');
            $t->string('source_url', 500)->nullable();
            $t->boolean('enabled')->default(true)->index();
            $t->string('image_hash', 40)->nullable(); // empreinte de l'image (cache du navigateur), null : pas d'image
            $t->string('import_status', 10)->nullable(); // pending, failed ; null : rien en cours
            $t->text('import_error')->nullable();
            $t->timestamps();
        });

        // Image à part : la liste des recettes ne charge pas les données binaires.
        Schema::create('recipe_images', function (Blueprint $t) {
            $t->foreignId('recipe_id')->primary()->constrained('recipes')->cascadeOnDelete();
            $t->string('mime', 30);
            $t->longText('data'); // base64 : portable entre SQLite et PostgreSQL
            $t->timestamps();
        });

        Schema::create('recipe_ingredients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $t->unsignedSmallInteger('position')->default(0);
            $t->decimal('quantity', 10, 3)->nullable(); // null : « sel, poivre », « quelques feuilles »
            $t->string('unit', 30)->nullable();
            $t->string('name', 120);
            $t->string('note', 120)->nullable(); // précision : « bien mûres », « en dés »
            $t->string('emoji', 16)->nullable();
        });

        Schema::create('recipe_tags', function (Blueprint $t) {
            $t->id();
            $t->string('name', 40)->unique();
            $t->string('emoji', 16)->nullable();
            $t->timestamps();
        });

        Schema::create('recipe_tag', function (Blueprint $t) {
            $t->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained('recipe_tags')->cascadeOnDelete();
            $t->primary(['recipe_id', 'tag_id']);
        });

        Schema::create('recipe_cart_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->unique()->constrained('recipes')->cascadeOnDelete();
            $t->unsignedSmallInteger('servings');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_cart_items');
        Schema::dropIfExists('recipe_tag');
        Schema::dropIfExists('recipe_tags');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipe_images');
        Schema::dropIfExists('recipes');
    }
};
