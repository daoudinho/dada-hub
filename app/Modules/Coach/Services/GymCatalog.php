<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\Equipment;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La salle de l'athlète, découverte au fil des propositions du coach : pour chaque exercice proposé,
 * « J'ai » ajoute la machine et l'exercice, « Pas dans ma salle » note la machine comme absente.
 */
class GymCatalog
{
    /** Réponses possibles à un exercice proposé. */
    public const ANSWERS = ['have', 'missing', 'declined'];

    /**
     * Règles de validation d'un exercice (formulaire « Ma salle » et propositions du coach), préfixe compris.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function exerciseRules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:100'],
            $prefix.'measure' => ['required', Rule::in(array_keys(Exercise::MEASURES))],
            $prefix.'muscles' => ['required', 'array', 'min:1', 'max:6'],
            $prefix.'muscles.*' => [Rule::in(array_keys(Exercise::MUSCLES))],
            $prefix.'secondary_muscles' => ['nullable', 'array', 'max:6'],
            $prefix.'secondary_muscles.*' => [Rule::in(array_keys(Exercise::MUSCLES))],
            $prefix.'unilateral' => ['nullable', 'boolean'],
            $prefix.'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** Machine du même nom, sans tenir compte des majuscules (accents compris : comparé en PHP, lower() de SQLite les ignore). */
    public function findEquipment(User $user, string $name): ?Equipment
    {
        return Equipment::ownedBy($user)->get()->first(fn (Equipment $equipment) => self::sameName($equipment->name, $name));
    }

    /** Exercice du même nom, sans tenir compte des majuscules. */
    public function findExercise(User $user, string $name): ?Exercise
    {
        return Exercise::ownedBy($user)->get()->first(fn (Exercise $exercise) => self::sameName($exercise->name, $name));
    }

    private static function sameName(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /** Machine de la salle, créée si elle n'existe pas encore, avec son état. */
    public function markEquipment(User $user, string $name, string $status): Equipment
    {
        $equipment = $this->findEquipment($user, $name) ?? (new Equipment(['name' => trim($name)]))->user()->associate($user);
        $equipment->fill(['status' => $status])->save();

        return $equipment;
    }

    /**
     * Réponse de l'athlète à un exercice proposé. La proposition est close quand tous ont une réponse.
     */
    public function answer(Proposal $proposal, int $index, string $answer): void
    {
        DB::transaction(function () use ($proposal, $index, $answer) {
            $user = $proposal->user;
            $payload = $proposal->payload;
            $item = $payload['items'][$index];

            if ($answer === 'have') {
                $equipment = filled($item['equipment'] ?? null) ? $this->markEquipment($user, $item['equipment'], 'available') : null;
                if (! $this->findExercise($user, $item['name'])) {
                    $exercise = new Exercise([
                        'equipment_id' => $equipment?->id,
                        'name' => trim($item['name']),
                        'measure' => $item['measure'],
                        'muscles' => $item['muscles'],
                        'secondary_muscles' => $item['secondary_muscles'] ?? null,
                        'unilateral' => (bool) ($item['unilateral'] ?? false),
                        'notes' => $item['notes'] ?? null,
                    ]);
                    $exercise->user()->associate($user)->save();
                }
            } elseif ($answer === 'missing' && filled($item['equipment'] ?? null)) {
                $this->markEquipment($user, $item['equipment'], 'missing');
            }

            $payload['items'][$index]['answer'] = $answer;
            $answered = collect($payload['items'])->every(fn (array $proposed) => isset($proposed['answer']));
            $proposal->update(['payload' => $payload, 'status' => $answered ? 'accepted' : 'pending']);
        });
    }
}
