<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Батч B2C-событий аналитики.
 *
 * Валидация формы батча — жёсткая (422), валидация каждого события —
 * мягкая per-event в AnalyticsIngestService (invalid событие попадает
 * в rejected, остальные принимаются).
 */
final class StoreAnalyticsEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:' . config('analytics.max_batch_size', 100)],
            'events.*' => ['array'],
        ];
    }
}
