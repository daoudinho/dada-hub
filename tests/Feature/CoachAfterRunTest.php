<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Jobs\WriteAutoReviewJob;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\CoachConversation;
use App\Modules\Coach\Services\SessionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\FakesClaude;
use Tests\TestCase;

class CoachAfterRunTest extends TestCase
{
    use FakesClaude, RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureClaude();
        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
    }

    public function test_run_page_offers_the_debrief_once_the_feedback_is_given(): void
    {
        $activity = RunningActivity::factory()->for($this->athlete)->create();
        $page = route('coach.running.activities.show', $activity);

        $this->actingAs($this->athlete)->get($page)->assertSee('Donne d\'abord ton ressenti', false)->assertDontSee('Débriefer avec le coach');

        $this->addFeedback($activity);
        $this->actingAs($this->athlete)->get($page)->assertSee('Débriefer avec le coach');

        $this->actingAs($this->athlete)->post(route('coach.running.activities.debrief', $activity))
            ->assertRedirect(route('coach.chat.show', $debrief = Conversation::sole()));
        $this->assertSame(['debrief', $activity->id], [$debrief->kind, $debrief->activity_id]);

        // Le débrief existant est repris, pas dupliqué.
        $this->actingAs($this->athlete)->post(route('coach.running.activities.debrief', $activity))->assertRedirect(route('coach.chat.show', $debrief));
        $this->assertSame(1, Conversation::count());
    }

    public function test_athlete_cannot_open_the_debrief_of_another_athletes_run(): void
    {
        $other = RunningActivity::factory()->create();

        $this->actingAs($this->athlete)->post(route('coach.running.activities.debrief', $other))->assertNotFound();
        $this->assertSame(0, Conversation::count());
    }

    public function test_a_session_missed_in_the_previous_days_is_offered_and_can_be_confirmed_or_dismissed(): void
    {
        $this->travelTo('2026-11-25 18:00');
        $tuesday = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-24', 'title' => '10 × 400 m', 'type' => 'intervals']);
        RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-21']); // trop ancienne
        RunningSession::factory()->for($this->athlete)->done()->create(['scheduled_on' => '2026-11-23']); // déjà faite

        $this->assertTrue(SessionMatcher::missedBefore($this->athlete, now())->is($tuesday));

        $activity = RunningActivity::factory()->for($this->athlete)->create(['started_at' => now(), 'suggested_session_id' => $tuesday->id]);
        $this->actingAs($this->athlete)->get(route('coach.running.activities.show', $activity))->assertSee('C\'était ta séance', false);

        $this->actingAs($this->athlete)->post(route('coach.running.activities.session.confirm', $activity))->assertSessionHas('status');
        $this->assertSame([$tuesday->id, null], [$activity->fresh()->session_id, $activity->fresh()->suggested_session_id]);
        $this->assertSame('done', $tuesday->fresh()->status);

        $other = RunningActivity::factory()->for($this->athlete)->create(['suggested_session_id' => RunningSession::factory()->for($this->athlete)->create()->id]);
        $this->actingAs($this->athlete)->delete(route('coach.running.activities.session.dismiss', $other));
        $this->assertNull($other->fresh()->suggested_session_id);
    }

    public function test_garmin_sync_status_reports_the_new_runs_once_finished(): void
    {
        $this->travelTo('2026-11-24 10:00:00');
        $account = (new GarminAccount(['api_key' => 'key', 'backfilled_at' => now()->subMonth()]))->user()->associate($this->athlete);
        $account->save();
        $since = now()->toIso8601String();

        $this->actingAs($this->athlete)->getJson(route('coach.garmin.status', ['depuis' => $since]))->assertJson(['done' => false, 'runs' => []]);

        $this->travelTo('2026-11-24 10:00:05');
        $account->update(['last_synced_at' => now(), 'last_sync_runs' => 1]);
        RunningActivity::factory()->for($this->athlete)->create(['source' => 'garmin', 'external_id' => '9', 'distance_m' => 7600, 'moving_seconds' => 2338,
            'new_records' => [['key' => '5K', 'label' => 'Meilleur 5 km', 'value' => '25:14', 'previous' => '26:23']]]);

        $this->travelTo('2026-11-24 10:00:20');
        $this->actingAs($this->athlete)->getJson(route('coach.garmin.status', ['depuis' => $since]))
            ->assertJson(['done' => true, 'error' => null, 'runs' => [['label' => '7,6 km en 38:58', 'record' => true]]]);
    }

    public function test_opening_a_debrief_schedules_its_review_48_hours_later(): void
    {
        Queue::fake();
        $activity = RunningActivity::factory()->for($this->athlete)->create();

        $this->actingAs($this->athlete)->post(route('coach.running.activities.debrief', $activity));

        $debrief = Conversation::sole();
        Queue::assertPushed(WriteAutoReviewJob::class, fn (WriteAutoReviewJob $job) => $job->conversationId === $debrief->id
            && $job->delay->equalTo($debrief->created_at->copy()->addHours(WriteAutoReviewJob::AFTER_HOURS)));
    }

    public function test_review_job_does_nothing_before_it_is_due_or_once_a_review_exists(): void
    {
        Http::preventStrayRequests();
        $early = $this->debrief(now()->subHours(10));
        $reviewed = $this->debrief(now()->subHours(60), ['review' => ['verdict' => 'reached', 'summary' => 'Déjà fait.', 'strengths' => [], 'watchpoints' => [], 'next' => '—']]);

        foreach ([$early, $reviewed] as $debrief) {
            (new WriteAutoReviewJob($debrief->id))->handle(app(CoachConversation::class));
        }

        Http::assertNothingSent();
        $this->assertNull($early->activity->fresh()->review);
    }

    public function test_auto_review_is_written_without_debrief_and_closes_the_conversation(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([$this->toolUse('call_review', 'write_session_review', [
                'verdict' => 'reached', 'summary' => 'Footing tenu en endurance, bilan fait sans débrief.', 'next' => 'Rien à changer.',
            ])], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Ton bilan est sur la fiche de la course.']])),
        ]);
        $debrief = $this->debrief(now()->subHours(49));

        (new WriteAutoReviewJob($debrief->id))->handle(app(CoachConversation::class));

        $this->assertSame('reached', $debrief->activity->fresh()->review['verdict']);
        $this->assertSame('closed', $debrief->fresh()->status);
        $this->assertStringContainsString('pas fait le débrief', Http::recorded()->first()[0]['messages'][0]['content'][0]['text']);
        // La consigne reste cachée : seul le mot du coach s'affiche.
        $this->assertSame(['Ton bilan est sur la fiche de la course.'], $debrief->messages()->whereNotNull('display_text')->pluck('display_text')->all());
    }

    /**
     * @param  array<string, mixed>  $activityAttributes
     */
    private function debrief(\DateTimeInterface $openedAt, array $activityAttributes = []): Conversation
    {
        $activity = RunningActivity::factory()->for($this->athlete)->create($activityAttributes + ['started_at' => now()->subDays(3)]);
        $conversation = (new Conversation(['kind' => 'debrief', 'title' => 'Débrief', 'activity_id' => $activity->id, 'status' => 'pending']))->user()->associate($this->athlete);
        $conversation->created_at = $openedAt;
        $conversation->save();

        return $conversation;
    }

    private function addFeedback(RunningActivity $activity): void
    {
        (new Feedback(['source' => 'form', 'activity_id' => $activity->id, 'rpe' => 5, 'fatigue' => 2]))->user()->associate($this->athlete)->save();
    }
}
