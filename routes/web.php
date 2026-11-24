<?php

use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/acces', [AccessController::class, 'index'])->name('access.index');
    Route::put('/acces/{user}', [AccessController::class, 'update'])->name('access.update');
});

require __DIR__.'/auth.php';

// Les routes des sous-projets sont chargées par leurs modules (voir config/hub.php).
