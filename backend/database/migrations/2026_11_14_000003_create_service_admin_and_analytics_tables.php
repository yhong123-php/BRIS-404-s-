<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining tables of SDD Section 3:
 *  Table 3.2 — relief (B2/B4), population events (B3), record copies (E1);
 *  Table 3.3 — backups (D3);
 *  Table 3.4 — AI suggestions (F1), opportunities (F2), demographic forecasts
 *              (C3), consent (G1), data-subject requests (G2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------ B2 / B4
        Schema::create('relief_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_of_funds')->nullable();
            $table->string('goods_description');
            $table->unsignedInteger('quantity_available');
            $table->unsignedInteger('quantity_per_beneficiary')->default(1);
            $table->json('target_rule');
            $table->json('priority_weights')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('venue')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'open', 'closed'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('relief_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relief_distribution_id')->constrained('relief_distributions')->cascadeOnDelete();
            $table->foreignId('household_id')->constrained('households')->restrictOnDelete();
            $table->foreignId('resident_id')->nullable()->constrained('residents')->restrictOnDelete();
            $table->unsignedInteger('entitlement_quantity')->default(1);
            $table->decimal('priority_score', 8, 2)->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->json('duplicate_flag')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->enum('inclusion', ['rule', 'manual'])->default('rule');
            $table->boolean('is_excluded')->default(false);
            $table->boolean('within_allocation')->default(true);
            $table->string('override_reason')->nullable();
            $table->enum('status', ['listed', 'claimed', 'unclaimed', 'forfeited'])->default('listed');
            $table->timestamps();
            $table->unique(['relief_distribution_id', 'household_id']);
            $table->index(['relief_distribution_id', 'rank']);
        });

        Schema::create('relief_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relief_beneficiary_id')->constrained('relief_beneficiaries')->cascadeOnDelete();
            $table->foreignId('claimed_by_resident_id')->nullable()->constrained('residents')->nullOnDelete();
            $table->string('claimant_name');
            $table->string('relationship_to_head')->nullable();
            $table->unsignedInteger('quantity_released');
            $table->dateTime('claimed_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------- B3
        Schema::create('population_events', function (Blueprint $table) {
            $table->id();
            $table->enum('event_type', ['birth', 'death', 'transfer_in', 'transfer_out']);
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->date('event_date');
            $table->date('reported_date');
            $table->foreignId('purok_id')->nullable()->constrained('puroks')->nullOnDelete();
            $table->string('document_type')->nullable();
            $table->string('document_number')->nullable();
            $table->string('previous_address')->nullable();
            $table->string('destination_address')->nullable();
            $table->string('cause_or_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['event_type', 'event_date']);
        });

        // ------------------------------------------------------------ E1 / E2
        Schema::create('record_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->string('requested_by_name');
            $table->string('relationship_to_resident')->nullable();
            $table->string('id_presented');
            $table->string('purpose');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });


        // ---------------------------------------------------------------- D3
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('storage_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->enum('type', ['scheduled', 'manual', 'pre_restore']);
            $table->string('checksum', 64)->nullable();
            $table->enum('status', ['success', 'failed']);
            $table->text('error_message')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ------------------------------------------------------------ F1 / F2
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->nullable()->constrained('residents')->cascadeOnDelete();
            $table->foreignId('household_id')->nullable()->constrained('households')->cascadeOnDelete();
            $table->enum('suggestion_type', ['eligibility', 'employment_status', 'skill_category', 'achievement_summary', 'beneficiary_rank']);
            $table->string('program_name')->nullable();
            $table->json('payload');
            $table->text('rationale');
            $table->string('engine_version');
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'suggestion_type']);
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('type', ['job', 'livelihood', 'training']);
            $table->string('provider')->nullable();
            $table->json('required_skills');
            $table->unsignedInteger('slots')->default(1);
            $table->date('closing_date')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('opportunity_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->decimal('score', 6, 2);
            $table->unsignedInteger('rank');
            $table->enum('outcome', ['shortlisted', 'contacted', 'interested', 'declined', 'hired'])->default('shortlisted');
            $table->dateTime('generated_at');
            $table->timestamps();
            $table->unique(['opportunity_id', 'resident_id']);
        });

        // ---------------------------------------------------------------- C3
        Schema::create('demographic_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purok_id')->nullable()->constrained('puroks')->cascadeOnDelete();
            $table->enum('metric', ['population', 'households', 'births', 'deaths', 'relief_demand']);
            $table->string('period');
            $table->decimal('projected_value', 12, 2);
            $table->string('method');
            $table->date('input_from')->nullable();
            $table->date('input_to')->nullable();
            $table->dateTime('generated_at');
            $table->timestamps();
        });

        // ------------------------------------------------------------ G1 / G2
        Schema::create('consent_purposes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_optional')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('resident_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->foreignId('consent_purpose_id')->constrained('consent_purposes')->restrictOnDelete();
            $table->enum('status', ['granted', 'revoked']);
            $table->string('form_path')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['resident_id', 'consent_purpose_id']);
        });

        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->enum('request_type', ['access', 'correction', 'objection', 'erasure']);
            $table->text('details');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['received', 'approved', 'rejected', 'completed'])->default('received');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_reason')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'data_subject_requests', 'resident_consents', 'consent_purposes', 'demographic_forecasts',
            'opportunity_matches', 'opportunities', 'ai_suggestions', 'backups',
            'record_copies', 'population_events', 'relief_claims', 'relief_beneficiaries',
            'relief_distributions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
