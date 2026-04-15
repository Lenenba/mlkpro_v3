<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'is_active', 'sort_order']);
        });

        Schema::create('accounting_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_domain');
            $table->string('source_key');
            $table->foreignId('debit_account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->foreignId('credit_account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->foreignId('tax_account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_domain', 'source_key'], 'accounting_mappings_domain_key_unique');
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('accounting_entry_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('source_event_key');
            $table->string('source_reference')->nullable();
            $table->date('entry_date');
            $table->timestamp('generated_at')->nullable();
            $table->string('status')->default('generated');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'source_type', 'source_id', 'source_event_key'],
                'accounting_entry_batches_source_unique'
            );
            $table->index(['user_id', 'entry_date', 'source_type', 'status'], 'accounting_entry_batches_lookup_idx');
        });

        Schema::create('accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('accounting_entry_batches')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->string('direction');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->string('currency_code')->default('CAD');
            $table->date('entry_date');
            $table->string('description');
            $table->string('review_status')->default('unreviewed');
            $table->string('reconciliation_status')->default('unreviewed');
            $table->timestamp('locked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'entry_date'], 'accounting_entries_user_date_idx');
            $table->index(['batch_id', 'direction'], 'accounting_entries_batch_direction_idx');
            $table->index(['user_id', 'review_status', 'reconciliation_status'], 'accounting_entries_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entries');
        Schema::dropIfExists('accounting_entry_batches');
        Schema::dropIfExists('accounting_mappings');
        Schema::dropIfExists('accounting_accounts');
    }
};
