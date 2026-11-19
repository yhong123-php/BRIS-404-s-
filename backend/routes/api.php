<?php

use App\Http\Controllers\Admin\Auth\StaffAuthController;
use App\Http\Controllers\Admin\AchievementController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ClassificationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FamilyController;
use App\Http\Controllers\Admin\HouseholdController;
use App\Http\Controllers\Admin\PurokController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SkillController;
use App\Http\Controllers\Admin\RelationshipController;
use App\Http\Controllers\Admin\ResidentController;
use App\Http\Controllers\Admin\AnalysisController;
use App\Http\Controllers\Admin\AssistantAccessController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\PopulationEventController;
use App\Http\Controllers\Admin\RecordRequestController;
use App\Http\Controllers\Admin\ReliefController;
use App\Http\Controllers\Admin\ReportEngineController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\SocialAssistanceController;
use App\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])->name('login');

    Route::middleware(['auth:sanctum', 'ability:staff', 'idle-check'])->group(function () {
        Route::post('logout', [StaffAuthController::class, 'logout'])->name('logout');
        Route::get('me', [StaffAuthController::class, 'me'])->name('me');
        Route::post('change-password', [StaffAuthController::class, 'changePassword'])->name('change-password');

        // C1 — Dashboard
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])
            ->middleware('permission:dashboard.view');


        // A1 — Resident registration and updates
        Route::middleware('permission:residents.view')->group(function () {
            Route::get('residents', [ResidentController::class, 'index']);
            Route::get('residents/{resident}', [ResidentController::class, 'show']);
        });
        Route::post('residents', [ResidentController::class, 'store'])
            ->middleware('permission:residents.create');
        Route::patch('residents/{resident}', [ResidentController::class, 'update'])
            ->middleware('permission:residents.edit');
        Route::post('residents/{resident}/restore', [ResidentController::class, 'restore'])
            ->middleware('permission:residents.restore');
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
        Route::middleware('permission:relationships.view')->group(function () {
            Route::get('residents/{resident}/relationships', [RelationshipController::class, 'index']);
            Route::get('families', [FamilyController::class, 'index']);
            Route::get('families/{family}', [FamilyController::class, 'show']);
        });
        Route::middleware('permission:relationships.manage')->group(function () {
            Route::post('relationships', [RelationshipController::class, 'store']);
            Route::post('relationships/{relationship}/end-spouse', [RelationshipController::class, 'endSpouseRelation']);
            Route::post('families', [FamilyController::class, 'store']);
            Route::patch('families/{family}', [FamilyController::class, 'update']);
        });

        // D2 — Audit log
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');

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
        Route::middleware('permission:classifications.view')->group(function () {
            Route::get('classification-types', [ClassificationController::class, 'types']);
            Route::get('residents/{resident}/classifications', [ClassificationController::class, 'index']);
            Route::get('residents/{resident}/classifications/suggestions', [ClassificationController::class, 'suggestions']);
        });
        Route::post('classification-types', [ClassificationController::class, 'storeType'])
            ->middleware('permission:classifications.configure');
        Route::middleware('permission:classifications.manage')->group(function () {
            Route::post('residents/{resident}/classifications', [ClassificationController::class, 'store']);
            Route::post('classifications/{classification}/revoke', [ClassificationController::class, 'revoke']);
        });

        // Social assistance received (SRS §3.2.3.10)
        Route::get('residents/{resident}/assistance', [SocialAssistanceController::class, 'index'])
            ->middleware('permission:assistance.view');
        Route::post('residents/{resident}/assistance', [SocialAssistanceController::class, 'store'])
            ->middleware('permission:assistance.record');

        // A6 — Skills, achievements, and livelihood registry
        Route::middleware('permission:a6.view')->group(function () {
            Route::get('skill-types', [SkillController::class, 'types']);
            Route::get('skill-types/{skillType}/residents', [SkillController::class, 'bySkillType']);
            Route::get('residents/{resident}/skills', [SkillController::class, 'index']);
            Route::get('residents/{resident}/achievements', [AchievementController::class, 'index']);
        });
        Route::middleware('permission:a6.manage')->group(function () {
            Route::post('skill-types', [SkillController::class, 'storeType']);
            Route::post('residents/{resident}/skills', [SkillController::class, 'store']);
            Route::post('skills/{skill}/verify', [SkillController::class, 'verify']);
            Route::post('skills/{skill}/revoke', [SkillController::class, 'revoke']);
            Route::post('residents/{resident}/achievements', [AchievementController::class, 'store']);
            Route::delete('achievements/{achievement}', [AchievementController::class, 'destroy']);
        });

        // C2 — Reports
        Route::middleware('permission:reports.generate.all')->group(function () {
            Route::get('reports/beneficiaries', [ReportsController::class, 'beneficiaries']);
            Route::get('reports/employment', [ReportsController::class, 'employment']);
            Route::get('reports/skills', [ReportsController::class, 'skills']);
            Route::get('reports/achievements', [ReportsController::class, 'achievements']);
        });

        // C2 — Report engine (all SDD §4.12 reports, CSV export)
        Route::middleware('permission:reports.generate.all')->group(function () {
            Route::get('report-engine', [ReportEngineController::class, 'catalog']);
            Route::get('report-engine/{report}', [ReportEngineController::class, 'run']);
        });

        // D1 — User, role and permission management (Captain)
        Route::middleware('permission:users.manage')->group(function () {
            Route::get('users', [UserManagementController::class, 'index']);
            Route::post('users', [UserManagementController::class, 'store']);
            Route::patch('users/{user}', [UserManagementController::class, 'update']);
            Route::post('users/{user}/reset-password', [UserManagementController::class, 'resetPassword']);
            Route::get('roles', [UserManagementController::class, 'roles']);
            Route::put('roles/{role}/permissions', [UserManagementController::class, 'updateRolePermissions']);
        });

        // D3 — Backup and restore (Captain)
        Route::middleware('permission:backup.restore')->group(function () {
            Route::get('backups', [BackupController::class, 'index']);
            Route::post('backups', [BackupController::class, 'store']);
            Route::get('backups/{backup}/download', [BackupController::class, 'download']);
            Route::post('backups/{backup}/restore', [BackupController::class, 'restore']);
        });

        // B2 / B4 — Relief distribution and smart allocation
        Route::middleware('permission:relief.view')->group(function () {
            Route::get('relief', [ReliefController::class, 'index']);
            Route::get('relief/{distribution}', [ReliefController::class, 'show']);
        });
        Route::middleware('permission:relief.create')->group(function () {
            Route::post('relief', [ReliefController::class, 'store']);
            Route::patch('relief/{distribution}', [ReliefController::class, 'update']);
            Route::post('relief/{distribution}/households', [ReliefController::class, 'addHousehold']);
            Route::post('relief-beneficiaries/{beneficiary}/exclude', [ReliefController::class, 'exclude']);
        });
        Route::middleware('permission:f3.prepare')->group(function () {
            Route::post('relief/{distribution}/generate', [ReliefController::class, 'generate']);
            Route::post('relief/{distribution}/submit', [ReliefController::class, 'submit']);
        });
        Route::middleware('permission:f3.approve')->group(function () {
            Route::post('relief/{distribution}/decide', [ReliefController::class, 'decide']);
            Route::post('relief/{distribution}/close', [ReliefController::class, 'close']);
        });
        Route::post('relief-beneficiaries/{beneficiary}/claim', [ReliefController::class, 'claim'])->middleware('permission:relief.record_claim');
        Route::post('relief-claims/{claim}/reverse', [ReliefController::class, 'reverseClaim'])->middleware('permission:relief.reverse_claim');

        // B3 — Births, deaths, transfers
        Route::get('population-events', [PopulationEventController::class, 'index'])->middleware('permission:population_events.view');
        Route::post('population-events', [PopulationEventController::class, 'store'])->middleware('permission:population_events.record');

        // E1 — Resident record copies (both roles)
        Route::middleware('permission:record_copies.issue')->group(function () {
            Route::get('residents/{resident}/record-copies', [RecordRequestController::class, 'recordCopies']);
            Route::post('residents/{resident}/record-copies', [RecordRequestController::class, 'issueRecordCopy']);
        });

        // G1 — Consent (Secretary records, Captain configures purposes)
        Route::get('consent-purposes', [RecordRequestController::class, 'purposes'])->middleware('permission:g1.record,g1.configure');
        Route::middleware('permission:g1.configure')->group(function () {
            Route::post('consent-purposes', [RecordRequestController::class, 'storePurpose']);
            Route::patch('consent-purposes/{purpose}', [RecordRequestController::class, 'updatePurpose']);
        });
        Route::get('residents/{resident}/consents', [RecordRequestController::class, 'consents'])->middleware('permission:g1.record,g1.configure');
        Route::post('residents/{resident}/consents', [RecordRequestController::class, 'recordConsent'])->middleware('permission:g1.record');

        // G2 — Data-subject requests (Secretary receives, Captain decides)
        Route::get('data-subject-requests', [RecordRequestController::class, 'dataSubjectRequests'])->middleware('permission:g2.receive,g2.decide');
        Route::post('residents/{resident}/data-subject-requests', [RecordRequestController::class, 'receiveDataSubjectRequest'])->middleware('permission:g2.receive');
        Route::middleware('permission:g2.decide')->group(function () {
            Route::post('data-subject-requests/{dataSubjectRequest}/decide', [RecordRequestController::class, 'decideDataSubjectRequest']);
            Route::post('data-subject-requests/{dataSubjectRequest}/complete', [RecordRequestController::class, 'completeDataSubjectRequest']);
        });

        // F1 — AI resident analysis (Secretary confirms; Captain views)
        Route::get('ai-suggestions', [AnalysisController::class, 'suggestions'])->middleware('permission:f1.read');
        Route::middleware('permission:f1.confirm')->group(function () {
            Route::post('ai-suggestions/run', [AnalysisController::class, 'runAnalysis']);
            Route::post('ai-suggestions/{suggestion}/decide', [AnalysisController::class, 'decideSuggestion']);
        });

        // F2 — Livelihood and job opportunity matching
        Route::middleware('permission:f2.view')->group(function () {
            Route::get('opportunities', [AnalysisController::class, 'opportunities']);
            Route::get('opportunities/{opportunity}', [AnalysisController::class, 'showOpportunity']);
        });
        Route::middleware('permission:f2.manage')->group(function () {
            Route::post('opportunities', [AnalysisController::class, 'storeOpportunity']);
            Route::patch('opportunities/{opportunity}', [AnalysisController::class, 'updateOpportunity']);
            Route::post('opportunities/{opportunity}/match', [AnalysisController::class, 'rematch']);
            Route::patch('opportunity-matches/{match}', [AnalysisController::class, 'updateMatch']);
        });

        // C3 — Predictive and demographic dashboard
        Route::middleware('permission:f4.view')->group(function () {
            Route::get('forecasts', [AnalysisController::class, 'forecasts']);
            Route::post('forecasts/generate', [AnalysisController::class, 'generateForecasts']);
        });

        // H1 — Assistant access configuration and assistant logs (Captain)
        Route::middleware('permission:assistant.configure')->group(function () {
            Route::get('assistant-access', [AssistantAccessController::class, 'index']);
            Route::put('assistant-access', [AssistantAccessController::class, 'update']);
            Route::get('assistant-logs', [AssistantAccessController::class, 'logs']);
        });
    });
});

// H1 — AI-Powered Barangay Assistant (SDD §2.3: assistant routes under /api/assistant/*)
Route::prefix('assistant')->middleware(['auth:sanctum', 'ability:staff', 'idle-check', 'permission:assistant.use'])->group(function () {
    Route::get('status', [AssistantController::class, 'status']);
    Route::get('conversations/current', [AssistantController::class, 'current']);
    Route::post('conversations', [AssistantController::class, 'newConversation']);
    Route::post('messages', [AssistantController::class, 'message'])->middleware('throttle:20,1');
});
