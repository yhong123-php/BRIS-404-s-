<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A6 — Achievements (Addendum §2.1).
 */
class AchievementController extends Controller
{
    public function index(Resident $resident)
    {
        return response()->json(['data' => $resident->achievements()->get()]);
    }

    public function store(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'date_achieved' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:255'],
            'attachment_path' => ['nullable', 'string'],
        ]);

        $achievement = Achievement::create($data + [
            'resident_id' => $resident->id,
            'recorded_by' => Auth::id(),
        ]);

        return response()->json(['data' => $achievement], 201);
    }

    public function destroy(Achievement $achievement)
    {
        $achievement->delete();

        return response()->json(['message' => 'Achievement removed.']);
    }
}
