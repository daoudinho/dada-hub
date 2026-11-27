<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\RunningActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class CoachStravaExportTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    private string $export;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
        $this->export = $this->makeExport();
    }

    protected function tearDown(): void
    {
        @unlink($this->export);

        parent::tearDown();
    }

    public function test_runs_are_imported_with_route_heart_rate_and_local_time_other_sports_are_skipped_and_a_rerun_duplicates_nothing(): void
    {
        $this->artisan('coach:import-strava-export', ['path' => $this->export, 'user' => $this->athlete->email])
            ->expectsOutputToContain('2 course(s) importée(s), 0 complétée(s) (déjà présentes), 0 mise(s) à jour ; 1 activité(s) ignorée(s)')
            ->assertSuccessful();

        $run = RunningActivity::ownedBy($this->athlete)->where('external_id', '111')->sole();
        $this->assertSame(['strava', 'Sortie du soir', 'Run', 2000, 600, 150, 165, 160], [
            $run->source, $run->name, $run->sport_type, $run->distance_m, $run->moving_seconds, $run->avg_hr, $run->max_hr, $run->avg_cadence,
        ]);
        $this->assertSame('2025-07-15 18:00:00', $run->started_at->toDateTimeString()); // 16:00 UTC, heure d'été à Paris
        $this->assertNotEmpty($run->polyline);
        $this->assertNotContains(null, $run->streams['heartrate']); // FC reprise sur les points du TCX qui n'en ont pas
        $this->assertCount(2, $run->laps);

        $virtual = RunningActivity::ownedBy($this->athlete)->where('external_id', '333')->sole();
        $this->assertSame(['VirtualRun', '2025-04-03 11:30:00', 5000], [$virtual->sport_type, $virtual->started_at->toDateTimeString(), $virtual->distance_m]);
        $this->assertNull($virtual->streams);
        $this->assertSame(0, Conversation::count());

        $this->artisan('coach:import-strava-export', ['path' => $this->export, 'user' => $this->athlete->id])
            ->expectsOutputToContain('0 course(s) importée(s), 0 complétée(s) (déjà présentes), 2 mise(s) à jour')
            ->assertSuccessful();
        $this->assertSame(2, RunningActivity::ownedBy($this->athlete)->count());
    }

    public function test_run_already_imported_from_garmin_at_the_same_time_is_completed_instead_of_duplicated(): void
    {
        $garmin = RunningActivity::factory()->for($this->athlete)->create([
            'source' => 'garmin', 'external_id' => 'g-1', 'name' => 'Footing Garmin', 'started_at' => '2025-07-15 18:01:00', 'avg_hr' => null,
        ]);

        $this->artisan('coach:import-strava-export', ['path' => $this->export, 'user' => $this->athlete->email])
            ->expectsOutputToContain('1 course(s) importée(s), 1 complétée(s)')
            ->assertSuccessful();

        $garmin->refresh();
        $this->assertSame(['garmin', 'Footing Garmin', 8000, 150], [$garmin->source, $garmin->name, $garmin->distance_m, $garmin->avg_hr]);
        $this->assertNotEmpty($garmin->polyline);
        $this->assertSame(2, RunningActivity::ownedBy($this->athlete)->count());
    }

    public function test_export_sent_from_the_profile_is_imported_in_the_background_then_deleted(): void
    {
        Storage::fake('local');

        $this->actingAs($this->athlete)
            ->post(route('coach.strava.export'), ['export' => new UploadedFile($this->export, 'export_1.zip', 'application/zip', null, true)])
            ->assertRedirect(route('coach.profile.edit').'#strava')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '2 courses'));

        $this->assertSame(2, RunningActivity::ownedBy($this->athlete)->where('source', 'strava')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->actingAs($this->athlete)->get(route('coach.profile.edit'))->assertSee('2 course(s) ajoutée(s)');
    }

    public function test_a_zip_that_is_not_a_strava_export_is_refused(): void
    {
        Storage::fake('local');
        $other = tempnam(sys_get_temp_dir(), 'other').'.zip';
        $zip = new ZipArchive;
        $zip->open($other, ZipArchive::CREATE);
        $zip->addFromString('photo.txt', 'pas un export');
        $zip->close();

        $this->actingAs($this->athlete)
            ->post(route('coach.strava.export'), ['export' => new UploadedFile($other, 'photos.zip', 'application/zip', null, true)])
            ->assertSessionHasErrors('export');

        $this->assertSame([], Storage::disk('local')->allFiles());
        @unlink($other);
    }

    /**
     * Export Strava en français : une course avec son TCX compressé (2 km vers le nord, un point toutes les 3 s, FC un point
     * sur deux), une randonnée et une course virtuelle sans fichier.
     */
    private function makeExport(): string
    {
        $row = function (array $values): array {
            $row = array_fill(0, 104, '');
            foreach ($values as $column => $value) {
                $row[$column] = $value;
            }

            return $row;
        };
        $rows = [
            $row([0 => 'ID de l\'activité', 1 => 'Date de l\'activité', 3 => 'Type d\'activité', 103 => 'Étiquette']),
            $row([0 => '333', 1 => '3 avr. 2025, 09:30:00', 2 => 'Tapis', 3 => 'Course à pied virtuelle', 15 => '1900.0', 16 => '1800.0', 17 => '5000.0', 103 => 'virtual|running']),
            $row([0 => '222', 1 => '10 juil. 2025, 08:00:00', 2 => 'Rando', 3 => 'Randonnée', 12 => 'activities/222.gpx', 17 => '8000.0', 103 => 'offroad']),
            $row([0 => '111', 1 => '15 juil. 2025, 16:00:00', 2 => 'Sortie du soir', 3 => 'Course à pied', 12 => 'activities/111.tcx.gz', 15 => '620.0',
                16 => '600.0', 17 => '2000.0', 20 => '12.0', 29 => '80.0', 30 => '165.0', 31 => '150.0', 103 => 'running']),
        ];
        $csv = fopen('php://memory', 'r+');
        foreach ($rows as $line) {
            fputcsv($csv, $line, escape: '');
        }
        rewind($csv);

        $trackpoints = '';
        foreach (range(0, 200) as $i) {
            $heartRate = $i % 2 === 0 ? '<HeartRateBpm><Value>'.(140 + intdiv($i, 10)).'</Value></HeartRateBpm>' : '';
            $trackpoints .= sprintf('<Trackpoint><Time>%s</Time><Position><LatitudeDegrees>%.6f</LatitudeDegrees><LongitudeDegrees>7.15</LongitudeDegrees></Position>%s<Cadence>80</Cadence></Trackpoint>',
                gmdate('Y-m-d\TH:i:s\Z', strtotime('2025-07-15 16:00:00 UTC') + 3 * $i), 43.65 + $i * 0.00009, $heartRate);
        }
        $tcx = '<?xml version="1.0" encoding="UTF-8"?><TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2"><Activities><Activity Sport="Run"><Lap><Track>'
            .$trackpoints.'</Track></Lap></Activity></Activities></TrainingCenterDatabase>';

        $path = tempnam(sys_get_temp_dir(), 'strava').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('activities.csv', stream_get_contents($csv));
        $zip->addFromString('activities/111.tcx.gz', gzencode($tcx));
        $zip->close();

        return $path;
    }
}
