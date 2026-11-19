<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1 — AI-Powered Barangay Assistant tables (SDD Tables 3.3 and 3.4, §4.22).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which query categories each role may use; maintained by the Captain.
        Schema::create('assistant_access', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->enum('query_category', ['residents', 'social_assistance', 'employment_skills', 'education', 'community_analysis']);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->primary(['role_id', 'query_category']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('last_message_at');
            $table->dateTime('delete_after');
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
            $table->index('delete_after');
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->enum('sender', ['user', 'assistant']);
            $table->text('content');
            // Structured "From BRIS records" payload (tables/figures) shown to the user.
            $table->json('records')->nullable();
            $table->string('function_called')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only log of every assistant query (SRS §3.2.3.9, SDD §5.3).
        Schema::create('ai_query_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ai_conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('question', 1000);
            $table->string('query_category')->nullable();
            $table->string('function_called')->nullable();
            $table->json('parameters')->nullable();
            $table->string('personal_data_types')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->enum('sent_to_model', ['none', 'aggregated', 'masked'])->default('none');
            $table->enum('outcome', ['answered', 'refused', 'unavailable', 'no_data']);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_query_logs');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('assistant_access');
    }
};
