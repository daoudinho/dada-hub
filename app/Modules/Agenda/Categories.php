<?php

namespace App\Modules\Agenda;

/**
 * Catégories d'événements de l'agenda et filtres proposés dans la modal de scan.
 */
final class Categories
{
    /** Libellés affichés (clé = valeur stockée en base). */
    public const LABELS = [
        'fete' => 'Fête / tradition',
        'cine' => 'Cinéma',
        'musique' => 'Concert / musique',
        'scene' => 'Scène',
        'expo' => 'Expo art / architecture',
        'photo' => 'Expo photo',
        'manga' => 'Manga',
        'sport' => 'Sport',
        'barca' => 'Match du Barça',
    ];

    /** Filtre choisi dans la modal -> catégories stockées en base. */
    public const FILTERS = [
        'sport' => ['sport'],
        'barca' => ['barca'],
        'musique' => ['musique'],
        'culturel' => ['fete', 'cine', 'scene'],
        'expos' => ['expo', 'photo', 'manga'],
    ];

    /** Aide donnée à l'IA pour classer chaque événement. */
    public const HELP = [
        'sport' => 'événements sportifs (hors matchs du FC Barcelone)',
        'barca' => 'matchs du FC Barcelone (équipe masculine) ; title = « FC Barcelone – Adversaire » ou « Adversaire – FC Barcelone », time = heure du coup d\'envoi',
        'musique' => 'concerts importants et festivals de musique',
        'fete' => 'fêtes de quartier et traditions',
        'cine' => 'festivals et événements de cinéma',
        'scene' => 'théâtre, danse, cirque, opéra, spectacles',
        'expo' => 'expositions d\'art et d\'architecture',
        'photo' => 'expositions de photographie',
        'manga' => 'manga, BD, anime : salons et expositions',
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * @param  array<int, string>  $filters
     * @return array<int, string>
     */
    public static function forFilters(array $filters): array
    {
        return collect($filters)
            ->flatMap(fn (string $filter) => self::FILTERS[$filter] ?? [])
            ->unique()->values()->all();
    }

    /**
     * Liste « - catégorie : sens » à inclure dans les prompts.
     *
     * @param  array<int, string>  $categories
     */
    public static function guide(array $categories): string
    {
        return implode("\n", array_map(fn (string $c) => '- '.$c.' : '.(self::HELP[$c] ?? $c), $categories));
    }
}
