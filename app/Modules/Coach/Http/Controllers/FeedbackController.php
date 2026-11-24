<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Http\Requests\QuickFeedbackRequest;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\QuickFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Ressenti rapide après une course (10 secondes), sans passer par le chat.
 */
class FeedbackController extends Controller
{
    public function store(QuickFeedbackRequest $request, RunningActivity $activity, QuickFeedback $quickFeedback): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $quickFeedback->record($activity, $request->validated());

        return back()->with('status', 'Ressenti noté ! Tu peux maintenant débriefer ta séance avec le coach.');
    }
}
