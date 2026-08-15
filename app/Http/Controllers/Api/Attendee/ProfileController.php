<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Registration\Actions\RemoveAttendeeProfilePhoto;
use App\Domain\Registration\Actions\UpdateAttendeeProfilePhoto;
use App\Domain\Registration\Models\Attendee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\UpdateProfilePhotoRequest;
use App\Http\Requests\Attendee\UpdateProfileRequest;
use App\Http\Resources\AttendeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Attendee Self-Service')]
class ProfileController extends Controller
{
    #[OAT\Get(
        path: '/attendee/me',
        summary: 'Get the authenticated attendee\'s own profile',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The attendee profile',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'full_name', type: 'string'),
                            new OAT\Property(property: 'full_name_bn', type: 'string', nullable: true),
                            new OAT\Property(property: 'mobile', type: 'string'),
                            new OAT\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                            new OAT\Property(property: 'gender', type: 'string', nullable: true),
                            new OAT\Property(property: 'date_of_birth', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'occupation', type: 'string', nullable: true),
                            new OAT\Property(property: 'designation', type: 'string', nullable: true),
                            new OAT\Property(property: 'organization', type: 'string', nullable: true),
                            new OAT\Property(property: 'participant_type', type: 'string', nullable: true),
                            new OAT\Property(property: 'ssc_batch_year', nullable: true),
                            new OAT\Property(property: 'current_class', type: 'string', nullable: true),
                            new OAT\Property(property: 'tshirt_required', type: 'boolean'),
                            new OAT\Property(property: 'tshirt_size', type: 'string', nullable: true),
                            new OAT\Property(property: 'address_district', type: 'string', nullable: true),
                            new OAT\Property(property: 'country', type: 'string', nullable: true),
                            new OAT\Property(property: 'blood_group', type: 'string', nullable: true),
                            new OAT\Property(property: 'emergency_contact_name', type: 'string', nullable: true),
                            new OAT\Property(property: 'emergency_contact_phone', type: 'string', nullable: true),
                            new OAT\Property(property: 'notes', type: 'string', nullable: true),
                            new OAT\Property(property: 'is_verified', type: 'boolean'),
                            new OAT\Property(property: 'profile_photo_url', type: 'string', nullable: true),
                            new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                        ]
                    )
                )
            ),
        ]
    )]
    public function show(Request $request): AttendeeResource
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        return new AttendeeResource($attendee);
    }

    #[OAT\Patch(
        path: '/attendee/me',
        summary: 'Update the authenticated attendee\'s own profile',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'full_name', type: 'string'),
                        new OAT\Property(property: 'full_name_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'whatsapp_number', type: 'string', nullable: true),
                        new OAT\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                        new OAT\Property(property: 'occupation', type: 'string', nullable: true),
                        new OAT\Property(property: 'designation', type: 'string', nullable: true),
                        new OAT\Property(property: 'organization', type: 'string', nullable: true),
                        new OAT\Property(property: 'tshirt_required', type: 'boolean'),
                        new OAT\Property(
                            property: 'tshirt_size',
                            type: 'string',
                            description: 'Required when tshirt_required is true',
                            enum: ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
                            nullable: true
                        ),
                        new OAT\Property(property: 'address_district', type: 'string', nullable: true),
                        new OAT\Property(property: 'country', type: 'string', nullable: true),
                        new OAT\Property(property: 'blood_group', type: 'string', enum: ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], nullable: true),
                        new OAT\Property(property: 'emergency_contact_name', type: 'string', nullable: true),
                        new OAT\Property(property: 'emergency_contact_phone', type: 'string', nullable: true),
                        new OAT\Property(property: 'notes', type: 'string', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Profile updated',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'full_name', type: 'string'),
                            new OAT\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                            new OAT\Property(property: 'mobile', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateProfileRequest $request): AttendeeResource
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        $attendee->update($request->validated());

        return new AttendeeResource($attendee);
    }

    #[OAT\Post(
        path: '/attendee/me/photo',
        summary: 'Change the authenticated attendee\'s own account photo',
        description: 'The image is validated by magic bytes, re-encoded (stripping EXIF/GPS), '.
            'downscaled and stored privately; the response carries a short-TTL signed URL, never a '.
            'public path. Replaces any existing photo.',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'photo',
                            type: 'string',
                            format: 'binary',
                            description: 'JPEG, PNG or WebP, up to 4 MB',
                            required: ['photo']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Photo stored and linked to the attendee',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'object',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'url', type: 'string', description: 'Short-TTL signed URL'),
                                    new OAT\Property(property: 'width', type: 'integer'),
                                    new OAT\Property(property: 'height', type: 'integer'),
                                ]
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 422, description: 'Not an accepted image'),
        ]
    )]
    public function photo(UpdateProfilePhotoRequest $request, UpdateAttendeeProfilePhoto $action): JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();
        /** @var UploadedFile $file */
        $file = $request->file('photo');

        try {
            $media = $action->execute($attendee, $file, $request->ip(), $request->header('X-Request-Id'));
        } catch (InvalidArgumentException $e) {
            // The Action's own rejections are caller error, not a 500 —
            // reported in the API's uniform error shape.
            return response()->json([
                'code' => 'photo_rejected',
                'message' => $e->getMessage(),
                'request_id' => $request->header('X-Request-Id'),
            ], 422);
        }

        return response()->json([
            'data' => [
                'ulid' => $media->ulid,
                'url' => $media->temporarySignedUrl(),
                'width' => $media->width,
                'height' => $media->height,
            ],
        ]);
    }

    #[OAT\Delete(
        path: '/attendee/me/photo',
        summary: 'Remove the authenticated attendee\'s own account photo',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        responses: [
            new OAT\Response(response: 204, description: 'Photo removed (or there was none)'),
        ]
    )]
    public function removePhoto(Request $request, RemoveAttendeeProfilePhoto $action): JsonResponse|Response
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        $action->execute($attendee, $request->ip(), $request->header('X-Request-Id'));

        return response()->noContent();
    }
}
