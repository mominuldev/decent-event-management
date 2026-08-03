<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Registration\Actions\CreateRegistration;
use App\Domain\Registration\Models\Registration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function store(StoreRegistrationRequest $request, CreateRegistration $action): JsonResponse
    {
        $registration = $action($request->validated());

        return (new RegistrationResource($registration))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Registration $registration): RegistrationResource
    {
        $registration->load(['attendee', 'guests', 'ticketType', 'payments']);

        return new RegistrationResource($registration);
    }
}
