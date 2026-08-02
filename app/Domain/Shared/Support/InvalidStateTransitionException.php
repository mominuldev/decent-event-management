<?php

namespace App\Domain\Shared\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class InvalidStateTransitionException extends RuntimeException
{
    public static function forModel(Model $model, string $from, string $to): self
    {
        return new self(sprintf(
            '%s #%s cannot transition from "%s" to "%s".',
            class_basename($model),
            $model->getKey(),
            $from,
            $to
        ));
    }
}
