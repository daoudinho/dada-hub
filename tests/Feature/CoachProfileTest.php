<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\CycleEntry;
use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\Injury;
use App\Modules\Coach\Models\PastRace;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\Unavailability;
use App\Modules\Coach\Services\AthleteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
    }

    public function test_profile_saves_background_and_weekly_availability(): void
    {
        $this->actingAs($this->athlete)->put('/coach/profil', [
            'sex' => 'male',
            'height_cm' => 180,
            'running_since' => 2018,
            'availability_notes' => 'Garde des enfants le mercredi',
            'other_activities' => 'Vélo le week-end',
            'long_run_day' => 'sun',
            'availability' => [
                'tue' => ['available' => '1', 'max_minutes' => 60, 'slot' => 'evening'],
                'sun' => ['available' => '1', 'max_minutes' => 150],
                'wed' => ['max_minutes' => 30],
            ],
        ])->assertRedirect(route('coach.profile.edit'));

        $profile = Profile::ownedBy($this->athlete)->sole();
        $this->assertSame([2018, 'Garde des enfants le mercredi', 'sun'], [$profile->running_since, $profile->availability_notes, $profile->long_run_day]);
        $this->assertSame(['available' => true, 'max_minutes' => 60, 'slot' => 'evening'], $profile->dayAvailability('tue'));
        $this->assertFalse($profile->dayAvailability('wed')['available']);
        $this->actingAs($this->athlete)->get('/coach/profil')->assertOk()->assertSee('Vélo le week-end');
    }

    public function test_cycle_tracking_is_only_kept_for_women(): void
    {
        $this->actingAs($this->athlete)->put('/coach/profil', ['sex' => 'male', 'cycle_tracking' => '1']);
        $this->assertFalse(Profile::ownedBy($this->athlete)->sole()->cycle_tracking);
        $this->actingAs($this->athlete)->post('/coach/cycle', ['started_on' => today()->toDateString()])->assertForbidden();
        $this->actingAs($this->athlete)->get('/coach/profil')->assertDontSee('Mon cycle');

        $this->actingAs($this->athlete)->put('/coach/profil', ['sex' => 'female', 'cycle_tracking' => '1']);
        $this->assertTrue(Profile::ownedBy($this->athlete)->sole()->cycle_tracking);
        $this->actingAs($this->athlete)->get('/coach/profil')->assertSee('Mon cycle');
    }

    public function test_cycle_status_reaches_the_dashboard_and_the_coach_only_while_tracking_is_on(): void
    {
        $this->travelTo('2026-11-17 08:00');
        $this->actingAs($this->athlete)->put('/coach/profil', ['sex' => 'female', 'cycle_tracking' => '1']);
        foreach (['2026-09-22', '2026-10-21', '2026-11-08'] as $date) { // cycles de 29 et 18 jours (le second est écarté)
            $this->actingAs($this->athlete)->post('/coach/cycle', ['started_on' => $date])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->athlete)->get('/coach')->assertSee('Jour 10')->assertSee('phase folliculaire');
        $conversation = Conversation::factory()->for($this->athlete)->create();
        $context = app(AthleteContext::class)->build($this->athlete, $conversation);
        $this->assertStringContainsString('Jour 10 : phase folliculaire', $context);
        $this->assertStringContainsString('Cycle moyen : 29 jours', $context);
        // Projection sur les semaines à venir, pour placer les séances clés.
        $this->assertStringContainsString('Cycle en cours, du 08/11 : règles 08/11–12/11 · phase folliculaire 13/11–20/11 · période d\'ovulation 21/11–23/11 · phase lutéale 24/11–06/12', $context);
        $this->assertStringContainsString('Cycle prévu du 07/12 : règles 07/12–11/12', $context);
        $this->actingAs($this->athlete)->get('/coach/running/calendrier')->assertSee('Règles prévues');

        $this->actingAs($this->athlete)->put('/coach/profil', ['sex' => 'female']);
        $this->assertStringNotContainsString('Cycle menstruel', app(AthleteContext::class)->build($this->athlete, $conversation));
        $this->actingAs($this->athlete)->get('/coach')->assertDontSee('Mon cycle');
    }

    public function test_past_race_gives_reference_paces_on_the_profile_and_to_the_coach(): void
    {
        $this->actingAs($this->athlete)->post('/coach/profil/courses', [
            'raced_on' => today()->subMonths(2)->toDateString(), 'name' => '5 km du parc', 'distance_km' => '5', 'time' => '20:00',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->athlete)->get('/coach/profil')->assertSee('VDOT 49,8')->assertSee('Seuil')->assertSee('10 km 41:');
        $context = app(AthleteContext::class)->build($this->athlete, Conversation::factory()->for($this->athlete)->create());
        $this->assertStringContainsString('VDOT 49,8 d\'après la course passée : 5 km du parc', $context);
    }

    public function test_past_race_rejects_an_unreadable_time(): void
    {
        $this->actingAs($this->athlete)->post('/coach/profil/courses', ['raced_on' => '2026-05-01', 'distance_km' => '10', 'time' => 'une heure'])
            ->assertSessionHasErrors(['time' => 'Temps illisible : écris par exemple « 52:10 » ou « 1:58:30 ».']);

        $this->assertDatabaseCount('coach_past_races', 0);
    }

    public function test_injury_can_be_marked_healed_and_flags_the_coach_while_current(): void
    {
        $this->actingAs($this->athlete)->post('/coach/profil/blessures', ['zone' => 'Tendon d\'Achille', 'current' => '1'])->assertSessionHasNoErrors();
        $injury = Injury::ownedBy($this->athlete)->sole();
        $conversation = Conversation::factory()->for($this->athlete)->create();
        $this->assertStringContainsString('Blessure ou gêne en cours : Tendon d\'Achille', app(AthleteContext::class)->build($this->athlete, $conversation));

        $this->actingAs($this->athlete)->patch("/coach/profil/blessures/{$injury->id}");

        $this->assertFalse($injury->fresh()->current);
        $this->assertStringNotContainsString('Blessure ou gêne en cours', app(AthleteContext::class)->build($this->athlete, $conversation));
    }

    public function test_athlete_cannot_touch_another_athletes_background(): void
    {
        $other = User::factory()->withModules('coach')->create();
        $race = (new PastRace(['raced_on' => '2026-01-01', 'distance_km' => 10, 'time_seconds' => 3000]))->user()->associate($other);
        $race->save();
        $injury = (new Injury(['zone' => 'genou']))->user()->associate($other);
        $injury->save();
        $entry = (new CycleEntry(['started_on' => '2026-11-01']))->user()->associate($other);
        $entry->save();
        $goal = Goal::factory()->for($other)->create();

        $this->actingAs($this->athlete)->delete("/coach/profil/courses/{$race->id}")->assertNotFound();
        $this->actingAs($this->athlete)->patch("/coach/profil/blessures/{$injury->id}")->assertNotFound();
        $this->actingAs($this->athlete)->delete("/coach/cycle/{$entry->id}")->assertNotFound();
        $this->actingAs($this->athlete)->patch("/coach/objectifs/{$goal->id}", ['status' => 'abandoned'])->assertNotFound();

        $this->assertModelExists($race);
        $this->assertModelExists($entry);
        $this->assertSame('active', $goal->fresh()->status);
    }

    public function test_main_goal_replaces_the_previous_one_while_intermediate_goals_add_up(): void
    {
        $this->freezeTime();
        $old = Goal::factory()->for($this->athlete)->create(['title' => 'Ancien objectif']);

        $this->actingAs($this->athlete)->post('/coach/objectifs', [
            'title' => 'Marathon de Paris', 'event_name' => 'le marathon de Paris', 'event_date' => today()->addDays(150)->toDateString(),
            'priority' => 'main', 'finish_only' => '1', 'target_time' => '3:30:00',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->athlete)->post('/coach/objectifs', [
            'title' => 'Semi de préparation', 'event_name' => 'le semi de Boulogne', 'event_date' => today()->addDays(60)->toDateString(), 'priority' => 'intermediate',
        ])->assertSessionHasNoErrors();

        $this->assertSame('abandoned', $old->fresh()->status);
        $main = Goal::ownedBy($this->athlete)->where('status', 'active')->where('priority', 'main')->sole();
        $this->assertSame([true, null, 'finir'], [$main->finish_only, $main->target_seconds, $main->targetLabel()]);
        $this->actingAs($this->athlete)->get('/coach')
            ->assertSee('Plus que 150 jours avant le marathon de Paris')
            ->assertSee('Étape : Plus que 60 jours avant le semi de Boulogne');
    }

    public function test_unavailability_shows_on_the_calendar(): void
    {
        $this->travelTo('2026-11-17 08:00');

        $this->actingAs($this->athlete)->post('/coach/profil/indisponibilites', [
            'starts_on' => '2026-11-24', 'ends_on' => '2026-11-26', 'kind' => 'none', 'reason' => 'Déplacement pro',
        ])->assertSessionHasNoErrors();

        $this->assertSame('none', Unavailability::ownedBy($this->athlete)->sole()->kind);
        $this->actingAs($this->athlete)->get('/coach/running/calendrier')->assertSee('Déplacement pro');
    }

    public function test_dashboard_asks_the_feedback_of_the_latest_run_only(): void
    {
        RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subDays(4)]);
        RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subHour()]);

        $html = $this->actingAs($this->athlete)->get('/coach')->assertSee('Ta dernière course')->getContent();

        $this->assertSame(1, substr_count($html, 'Effort ressenti'));
    }

    public function test_dashboard_asks_for_a_quick_feedback_after_a_run_and_records_it(): void
    {
        $activity = RunningActivity::factory()->for($this->athlete)->create(['rpe' => null]);
        $this->actingAs($this->athlete)->get('/coach')->assertSee('Ta dernière course')->assertSee('Effort ressenti')->assertDontSee('Débriefer avec le coach');

        $this->actingAs($this->athlete)->post("/coach/running/courses/{$activity->id}/ressenti", [
            'rpe' => 7, 'fatigue' => 4, 'sleep' => 2, 'pain_zone' => 'mollet gauche', 'pain_intensity' => 5,
        ])->assertSessionHasNoErrors();

        $feedback = Feedback::sole();
        $this->assertSame(['form', 7, 4, 2], [$feedback->source, $feedback->rpe, $feedback->fatigue, $feedback->sleep]);
        $this->assertSame([['zone' => 'mollet gauche', 'intensity' => 5]], $feedback->pains);
        $this->assertSame(7, $activity->fresh()->rpe);
        // Ressenti donné : la carte propose le débrief.
        $this->actingAs($this->athlete)->get('/coach')->assertDontSee('Effort ressenti')->assertSee('Débriefer avec le coach');
    }

    public function test_sleep_question_is_replaced_by_the_watch_measure_when_the_night_was_recorded(): void
    {
        $this->travelTo('2026-11-20 19:00');
        $measured = RunningActivity::factory()->for($this->athlete)->create(['started_at' => '2026-11-20 18:00']);
        $unmeasured = RunningActivity::factory()->for($this->athlete)->create(['started_at' => '2026-11-18 18:00']);
        (new DailyWellness(['day' => '2026-11-20', 'sleep_seconds' => 25920, 'sleep_score' => 81]))->user()->associate($this->athlete)->save();

        $this->actingAs($this->athlete)->get("/coach/running/courses/{$measured->id}")
            ->assertSee('Sommeil mesuré par ta montre')->assertSee('7h12')->assertDontSee('Sommeil des dernières nuits');
        $this->actingAs($this->athlete)->get("/coach/running/courses/{$unmeasured->id}")
            ->assertSee('Sommeil des dernières nuits')->assertDontSee('Sommeil mesuré par ta montre');

        $debrief = Conversation::factory()->for($this->athlete)->create(['kind' => 'debrief', 'activity_id' => $measured->id]);
        $this->assertStringContainsString('Sommeil de la nuit précédente, mesuré par la montre : 7h12 (score 81)', app(AthleteContext::class)->build($this->athlete, $debrief));
    }

    public function test_quick_feedback_requires_effort_and_fatigue_and_a_pain_intensity_with_its_zone(): void
    {
        $activity = RunningActivity::factory()->for($this->athlete)->create();

        $this->actingAs($this->athlete)->post("/coach/running/courses/{$activity->id}/ressenti", ['pain_zone' => 'genou'])
            ->assertSessionHasErrors(['rpe', 'fatigue', 'pain_intensity' => 'Indique l\'intensité de la douleur (1 à 10).']);

        $this->assertDatabaseCount('coach_feedbacks', 0);
    }

    public function test_quick_feedback_cannot_target_another_athletes_run(): void
    {
        $activity = RunningActivity::factory()->create();

        $this->actingAs($this->athlete)->post("/coach/running/courses/{$activity->id}/ressenti", ['rpe' => 5, 'fatigue' => 2])->assertNotFound();

        $this->assertDatabaseCount('coach_feedbacks', 0);
    }

    public function test_logging_a_run_with_its_feedback_records_both(): void
    {
        $this->actingAs($this->athlete)->post('/coach/running/courses', [
            'date' => today()->toDateString(), 'distance_km' => '10', 'duration' => '55:00', 'rpe' => 6, 'fatigue' => 3,
        ])->assertRedirect(route('coach.index'));

        $feedback = Feedback::sole();
        $this->assertSame([RunningActivity::sole()->id, 'form', 3], [$feedback->activity_id, $feedback->source, $feedback->fatigue]);
    }
}
