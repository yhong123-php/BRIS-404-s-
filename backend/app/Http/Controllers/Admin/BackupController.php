<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\BackupService;
use App\Support\Audit;
use Illuminate\Http\Request;
use RuntimeException;

/** D3 — Backup and restore (SDD §4.13, §6). Barangay Captain only. */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index()
    {
        return response()->json(['data' => Backup::with('trigger:id,full_name')->latest('id')->limit(100)->get()]);
    }

    public function store()
    {
        set_time_limit(900);
        $backup = $this->backups->run('manual');

        return response()->json(['data' => $backup], $backup->status === 'success' ? 201 : 500);
    }

    /** Download a decompressed-ready .sql.gz; the checksum is re-verified first. */
    public function download(Backup $backup)
    {
        try {
            $archive = $this->backups->readArchive($backup);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        Audit::log('backup_downloaded', 'D3', 'backups', $backup->id);

        return response($archive, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.str_replace('.enc', '', $backup->file_name).'"',
        ]);
    }

    public function restore(Request $request, Backup $backup)
    {
        $request->validate(['confirm' => ['required', 'in:RESTORE']]);
        set_time_limit(1800);

        try {
            $snapshot = $this->backups->restore($backup);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['message' => "Restored {$backup->file_name}. A pre-restore snapshot was saved as {$snapshot->file_name}."]);
    }
}
