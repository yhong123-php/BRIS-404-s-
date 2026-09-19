<?php

use App\Http\Controllers\Admin\Auth\StaffAuthController;
use App\Http\Controllers\Admin\AchievementController;
use App\Http\Controllers\Admin\ClassificationController;
use App\Http\Controllers\Admin\HouseholdController;
use App\Http\Controllers\Admin\PurokController;
use App\Http\Controllers\Admin\SkillController;
use App\Http\Controllers\Admin\RelationshipController;
use App\Http\Controllers\Admin\ResidentController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])->name('login');

    Route::middleware(['auth:sanctum', 'ability:staff', 'idle-check'])->group(function () {
        Route::post('logout', [StaffAuthController::class, 'logout'])->name('logout');
        Route::get('me', [StaffAuthController::class, 'me'])->name('me');
        Route::post('change-password', [StaffAuthController::class, 'changePassword'])->name('change-password');

        // A1 — Resident registration and updates
        Route::middleware('permission:residents.view')->group(function () {
            Route::get('residents', [ResidentController::class, 'index']);
            Route::get('residents/{resident}', [ResidentController::class, 'show']);
        });
        Route::post('residents', [ResidentController::class, 'store'])
            ->middleware('permission:residents.create');
        Route::patch('residents/{resident}', [ResidentController::class, 'update'])
            ->middleware('permission:residents.edit');
        Route::patch('residents/{resident}/health-fields', [ResidentController::class, 'updateHealthFields'])
            ->middleware('permission:residents.edit_health_fields');
        Route::post('residents/{resident}/merge', [ResidentController::class, 'merge'])
            ->middleware('permission:residents.merge_archive');
        Route::post('residents/{resident}/archive', [ResidentController::class, 'archive'])
            ->middleware('permission:residents.merge_archive');

        // A2 — Household management
        Route::middleware('permission:households.view')->group(function () {
            Route::get('households', [HouseholdController::class, 'index']);
            Route::get('households/{household}', [HouseholdController::class, 'show']);
        });
        Route::middleware('permission:households.manage')->group(function () {
            Route::post('households', [HouseholdController::class, 'store']);
            Route::patch('households/{household}', [HouseholdController::class, 'update']);
            Route::post('households/{household}/members', [HouseholdController::class, 'addMember']);
            Route::post('households/{household}/promote-head', [HouseholdController::class, 'promoteHead']);
            Route::post('households/{household}/split', [HouseholdController::class, 'split']);
        });

        // A3 — Family relationship management
        Route::middleware('permission:relationships.manage')->group(function () {
            Route::get('residents/{resident}/relationships', [RelationshipController::class, 'index']);
            Route::post('relationships', [RelationshipController::class, 'store']);
            Route::post('relationships/{relationship}/end-spouse', [RelationshipController::class, 'endSpouseRelation']);
        });

        // A4 — Purok population monitoring
        Route::middleware('permission:puroks.view')->group(function () {
            Route::get('puroks', [PurokController::class, 'index']);
            Route::get('puroks/{purok}/households', [PurokController::class, 'households']);
            Route::get('puroks/{purok}/statistics', [PurokController::class, 'statistics']);
        });
        Route::middleware('permission:puroks.manage')->group(function () {
            Route::post('puroks', [PurokController::class, 'store']);
            Route::patch('puroks/{purok}', [PurokController::class, 'update']);
        });

        // A5 — Resident classification
        Route::middleware('permission:classifications.manage')->group(function () {
            Route::get('classification-types', [ClassificationController::class, 'types']);
            Route::post('classification-types', [ClassificationController::class, 'storeType']);
            Route::get('residents/{resident}/classifications', [ClassificationController::class, 'index']);
            Route::get('residents/{resident}/classifications/suggestions', [ClassificationController::class, 'suggestions']);
            Route::post('residents/{resident}/classifications', [ClassificationController::class, 'store']);
            Route::post('classifications/{classification}/revoke', [ClassificationController::class, 'revoke']);
        });

        // A6 — Skills, achievements, and livelihood registry
        Route::middleware('permission:a6.manage,a6.manage_health_linked')->group(function () {
            Route::get('skill-types', [SkillController::class, 'types']);
            Route::post('skill-types', [SkillController::class, 'storeType'])->middleware('permission:a6.manage');
            Route::get('skill-types/{skillType}/residents', [SkillController::class, 'bySkillType']);
            Route::get('residents/{resident}/skills', [SkillController::class, 'index']);
            Route::post('residents/{resident}/skills', [SkillController::class, 'store']);
            Route::post('skills/{skill}/verify', [SkillController::class, 'verify']);
            Route::post('skills/{skill}/revoke', [SkillController::class, 'revoke']);

            Route::get('residents/{resident}/achievements', [AchievementController::class, 'index']);
            Route::post('residents/{resident}/achievements', [AchievementController::class, 'store']);
            Route::delete('achievements/{achievement}', [AchievementController::class, 'destroy']);
        });
    });
});
