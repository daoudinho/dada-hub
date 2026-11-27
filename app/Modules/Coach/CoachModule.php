<?php

namespace App\Modules\Coach;

use App\Models\User;
use App\Modules\Coach\Console\ImportStravaExportCommand;
use App\Modules\Coach\Console\ReanalyzeActivitiesCommand;
use App\Modules\Coach\Console\RecomputeRecordsCommand;
use App\Modules\Coach\Console\StravaWebhookCommand;
use App\Modules\Coach\Http\Controllers\StravaWebhookController;
use App\Modules\Coach\Http\Controllers\SyncApiController;
use App\Modules\Coach\Http\Middleware\AuthenticateSyncAccount;
use App\Modules\Coach\Http\Middleware\SyncConnectedAccounts;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\CycleEntry;
use App\Modules\Coach\Models\Equipment;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Modules\Coach\Models\Injury;
use App\Modules\Coach\Models\PastRace;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\Unavailability;
use App\Modules\Coach\Models\WeightEntry;
use App\Modules\Coach\Models\Workout;
use App\Modules\Coach\Policies\OwnerPolicy;
use App\Modules\Module;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

class CoachModule extends Module
{
    public function slug(): string
    {
        return 'coach';
    }

    public function name(): string
    {
        return 'Dada Coach';
    }

    public function features(): array
    {
        return ['running' => 'Running', 'fitness' => 'Fitness'];
    }

    public function description(): string
    {
        return 'Profil, courbe de poids et coach IA running et fitness : objectifs, programme, débriefs, carnet de salle.';
    }

    public function icon(): string
    {
        return '🏃';
    }

    /**
     * Middlewares du module, plus la synchronisation automatique des comptes reliés (Garmin, Google Health).
     *
     * @return array<int, string>
     */
    public function middleware(): array
    {
        return [...parent::middleware(), SyncConnectedAccounts::class];
    }

    public function consoleCommands(): array
    {
        return [StravaWebhookCommand::class, RecomputeRecordsCommand::class, ReanalyzeActivitiesCommand::class, ImportStravaExportCommand::class];
    }

    public function boot(): void
    {
        parent::boot();

        // Webhook Strava : appelé par les serveurs de Strava, donc hors connexion, session et jeton CSRF.
        if (! $this->app->routesAreCached()) {
            Route::prefix('coach/strava/webhook')->name('coach.strava.webhook.')->middleware('throttle:120,1')->group(function () {
                Route::get('/', [StravaWebhookController::class, 'verify'])->name('verify');
                Route::post('/', [StravaWebhookController::class, 'handle'])->name('handle');
            });

            // API de l'app Android Dada Sync : sans session ni jeton CSRF, la clé personnelle de l'athlète fait foi.
            Route::prefix('api/coach/sync')->name('coach.sync.api.')->middleware(['throttle:60,1', AuthenticateSyncAccount::class])->group(function () {
                Route::get('etat', [SyncApiController::class, 'status'])->name('status');
                Route::post('courses', [SyncApiController::class, 'runs'])->name('runs');
            });
        }

        // Chaque donnée du coach n'est visible et modifiable que par son propriétaire.
        foreach ([Conversation::class, CycleEntry::class, Equipment::class, Exercise::class, Goal::class, Injury::class, PastRace::class, Proposal::class,
            RunningActivity::class, RunningSession::class, Unavailability::class, WeightEntry::class, Workout::class] as $model) {
            Gate::policy($model, OwnerPolicy::class);
        }

        // Onglet Fitness et outils fitness du coach : seulement si le sous-module est ouvert à ce compte.
        Gate::define('coach-fitness', fn (User $user) => $user->hasModuleFeature('coach', 'fitness'));

        // Débriefs et retours d'annulation en attente, signalés dans la barre du module.
        // Bouton de synchronisation dans l'en-tête : Garmin (avec une clé), sinon Google Health (compte relié).
        View::composer('coach::partials.topbar', fn ($view) => $view->with([
            'awaitingCount' => Conversation::ownedBy(auth()->user())->whereIn('kind', ['debrief', 'cancellation'])->where('status', '!=', 'closed')->count(),
            'hasGarmin' => GarminAccount::ownedBy(auth()->user())->exists(),
            'hasGoogleHealth' => GoogleHealthAccount::ownedBy(auth()->user())->exists(),
        ]));
    }
}
