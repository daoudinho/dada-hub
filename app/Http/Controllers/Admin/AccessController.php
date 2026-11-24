<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administration : rôle de chaque compte et modules qui lui sont ouverts.
 */
class AccessController extends Controller
{
    public function index(ModuleRegistry $modules): View
    {
        return view('admin.access', [
            'users' => User::orderBy('name')->get(),
            'modules' => $modules->all(),
        ]);
    }

    public function update(Request $request, User $user, ModuleRegistry $modules): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MEMBER])],
            'modules' => ['array'],
            'modules.*' => ['string', Rule::in($modules->all()->keys()->all())],
            'features' => ['array'],
            'features.*' => ['array'],
        ]);

        // Sous-modules : seulement ceux que le module déclare.
        $features = $modules->all()
            ->mapWithKeys(fn ($module, string $slug) => [$slug => array_values(array_intersect(
                (array) ($data['features'][$slug] ?? []),
                array_keys($module->features()),
            ))])
            ->filter()
            ->all();

        if ($user->is($request->user()) && $data['role'] !== User::ROLE_ADMIN) {
            return back()->withErrors(['role' => 'Vous ne pouvez pas retirer votre propre rôle d\'administrateur.']);
        }

        $user->forceFill(['role' => $data['role']])->save();
        $user->syncModules($data['modules'] ?? []);
        $user->syncModuleFeatures($features);

        return back()->with('status', 'Accès de '.$user->name.' mis à jour.');
    }
}
