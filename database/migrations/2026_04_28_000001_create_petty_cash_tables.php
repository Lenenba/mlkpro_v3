<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name')->default('Petite caisse');
            $table->string('currency_code', 3)->default('CAD');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->decimal('low_balance_threshold', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->unique(['user_id', 'currency_code'], 'petty_cash_account_currency_unique');
        });

        Schema::create('petty_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('petty_cash_account_id')->constrained('petty_cash_accounts')->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->string('status', 50)->default('draft');
            $table->decimal('amount', 12, 2);
            $table->string('currency_code', 3)->default('CAD');
            $table->date('movement_date');
            $table->text('note')->nullable();
            $table->boolean('requires_receipt')->default(false);
            $table->boolean('receipt_attached')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['petty_cash_account_id', 'status']);
            $table->index(['petty_cash_account_id', 'movement_date']);
            $table->index(['petty_cash_account_id', 'type']);
        });

        Schema::create('petty_cash_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('petty_cash_movement_id')->constrained('petty_cash_movements')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'petty_cash_movement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_attachments');
        Schema::dropIfExists('petty_cash_movements');
        Schema::dropIfExists('petty_cash_accounts');
    }
};
