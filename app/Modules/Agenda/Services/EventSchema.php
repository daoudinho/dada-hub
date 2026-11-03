<?php

namespace App\Modules\Agenda\Services;

/**
 * Schéma JSON strict imposé à l'IA pour l'extraction des événements :
 * {"events": [{title, start, end, time, place, url, category}]}.
 */
class EventSchema
{
    public const NAME = 'agenda_events';

    /**
     * @param  array<int, string>  $categories
     * @return array<string, mixed>
     */
    public static function for(array $categories): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['events'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'start', 'end', 'time', 'place', 'url', 'category'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                            'end' => $nullableString + ['description' => 'YYYY-MM-DD'],
                            'time' => $nullableString + ['description' => 'HH:MM'],
                            'place' => $nullableString,
                            'url' => $nullableString,
                            'category' => ['type' => 'string', 'enum' => array_values($categories)],
                        ],
                    ],
                ],
            ],
        ];
    }
}
