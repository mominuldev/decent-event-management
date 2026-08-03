<?php

namespace Database\Factories;

use App\Domain\Content\Models\GalleryAlbum;
use App\Domain\Content\Models\GalleryItem;
use App\Domain\Shared\Models\MediaFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryItem>
 */
class GalleryItemFactory extends Factory
{
    protected $model = GalleryItem::class;

    public function definition(): array
    {
        return [
            'gallery_album_id' => GalleryAlbum::factory(),
            'media_id' => MediaFile::factory(),
            'caption' => fake()->sentence(4),
            'caption_bn' => 'ছবির শিরোনাম',
            'alt_text' => fake()->sentence(3),
            'position' => 0,
            'is_published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
