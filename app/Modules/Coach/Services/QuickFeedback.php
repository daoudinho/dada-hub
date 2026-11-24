<?php

namespace App\Modules\Coach\Services;

use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\RunningActivity;

/**
 * Enregistre un ressenti rapide (formulaire) pour une course.
 */
class QuickFeedback
{
    /**
     * @param  array{rpe?: ?int, fatigue?: ?int, sleep?: ?int, pain_zone?: ?string, pain_intensity?: ?int}  $data
     */
    public function record(RunningActivity $activity, array $data): ?Feedback
    {
        $pains = filled($data['pain_zone'] ?? null) ? [['zone' => $data['pain_zone'], 'intensity' => (int) $data['pain_intensity']]] : null;
        if (! isset($data['rpe']) && ! isset($data['fatigue']) && ! isset($data['sleep']) && ! $pains) {
            return null;
        }

        $feedback = new Feedback([
            'source' => 'form',
            'activity_id' => $activity->id,
            'session_id' => $activity->session_id,
            'rpe' => $data['rpe'] ?? null,
            'fatigue' => $data['fatigue'] ?? null,
            'sleep' => $data['sleep'] ?? null,
            'pains' => $pains,
        ]);
        $feedback->user()->associate($activity->user_id)->save();

        if (isset($data['rpe'])) {
            $activity->update(['rpe' => $data['rpe']]);
        }

        return $feedback;
    }
}
