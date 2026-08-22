<?php

use App\Domain\Notification\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->ulid('ulid')->nullable()->after('id');
        });

        // Templates are about to become editable from the admin console, and
        // this codebase's rule is that anything addressable over the API is
        // addressed by ULID — an auto-increment primary key is internal and
        // must not cross that boundary. Backfilled rather than generated on
        // write, so the rows the seeder already created are addressable too.
        // Deliberately not `cursor()`. Under `migrate --pretend` the connection
        // returns a plain array instead of a PDOStatement, so streaming it dies
        // with "Call to a member function fetch() on array" — which fails the
        // deploy pipeline's migration gate rather than the migration itself.
        // `chunkById` degrades to a no-op there, and is the right paging here
        // regardless: the filter column is the one being written, so an offset
        // walk would skip a row at every boundary as the set shrank beneath it.
        NotificationTemplate::query()
            ->whereNull('ulid')
            ->chunkById(100, function ($templates): void {
                foreach ($templates as $template) {
                    $template->newQuery()
                        ->whereKey($template->getKey())
                        ->update(['ulid' => (string) Str::ulid()]);
                }
            });

        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->ulid('ulid')->nullable(false)->change();
            $table->unique('ulid', 'uk_notification_templates_ulid');
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropUnique('uk_notification_templates_ulid');
            $table->dropColumn('ulid');
        });
    }
};
