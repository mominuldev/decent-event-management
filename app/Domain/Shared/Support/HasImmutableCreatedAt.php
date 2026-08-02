<?php

namespace App\Domain\Shared\Support;

/**
 * For append-only tables with `created_at` but no `updated_at`
 * (payment_transactions, check_ins, activity_logs, notification_events,
 * idempotency_keys). Rows are never updated after insert.
 */
trait HasImmutableCreatedAt
{
    public static function bootHasImmutableCreatedAt(): void
    {
        static::creating(function ($model): void {
            $model->created_at ??= now();
        });
    }
}
