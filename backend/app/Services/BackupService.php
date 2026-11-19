<?php

namespace App\Services;

use App\Models\Backup;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * D3 — Backup and restore (SDD §4.13, §6). Logical dump of the whole database,
 * compressed, encrypted at rest with the application key, and checksummed.
 * Every run writes a backups row. A pre-restore snapshot is taken before any
 * restore so a mistaken restore is itself reversible.
 */
class BackupService
{
    public function directory(): string
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public function run(string $type): Backup
    {
        $started = now();
        $fileName = sprintf('bris-%s-%s.sql.gz.enc', $type, $started->format('Ymd-His'));
        $path = $this->directory().DIRECTORY_SEPARATOR.$fileName;

        $backup = Backup::create([
            'file_name' => $fileName, 'storage_path' => $path, 'type' => $type, 'status' => 'failed',
            'started_at' => $started, 'triggered_by' => Auth::id(),
        ]);

        try {
            $db = config('database.connections.'.config('database.default'));
            $process = new Process(array_values(array_filter([
                config('services.backup.mysqldump'),
                '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'],
                $db['password'] !== '' && $db['password'] !== null ? '--password='.$db['password'] : null,
                '--single-transaction', '--routines', '--skip-lock-tables', '--default-character-set=utf8mb4',
                $db['database'],
            ])), null, $this->processEnv());
            $process->setTimeout(600)->mustRun();

            $encrypted = Crypt::encryptString(base64_encode(gzencode($process->getOutput(), 9)));
            File::put($path, $encrypted);

            $backup->update([
                'status' => 'success', 'file_size' => File::size($path), 'checksum' => hash_file('sha256', $path), 'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $backup->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
        }

        Audit::log('backup_'.$backup->status, 'D3', 'backups', $backup->id, ['type' => $type, 'file' => $fileName]);

        return $backup->fresh();
    }

    /**
     * On Windows, a child process without SystemRoot cannot open network
     * sockets (error 10106). Web servers such as `php artisan serve` do not
     * always pass it on, so it is supplied explicitly.
     */
    private function processEnv(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }
        $root = getenv('SystemRoot') ?: getenv('SYSTEMROOT') ?: ($_SERVER['SystemRoot'] ?? 'C:\\Windows');

        return ['SystemRoot' => $root, 'SYSTEMROOT' => $root, 'WINDIR' => getenv('WINDIR') ?: $root];
    }

    /** Decrypted, gzip-compressed SQL; verifies the stored checksum first (SDD §6.2). */
    public function readArchive(Backup $backup): string
    {
        if ($backup->status !== 'success' || ! File::exists($backup->storage_path)) {
            throw new RuntimeException('This backup archive is not available.');
        }
        if (hash_file('sha256', $backup->storage_path) !== $backup->checksum) {
            throw new RuntimeException('Checksum mismatch: the backup archive is damaged and cannot be used.');
        }

        return base64_decode(Crypt::decryptString(File::get($backup->storage_path)));
    }

    public function restore(Backup $backup): Backup
    {
        $sql = gzdecode($this->readArchive($backup));
        if ($sql === false) {
            throw new RuntimeException('The backup archive could not be decompressed.');
        }

        $snapshot = $this->run('pre_restore');
        if ($snapshot->status !== 'success') {
            throw new RuntimeException('The pre-restore snapshot failed, so the restore was not started: '.$snapshot->error_message);
        }

        $db = config('database.connections.'.config('database.default'));
        $process = new Process(array_values(array_filter([
            config('services.backup.mysql'),
            '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'],
            $db['password'] !== '' && $db['password'] !== null ? '--password='.$db['password'] : null,
            '--default-character-set=utf8mb4', $db['database'],
        ])), null, $this->processEnv());
        $process->setInput($sql)->setTimeout(900)->mustRun();

        // The restored data has no record of this restore; write it after the import.
        Audit::log('backup_restored', 'D3', 'backups', null, ['restored_file' => $backup->file_name, 'pre_restore_snapshot' => $snapshot->file_name]);

        return $snapshot;
    }

    /** Retention (SDD §6.2): daily 14 days, weekly (Sunday) 8 weeks, monthly (1st) 12 months. History rows are kept. */
    public function prune(): int
    {
        $deleted = 0;
        Backup::where('status', 'success')->where('started_at', '<', now()->subDays(14))->get()->each(function (Backup $b) use (&$deleted) {
            $date = $b->started_at;
            $keepWeekly = $date->isSunday() && $date->gt(now()->subWeeks(8));
            $keepMonthly = $date->day === 1 && $date->gt(now()->subMonths(12));
            if (! $keepWeekly && ! $keepMonthly && File::exists($b->storage_path)) {
                File::delete($b->storage_path);
                $b->update(['error_message' => 'Archive pruned by the retention schedule.']);
                $deleted++;
            }
        });

        return $deleted;
    }
}
