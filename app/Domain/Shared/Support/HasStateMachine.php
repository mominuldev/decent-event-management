<?php

namespace App\Domain\Shared\Support;

/**
 * Guards status transitions against the permitted-movement maps in
 * docs/04 §4.7. Any transition not present there is rejected. The
 * consuming model must define a `TRANSITIONS` constant shaped
 * `['from_status' => ['to_status', ...]]`.
 */
trait HasStateMachine
{
    public function stateColumn(): string
    {
        return 'status';
    }

    public function canTransitionTo(string $to): bool
    {
        $from = $this->{$this->stateColumn()};

        return in_array($to, static::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidStateTransitionException
     */
    public function transitionTo(string $to, array $attributes = []): static
    {
        if (! $this->canTransitionTo($to)) {
            throw InvalidStateTransitionException::forModel($this, $this->{$this->stateColumn()}, $to);
        }

        $this->fill(array_merge($attributes, [$this->stateColumn() => $to]));
        $this->save();

        return $this;
    }
}
