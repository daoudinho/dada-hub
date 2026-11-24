<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Jobs\ImportStravaActivityJob;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\Injury;
use App\Modules\Coach\Models\PastRace;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningPlan;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Models\Unavailability;
use App\Modules\Coach\Services\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\FakesClaude;
use Tests\TestCase;

class CoachChatTest extends TestCase
{
    use FakesClaude, RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureClaude();
        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create(['name' => 'Dada Test']);
    }

    public function test_coach_opens_onboarding_sets_the_goal_and_proposes_a_plan(): void
    {
        $this->travelTo('2026-11-16 09:00');
        Http::preventStrayRequests();
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
                $this->toolUse('call_goal', 'set_goal', ['title' => 'Semi de Paris en 1h50', 'event_name' => 'le semi-marathon de Paris', 'event_date' => '2027-03-07', 'target_time' => '1:50:00']),
                $this->toolUse('call_plan', 'propose_plan', ['feasibility' => 'realistic', 'summary' => '16 semaines, 3 sorties par semaine.', 'sessions' => [
                    ['date' => '2026-11-17', 'type' => 'easy', 'title' => 'Footing en aisance', 'duration_minutes' => 40],
                    ['date' => '2026-11-19', 'type' => 'intervals', 'title' => '8 × 400 m', 'duration_minutes' => 50],
                ]]),
            ], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Voici **ton programme** de départ.']])),
        ]);
        $conversation = $this->startConversation();

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $conversation))
            ->assertOk()
            ->assertJsonPath('html', fn (string $html) => str_contains($html, '<strong>ton programme</strong>') && str_contains($html, 'Programme proposé'));

        $goal = Goal::ownedBy($this->athlete)->sole();
        $this->assertSame(['Semi de Paris en 1h50', 6600, '2027-03-07'], [$goal->title, $goal->target_seconds, $goal->event_date->toDateString()]);
        $proposal = Proposal::sole();
        $this->assertSame(['plan', 'pending', 2], [$proposal->kind, $proposal->status, count($proposal->payload['sessions'])]);
        $this->assertDatabaseCount('coach_running_sessions', 0);

        Http::assertSentCount(2);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]);
        [$first, $second] = [$requests[0], $requests[1]];
        $this->assertSame('claude-opus-5-5', $first['model']);
        $this->assertSame(['user', 'system'], array_column($first['messages'], 'role'));
        $this->assertStringContainsString('Fiche athlète à jour', $first['messages'][1]['content']);
        $this->assertStringContainsString('Prénom : Dada', $first['messages'][1]['content']);
        $this->assertContains('propose_plan', array_column($first['tools'], 'name'));

        // Second appel : historique complet, réflexion renvoyée telle quelle, résultats des deux outils dans un seul message.
        $this->assertSame(['user', 'system', 'assistant', 'user'], array_column($second['messages'], 'role'));
        $this->assertSame(['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'], $second['messages'][2]['content'][0]);
        $results = $second['messages'][3]['content'];
        $this->assertSame(['call_goal', 'call_plan'], array_column($results, 'tool_use_id'));
        $this->assertSame([false, false], array_column($results, 'is_error'));
    }

    public function test_accepting_a_plan_replaces_the_upcoming_sessions(): void
    {
        $this->travelTo('2026-11-16 09:00');
        $old = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-20', 'title' => 'Ancienne séance']);
        $done = RunningSession::factory()->for($this->athlete)->done()->create(['scheduled_on' => '2026-11-14']);
        $proposal = $this->proposal('plan', ['feasibility' => 'ambitious', 'summary' => 'Bloc de 12 semaines', 'sessions' => [
            ['date' => '2026-11-18', 'type' => 'tempo', 'title' => '3 × 10 min au seuil', 'duration_minutes' => 55, 'pace_from' => 310, 'pace_to' => 320],
        ]]);

        $this->actingAs($this->athlete)->post(route('coach.proposals.accept', $proposal))->assertRedirect(route('coach.chat.show', $proposal->conversation_id));

        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertModelMissing($old);
        $this->assertModelExists($done);
        $plan = RunningPlan::ownedBy($this->athlete)->sole();
        $session = RunningSession::ownedBy($this->athlete)->where('plan_id', $plan->id)->sole();
        $this->assertSame(['3 × 10 min au seuil', 'tempo', '55 min · 5:10-5:20 /km', '2026-11-18'], [$session->title, $session->type, $session->targets(), $session->scheduled_on->toDateString()]);
    }

    public function test_accepting_an_adjustment_adds_updates_and_removes_sessions(): void
    {
        $this->travelTo('2026-11-16 09:00');
        $toUpdate = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-18', 'duration_minutes' => 60]);
        $toRemove = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-19']);
        $proposal = $this->proposal('adjustment', [
            'add' => [['date' => '2026-11-21', 'type' => 'test', 'title' => 'Demi-Cooper']],
            'update' => [['id' => $toUpdate->id, 'duration_minutes' => 40]],
            'remove' => [$toRemove->id],
        ]);

        $this->actingAs($this->athlete)->post(route('coach.proposals.accept', $proposal));

        $this->assertSame(40, $toUpdate->fresh()->duration_minutes);
        $this->assertModelMissing($toRemove);
        $this->assertSame('planned', RunningSession::where('title', 'Demi-Cooper')->sole()->status);
    }

    public function test_refusing_a_proposal_leaves_the_calendar_untouched(): void
    {
        $session = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => today()->addDay()]);
        $proposal = $this->proposal('adjustment', ['add' => [], 'update' => [], 'remove' => [$session->id]]);

        $this->actingAs($this->athlete)->post(route('coach.proposals.refuse', $proposal));

        $this->assertSame('refused', $proposal->fresh()->status);
        $this->assertModelExists($session);
        $this->actingAs($this->athlete)->post(route('coach.proposals.accept', $proposal))->assertStatus(422);
    }

    public function test_athlete_cannot_answer_another_athletes_proposal(): void
    {
        $other = User::factory()->withModules('coach')->create();
        $proposal = $this->proposal('adjustment', ['add' => [], 'update' => [], 'remove' => []], $other);

        $this->actingAs($this->athlete)->post(route('coach.proposals.accept', $proposal))->assertNotFound();

        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_coach_cannot_target_sessions_of_another_athlete(): void
    {
        Http::preventStrayRequests();
        $foreign = RunningSession::factory()->create(['scheduled_on' => today()->addDay()]);
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([$this->toolUse('call_adj', 'propose_adjustment', ['reason' => 'Alléger', 'remove' => [$foreign->id]])], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Je reprends.']])),
        ]);
        $conversation = $this->startConversation();

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $conversation), ['message' => 'Allège ma semaine'])->assertOk();

        $this->assertDatabaseCount('coach_proposals', 0);
        $result = Http::recorded()->last()[0]['messages'][3]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('#'.$foreign->id, $result['content']);
    }

    public function test_plan_outside_the_athletes_availability_is_sent_back_to_the_coach(): void
    {
        $this->travelTo('2026-11-16 09:00'); // lundi
        Http::preventStrayRequests();
        $profile = new Profile(['availability' => [
            'tue' => ['available' => true, 'max_minutes' => 45], 'sat' => ['available' => true, 'max_minutes' => 120],
        ]]);
        $profile->user()->associate($this->athlete)->save();
        $holidays = new Unavailability(['starts_on' => '2026-11-28', 'ends_on' => '2026-11-29', 'kind' => 'none', 'reason' => 'Mariage']);
        $holidays->user()->associate($this->athlete)->save();
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([$this->toolUse('call_plan', 'propose_plan', ['feasibility' => 'realistic', 'summary' => 'Test', 'sessions' => [
                ['date' => '2026-11-17', 'type' => 'easy', 'title' => 'Footing', 'duration_minutes' => 40],
                ['date' => '2026-11-18', 'type' => 'intervals', 'title' => 'VMA du mercredi', 'duration_minutes' => 50],
                ['date' => '2026-11-24', 'type' => 'tempo', 'title' => 'Seuil long', 'duration_minutes' => 70],
                ['date' => '2026-11-28', 'type' => 'long', 'title' => 'Sortie longue', 'duration_minutes' => 90],
            ]])], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Je corrige.']])),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $this->startConversation()), ['message' => 'Programme ?'])->assertOk();

        $this->assertDatabaseCount('coach_proposals', 0);
        $result = Http::recorded()->last()[0]['messages'][3]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('VMA du mercredi du mer. 18/11 : l\'athlète ne court pas le mercredi', $result['content']);
        $this->assertStringContainsString('70 min prévues, 45 min au plus', $result['content']);
        $this->assertStringContainsString('indisponible du 28/11 au 29/11', $result['content']);
        $this->assertStringNotContainsString('Footing du', $result['content']);
    }

    public function test_coach_completes_the_athletes_background_from_the_conversation(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([$this->toolUse('call_facts', 'update_athlete_facts', [
                'running_since' => 2019,
                'long_run_day' => 'sun',
                'availability' => [['day' => 'sun', 'available' => true, 'max_minutes' => 120, 'slot' => 'morning']],
                'past_races' => [['date' => '2026-05-10', 'name' => '10 km de Nantes', 'distance_km' => 10, 'time' => '48:30']],
                'injuries' => [['zone' => 'genou droit', 'period' => '2024', 'recurrent' => true]],
            ])], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Noté !']])),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $this->startConversation()), ['message' => 'Je cours depuis 2019…'])->assertOk();

        $profile = Profile::ownedBy($this->athlete)->sole();
        $this->assertSame([2019, 'sun'], [$profile->running_since, $profile->long_run_day]);
        $this->assertSame(['available' => true, 'max_minutes' => 120, 'slot' => 'morning'], $profile->dayAvailability('sun'));
        $this->assertFalse($profile->dayAvailability('mon')['available']);
        $this->assertSame(2910, PastRace::ownedBy($this->athlete)->sole()->time_seconds);
        $this->assertTrue(Injury::ownedBy($this->athlete)->sole()->recurrent);
        $this->assertFalse(Http::recorded()->last()[0]['messages'][3]['content'][0]['is_error']);
    }

    public function test_coach_fetches_a_past_race_from_strava_without_analysing_it(): void
    {
        $this->travelTo('2026-11-18 10:00');
        Http::preventStrayRequests();
        $this->stravaAccount();
        Http::fake([
            self::CLAUDE_URL => Http::sequence()
                ->push($this->claudeResponse([$this->toolUse('call_fetch', 'fetch_strava_activities', ['from' => '2025-01-09', 'to' => '2025-01-09'])], 'tool_use'))
                ->push($this->claudeResponse([['type' => 'text', 'text' => 'C\'est récupéré.']])),
            'https://www.strava.com/api/v3/athlete/activities*' => Http::response([
                ['id' => 11, 'sport_type' => 'Run', 'start_date_local' => '2025-01-09T09:30:00Z', 'distance' => 21100],
                ['id' => 12, 'sport_type' => 'Run', 'start_date_local' => '2025-01-10T08:00:00Z', 'distance' => 5000], // lendemain (marge de fuseau)
                ['id' => 13, 'sport_type' => 'Ride', 'start_date_local' => '2025-01-09T15:00:00Z', 'distance' => 40000],
            ]),
            'https://www.strava.com/api/v3/activities/11/streams*' => Http::response([]),
            'https://www.strava.com/api/v3/activities/11*' => Http::response([
                'id' => 11, 'name' => 'Semi-marathon', 'sport_type' => 'Run', 'start_date_local' => '2025-01-09T09:30:00Z',
                'distance' => 21100.0, 'moving_time' => 6300, 'average_heartrate' => 168.0,
            ]),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $this->startConversation()), ['message' => 'Récupère mon semi du 9 janvier 2025'])->assertOk();

        $run = RunningActivity::ownedBy($this->athlete)->sole();
        $this->assertSame(['11', 'Semi-marathon', 21100], [$run->external_id, $run->name, $run->distance_m]);
        $this->assertDatabaseCount('coach_conversations', 1); // pas de débrief pour une course ancienne
        $result = Http::recorded()->last()[0]['messages'][3]['content'][0]['content'];
        $this->assertStringContainsString('1 course(s) importée(s)', $result);
        $this->assertStringContainsString('[#'.$run->id.'] 09/01/2025 « Semi-marathon » · 21,10 km en 1h45:00', $result);
        $this->assertStringNotContainsString('FC', $result); // pas d'analyse à ce stade
    }

    public function test_fetching_a_whole_year_imports_in_the_background(): void
    {
        $this->travelTo('2026-11-18 10:00');
        Http::preventStrayRequests();
        Queue::fake([ImportStravaActivityJob::class]);
        $this->stravaAccount();
        RunningActivity::factory()->for($this->athlete)->create(['source' => 'strava', 'external_id' => '1']); // déjà importée
        $runs = array_map(fn (int $id) => ['id' => $id, 'sport_type' => 'Run', 'start_date_local' => '2025-03-0'.min(9, $id).'T08:00:00Z', 'distance' => 8000], range(1, 8));
        Http::fake([
            self::CLAUDE_URL => Http::sequence()
                ->push($this->claudeResponse([$this->toolUse('call_fetch', 'fetch_strava_activities', ['from' => '2025-01-01', 'to' => '2025-12-31'])], 'tool_use'))
                ->push($this->claudeResponse([['type' => 'text', 'text' => 'Import lancé.']])),
            'https://www.strava.com/api/v3/athlete/activities*' => Http::response($runs),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $this->startConversation()), ['message' => 'Récupère toutes mes courses de 2025'])->assertOk();

        Queue::assertPushed(ImportStravaActivityJob::class, 7);
        Queue::assertNotPushed(ImportStravaActivityJob::class, fn (ImportStravaActivityJob $job) => $job->stravaId === 1 || $job->notify);
        $result = Http::recorded()->last()[0]['messages'][3]['content'][0]['content'];
        $this->assertStringContainsString('7 courses trouvées', $result);
        $this->assertStringContainsString('1 déjà présente(s)', $result);
    }

    public function test_strava_refusal_is_explained_by_the_coach_instead_of_breaking_the_chat(): void
    {
        Http::preventStrayRequests();
        $this->stravaAccount();
        Http::fake([
            self::CLAUDE_URL => Http::sequence()
                ->push($this->claudeResponse([$this->toolUse('call_fetch', 'fetch_strava_activities', ['from' => '2025-01-09', 'to' => '2025-01-09'])], 'tool_use'))
                ->push($this->claudeResponse([['type' => 'text', 'text' => 'Je n\'ai pas pu récupérer ta course.']])),
            'https://www.strava.com/api/v3/athlete/activities*' => Http::response(
                ['message' => 'Forbidden', 'errors' => [['resource' => 'Application', 'field' => 'Status', 'code' => 'Inactive']]], 403),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $this->startConversation()), ['message' => 'Récupère mon semi'])
            ->assertOk()
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'pas pu récupérer ta course'));

        $result = Http::recorded()->last()[0]['messages'][3]['content'][0]['content'];
        $this->assertStringContainsString('l\'application Strava du site est inactive', $result);
        $this->assertStringContainsString('HTTP 403 sur /athlete/activities — Forbidden (Application Status Inactive)', $result);
    }

    public function test_an_unexpected_tool_failure_does_not_break_the_chat(): void
    {
        Http::preventStrayRequests();
        $this->stravaAccount();
        Http::fake([
            self::CLAUDE_URL => Http::sequence()
                ->push($this->claudeResponse([$this->toolUse('call_fetch', 'fetch_strava_activities', ['from' => '2025-01-09', 'to' => '2025-01-09'])], 'tool_use'))
                ->push($this->claudeResponse([['type' => 'text', 'text' => 'Petit souci technique.']])),
            'https://www.strava.com/api/v3/athlete/activities*' => fn () => throw new \RuntimeException('panne inattendue'),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $this->startConversation()), ['message' => 'Récupère mon semi'])->assertOk();

        $result = Http::recorded()->last()[0]['messages'][3]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('panne inattendue', $result['content']);
    }

    public function test_coach_reads_the_details_of_a_stored_run_only_for_its_owner(): void
    {
        Http::preventStrayRequests();
        $mine = RunningActivity::factory()->for($this->athlete)->create(['name' => 'Semi-marathon', 'analysis' => ['drift_percent' => 3.1]]);
        $foreign = RunningActivity::factory()->create(['name' => 'Course d\'un autre']);
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([
                $this->toolUse('call_a', 'get_activity_details', ['activity_id' => $mine->id]),
                $this->toolUse('call_b', 'get_activity_details', ['activity_id' => $foreign->id]),
            ], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Voici mon analyse.']])),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $this->startConversation()), ['message' => 'Analyse mon semi'])->assertOk();

        [$details, $refused] = array_column(Http::recorded()->last()[0]['messages'][3]['content'], 'content');
        $this->assertStringContainsString('« Semi-marathon »', $details);
        $this->assertStringContainsString('Dérive cardiaque 3,1 %', $details);
        $this->assertStringContainsString('introuvable', $refused);
        $this->assertStringNotContainsString('Course d\'un autre', $refused);
    }

    public function test_debrief_feedback_is_recorded_and_closes_the_pending_debrief(): void
    {
        Http::preventStrayRequests();
        $activity = RunningActivity::factory()->for($this->athlete)->create(['rpe' => null]);
        $conversation = $this->startConversation('debrief', $activity);
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([$this->toolUse('call_fb', 'record_feedback', [
                'summary' => 'Bonnes jambes, mollet un peu tendu.', 'rpe' => 6, 'mood' => 4, 'pains' => [['zone' => 'mollet gauche', 'intensity' => 2]],
            ])], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Merci, c\'est noté.']])),
        ]);

        // La page affiche déjà le message envoyé : la réponse ne renvoie que celui du coach (sinon il apparaît en double).
        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $conversation), ['message' => 'Ça s\'est bien passé'])
            ->assertOk()
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'est noté') && ! str_contains($html, 'bien passé'));

        $feedback = Feedback::sole();
        $this->assertSame([$activity->id, 6, 'mollet gauche'], [$feedback->activity_id, $feedback->rpe, $feedback->pains[0]['zone']]);
        $this->assertSame(6, $activity->fresh()->rpe);
        $this->assertSame('closed', $conversation->fresh()->status);
        $this->assertStringContainsString('Débrief de la course', Http::recorded()->first()[0]['messages'][1]['content']);
    }

    public function test_coach_writes_the_session_review_shown_on_the_run_page(): void
    {
        Http::preventStrayRequests();
        $activity = RunningActivity::factory()->for($this->athlete)->create();
        $conversation = $this->startConversation('debrief', $activity);
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([$this->toolUse('call_review', 'write_session_review', [
                'verdict' => 'partial', 'summary' => 'Fractions tenues, mais la dernière a coincé après une nuit courte.',
                'strengths' => ['Récupérations bien respectées'], 'watchpoints' => ['Dernière fraction 12 s trop lente'],
                'next' => 'On garde la séance de jeudi, en footing plus court.',
            ])], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Bilan noté sur ta fiche.']])),
        ]);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $conversation), ['message' => 'Dur à la fin'])->assertOk();

        $this->assertSame('partial', $activity->fresh()->review['verdict']);
        $this->actingAs($this->athlete)->get(route('coach.running.activities.show', $activity))
            ->assertOk()
            ->assertSee('Objectif en partie atteint')
            ->assertSee('Dernière fraction 12 s trop lente')
            ->assertSee('On garde la séance de jeudi, en footing plus court.');
    }

    public function test_session_review_needs_a_debriefed_run(): void
    {
        $tools = app(CoachTools::class);
        $conversation = $this->startConversation();

        $result = $tools->run($this->athlete, $conversation, 'write_session_review', ['verdict' => 'reached', 'summary' => 'Bien.', 'next' => 'Rien.']);

        $this->assertStringContainsString('Pas de course liée', $result);
        $this->assertNull(RunningActivity::whereNotNull('review')->first());
    }

    public function test_failed_reply_returns_an_error_and_can_be_retried_without_duplicating_the_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push(['error' => ['message' => 'invalid request']], 400)
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Me revoilà !']])),
        ]);
        $conversation = $this->startConversation();

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $conversation), ['message' => 'Bonjour coach'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'Le coach n\'a pas pu répondre. Réessaie dans un instant.');

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $conversation))
            ->assertOk()
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'Me revoilà'));

        // La fiche restée sans réponse est écartée : un message système doit être suivi d'une réponse du coach.
        $retry = Http::recorded()->last()[0];
        $this->assertSame(['user', 'system'], array_column($retry['messages'], 'role'));
        $this->assertSame('Bonjour coach', $retry['messages'][0]['content'][0]['text']);
    }

    private function stravaAccount(): StravaAccount
    {
        $account = new StravaAccount(['athlete_id' => 4242, 'access_token' => 'acces', 'refresh_token' => 'renouv', 'expires_at' => now()->addHours(5)]);
        $account->user()->associate($this->athlete)->save();

        return $account;
    }

    private function startConversation(string $kind = 'onboarding', ?RunningActivity $activity = null): Conversation
    {
        return Conversation::factory()->for($this->athlete)->create([
            'kind' => $kind,
            'title' => 'Test',
            'status' => $kind === 'debrief' ? 'pending' : 'open',
            'activity_id' => $activity?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function proposal(string $kind, array $payload, ?User $owner = null): Proposal
    {
        $owner ??= $this->athlete;
        $proposal = new Proposal([
            'conversation_id' => Conversation::factory()->for($owner)->create()->id,
            'kind' => $kind,
            'reason' => 'Test',
            'payload' => $payload,
            'status' => 'pending',
        ]);
        $proposal->user()->associate($owner)->save();

        return $proposal;
    }
}
