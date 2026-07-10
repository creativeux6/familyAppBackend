<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Models\CalendarReminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'visibility' => [
                'sometimes',
                'string',
                Rule::in([
                    CalendarReminder::VISIBILITY_PERSONAL,
                    CalendarReminder::VISIBILITY_CONNECTED_ONLY,
                ]),
            ],
            'recurring_yearly' => ['sometimes', 'boolean'],
            'notify_days_before' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ];
    }
}
