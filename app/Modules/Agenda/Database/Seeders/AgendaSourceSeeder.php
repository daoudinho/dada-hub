<?php

namespace App\Modules\Agenda\Database\Seeders;

use App\Modules\Agenda\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Pages agenda / programmation consultées par le scan (relancer sans risque : pas de doublons).
 */
class AgendaSourceSeeder extends Seeder
{
    public function run(): void
    {
        // [nom, URL de la page programmation, catégorie, active]
        $rows = [
            // Concerts et musique
            ['Palau de la Música Catalana', 'https://www.palaumusica.cat/ca/temporada-2026-2027_1466030', 'musique', true],
            ["L'Auditori", 'https://www.auditori.cat/en/', 'musique', true],
            ['Razzmatazz', 'https://www.salarazzmatazz.com/en/', 'musique', true],
            ['Sala Apolo', 'https://www.sala-apolo.com/en/', 'musique', true],
            ['Palau Sant Jordi', 'https://palausantjordi.barcelona/en', 'musique', true],
            ['Jamboree', 'https://www.jamboreebarcelona.com/en/', 'musique', true],
            ['Harlem Jazz Club', 'https://www.harlemjazzclub.es/', 'musique', true],
            ['Bikini', 'https://www.sala-bikini.com/en/', 'musique', true],
            ['Sala Barts', 'https://www.salabarts.com/en/', 'musique', true],
            ['Festival de Jazz de Barcelona', 'https://www.jazz.barcelona/', 'musique', true],
            ['Primavera Sound', 'https://www.primaverasound.com/', 'musique', false],
            ['Sónar', 'https://sonar.es/en', 'musique', false],
            ['Cruïlla', 'https://cruillabarcelona.com/en/', 'musique', false],

            // Opéra, théâtre, danse
            ['Gran Teatre del Liceu', 'https://www.liceubarcelona.cat/en/whats-on', 'scene', true],
            ['TNC', 'https://www.tnc.cat/en/', 'scene', true],
            ['Teatre Lliure', 'https://www.teatrelliure.com/en', 'scene', true],
            ['Teatre Poliorama', 'https://www.teatrepoliorama.com/', 'scene', true],
            ['Teatre Romea', 'https://www.teatreromea.cat/ca/', 'scene', true],
            ['Teatre Tívoli', 'https://www.teatretivoli.com/', 'scene', true],
            ['Mercat de les Flors', 'https://mercatflors.cat/en/season-at-a-glance/', 'scene', true],
            ['Sala Beckett', 'https://www.salabeckett.cat/en/', 'scene', true],
            ['Grec Festival', 'https://grec.barcelona.cat/en', 'scene', false],

            // Musées et expositions
            ['Museu Picasso', 'https://museupicasso.bcn.cat/en/exhibitions', 'expo', true],
            ['Fundació Joan Miró', 'https://www.fundaciomiro-bcn.org/en/exhibitions', 'expo', true],
            ['MNAC', 'https://www.museunacional.cat/en/exhibitions', 'expo', true],
            ['MACBA', 'https://www.macba.cat/en/exhibitions-activities/exhibitions', 'expo', true],
            ['CCCB', 'https://www.cccb.org/en/exhibitions', 'expo', true],
            ['CaixaForum', 'https://caixaforum.org/en/barcelona/exposiciones', 'expo', true],
            ['Fundació Tàpies', 'https://www.fundaciotapies.org/en/exhibitions/', 'expo', true],
            ['Museu Frederic Marès', 'https://www.museumares.bcn.cat/', 'expo', true],
            ['Museu del Disseny', 'https://www.museudeldisseny.barcelona.cat/en/exhibitions', 'expo', true],
            ['Casa Batlló', 'https://www.casabatllo.es/en/', 'expo', true],
            ['La Pedrera', 'https://www.lapedrera.com/en/exhibitions', 'expo', true],
            ['Palau Güell', 'https://palauguell.cat/en', 'expo', true],
            ['Hospital de Sant Pau', 'https://santpaubarcelona.org/en/activitats/', 'expo', true],
            ['Nit dels Museus', 'https://www.barcelona.cat/nitdelsmuseus/en', 'expo', false],
            ['KBr Fundación MAPFRE', 'https://kbr.fundacionmapfre.org/en/exhibitions/upcoming/', 'photo', true],
            ['Manga Barcelona', 'https://www.manga-barcelona.com/', 'manga', true],

            // Cinéma
            ["L'Alternativa", 'https://alternativa.cccb.org/2026/en/', 'cine', true],
            ['Sitges Film Festival', 'https://sitgesfilmfestival.com/en', 'cine', true],

            // Barça
            ['FC Barcelone · LaLiga', 'https://www.laliga.com/en-GB/clubs/fc-barcelona/next-matches', 'barca', true],
            ['FC Barcelone · Ligue des champions', 'https://es.uefa.com/uefachampionsleague/clubs/50080--barcelona/matches/', 'barca', true],
        ];

        foreach ($rows as [$name, $url, $category, $active]) {
            Source::firstOrCreate(['url' => $url], ['name' => $name, 'category' => $category, 'active' => $active]);
        }
    }
}
