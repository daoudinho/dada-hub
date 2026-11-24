<?php

namespace App\Modules\Coach\Services;

use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Models\RunningPlan;
use App\Modules\Coach\Models\RunningSession;
use Illuminate\Support\Facades\DB;

/**
 * Applique au calendrier une proposition du coach acceptée par l'athlète.
 */
class PlanApplier
{
    public function accept(Proposal $proposal): void
    {
        DB::transaction(function () use ($proposal) {
            $proposal->kind === 'plan' ? $this->applyPlan($proposal) : $this->applyAdjustment($proposal);
            $proposal->update(['status' => 'accepted']);
        });
    }

    /** Nouveau programme : l'ancien est archivé, ses séances encore à venir sont remplacées. */
    private function applyPlan(Proposal $proposal): void
    {
        $user = $proposal->user;
        $payload = $proposal->payload;

        RunningPlan::ownedBy($user)->where('status', 'active')->update(['status' => 'archived']);
        RunningSession::ownedBy($user)->where('status', 'planned')->where('scheduled_on', '>=', today())->delete();

        $plan = new RunningPlan([
            'goal_id' => Goal::ownedBy($user)->where('status', 'active')->where('priority', 'main')->latest()->value('id'),
            'feasibility' => $payload['feasibility'] ?? null,
            'summary' => $payload['summary'],
            'rationale' => $payload['rationale'] ?? null,
            'status' => 'active',
        ]);
        $plan->user()->associate($user)->save();

        foreach ($payload['sessions'] as $session) {
            $this->createSession($proposal, $session, $plan->id);
        }
    }

    private function applyAdjustment(Proposal $proposal): void
    {
        $user = $proposal->user;
        $payload = $proposal->payload;
        $planId = RunningPlan::ownedBy($user)->where('status', 'active')->latest()->value('id');

        // Seules les séances encore prévues bougent (elles ont pu être faites depuis la proposition).
        RunningSession::ownedBy($user)->where('status', 'planned')->whereIn('id', $payload['remove'] ?? [])->delete();

        foreach ($payload['update'] ?? [] as $changes) {
            $session = RunningSession::ownedBy($user)->where('status', 'planned')->find($changes['id']);
            $session?->update($this->sessionAttributes($changes));
        }

        foreach ($payload['add'] ?? [] as $session) {
            $this->createSession($proposal, $session, $planId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createSession(Proposal $proposal, array $data, ?int $planId): void
    {
        $session = new RunningSession($this->sessionAttributes($data) + ['plan_id' => $planId, 'status' => 'planned']);
        $session->user()->associate($proposal->user)->save();
    }

    /**
     * Champs d'une séance proposée, au nom des colonnes (seuls ceux présents).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sessionAttributes(array $data): array
    {
        $attributes = collect($data)->only(['type', 'title', 'description', 'duration_minutes', 'distance_km', 'pace_from', 'pace_to', 'hr_from', 'hr_to']);
        if (isset($data['date'])) {
            $attributes['scheduled_on'] = $data['date'];
        }

        return $attributes->all();
    }
}
