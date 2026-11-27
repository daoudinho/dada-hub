<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Equipment;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Models\Workout;
use App\Modules\Coach\Services\AthleteContext;
use App\Modules\Coach\Services\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\FakesClaude;
use Tests\TestCase;

class CoachFitnessTest extends TestCase
{
    use FakesClaude, RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureClaude();
        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running', 'fitness']])->create();
    }

    public function test_fitness_pages_are_reserved_to_accounts_with_fitness(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();

        $this->actingAs($runner)->get('/coach/fitness')->assertForbidden();
        $this->actingAs($runner)->get('/coach')->assertDontSee(route('coach.fitness.index'));
        $this->actingAs($this->athlete)->get('/coach/fitness')->assertOk()->assertSee('Ta salle est encore vide');
    }

    public function test_dashboard_opens_with_a_fitness_block_showing_the_last_workout(): void
    {
        Workout::factory()->for($this->athlete)->create(['performed_on' => '2026-11-20', 'total_load_kg' => 4200]);
        Workout::factory()->for($this->athlete)->create(['performed_on' => '2026-11-24', 'total_load_kg' => 3150]);

        $this->actingAs($this->athlete)->get('/coach')
            ->assertSeeInOrder([route('coach.fitness.index'), 'Dernière séance mar. 24 nov. · 3 150 kg soulevés', 'goal-title'], false);
    }

    public function test_recorded_workout_shows_total_load_and_worked_muscles_in_history(): void
    {
        $press = $this->exercise('Presse à cuisses', ['muscles' => ['quads', 'glutes']]);
        $clamshell = $this->exercise('Clamshell', ['muscles' => ['glute_med'], 'unilateral' => true]);
        $plank = $this->exercise('Gainage', ['measure' => 'duration', 'muscles' => ['core']]);

        $this->actingAs($this->athlete)->post('/coach/fitness/seances', [
            'performed_on' => today()->toDateString(),
            'duration_minutes' => 50,
            'rpe' => 7,
            'entries' => [
                ['exercise_id' => $press->id, 'sets' => 3, 'reps' => 10, 'load_kg' => '100'],
                ['exercise_id' => $clamshell->id, 'sets' => 2, 'reps' => 15, 'load_kg' => '2,5'],
                ['exercise_id' => $plank->id, 'sets' => 2, 'duration_seconds' => 45],
                ['exercise_id' => '', 'sets' => 3, 'reps' => ''],
            ],
        ])->assertSessionHasNoErrors();

        // 3 × 10 × 100 + 2 × 15 × 2,5 × 2 côtés ; le gainage (sans charge) ne compte pas.
        $workout = Workout::ownedBy($this->athlete)->sole();
        $this->assertSame(3150, $workout->total_load_kg);
        $this->assertSame(7, $workout->sets()->count());

        $this->actingAs($this->athlete)->get('/coach/fitness')
            ->assertSeeInOrder(['3 150 kg', 'Abdos / gainage', 'Fessiers', 'Moyen fessier', 'Quadriceps']);
        $this->actingAs($this->athlete)->get(route('coach.fitness.workouts.show', $workout))
            ->assertSeeInOrder(['Presse à cuisses', '3 000 kg', 'Clamshell', '150 kg', 'Gainage', '45 s']);
    }

    public function test_machine_added_by_hand_can_carry_exercises_offered_in_the_workout_form(): void
    {
        $this->actingAs($this->athlete)->post('/coach/fitness/salle/machines', ['name' => 'Élastique'])->assertSessionHasNoErrors();
        $this->actingAs($this->athlete)->post('/coach/fitness/salle/machines', ['name' => 'élastique'])->assertSessionHasErrors('name');
        $band = Equipment::ownedBy($this->athlete)->sole();

        $this->actingAs($this->athlete)->post('/coach/fitness/salle/exercices', [
            'name' => 'Clamshell', 'equipment_id' => $band->id, 'measure' => 'reps', 'muscles' => ['glute_med'], 'unilateral' => '1',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->athlete)->get('/coach/fitness/salle')->assertOk()->assertSeeInOrder(['Élastique', 'Clamshell', 'Élastique · Moyen fessier · par côté'], false);
        $this->actingAs($this->athlete)->get('/coach/fitness/seances/nouvelle')->assertOk()->assertSee('Clamshell (par côté)');
    }

    public function test_workout_refuses_an_exercise_outside_the_catalogue_or_without_reps(): void
    {
        $foreign = Exercise::factory()->create();
        $press = $this->exercise('Presse à cuisses');

        $this->actingAs($this->athlete)->post('/coach/fitness/seances', [
            'performed_on' => today()->toDateString(),
            'entries' => [['exercise_id' => $foreign->id, 'sets' => 3, 'reps' => 10]],
        ])->assertSessionHasErrors('entries.0.exercise_id');

        $this->actingAs($this->athlete)->post('/coach/fitness/seances', [
            'performed_on' => today()->toDateString(),
            'entries' => [['exercise_id' => $press->id, 'sets' => 3, 'load_kg' => '100']],
        ])->assertSessionHasErrors('entries.0.reps');

        $this->assertDatabaseCount('coach_workouts', 0);
    }

    public function test_athlete_cannot_see_or_delete_another_athletes_workout(): void
    {
        $workout = Workout::factory()->create();

        $this->actingAs($this->athlete)->get(route('coach.fitness.workouts.show', $workout))->assertNotFound();
        $this->actingAs($this->athlete)->delete(route('coach.fitness.workouts.destroy', $workout))->assertNotFound();

        $this->assertModelExists($workout);
    }

    public function test_an_exercise_already_practised_stays_in_the_catalogue(): void
    {
        $press = $this->exercise('Presse à cuisses');
        $unused = $this->exercise('Fentes');
        $this->actingAs($this->athlete)->post('/coach/fitness/seances', [
            'performed_on' => today()->toDateString(),
            'entries' => [['exercise_id' => $press->id, 'sets' => 1, 'reps' => 10]],
        ]);

        $this->actingAs($this->athlete)->delete(route('coach.fitness.exercises.destroy', $press))->assertSessionHasErrors('exercise');
        $this->actingAs($this->athlete)->delete(route('coach.fitness.exercises.destroy', $unused))->assertSessionHasNoErrors();

        $this->assertModelExists($press);
        $this->assertModelMissing($unused);
    }

    public function test_coach_proposes_exercises_and_the_athlete_answers_each_one(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CLAUDE_URL => Http::sequence()
            ->push($this->claudeResponse([
                $this->toolUse('call_ex', 'propose_exercises', ['reason' => 'Pour renforcer tes jambes.', 'items' => [
                    ['name' => 'Presse à cuisses', 'equipment' => 'Presse à cuisses', 'measure' => 'load_reps', 'muscles' => ['quads', 'glutes']],
                    ['name' => 'Leg curl', 'equipment' => 'Machine leg curl', 'measure' => 'load_reps', 'muscles' => ['hamstrings']],
                ]]),
            ], 'tool_use'))
            ->push($this->claudeResponse([['type' => 'text', 'text' => 'Dis-moi ce que tu as dans ta salle.']])),
        ]);
        $conversation = Conversation::factory()->for($this->athlete)->create(['kind' => 'chat', 'status' => 'open']);

        $this->actingAs($this->athlete)->postJson(route('coach.chat.reply', $conversation), ['message' => 'Propose-moi des exercices'])
            ->assertOk()
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'Exercices proposés') && str_contains($html, 'Pas dans ma salle'));

        $request = Http::recorded()->first()[0];
        $this->assertStringContainsString('## Fitness (musculation en salle)', $request['system'][0]['text']);
        $this->assertContains('propose_exercises', array_column($request['tools'], 'name'));

        $proposal = Proposal::sole();
        $this->actingAs($this->athlete)->post(route('coach.proposals.exercise', [$proposal, 0]), ['answer' => 'have'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $proposal->fresh()->status);
        $this->actingAs($this->athlete)->post(route('coach.proposals.exercise', [$proposal, 1]), ['answer' => 'missing']);

        $this->assertSame('accepted', $proposal->fresh()->status);
        $press = Exercise::ownedBy($this->athlete)->sole();
        $this->assertSame(['Presse à cuisses', 'available'], [$press->name, $press->equipment->status]);
        $this->assertSame('missing', Equipment::ownedBy($this->athlete)->where('name', 'Machine leg curl')->value('status'));
        $this->actingAs($this->athlete)->post(route('coach.proposals.exercise', [$proposal, 1]), ['answer' => 'have'])->assertStatus(422);
    }

    public function test_coach_cannot_propose_a_missing_machine_or_a_known_exercise(): void
    {
        Equipment::factory()->for($this->athlete)->missing()->create(['name' => 'Smith machine']);
        $this->exercise('Presse à cuisses');
        $tools = app(CoachTools::class);
        $conversation = Conversation::factory()->for($this->athlete)->create();

        try {
            $tools->run($this->athlete, $conversation, 'propose_exercises', ['reason' => 'Jambes', 'items' => [
                ['name' => 'presse à cuisses', 'equipment' => 'Presse', 'measure' => 'load_reps', 'muscles' => ['quads']],
                ['name' => 'Squat guidé', 'equipment' => 'smith machine', 'measure' => 'load_reps', 'muscles' => ['quads']],
            ]]);
            $this->fail('La proposition aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('« presse à cuisses » est déjà dans son catalogue ; « smith machine » n\'est pas dans sa salle', $e->getMessage());
        }

        $this->assertDatabaseCount('coach_proposals', 0);
    }

    public function test_coach_records_a_workout_told_in_the_chat(): void
    {
        $press = $this->exercise('Presse à cuisses');
        $tools = app(CoachTools::class);

        $result = $tools->run($this->athlete, Conversation::factory()->for($this->athlete)->create(), 'record_workout', [
            'performed_on' => today()->toDateString(),
            'entries' => [['exercise_id' => $press->id, 'sets' => 3, 'reps' => 10, 'load_kg' => 100]],
        ]);

        $workout = Workout::ownedBy($this->athlete)->sole();
        $this->assertSame(['chat', 3000], [$workout->source, $workout->total_load_kg]);
        $this->assertStringContainsString('Presse à cuisses 3×10 à 100 kg', $result);
    }

    public function test_fitness_tools_are_reserved_to_accounts_with_fitness(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        $press = Exercise::factory()->for($runner)->create();

        $this->assertNotContains('record_workout', array_column(app(CoachTools::class)->definitions(), 'name'));
        $this->expectException(ValidationException::class);
        app(CoachTools::class)->run($runner, Conversation::factory()->for($runner)->create(), 'record_workout', [
            'performed_on' => today()->toDateString(),
            'entries' => [['exercise_id' => $press->id, 'reps' => 10]],
        ]);
    }

    public function test_coach_sees_the_gym_and_recent_workouts_in_the_athlete_file(): void
    {
        Equipment::factory()->for($this->athlete)->missing()->create(['name' => 'Smith machine']);
        $press = $this->exercise('Presse à cuisses', ['muscles' => ['quads', 'glutes']]);
        $this->actingAs($this->athlete)->put('/coach/profil', ['fitness_goal' => 'running', 'fitness_level' => 'beginner', 'fitness_sessions_per_week' => 2, 'fitness_session_minutes' => 45]);
        $this->actingAs($this->athlete)->post('/coach/fitness/seances', [
            'performed_on' => today()->toDateString(),
            'entries' => [['exercise_id' => $press->id, 'sets' => 3, 'reps' => 10, 'load_kg' => '100']],
        ]);

        $context = app(AthleteContext::class)->build($this->athlete, Conversation::factory()->for($this->athlete)->create());

        $this->assertStringContainsString('- Objectif : Renfo pour la course ; niveau : Débutant ; 2 séance(s) par semaine ; 45 min par séance', $context);
        $this->assertStringContainsString('Pas dans sa salle (ne pas proposer) : Smith machine', $context);
        $this->assertStringContainsString('[#'.$press->id.'] Presse à cuisses · poids du corps · charge × répétitions · Fessiers, Quadriceps', $context);
        $this->assertStringContainsString('3 000 kg : Presse à cuisses 3×10 à 100 kg', $context);
        $this->assertStringContainsString('Séries par muscle sur 7 jours : Fessiers 3, Quadriceps 3', $context);
    }

    public function test_profile_update_keeps_fitness_settings_when_fitness_is_closed(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        $profile = (new Profile(['fitness_goal' => 'strength']))->user()->associate($runner);
        $profile->save();

        $this->actingAs($runner)->put('/coach/profil', ['height_cm' => 180])->assertSessionHasNoErrors();

        $this->assertSame(['strength', 180], [$profile->fresh()->fitness_goal, $profile->fresh()->height_cm]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function exercise(string $name, array $attributes = []): Exercise
    {
        return Exercise::factory()->for($this->athlete)->create(['name' => $name] + $attributes);
    }
}
