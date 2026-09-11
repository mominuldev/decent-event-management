<?php

namespace App\Http\Requests\Admin;

use App\Domain\Ticketing\Actions\ResendTicketNotification;
use App\Domain\Ticketing\Support\TicketListFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A bulk resend is a broadcast, so it sits on `notification.send_broadcast`
 * rather than `notification.resend` — resending one ticket to the person
 * standing at the desk and messaging every ticket-holder at once are
 * different acts, and the second one spends the prepaid SMS balance.
 *
 * Both permissions already exist in `config/rbac.php` and are already
 * granted to Event Manager, so — unlike `attendee.export` and
 * `payment.collect_cash` — this ships without needing
 * `db:seed --class=RbacSeeder` on the host.
 */
class BulkResendTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('notification.send_broadcast');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(ResendTicketNotification::CHANNELS)],

            // The filter set, mirroring the admin list exactly — the bulk
            // send is meant to hit what the screen is showing.
            'status' => ['nullable', 'string', 'max:40'],
            'ticket_type_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],

            // ...plus the tickets an operator hand-picked from it, which is
            // just a narrower filter rather than a second code path: it
            // reaches the same preview, the same count confirmation and the
            // same fan-out job.
            ...TicketListFilters::ulidRules(),

            // The count the operator was shown and agreed to. If the roster
            // moved between the preview and the confirm — someone issued
            // forty tickets while the dialog was open — the send is refused
            // rather than quietly being larger than what was approved.
            'expected_count' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channels.required' => 'Choose at least one channel to resend on.',
            'channels.*.in' => 'Tickets can be resent by email or SMS only.',
            'expected_count.required' => 'Confirm the number of tickets this will send to.',
            ...TicketListFilters::ulidMessages(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'status' => $this->input('status'),
            'ticket_type_id' => $this->input('ticket_type_id'),
            'search' => $this->input('search'),
            'ulids' => $this->input('ulids'),
        ];
    }
}
