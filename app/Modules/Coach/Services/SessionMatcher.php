<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rattache une course à la séance du programme qu'elle remplace. À l'import, seule la séance du jour est prise d'office ;
 * une séance non faite des jours précédents est seulement proposée, et l'athlète confirme.
 */
class SessionMatcher
{
    /** Jours avant la course où une séance non faite est proposée. */
    public const LOOKBACK_DAYS = 2;

    public function __construct(private ActivityAnalysis $analysis, private ReferencePaces $referencePaces) {}

    /**
     * Séance d'une course importée : celle déjà liée, sinon la séance prévue le jour même. Sans séance ce jour-là, une séance
     * non faite des jours précédents est proposée (nouvelle course seulement, pas l'historique).
     *
     * @return array{0: ?RunningSession, 1: ?int} séance, identifiant de la séance proposée
     */
    public static function forImport(User $user, Carbon $startedAt, ?RunningActivity $existing, bool $notify): array
    {
        $session = $existing?->session ?? RunningSession::ownedBy($user)->where('status', 'planned')
            ->whereDate('scheduled_on', $startedAt->toDateString())->orderBy('id')->first();

        return [$session, match (true) {
            $session !== null => null,
            $existing !== null => $existing->suggested_session_id,
            $notify => self::missedBefore($user, $startedAt)?->id,
            default => null,
        }];
    }

    /** Séance prévue et non faite dans les jours qui précèdent la course, la plus récente d'abord. */
    public static function missedBefore(User $user, Carbon $day): ?RunningSession
    {
        return RunningSession::ownedBy($user)->where('status', 'planned')->whereDoesntHave('activity')
            ->whereDate('scheduled_on', '>=', $day->copy()->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->whereDate('scheduled_on', '<=', $day->copy()->subDay()->toDateString())
            ->orderByDesc('scheduled_on')->orderBy('id')->first();
    }

    /** L'athlète confirme : la course devient cette séance (faite), et l'analyse est refaite avec ses cibles. */
    public function link(RunningActivity $activity, RunningSession $session): void
    {
        DB::transaction(function () use ($activity, $session) {
            $activity->fill(['session_id' => $session->id, 'suggested_session_id' => null]);
            if ($activity->streams) {
                $activity->analysis = $this->analysis->analyzeActivity($activity, $session, $this->referencePaces->zonesFor($activity->user));
            }
            $activity->save();

            $session->update(['status' => 'done']);
            Conversation::where('activity_id', $activity->id)->update(['session_id' => $session->id]);
        });
    }
}
