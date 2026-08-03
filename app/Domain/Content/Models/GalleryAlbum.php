<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Support\IsPublishableContent;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\GalleryAlbumFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GalleryAlbum extends Model
{
    /** @use HasFactory<GalleryAlbumFactory> */
    use HasFactory, HasUlid, IsPublishableContent;

    protected $fillable = [
        'slug',
        'title',
        'title_bn',
        'description',
        'description_bn',
        'cover_media_id',
        'position',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    /**
     * @return HasMany<GalleryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GalleryItem::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'cover_media_id');
    }
}
