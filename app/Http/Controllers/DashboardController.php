<?php

namespace App\Http\Controllers;

use App\Modules\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Tuiles des seuls modules ouverts au compte connecté. */
    public function __invoke(Request $request, ModuleRegistry $modules): View
    {
        $user = $request->user();

        return view('dashboard', [
            'modules' => $modules->all()->filter(fn (Module $module) => $user->canAccessModule($module->slug())),
        ]);
    }
}
