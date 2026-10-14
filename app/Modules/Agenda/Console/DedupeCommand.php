<?php

namespace App\Modules\Agenda\Console;

use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Services\EventImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('agenda:dedupe {--dry-run : Affiche les doublons sans rien modifier}')]
#[Description('Recalcule les clés anti-doublon (titres normalisés) et fusionne les événements en double')]
class DedupeCommand extends Command
{
    public function handle(): int
    {
        $groups = Event::whereIn('source', ['scan', 'seed'])->get()
            ->groupBy(fn (Event $event) => EventImporter::dedupeKey($event->title, $event->starts_on->toDateString()));

        $duplicates = $groups->filter(fn (Collection $events) => $events->count() > 1);

        foreach ($duplicates as $events) {
            $this->line($events->first()->starts_on->toDateString().' : '.$events->pluck('title')->implode(' | '));
        }

        if ($this->option('dry-run')) {
            $this->info($duplicates->count().' doublon(s) trouvé(s), rien n\'a été modifié.');

            return self::SUCCESS;
        }

        $deleted = 0;
        DB::transaction(function () use ($groups, &$deleted) {
            // Libère d'abord les clés pour éviter les collisions sur l'index unique.
            Event::whereKey($groups->flatten()->pluck('id'))->update(['dedupe_key' => null]);

            foreach ($groups as $key => $events) {
                $keep = $events->sortByDesc(fn (Event $event) => $this->completeness($event))->first();
                $deleted += Event::whereKey($events->reject(fn (Event $event) => $event->is($keep))->pluck('id'))->delete();
                $keep->update(['dedupe_key' => $key]);
            }
        });

        $this->info($duplicates->count().' doublon(s) fusionné(s), '.$deleted.' événement(s) supprimé(s).');

        return self::SUCCESS;
    }

    /** On garde la version la plus renseignée (lien, heure, lieu, date de fin), puis la plus récente. */
    private function completeness(Event $event): string
    {
        $filled = collect([$event->url, $event->time, $event->place, $event->ends_on, $event->source_id])->filter()->count();

        return $filled.'-'.$event->updated_at?->timestamp;
    }
}
