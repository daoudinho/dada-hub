<?php

namespace App\Modules\Coach\Http\Requests;

use App\Modules\Coach\Services\Duration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Course saisie à la main : « 8,5 » km, durée « 45:30 » ou « 1:02:10 ».
 */
class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'distance_km' => str_replace(',', '.', trim((string) $this->input('distance_km'))),
            'moving_seconds' => Duration::parse((string) $this->input('duration')),
            'started_at' => trim($this->input('date').' '.($this->input('time') ?: '12:00')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['nullable', 'integer'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time' => ['nullable', 'date_format:H:i'],
            'started_at' => ['required', 'date'],
            'distance_km' => ['required', 'numeric', 'between:0.1,300'],
            'duration' => ['required', 'string', 'max:12'],
            'moving_seconds' => ['required', 'integer', 'between:60,172800'],
            'avg_hr' => ['nullable', 'integer', 'between:40,230'],
            'max_hr' => ['nullable', 'integer', 'between:40,240'],
            'elevation_gain_m' => ['nullable', 'integer', 'between:0,10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...QuickFeedbackRequest::feedbackRules(required: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['moving_seconds.required' => 'Durée illisible : écris par exemple « 45:30 » ou « 1:02:10 ».'];
    }
}
