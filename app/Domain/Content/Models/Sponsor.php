<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Support\IsPublishableContent;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\SponsorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sponsor extends Model
{
    /** @use HasFactory<SponsorFactory> */
    use HasFactory, HasUlid, IsPublishableContent;

    /**
     * Tiers in display order, highest first. Ordering lives here rather than
     * in a column so adding a tier is a code change, not a migration.
     *
     * @var list<string>
     */
    public const array TIERS = ['platinum', 'gold', 'silver', 'bronze', 'partner'];

    protected $fillable = [
        'name',
        'name_bn',
        'tier',
        'logo_media_id',
        'website_url',
        'description',
        'description_bn',
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
     * @return BelongsTo<MediaFile, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'logo_media_id');
    }

    /**
     * Rank of this sponsor's tier for sorting. Unknown tiers sort last rather
     * than throwing — a sponsor grid must never fail to render.
     */
    public function tierRank(): int
    {
        $rank = array_search($this->tier, self::TIERS, true);

        return $rank === false ? count(self::TIERS) : $rank;
    }
}
