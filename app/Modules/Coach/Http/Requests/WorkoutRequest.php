<?php

namespace App\Modules\Coach\Http\Requests;

use App\Modules\Coach\Services\WorkoutRecorder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Séance de fitness saisie dans le formulaire : une ligne par exercice (séries, répétitions, charge « 102,5 »).
 * Les lignes laissées vides sont ignorées.
 */
class WorkoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $entries = collect($this->input('entries', []))
            ->filter(fn ($entry) => is_array($entry) && filled($entry['exercise_id'] ?? null))
            ->map(fn (array $entry) => ['load_kg' => str_replace(',', '.', trim((string) ($entry['load_kg'] ?? ''))) ?: null] + $entry)
            ->values()
            ->all();

        $this->merge(['entries' => $entries]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return WorkoutRecorder::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['entries.required' => 'Ajoute au moins un exercice.'];
    }
}
