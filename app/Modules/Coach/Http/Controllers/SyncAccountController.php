<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\SyncAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clé Dada Sync dans le profil : création (affichée une seule fois, elle remplace la précédente) et révocation.
 */
class SyncAccountController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $token = SyncAccount::issue($request->user());

        return redirect()->to(route('coach.profile.edit').'#dada-sync')->with('sync_token', $token);
    }

    public function destroy(Request $request): RedirectResponse
    {
        SyncAccount::ownedBy($request->user())->delete();

        return redirect()->to(route('coach.profile.edit').'#dada-sync')->with('status', 'Clé Dada Sync révoquée : l\'app ne peut plus envoyer de courses. Tes courses déjà reçues restent.');
    }
}
