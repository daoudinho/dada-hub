<?php

namespace App\Modules\Coach\Services\Strava;

use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Gear;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Services\CoachConversation;
use App\Modules\Coach\Services\ReferencePaces;
use App\Modules\Coach\Services\RunningRecords;
use App\Modules\Coach\Services\SessionMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Import d'une activité Strava : course avec ses tours, ses flux et son analyse, séance prévue validée,
 * meilleures performances, chaussures, et débrief en attente si la course est récente.
 */
class StravaImporter
{
    /** Flux demandés à Strava. */
    private const STREAMS = 'time,distance,heartrate,velocity_smooth,cadence,altitude,moving';

    public function __construct(
        private StravaClient $client,
        private ActivityAnalysis $analysis,
        private ReferencePaces $referencePaces,
        private CoachConversation $coach,
        private RunningRecords $records,
    ) {}

    /**
     * Liste des identifiants de courses Strava depuis une date.
     *
     * @return list<int>
     *
     * @throws StravaRateLimited
     */
    public function runIdsSince(StravaAccount $account, Carbon $since): array
    {
        return $this->listRuns($account, ['after' => $since->timestamp])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Identifiants des courses d'une période (jours inclus, à l'heure locale de départ).
     *
     * @return list<int>
     *
     * @throws StravaRateLimited
     */
    public function runIdsBetween(StravaAccount $account, Carbon $from, Carbon $to): array
    {
        [$first, $last] = [$from->toDateString(), $to->toDateString()];

        return $this->listRuns($account, [
            // Un jour de marge de chaque côté : Strava filtre en heure UTC.
            'after' => $from->copy()->startOfDay()->subDay()->timestamp,
            'before' => $to->copy()->endOfDay()->addDay()->timestamp,
        ])
            ->filter(fn (array $run) => ($day = $this->localStart($run)->toDateString()) >= $first && $day <= $last)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Résumés des courses renvoyés par Strava (jusqu'à 10 pages de 100).
     *
     * @param  array<string, int>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function listRuns(StravaAccount $account, array $filters): Collection
    {
        $runs = collect();
        for ($page = 1; $page <= 10; $page++) {
            $activities = $this->client->get($account, '/athlete/activities', $filters + ['per_page' => 100, 'page' => $page]);
            $runs = $runs->concat(array_filter($activities, fn (array $activity) => $this->isRun($activity)));
            if (count($activities) < 100) {
                break;
            }
        }

        return $runs;
    }

    /**
     * Heure locale de départ (Strava la donne avec un « Z » trompeur).
     *
     * @param  array<string, mixed>  $activity
     */
    private function localStart(array $activity): Carbon
    {
        return Carbon::parse(substr((string) ($activity['start_date_local'] ?? $activity['start_date']), 0, 19));
    }

    /**
     * Importe (ou met à jour) une activité. Null si ce n'est pas une course.
     *
     * @throws StravaRateLimited
     */
    public function import(StravaAccount $account, int $stravaId, bool $notify = true): ?RunningActivity
    {
        $user = $account->user;
        $detail = $this->client->get($account, '/activities/'.$stravaId, ['include_all_efforts' => 'false']);
        $existing = RunningActivity::ownedBy($user)->where('source', 'strava')->where('external_id', (string) $stravaId)->first();

        if (! $detail || ! $this->isRun($detail)) {
            $existing && $this->forget($existing); // devenue autre chose qu'une course (type modifié sur Strava)

            return null;
        }

        $streams = collect($this->client->get($account, '/activities/'.$stravaId.'/streams', ['keys' => self::STREAMS, 'key_by_type' => 'true']))
            ->map(fn ($stream) => $stream['data'] ?? [])
            ->all();
        $laps = collect($detail['laps'] ?? [])->map(fn (array $lap) => [
            'distance_m' => (int) round($lap['distance'] ?? 0),
            'moving_s' => (int) ($lap['moving_time'] ?? 0),
            'avg_speed' => round((float) ($lap['average_speed'] ?? 0), 3),
            'avg_hr' => isset($lap['average_heartrate']) ? (int) round($lap['average_heartrate']) : null,
            'max_hr' => isset($lap['max_heartrate']) ? (int) round($lap['max_heartrate']) : null,
        ])->values()->all();

        $startedAt = $this->localStart($detail);

        return DB::transaction(function () use ($account, $user, $detail, $streams, $laps, $startedAt, $existing, $notify, $stravaId) {
            [$session, $suggestedId] = SessionMatcher::forImport($user, $startedAt, $existing, $notify);

            $activity = $existing ?? (new RunningActivity(['source' => 'strava', 'external_id' => (string) $stravaId]))->user()->associate($user);
            $activity->fill([
                'session_id' => $session?->id,
                'suggested_session_id' => $suggestedId,
                'name' => mb_substr((string) ($detail['name'] ?? ''), 0, 150) ?: null,
                'sport_type' => $detail['sport_type'] ?? $detail['type'] ?? null,
                'started_at' => $startedAt,
                'distance_m' => (int) round($detail['distance'] ?? 0),
                'moving_seconds' => max(60, (int) ($detail['moving_time'] ?? 0)),
                'elapsed_seconds' => isset($detail['elapsed_time']) ? (int) $detail['elapsed_time'] : null,
                'avg_hr' => isset($detail['average_heartrate']) ? (int) round($detail['average_heartrate']) : null,
                'max_hr' => isset($detail['max_heartrate']) ? (int) round($detail['max_heartrate']) : null,
                // Strava compte les pas d'une seule jambe : ×2 pour des pas par minute.
                'avg_cadence' => isset($detail['average_cadence']) ? (int) round($detail['average_cadence'] * 2) : null,
                'elevation_gain_m' => isset($detail['total_elevation_gain']) ? (int) round($detail['total_elevation_gain']) : null,
                'gear_id' => $detail['gear_id'] ?? null,
                'laps' => $laps ?: null,
                'streams' => $streams ?: null,
                'rpe' => $activity->rpe ?? (isset($detail['perceived_exertion']) ? (int) round($detail['perceived_exertion']) : null),
                'notes' => $activity->notes ?? (filled($detail['description'] ?? null) ? mb_substr($detail['description'], 0, 2000) : null),
            ]);
            $activity->analysis = $this->analysis->analyzeActivity($activity, $session, $this->referencePaces->zonesFor($user));
            $activity->save();

            $session?->update(['status' => 'done']);
            $this->saveBestEfforts($activity, $detail['best_efforts'] ?? [], $startedAt);
            RunningRecords::saveBestTimes($activity); // complète avec les distances calculées sur les courbes (1 km, semi…)
            if (isset($detail['gear']['id'])) {
                $this->saveGear($account, $detail['gear']);
            }

            $isRecent = $startedAt->isAfter(now()->subHours((int) config('coach.strava.debrief_within_hours')));
            // Nouvelle course (hors historique importé) : comparée aux records avant le débrief.
            if (! $existing && $notify) {
                $this->records->markNewRecords($activity);
            }
            if (! $existing && $notify && $isRecent) {
                $this->coach->startDebrief($user, $activity);
            }

            return $activity;
        });
    }

    /** Course supprimée sur Strava : retirée ici, sa séance redevient « prévue ». */
    public function forget(RunningActivity $activity): void
    {
        DB::transaction(function () use ($activity) {
            $activity->session?->update(['status' => 'planned']);
            Conversation::where('activity_id', $activity->id)->where('status', 'pending')->delete();
            $activity->delete();
        });
    }

    /**
     * Chaussures du profil Strava (kilométrage à jour).
     *
     * @throws StravaRateLimited
     */
    public function syncGear(StravaAccount $account): void
    {
        foreach ($this->client->get($account, '/athlete')['shoes'] ?? [] as $shoe) {
            $this->saveGear($account, $shoe);
        }
    }

    /**
     * @param  array<string, mixed>  $activity  activité Strava (résumé ou détail)
     */
    private function isRun(array $activity): bool
    {
        return in_array($activity['sport_type'] ?? $activity['type'] ?? null, config('coach.strava.run_types'), true);
    }

    /**
     * @param  list<array<string, mixed>>  $efforts
     */
    private function saveBestEfforts(RunningActivity $activity, array $efforts, Carbon $startedAt): void
    {
        BestEffort::where('activity_id', $activity->id)->delete();

        foreach ($efforts as $effort) {
            if (! in_array($effort['name'] ?? null, BestEffort::RELEVANT, true) || empty($effort['elapsed_time'])) {
                continue;
            }
            (new BestEffort([
                'activity_id' => $activity->id,
                'name' => $effort['name'],
                'distance_m' => (int) round($effort['distance'] ?? 0),
                'elapsed_seconds' => (int) $effort['elapsed_time'],
                'achieved_on' => $startedAt->toDateString(),
            ]))->user()->associate($activity->user_id)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $gear
     */
    private function saveGear(StravaAccount $account, array $gear): void
    {
        $shoe = Gear::where('user_id', $account->user_id)->where('strava_id', (string) $gear['id'])->first()
            ?? (new Gear(['strava_id' => (string) $gear['id']]))->user()->associate($account->user_id);

        $shoe->fill([
            'name' => mb_substr((string) ($gear['name'] ?? $gear['nickname'] ?? 'Chaussures'), 0, 150),
            'distance_m' => (int) round($gear['distance'] ?? 0),
            'primary' => (bool) ($gear['primary'] ?? false),
            'retired' => (bool) ($gear['retired'] ?? false),
        ])->save();
    }
}
