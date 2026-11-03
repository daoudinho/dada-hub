<?php

/*
 * Routes du module Recettes, montées par RecettesModule sous /recettes,
 * nommées "recettes.*" et protégées par le middleware "auth".
 */

use App\Modules\Recettes\Http\Controllers\CartController;
use App\Modules\Recettes\Http\Controllers\RecipeController;
use App\Modules\Recettes\Http\Controllers\RecipeImageController;
use App\Modules\Recettes\Http\Controllers\RecipeImportController;
use App\Modules\Recettes\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RecipeController::class, 'index'])->name('index');
Route::get('nouvelle', [RecipeController::class, 'create'])->name('create');
Route::post('/', [RecipeController::class, 'store'])->name('store');
Route::post('import', [RecipeImportController::class, 'store'])->middleware('throttle:10,10')->name('import.store');

Route::get('panier', [CartController::class, 'index'])->name('cart.index');
Route::post('panier', [CartController::class, 'store'])->name('cart.store');
Route::delete('panier', [CartController::class, 'clear'])->name('cart.clear');
Route::patch('panier/{recipe}', [CartController::class, 'update'])->name('cart.update');
Route::delete('panier/{recipe}', [CartController::class, 'destroy'])->name('cart.destroy');

Route::get('etiquettes', [TagController::class, 'index'])->name('tags.index');
Route::post('etiquettes', [TagController::class, 'store'])->name('tags.store');
Route::patch('etiquettes/{tag}', [TagController::class, 'update'])->name('tags.update');
Route::delete('etiquettes/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

Route::get('images/{recipe}', RecipeImageController::class)->name('images.show');

Route::get('{recipe}', [RecipeController::class, 'show'])->whereNumber('recipe')->name('show');
Route::get('{recipe}/modifier', [RecipeController::class, 'edit'])->whereNumber('recipe')->name('edit');
Route::put('{recipe}', [RecipeController::class, 'update'])->whereNumber('recipe')->name('update');
Route::patch('{recipe}/statut', [RecipeController::class, 'toggle'])->whereNumber('recipe')->name('toggle');
Route::delete('{recipe}', [RecipeController::class, 'destroy'])->whereNumber('recipe')->name('destroy');
Route::post('{recipe}/import', [RecipeImportController::class, 'retry'])->whereNumber('recipe')->middleware('throttle:10,10')->name('import.retry');
