<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Jobs\ImportStravaExportJob;
use App\Modules\Coach\Services\Strava\StravaExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Envoi depuis le profil d'un export de compte Strava (.zip) : vérifié, gardé sur le disque, puis importé en tâche de fond.
 */
class StravaExportController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'export' => ['required', 'file', 'mimes:zip', 'max:12288'],
        ], [
            'export.required' => 'Choisis le fichier .zip de ton export Strava.',
            'export.mimes' => 'L\'export Strava doit être le fichier .zip téléchargé depuis Strava.',
            'export.max' => 'L\'export dépasse 12 Mo.',
        ]);

        try {
            $count = count((new StravaExport($request->file('export')->getRealPath()))->runRows());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['export' => 'Ce fichier n\'est pas un export de compte Strava (activities.csv introuvable ou illisible).']);
        }

        $user = $request->user();
        $path = $request->file('export')->store('coach/strava-exports', 'local');
        Cache::forever(ImportStravaExportJob::statusKey($user->id), ['state' => 'running', 'done' => 0, 'total' => $count, 'at' => now()->toIso8601String()]);
        ImportStravaExportJob::dispatch($user->id, $path);

        return redirect()->to(route('coach.profile.edit').'#strava')
            ->with('status', 'Import lancé : '.$count.' course'.($count > 1 ? 's' : '').' à reprendre de ton export Strava. Ça prend quelques minutes.');
    }
}
