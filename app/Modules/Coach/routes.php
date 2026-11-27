<?php

/*
 * Routes du module Coach, montées par CoachModule sous /coach, nommées "coach.*",
 * réservées aux comptes qui ont accès au module. Chaque donnée n'est visible que de son propriétaire (OwnerPolicy).
 */

use App\Modules\Coach\Http\Controllers\AthleteRecordController;
use App\Modules\Coach\Http\Controllers\ChatController;
use App\Modules\Coach\Http\Controllers\DashboardController;
use App\Modules\Coach\Http\Controllers\ExploitController;
use App\Modules\Coach\Http\Controllers\FeedbackController;
use App\Modules\Coach\Http\Controllers\Fitness\GymController;
use App\Modules\Coach\Http\Controllers\Fitness\WorkoutController;
use App\Modules\Coach\Http\Controllers\GarminController;
use App\Modules\Coach\Http\Controllers\GoalController;
use App\Modules\Coach\Http\Controllers\GoogleHealthController;
use App\Modules\Coach\Http\Controllers\ProfileController;
use App\Modules\Coach\Http\Controllers\ProposalController;
use App\Modules\Coach\Http\Controllers\Running\ActivityController;
use App\Modules\Coach\Http\Controllers\Running\ActivityListController;
use App\Modules\Coach\Http\Controllers\Running\AfterRunController;
use App\Modules\Coach\Http\Controllers\Running\CalendarController;
use App\Modules\Coach\Http\Controllers\Running\SessionController;
use App\Modules\Coach\Http\Controllers\StravaController;
use App\Modules\Coach\Http\Controllers\StravaExportController;
use App\Modules\Coach\Http\Controllers\SyncAccountController;
use App\Modules\Coach\Http\Controllers\WeightController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('index');

Route::get('profil', [ProfileController::class, 'edit'])->name('profile.edit');
Route::put('profil', [ProfileController::class, 'update'])->name('profile.update');

Route::post('profil/courses', [AthleteRecordController::class, 'storeRace'])->name('races.store');
Route::delete('profil/courses/{race}', [AthleteRecordController::class, 'destroyRace'])->name('races.destroy');
Route::post('profil/blessures', [AthleteRecordController::class, 'storeInjury'])->name('injuries.store');
Route::patch('profil/blessures/{injury}', [AthleteRecordController::class, 'toggleInjury'])->name('injuries.toggle');
Route::delete('profil/blessures/{injury}', [AthleteRecordController::class, 'destroyInjury'])->name('injuries.destroy');
Route::post('profil/indisponibilites', [AthleteRecordController::class, 'storeUnavailability'])->name('unavailabilities.store');
Route::delete('profil/indisponibilites/{unavailability}', [AthleteRecordController::class, 'destroyUnavailability'])->name('unavailabilities.destroy');
Route::post('cycle', [AthleteRecordController::class, 'storeCycle'])->name('cycle.store');
Route::delete('cycle/{entry}', [AthleteRecordController::class, 'destroyCycle'])->name('cycle.destroy');

Route::put('garmin', [GarminController::class, 'store'])->name('garmin.store');
Route::delete('garmin', [GarminController::class, 'destroy'])->name('garmin.destroy');
Route::post('garmin/synchroniser', [GarminController::class, 'sync'])->middleware('throttle:6,1')->name('garmin.sync');
Route::get('garmin/etat', [GarminController::class, 'status'])->name('garmin.status');

Route::get('strava/connexion', [StravaController::class, 'connect'])->name('strava.connect');
Route::get('strava/retour', [StravaController::class, 'callback'])->name('strava.callback');
Route::post('strava/synchroniser', [StravaController::class, 'sync'])->middleware('throttle:6,1')->name('strava.sync');
Route::delete('strava', [StravaController::class, 'disconnect'])->name('strava.disconnect');
Route::post('strava/export', [StravaExportController::class, 'store'])->middleware('throttle:3,1')->name('strava.export');
// Le webhook (public, sans session) est déclaré dans CoachModule.

Route::get('google-health/connexion', [GoogleHealthController::class, 'connect'])->name('google-health.connect');
Route::get('google-health/retour', [GoogleHealthController::class, 'callback'])->name('google-health.callback');
Route::post('google-health/synchroniser', [GoogleHealthController::class, 'sync'])->middleware('throttle:6,1')->name('google-health.sync');
Route::delete('google-health', [GoogleHealthController::class, 'disconnect'])->name('google-health.disconnect');

Route::post('dada-sync', [SyncAccountController::class, 'store'])->name('sync.store');
Route::delete('dada-sync', [SyncAccountController::class, 'destroy'])->name('sync.destroy');
// L'API de l'app (clé personnelle, sans session) est déclarée dans CoachModule.

Route::post('objectifs', [GoalController::class, 'store'])->name('goals.store');
Route::patch('objectifs/{goal}', [GoalController::class, 'update'])->name('goals.update');

Route::post('poids', [WeightController::class, 'store'])->name('weights.store');
Route::delete('poids/{weight}', [WeightController::class, 'destroy'])->name('weights.destroy');

Route::get('running/calendrier', CalendarController::class)->name('running.calendar');
Route::get('running/courses', [ActivityListController::class, 'index'])->name('running.activities.index');
Route::get('running/courses/nouvelle', [ActivityController::class, 'create'])->name('running.activities.create');
Route::get('running/courses/{activity}', [ActivityListController::class, 'show'])->whereNumber('activity')->name('running.activities.show');
Route::post('running/courses', [ActivityController::class, 'store'])->name('running.activities.store');
Route::post('running/courses/{activity}/ressenti', [FeedbackController::class, 'store'])->name('running.feedback.store');
Route::post('running/courses/{activity}/debrief', [AfterRunController::class, 'debrief'])->name('running.activities.debrief');
Route::post('running/courses/{activity}/seance', [AfterRunController::class, 'confirmSession'])->name('running.activities.session.confirm');
Route::delete('running/courses/{activity}/seance', [AfterRunController::class, 'dismissSession'])->name('running.activities.session.dismiss');
Route::post('running/seances/{session}/annuler', [SessionController::class, 'cancel'])->name('running.sessions.cancel');

// Fitness : réservé aux comptes à qui le sous-module est ouvert.
Route::prefix('fitness')->name('fitness.')->middleware('can:coach-fitness')->group(function () {
    Route::get('/', [WorkoutController::class, 'index'])->name('index');
    Route::get('seances/nouvelle', [WorkoutController::class, 'create'])->name('workouts.create');
    Route::post('seances', [WorkoutController::class, 'store'])->name('workouts.store');
    Route::get('seances/{workout}', [WorkoutController::class, 'show'])->whereNumber('workout')->name('workouts.show');
    Route::delete('seances/{workout}', [WorkoutController::class, 'destroy'])->name('workouts.destroy');

    Route::get('salle', [GymController::class, 'index'])->name('gym');
    Route::post('salle/machines', [GymController::class, 'storeEquipment'])->name('equipment.store');
    Route::patch('salle/machines/{equipment}', [GymController::class, 'updateEquipment'])->name('equipment.update');
    Route::delete('salle/machines/{equipment}', [GymController::class, 'destroyEquipment'])->name('equipment.destroy');
    Route::post('salle/exercices', [GymController::class, 'storeExercise'])->name('exercises.store');
    Route::put('salle/exercices/{exercise}', [GymController::class, 'updateExercise'])->name('exercises.update');
    Route::delete('salle/exercices/{exercise}', [GymController::class, 'destroyExercise'])->name('exercises.destroy');
});

Route::get('exploits', ExploitController::class)->name('exploits');

Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
Route::post('chat', [ChatController::class, 'store'])->name('chat.store');
Route::get('chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
Route::post('chat/{conversation}/messages', [ChatController::class, 'reply'])->middleware('throttle:20,1')->name('chat.reply');

Route::post('propositions/{proposal}/accepter', [ProposalController::class, 'accept'])->name('proposals.accept');
Route::post('propositions/{proposal}/refuser', [ProposalController::class, 'refuse'])->name('proposals.refuse');
Route::post('propositions/{proposal}/exercices/{index}', [ProposalController::class, 'answerExercise'])->whereNumber('index')->name('proposals.exercise');
