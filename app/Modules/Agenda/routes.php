<?php

/*
 * Routes du module Agenda, montées par AgendaModule sous /agenda,
 * nommées "agenda.*" et protégées par le middleware "auth".
 */

use App\Modules\Agenda\Http\Controllers\AgendaController;
use App\Modules\Agenda\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AgendaController::class, 'index'])->name('index');
Route::get('events', [AgendaController::class, 'events'])->name('events.index');
Route::post('events', [AgendaController::class, 'store'])->name('events.store');
Route::delete('events/{event}', [AgendaController::class, 'destroy'])->name('events.destroy');
Route::post('scan', [AgendaController::class, 'scan'])->middleware('throttle:4,10')->name('scan.store');
Route::get('scan/{scan}', [AgendaController::class, 'scanStatus'])->name('scan.show');

Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
Route::patch('sources/{source}', [SourceController::class, 'update'])->name('sources.update');
Route::delete('sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
