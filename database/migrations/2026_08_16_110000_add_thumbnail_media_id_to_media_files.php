<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A derived small rendition of an image, pointed at by the original.
 *
 * The variant relationship belongs to the media, not to whatever references
 * it: an attendee's profile photo, a sponsor logo and a speaker portrait all
 * want the same "give me a cheap version for a list" answer, and hanging a
 * second FK off each consuming table would repeat that idea once per consumer.
 *
 * One direction only — parent → thumbnail. The child is identified as a
 * derivative by `collection = 'thumbnail'`, which also keeps it out of every
 * existing `collection`-scoped listing (the CMS media library filters on
 * UploadContentMedia::COLLECTIONS) without those queries needing to learn
 * about variants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('thumbnail_media_id')
                ->nullable()
                ->after('height')
                ->constrained('media_files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropForeign(['thumbnail_media_id']);
            $table->dropColumn('thumbnail_media_id');
        });
    }
};
