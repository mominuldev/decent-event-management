<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Str;

/**
 * Auto-increment `id` stays the internal primary key; `ulid` is the public
 * identifier used in URLs, QR payloads, and API responses (ADR-06).
 */
trait HasUlid
{
    public static function bootHasUlid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
