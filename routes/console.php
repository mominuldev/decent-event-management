<?php

use App\Console\Commands\BackupDatabaseCommand;
use App\Console\Commands\ExpirePaymentIntentsCommand;
use App\Console\Commands\QueueEventReminders;
use App\Console\Commands\ReconcilePaymentsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(QueueEventReminders::class)->dailyAt('08:00');

// Payment intent expiry sweeper (docs/05 §"Payment intent expiry"; closes D5).
Schedule::command(ExpirePaymentIntentsCommand::class)->everyFiveMinutes()->withoutOverlapping();

// Nightly settlement reconciliation (docs/06 §6.6 "Reconciliation as a security control").
Schedule::command(ReconcilePaymentsCommand::class)->dailyAt('02:00')->withoutOverlapping();

// Nightly database dump (Phase 9, docs/08 §Phase 9 "Encrypted backups with
// verified restore"). Ship the resulting gzip off-box and run
// `db:restore --verify` against it periodically — neither is automated here;
// see CLAUDE.md's Phase 9 section for why.
Schedule::command(BackupDatabaseCommand::class)->dailyAt('03:00')->withoutOverlapping();
