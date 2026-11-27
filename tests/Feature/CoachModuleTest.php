<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\WeightEntry;
use App\Modules\Coach\Services\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running', 'fitness']])->create();
    }

    public function test_module_is_reserved_to_accounts_it_is_open_to(): void
    {
        $this->actingAs(User::factory()->withModules('recettes')->create())->get('/coach')->assertForbidden();
        $this->actingAs($this->athlete)->get('/coach')->assertOk();
    }

    public function test_dashboard_shows_the_countdown_to_the_race(): void
    {
        $this->freezeTime();
        $goal = Goal::factory()->for($this->athlete)->create(['event_name' => 'le semi-marathon de Paris', 'event_date' => today()->addDays(42)]);

        $this->actingAs($this->athlete)->get('/coach')->assertOk()->assertSee('Plus que 42 jours avant le semi-marathon de Paris');

        $goal->update(['event_date' => today()]);
        $this->actingAs($this->athlete)->get('/coach')->assertSee('C\'est aujourd\'hui : le semi-marathon de Paris !');
    }

    public function test_dashboard_only_shows_the_athletes_own_data(): void
    {
        Goal::factory()->create(['title' => 'Objectif de quelqu\'un d\'autre', 'event_date' => null]);
        WeightEntry::factory()->create(['weight_kg' => 99.9]);

        $this->actingAs($this->athlete)->get('/coach')
            ->assertDontSee('Objectif de quelqu\'un d\'autre')
            ->assertDontSee('99,9');
    }

    public function test_weight_entry_accepts_a_comma_and_replaces_the_same_day(): void
    {
        $this->freezeTime();

        $this->actingAs($this->athlete)->post('/coach/poids', ['weight_kg' => '72,4'])->assertSessionHasNoErrors();
        $this->actingAs($this->athlete)->post('/coach/poids', ['weight_kg' => '72,1'])->assertSessionHasNoErrors();

        $entry = WeightEntry::ownedBy($this->athlete)->sole();
        $this->assertSame(72.1, $entry->weight_kg);
        $this->actingAs($this->athlete)->get('/coach')->assertSee('72,1');
    }

    public function test_weight_entry_rejects_an_implausible_value(): void
    {
        $this->actingAs($this->athlete)->post('/coach/poids', ['weight_kg' => '7'])->assertSessionHasErrors('weight_kg');

        $this->assertDatabaseCount('coach_weight_entries', 0);
    }

    public function test_athlete_cannot_touch_another_athletes_data(): void
    {
        $weight = WeightEntry::factory()->create();
        $session = RunningSession::factory()->create();
        $conversation = Conversation::factory()->create();

        $this->actingAs($this->athlete)->delete("/coach/poids/{$weight->id}")->assertNotFound();
        $this->actingAs($this->athlete)->post("/coach/running/seances/{$session->id}/annuler")->assertNotFound();
        $this->actingAs($this->athlete)->get("/coach/chat/{$conversation->id}")->assertNotFound();
        $this->actingAs($this->athlete)->postJson("/coach/chat/{$conversation->id}/messages", ['message' => 'Salut'])->assertNotFound();

        $this->assertModelExists($weight);
        $this->assertSame('planned', $session->fresh()->status);
    }

    public function test_logging_a_run_completes_the_session_and_opens_a_pending_debrief(): void
    {
        $session = RunningSession::factory()->for($this->athlete)->create(['title' => '10 × 400 m']);

        $this->actingAs($this->athlete)->post('/coach/running/courses', [
            'session_id' => $session->id,
            'date' => today()->toDateString(),
            'distance_km' => '8,5',
            'duration' => '45:30',
            'avg_hr' => 152,
            'rpe' => 7,
        ])->assertRedirect(route('coach.index'));

        $activity = RunningActivity::sole();
        $this->assertSame(8500, $activity->distance_m);
        $this->assertSame(2730, $activity->moving_seconds);
        $this->assertSame('done', $session->fresh()->status);

        $debrief = Conversation::sole();
        $this->assertSame(['debrief', 'pending', $activity->id], [$debrief->kind, $debrief->status, $debrief->activity_id]);
        // La dernière course porte son propre parcours (ressenti, débrief, bilan) au lieu d'un « débrief en attente ».
        $this->actingAs($this->athlete)->get('/coach')->assertSee('Ta dernière course')->assertSee('10 × 400 m')->assertDontSee('Débrief en attente');
    }

    public function test_logging_a_run_rejects_an_unreadable_duration(): void
    {
        $this->actingAs($this->athlete)->post('/coach/running/courses', [
            'date' => today()->toDateString(),
            'distance_km' => '8',
            'duration' => 'trois quarts d\'heure',
        ])->assertSessionHasErrors(['moving_seconds' => 'Durée illisible : écris par exemple « 45:30 » ou « 1:02:10 ».']);

        $this->assertDatabaseCount('coach_running_activities', 0);
    }

    public function test_logging_a_run_cannot_complete_another_athletes_session(): void
    {
        $session = RunningSession::factory()->create();

        $this->actingAs($this->athlete)->post('/coach/running/courses', [
            'session_id' => $session->id,
            'date' => today()->toDateString(),
            'distance_km' => '8',
            'duration' => '45:00',
        ])->assertNotFound();

        $this->assertSame('planned', $session->fresh()->status);
    }

    public function test_cancelling_a_session_opens_a_pending_cancellation_talk(): void
    {
        $session = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => today()->addDay()]);

        $this->actingAs($this->athlete)
            ->post("/coach/running/seances/{$session->id}/annuler", ['reason' => 'Mal au genou'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['cancelled', 'Mal au genou'], [$session->fresh()->status, $session->fresh()->cancel_reason]);
        $conversation = Conversation::sole();
        $this->assertSame(['cancellation', 'pending', $session->id], [$conversation->kind, $conversation->status, $conversation->session_id]);
    }

    public function test_calendar_starts_at_the_current_week_and_shows_five_weeks(): void
    {
        $this->travelTo('2026-11-18 10:00'); // mercredi
        RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-10', 'title' => 'Séance de la semaine dernière']);
        RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-16', 'title' => 'Footing de lundi']);
        RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-12-20', 'title' => 'Sortie longue de décembre']);
        RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-12-21', 'title' => 'Séance hors fenêtre']);

        $this->actingAs($this->athlete)->get('/coach/running/calendrier')
            ->assertOk()
            ->assertSee('16 nov. – 20 déc. 2026')
            ->assertSee('Footing de lundi')
            ->assertSee('Sortie longue de décembre')
            ->assertDontSee('Séance de la semaine dernière')
            ->assertDontSee('Séance hors fenêtre')
            ->assertDontSee('Revenir à cette semaine');

        $this->actingAs($this->athlete)->get('/coach/running/calendrier?semaine=2026-10-19')
            ->assertSee('Séance de la semaine dernière')
            ->assertSee('Revenir à cette semaine');
    }

    public function test_only_cancelling_today_s_session_shows_the_motivation_video_first(): void
    {
        $this->travelTo('2026-11-18 10:00');
        $today = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-18']);
        $tomorrow = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-19']);

        $this->actingAs($this->athlete)->get('/coach/running/calendrier')
            ->assertOk()
            ->assertSee('videos/motivation.mp4')
            ->assertSee('seances/'.$today->id.'/annuler" class="form" style="margin-top:12px" data-motivation', false)
            ->assertDontSee('seances/'.$tomorrow->id.'/annuler" class="form" style="margin-top:12px" data-motivation', false);
    }

    public function test_run_form_preselects_the_planned_session(): void
    {
        $session = RunningSession::factory()->for($this->athlete)->create(['title' => 'Footing du jour']);

        $this->actingAs($this->athlete)->get("/coach/running/courses/nouvelle?seance={$session->id}")
            ->assertOk()->assertSee('<option value="'.$session->id.'" selected', false);
    }

    public function test_chat_page_lists_messages_and_pending_proposals(): void
    {
        $conversation = Conversation::factory()->for($this->athlete)->create(['title' => 'Premier échange']);
        $conversation->messages()->create(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Salut']], 'display_text' => 'Salut <b>coach</b>']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'x']], 'display_text' => 'Bonjour, **prêt** ? <script>alert(1)</script>']);
        $conversation->messages()->create(['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Fiche secrète']]]);

        $this->actingAs($this->athlete)->get("/coach/chat/{$conversation->id}")
            ->assertOk()
            ->assertSee('Salut &lt;b&gt;coach&lt;/b&gt;', false)
            ->assertSee('<strong>prêt</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Fiche secrète');
        $this->actingAs($this->athlete)->get('/coach/chat')->assertOk()->assertSee('Premier échange');
    }

    public function test_dashboard_sums_kilometres_per_week(): void
    {
        $this->travelTo('2026-11-18 10:00'); // mercredi
        RunningActivity::factory()->for($this->athlete)->create(['started_at' => '2026-11-16 08:00', 'distance_m' => 8000]);
        RunningActivity::factory()->for($this->athlete)->create(['started_at' => '2026-11-17 08:00', 'distance_m' => 5500]);
        RunningActivity::factory()->for($this->athlete)->create(['started_at' => '2026-11-10 08:00', 'distance_m' => 10000]);

        $weeks = app(TrainingLoad::class)->weeklyDistance($this->athlete, 3);

        $this->assertSame(['2026-11-02' => 0.0, '2026-11-09' => 10.0, '2026-11-16' => 13.5], $weeks->all());
        $this->actingAs($this->athlete)->get('/coach')->assertSee('Semaine du 16/11 · 13,5 km');
    }

    public function test_training_load_flags_a_recent_pain_and_an_overload(): void
    {
        $this->travelTo('2026-11-18 10:00');
        foreach ([25, 18, 11] as $daysAgo) {
            RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subDays($daysAgo), 'moving_seconds' => 1800, 'rpe' => 4]);
        }
        foreach ([3, 2, 1] as $daysAgo) {
            RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subDays($daysAgo), 'moving_seconds' => 3600, 'rpe' => 8]);
        }
        $feedback = new Feedback(['summary' => 'Gêne au genou', 'pains' => [['zone' => 'genou droit', 'intensity' => 6]]]);
        $feedback->user()->associate($this->athlete)->save();

        $signals = implode("\n", app(TrainingLoad::class)->signals($this->athlete));

        $this->assertStringContainsString('Ratio de charge aigu/chronique', $signals);
        $this->assertStringContainsString('genou droit 6/10', $signals);
    }

    public function test_training_load_warns_from_a_ratio_of_1_3(): void
    {
        $this->travelTo('2026-11-18 10:00');
        // 3 semaines à 20 km, puis 32 km sur les 7 derniers jours : ratio 32 / (92 / 4) ≈ 1,39.
        foreach ([24, 17, 10] as $daysAgo) {
            RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subDays($daysAgo), 'distance_m' => 20000, 'moving_seconds' => 7200, 'rpe' => 4]);
        }
        RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subDays(2), 'distance_m' => 32000, 'moving_seconds' => 11520, 'rpe' => 4]);

        $signals = implode("\n", app(TrainingLoad::class)->signals($this->athlete));

        $this->assertStringContainsString('au-dessus de 1.3', $signals);
        $this->assertStringContainsString('vigilance', $signals);
        $this->assertStringNotContainsString('au-dessus de 1.5', $signals);
    }

    public function test_training_load_flags_too_much_intensity_and_repeated_fatigue(): void
    {
        $this->travelTo('2026-11-18 10:00');
        foreach ([20, 13, 6] as $daysAgo) {
            $session = RunningSession::factory()->for($this->athlete)->done()->create(['scheduled_on' => now()->subDays($daysAgo), 'type' => 'intervals']);
            RunningActivity::factory()->for($this->athlete)->create(['session_id' => $session->id, 'started_at' => now()->subDays($daysAgo), 'moving_seconds' => 3000]);
        }
        foreach ([18, 11, 4] as $daysAgo) {
            RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subDays($daysAgo), 'moving_seconds' => 3000, 'rpe' => 3]);
        }
        foreach ([3, 1] as $daysAgo) {
            (new Feedback(['fatigue' => 4, 'source' => 'form']))->forceFill(['created_at' => now()->subDays($daysAgo)])->user()->associate($this->athlete)->save();
        }

        $signals = implode("\n", app(TrainingLoad::class)->signals($this->athlete));

        $this->assertStringContainsString('Intensité : 50 % du temps de course en séances dures', $signals);
        $this->assertStringContainsString('Arrivé fatigué aux séances (forme 4 ou 5 sur 5) 2 fois', $signals);
    }

    public function test_training_load_stays_quiet_for_a_steady_athlete(): void
    {
        $this->travelTo('2026-11-18 10:00');
        foreach ([26, 22, 19, 15, 12, 8, 5, 1] as $daysAgo) {
            RunningActivity::factory()->for($this->athlete)->create(['started_at' => now()->subDays($daysAgo), 'moving_seconds' => 2700, 'rpe' => 5]);
        }

        $this->assertSame([], app(TrainingLoad::class)->signals($this->athlete));
    }
}
