<?php

namespace Database\Factories;

use App\Domain\Shared\Models\MediaFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaFile>
 */
class MediaFileFactory extends Factory
{
    protected $model = MediaFile::class;

    public function definition(): array
    {
        return [
            'collection' => fake()->randomElement(['profile_photo', 'payment_proof', 'ticket_pdf', 'qr_image']),
            'disk' => 'local',
            'path' => 'seed/'.fake()->uuid().'.jpg',
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
            'checksum_sha256' => hash('sha256', fake()->uuid()),
            'is_public' => false,
            'scan_status' => 'clean',
            'scanned_at' => now(),
        ];
    }
}
