<?php

namespace App\Modules\Coach\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ressenti rapide : effort, fatigue, sommeil, et une douleur éventuelle (zone et intensité).
 */
class QuickFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Règles partagées avec la saisie d'une course (ActivityRequest).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function feedbackRules(bool $required = true): array
    {
        return [
            'rpe' => [$required ? 'required' : 'nullable', 'integer', 'between:1,10'],
            'fatigue' => [$required ? 'required' : 'nullable', 'integer', 'between:1,5'],
            'sleep' => ['nullable', 'integer', 'between:1,5'],
            'pain_zone' => ['nullable', 'required_with:pain_intensity', 'string', 'max:60'],
            'pain_intensity' => ['nullable', 'required_with:pain_zone', 'integer', 'between:1,10'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::feedbackRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pain_intensity.required_with' => 'Indique l\'intensité de la douleur (1 à 10).',
            'pain_zone.required_with' => 'Indique où se situe la douleur.',
        ];
    }
}
