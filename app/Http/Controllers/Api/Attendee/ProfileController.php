<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\UpdateProfileRequest;
use App\Http\Resources\AttendeeResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): AttendeeResource
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        return new AttendeeResource($attendee);
    }

    public function update(UpdateProfileRequest $request): AttendeeResource
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        $attendee->update($request->validated());

        return new AttendeeResource($attendee);
    }
}
