<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Events\ContentChanged;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Create/update choke point for the simple CMS collections — sponsors,
 * schedule items, FAQs, menus, menu items, gallery albums and items.
 *
 * One Action rather than fifteen because the behaviour genuinely is
 * identical: fill, save, audit, invalidate. Pages are *not* routed through
 * here — they carry a state machine, a block tree and revision history, which
 * is {@see SaveContentPage}'s job.
 *
 * Exists at all so the audit entry is written in the domain layer rather than
 * the controller (D8): a seeder or console command doing the same edit gets
 * the same trail.
 */
class SaveContentResource
{
    /**
     * @template TModel of Model
     *
     * @param  TModel  $model  a fresh unsaved instance to create, or an existing row to edit
     * @param  array<string, mixed>  $attributes  already validated, with ULID references resolved to ids
     * @param  string  $label  short type name for the audit description, e.g. `sponsor`
     * @return TModel
     */
    public function execute(
        Model $model,
        array $attributes,
        User $editor,
        string $label,
        ?string $ip = null,
        ?string $requestId = null,
    ): Model {
        return DB::transaction(function () use ($model, $attributes, $editor, $label, $ip, $requestId): Model {
            $isNew = ! $model->exists;
            $before = $isNew ? null : $this->auditable($model);

            $model->fill($attributes);
            $model->save();

            ActivityLog::create([
                'log_name' => 'content',
                'event' => $isNew ? 'created' : 'updated',
                'description' => ($isNew ? 'Created' : 'Updated')." {$label} {$model->getKey()}",
                'causer_type' => $editor->getMorphClass(),
                'causer_id' => $editor->id,
                'subject_type' => $model->getMorphClass(),
                'subject_id' => $model->getKey(),
                'properties' => ['old' => $before, 'new' => $this->auditable($model)],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            // No slug: these collections are shared furniture that any page
            // may render, so the whole site is revalidated rather than one path.
            ContentChanged::dispatch(null, "{$label}.".($isNew ? 'created' : 'updated'));

            return $model;
        });
    }

    /**
     * Soft-deletes a row (or hard-deletes where the model has no
     * SoftDeletes), leaving the audit entry behind either way.
     */
    public function delete(
        Model $model,
        User $actor,
        string $label,
        ?string $ip = null,
        ?string $requestId = null,
    ): void {
        DB::transaction(function () use ($model, $actor, $label, $ip, $requestId): void {
            $before = $this->auditable($model);
            $key = $model->getKey();

            $model->delete();

            ActivityLog::create([
                'log_name' => 'content',
                'event' => 'deleted',
                'description' => "Deleted {$label} {$key}",
                'causer_type' => $actor->getMorphClass(),
                'causer_id' => $actor->id,
                'subject_type' => $model->getMorphClass(),
                'subject_id' => $key,
                'properties' => [
                    'old' => $before,
                    'soft_deleted' => in_array(SoftDeletes::class, class_uses_recursive($model), true),
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            ContentChanged::dispatch(null, "{$label}.deleted");
        });
    }

    /**
     * The row as it should appear in the audit diff: casts applied, hidden
     * columns respected, internal primary key dropped.
     *
     * @return array<string, mixed>
     */
    private function auditable(Model $model): array
    {
        return Arr::except($model->attributesToArray(), ['id']);
    }
}
