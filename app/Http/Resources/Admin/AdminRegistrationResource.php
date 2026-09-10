<?php

namespace App\Http\Resources\Admin;

use App\Domain\Registration\Models\Registration;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RegistrationResource;
use Illuminate\Http\Request;

/**
 * A registration as staff see it: everything the shared resource carries,
 * plus its payments.
 *
 * A separate class rather than a field added to
 * {@see RegistrationResource}, because that resource is also the response
 * of the **unauthenticated** `GET /public/registrations/{ulid}` and of the
 * attendee's own endpoints. Payment rows carry `payer_msisdn`,
 * `manual_trx_id`, `gateway_transaction_id` and every money column, and a
 * registration ULID is the only thing standing between an anonymous
 * caller and that endpoint — so the field is added where the audience is
 * an authenticated staff member holding `registration.view*`, and nowhere
 * else. This is the allowlist-per-audience rule, not a blocklist applied
 * at render time.
 *
 * @mixin Registration
 */
class AdminRegistrationResource extends RegistrationResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ]);
    }
}
