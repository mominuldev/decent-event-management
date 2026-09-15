<?php

namespace App\Http\Requests\Attendee;

use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Support\PartySize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Registration|null $registration */
        $registration = $this->route('registration');

        if (! $registration) {
            return false;
        }

        return $registration->attendee_id === auth('attendee')->id()
            && in_array($registration->status, ['draft', 'pending_payment'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'special_notes' => ['nullable', 'string', 'max:1000'],
            'guests' => ['nullable', 'array'],
            'guests.*.full_name' => ['required', 'string', 'max:200'],
            'guests.*.relation' => ['required', 'string'],
            'guests.*.age_group' => ['required', 'string', Rule::in(['adult', 'child'])],
            'guests.*.tshirt_required' => ['nullable', 'boolean'],
            'guests.*.tshirt_size' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * The replaced guest list is held to the same ceiling as checkout. This
     * endpoint swaps the whole list, so without the check it was the one
     * way past `CreateRegistration::assertPartyFits()`: register alone, then
     * append as many guests as you like.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Registration|null $registration */
            $registration = $this->route('registration');
            $ticketType = $registration?->ticketType;
            $guests = $this->input('guests');

            if ($ticketType === null || ! is_array($guests) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $limit = PartySize::limitFor($ticketType);
            $members = PartySize::maxMembersFor($ticketType);

            if (count($guests) + 1 > $limit) {
                $validator->errors()->add(
                    'guests',
                    "This ticket admits at most {$limit} people including you — up to {$members} "
                    .($members === 1 ? 'family member.' : 'family members.'),
                );
            }
        });
    }
}
