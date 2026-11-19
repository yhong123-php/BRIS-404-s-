<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SPMP §5.5.5 / SDD §4.22: delete assistant conversations after the retention
// period approved by the barangay. ai_query_logs are kept (append-only audit).
Artisan::command('assistant:purge', function () {
    $deleted = \App\Models\AiConversation::where('delete_after', '<=', now())->delete();
    $this->info("Deleted {$deleted} expired assistant conversation(s).");
})->purpose('Delete AI assistant conversations past their retention period');

\Illuminate\Support\Facades\Schedule::command('assistant:purge')->daily();

// D3 — nightly backup and retention pruning (SDD §6.2).
Artisan::command('backup:run {--type=scheduled}', function (\App\Services\BackupService $backups) {
    $backup = $backups->run($this->option('type'));
    $this->info("Backup {$backup->file_name}: {$backup->status}".($backup->error_message ? " ({$backup->error_message})" : ''));
    $this->info('Pruned '.$backups->prune().' expired archive(s).');
})->purpose('Back up the BRIS database and prune expired archives');

// A5 / C3 / F1 — nightly jobs outside the request cycle (SDD §2.3 scheduler).
Artisan::command('bris:nightly', function (\App\Services\ClassificationService $classifications, \App\Services\AnalysisService $analysis) {
    $this->info('Expired classifications: '.$classifications->expireOverdueClassifications());
    $this->info('Forecast rows: '.$analysis->generateForecasts());
    $this->info('AI suggestions: '.json_encode($analysis->runResidentAnalysis()));
})->purpose('Expire classifications, refresh projections, and run AI resident analysis');

\Illuminate\Support\Facades\Schedule::command('backup:run')->dailyAt('01:00');
\Illuminate\Support\Facades\Schedule::command('bris:nightly')->dailyAt('02:00');
