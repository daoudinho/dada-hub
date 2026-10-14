<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Models\Source;

/**
 * Prompts envoyés à l'IA.
 */
class Prompts
{
    /**
     * Extraction depuis le contenu d'une page agenda (mode « sources »).
     *
     * @param  array<int, string>  $categories
     */
    public function forPage(Source $source, string $markdown, array $categories): string
    {
        [$today, $until] = $this->window();
        $guide = Categories::guide($categories);

        return <<<TXT
Voici le contenu de la page de programmation « {$source->name} » ({$source->url}), récupéré le {$today}.
Extrais les événements à Barcelone ayant lieu entre le {$today} et le {$until}.

Réponds en JSON : {"events": [{"title":"...","start":"YYYY-MM-DD","end":"YYYY-MM-DD ou null","time":"HH:MM ou null","place":"lieu court ou null","url":"https://... ou null","category":"..."}]}

Règles :
- Uniquement des événements présents dans la page, avec une date lisible dans la page. N'invente rien. Aucun événement : {"events": []}.
- category doit être exactement l'une de ces valeurs (avec leur sens) ; ignore les événements qui n'entrent dans aucune :
{$guide}
- Événement sur plusieurs jours (expo, festival) : start = date d'ouverture (ou {$today} si déjà ouvert), end = date de fin. Sinon end = null.
- Plusieurs représentations d'un même spectacle : un seul objet avec start = première date et end = dernière date.
- url : lien de l'événement tel qu'il apparaît dans la page, sinon null.
- place : salle ou lieu si indiqué, sinon null.
- Titres dans la langue de la page, sans le nom du lieu.

Contenu de la page :
---
{$markdown}
TXT;
    }

    /**
     * Recherche web libre (mode « web »).
     *
     * @param  array<int, string>  $filters
     * @param  array<int, string>  $categories
     * @param  iterable<Source>  $sources  lieux à consulter en priorité
     */
    public function forWebSearch(array $filters, array $categories, iterable $sources): string
    {
        [$today, $until] = $this->window();
        $labels = implode(', ', $filters);
        $guide = Categories::guide($categories);
        $venues = collect($sources)->map(fn (Source $s) => '- '.$s->name.' : '.$s->url)->implode("\n");

        return <<<TXT
Nous sommes le {$today}. Cherche sur le web les événements à Barcelone (et agglomération proche) entre le {$today} et le {$until}, de type : {$labels}.

Réponds UNIQUEMENT par un tableau JSON, sans texte autour, de 15 à 30 objets de la forme :
{"title":"...","start":"YYYY-MM-DD","end":"YYYY-MM-DD ou null","time":"HH:MM ou null","place":"lieu court ou null","url":"https://...","category":"..."}

Lieux à consulter en priorité, en plus de ta recherche générale (cherche leur programmation à venir) :
{$venues}

Règles :
- category doit être exactement l'une de ces valeurs (avec leur sens) :
{$guide}
- Pour une exposition ou un événement qui dure, start = date d'ouverture (ou aujourd'hui si déjà ouverte) et end = date de fin.
- url : page de l'événement ou, à défaut, page de programmation du lieu, tirée de tes résultats de recherche ou de la liste ci-dessus. Jamais d'URL inventée.
- N'inclus que des événements dont la date est confirmée par une source.
- Titres en français si possible, sinon nom d'origine.
TXT;
    }

    /**
     * Dernier jour couvert par un scan (config agenda.days).
     */
    public function until(): string
    {
        return now('Europe/Madrid')->addDays((int) config('agenda.days'))->toDateString();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(): array
    {
        return [now('Europe/Madrid')->toDateString(), $this->until()];
    }
}
