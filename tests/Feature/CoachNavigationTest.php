<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\RunningRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_opens_sub_modules_for_each_account(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->withModules('coach')->create();

        $this->actingAs($admin)->get('/admin/acces')->assertOk()->assertSee('Running')->assertSee('Fitness');
        $this->actingAs($admin)->put("/admin/acces/{$member->id}", [
            'role' => 'member', 'modules' => ['coach'], 'features' => ['coach' => ['running', 'inconnu'], 'recettes' => ['running']],
        ])->assertSessionHasNoErrors();

        $member = $member->fresh();
        $this->assertSame(['running'], $member->moduleFeatures('coach'));
        $this->assertSame([], $member->moduleFeatures('recettes'));
        $this->assertFalse($member->hasModuleFeature('coach', 'fitness'));
    }

    public function test_next_sessions_open_their_detail_in_the_calendar(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        $session = RunningSession::factory()->for($runner)->create(['scheduled_on' => today()->addDays(2), 'title' => '10 × 400 m']);
        $link = route('coach.running.calendar', ['seance' => $session->id]);

        $this->actingAs($runner)->get('/coach')->assertSee($link, false);
        $this->actingAs($runner)->get($link)->assertSee('id="session-'.$session->id.'"', false);
    }

    public function test_home_shows_only_the_blocks_of_the_open_sub_modules(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        $fitness = User::factory()->withModules('coach')->withFeatures(['coach' => ['fitness']])->create();

        $this->actingAs($runner)->get('/coach')->assertOk()
            ->assertSee('Prochaines séances')->assertSee('Kilomètres par semaine')->assertDontSee('Ajoute ta première pesée');
        $this->actingAs($fitness)->get('/coach')->assertOk()
            ->assertSee('Ajoute ta première pesée')->assertDontSee('Prochaines séances')->assertDontSee('Kilomètres par semaine');
    }

    public function test_new_athlete_is_guided_through_profile_watch_then_coach(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();

        $this->actingAs($runner)->get('/coach')
            ->assertSee('Bienvenue')
            ->assertSee('Il manque : sexe, date de naissance, taille, disponibilités.')
            ->assertSee('Facultatif, mais le coach en saura beaucoup plus.')
            ->assertSee('le coach en saura moins sur toi');

        (new Profile(['sex' => 'female', 'birth_date' => '1995-04-02', 'height_cm' => 165, 'availability' => ['tue' => ['available' => true, 'max_minutes' => 60, 'slot' => null]]]))
            ->user()->associate($runner)->save();
        $account = (new GarminAccount(['api_key' => 'key', 'backfilled_at' => now(), 'history_total' => 3]))->user()->associate($runner);
        $account->save();
        RunningActivity::factory()->for($runner)->create(['source' => 'garmin', 'external_id' => '1']);

        $this->actingAs($runner)->get('/coach')
            ->assertSee('Import de ton historique : 1 / 3 courses')
            ->assertSee('le coach en saura moins sur toi');

        RunningActivity::factory()->for($runner)->count(2)->sequence(['external_id' => '2'], ['external_id' => '3'])->create(['source' => 'garmin']);

        $this->actingAs($runner)->get('/coach')
            ->assertSee('Historique importé : 3 courses sur 2 ans.')
            ->assertDontSee('le coach en saura moins sur toi');

        Conversation::factory()->for($runner)->create(['kind' => 'onboarding']);
        $this->actingAs($runner)->get('/coach')->assertDontSee('Bienvenue');
    }

    public function test_home_goal_block_has_no_shortcut_buttons_anymore(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        Goal::factory()->for($runner)->create();

        $this->actingAs($runner)->get('/coach')->assertSee('Plus que')->assertDontSee('Voir mon programme')->assertDontSee('Ajouter une course');
    }

    public function test_exploits_list_only_existing_records_with_their_run(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        $long = RunningActivity::factory()->for($runner)->create(['name' => 'Sortie longue', 'distance_m' => 18000, 'moving_seconds' => 6300]);
        $fast = RunningActivity::factory()->for($runner)->create(['name' => 'Fractionné', 'analysis' => ['top_speed' => 5.5]]);
        $slower = RunningActivity::factory()->for($runner)->create(['name' => 'Footing', 'analysis' => ['top_speed' => 4.0]]);
        foreach ([[$fast, '5K', 5000, 1500], [$long, '5K', 5000, 1620], [$long, '10K', 10000, 3400]] as [$run, $name, $meters, $seconds]) {
            (new BestEffort(['activity_id' => $run->id, 'name' => $name, 'distance_m' => $meters, 'elapsed_seconds' => $seconds, 'achieved_on' => today()]))
                ->user()->associate($runner)->save();
        }
        $this->assertModelExists($slower);

        $this->actingAs($runner)->get('/coach/exploits')->assertOk()
            ->assertSeeInOrder(['Plus longue course', '18,00 km', 'Sortie longue', 'Meilleur 5 kilomètres', '25:00', 'Fractionné', 'Meilleur 10 kilomètres', '56:40', 'Vitesse de pointe', '19,8 km/h'])
            ->assertDontSee('Meilleur semi-marathon')
            ->assertDontSee('Meilleur kilomètre');
        $this->actingAs($runner)->get('/coach')->assertSee('Exploits')->assertSee('19,8 km/h');
    }

    public function test_versus_compares_each_record_with_the_other_runner_with_the_winner_and_the_gap(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create(['name' => 'Daoud']);
        $rival = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create(['name' => 'Sarah']);
        User::factory()->withModules('coach')->withFeatures(['coach' => ['fitness']])->create(['name' => 'Karim']);
        $mine = RunningActivity::factory()->for($runner)->create(['started_at' => '2026-01-05 08:00', 'distance_m' => 18000]);
        $theirs = RunningActivity::factory()->for($rival)->create(['started_at' => '2026-01-05 08:00', 'distance_m' => 12000, 'analysis' => ['top_speed' => 5.0]]);
        foreach ([[$mine, 1500], [$theirs, 1451]] as [$run, $seconds]) {
            (new BestEffort(['activity_id' => $run->id, 'name' => '5K', 'distance_m' => 5000, 'elapsed_seconds' => $seconds, 'achieved_on' => today()]))
                ->user()->associate($run->user_id)->save();
        }

        $this->actingAs($runner)->get('/coach/exploits')->assertOk()
            ->assertSeeInOrder(['Versus', 'Toi', '2 – 2', 'Sarah',
                'Plus longue course', 'Tu gagnes de 6,00 km', '18,00 km', '12,00 km',
                'Semaine la plus prolifique', 'Tu gagnes de 6,0 km',
                'Meilleur 5 kilomètres', 'Sarah gagne de 0:49', '25:00', '24:11',
                'Vitesse de pointe', 'Pas encore de record pour toi', '—', '18,0 km/h'])
            ->assertDontSee('Karim');
    }

    public function test_most_prolific_week_shows_its_total_distance_and_sessions(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        foreach (['2026-01-05 08:00' => 10000, '2026-01-07 08:00' => 8000, '2026-01-11 09:00' => 14500, '2026-01-13 08:00' => 12000] as $date => $meters) {
            RunningActivity::factory()->for($runner)->create(['started_at' => $date, 'distance_m' => $meters]);
        }

        $this->actingAs($runner)->get('/coach/exploits')
            ->assertSeeInOrder(['Semaine la plus prolifique', '32,5 km', 'Du lundi 5 janvier au dimanche 11 janvier 2026', 'Distance totale', '32,5 km', 'Séances', '3']);
        $this->actingAs($runner)->get('/coach')->assertSee('Semaine du 5 janv. 2026')->assertSee('3 séances');
    }

    public function test_a_run_that_makes_its_week_the_most_prolific_is_a_new_record(): void
    {
        $this->travelTo('2026-11-20 19:00');
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        RunningActivity::factory()->for($runner)->create(['started_at' => '2026-11-03 08:00', 'distance_m' => 20000]);
        RunningActivity::factory()->for($runner)->create(['started_at' => '2026-11-17 08:00', 'distance_m' => 15000]);
        $new = RunningActivity::factory()->for($runner)->create(['started_at' => '2026-11-20 18:00', 'distance_m' => 8000]);

        $records = app(RunningRecords::class)->markNewRecords($new);

        $this->assertSame(['best_week' => ['23,0 km', '20,0 km']], collect($records)->mapWithKeys(fn ($r) => [$r['key'] => [$r['value'], $r['previous']]])->all());
    }

    public function test_history_shows_one_tile_per_run_with_its_route(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        RunningActivity::factory()->for($runner)->create(['name' => 'Tour du parc', 'sport_type' => 'trail_running', 'polyline' => [[48.85, 2.35], [48.86, 2.36], [48.85, 2.37]]]);

        $this->actingAs($runner)->get('/coach/running/courses')->assertOk()
            ->assertSee('Tour du parc')->assertSee('Trail')->assertSee('class="tile-route"', false);
    }

    public function test_calendar_only_shows_the_programme(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        RunningSession::factory()->for($runner)->create(['scheduled_on' => today()->addDay(), 'title' => 'Séance du programme']);
        RunningActivity::factory()->for($runner)->create(['name' => 'Course libre du jour', 'started_at' => now()->subHour()]);

        $this->actingAs($runner)->get('/coach/running/calendrier')->assertOk()
            ->assertSee('Séance du programme')->assertDontSee('Course libre du jour')->assertDontSee('Semaines précédentes');
    }

    public function test_header_offers_the_garmin_sync_only_with_a_key(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();

        $this->actingAs($runner)->get('/coach/profil')->assertSee('Dada Coach')->assertDontSee('Synchroniser avec Garmin')
            ->assertSee('href="'.route('coach.index').'" title="Accueil du coach"', false)
            ->assertSee('href="'.route('dashboard').'" title="Accueil du Hub"', false);

        (new GarminAccount(['api_key' => 'gk_live_0123456789abcdef']))->user()->associate($runner)->save();
        $this->actingAs($runner)->get('/coach')->assertSee('Synchroniser avec Garmin')->assertSee(route('coach.garmin.sync'), false);
    }

    public function test_tabs_lead_to_every_section(): void
    {
        $runner = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();

        $response = $this->actingAs($runner)->get('/coach');
        foreach (['coach.index', 'coach.chat.index', 'coach.running.calendar', 'coach.running.activities.index', 'coach.exploits', 'coach.profile.edit'] as $route) {
            $response->assertSee(route($route), false);
            $this->actingAs($runner)->get(route($route))->assertOk();
        }
    }
}
