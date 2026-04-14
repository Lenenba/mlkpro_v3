<?php

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('reimbursed_by_user_id')->nullable()->after('paid_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('team_member_id')->nullable()->after('paid_by_user_id')->constrained('team_members')->nullOnDelete();
            $table->foreignId('recurrence_source_expense_id')->nullable()->after('team_member_id')->constrained('expenses')->nullOnDelete();
            $table->string('reimbursement_status', 50)->default(Expense::REIMBURSEMENT_STATUS_NOT_APPLICABLE)->after('reimbursable');
            $table->string('reimbursement_reference')->nullable()->after('reimbursement_status');
            $table->timestamp('reimbursed_at')->nullable()->after('approved_at');
            $table->string('recurrence_frequency', 50)->nullable()->after('is_recurring');
            $table->unsignedSmallInteger('recurrence_interval')->default(1)->after('recurrence_frequency');
            $table->date('recurrence_next_date')->nullable()->after('recurrence_interval');
            $table->date('recurrence_ends_at')->nullable()->after('recurrence_next_date');
            $table->timestamp('recurrence_last_generated_at')->nullable()->after('recurrence_ends_at');

            $table->index(['user_id', 'reimbursement_status']);
            $table->index(['user_id', 'team_member_id']);
            $table->index(['user_id', 'is_recurring', 'recurrence_next_date'], 'expenses_recurring_next_idx');
        });

        DB::table('expenses')
            ->select(['id', 'status', 'reimbursable', 'is_recurring', 'expense_date', 'paid_date', 'paid_by_user_id', 'updated_at'])
            ->orderBy('id')
            ->chunkById(100, function ($expenses): void {
                foreach ($expenses as $expense) {
                    $updates = [];

                    if ((bool) $expense->reimbursable) {
                        $updates['reimbursement_status'] = $expense->status === Expense::STATUS_REIMBURSED
                            ? Expense::REIMBURSEMENT_STATUS_REIMBURSED
                            : Expense::REIMBURSEMENT_STATUS_PENDING;
                    }

                    if ($expense->status === Expense::STATUS_REIMBURSED) {
                        $updates['reimbursed_by_user_id'] = $expense->paid_by_user_id;
                        $updates['reimbursed_at'] = $expense->paid_date
                            ? Carbon::parse((string) $expense->paid_date)->endOfDay()
                            : $expense->updated_at;
                    }

                    if ((bool) $expense->is_recurring) {
                        $expenseDate = $expense->expense_date ? Carbon::parse((string) $expense->expense_date) : null;

                        if ($expenseDate) {
                            $updates['recurrence_frequency'] = Expense::RECURRENCE_FREQUENCY_MONTHLY;
                            $updates['recurrence_interval'] = 1;
                            $updates['recurrence_next_date'] = $expenseDate->copy()->addMonthNoOverflow()->toDateString();
                        }
                    }

                    if ($updates !== []) {
                        DB::table('expenses')->where('id', $expense->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'reimbursement_status']);
            $table->dropIndex(['user_id', 'team_member_id']);
            $table->dropIndex('expenses_recurring_next_idx');
            $table->dropConstrainedForeignId('reimbursed_by_user_id');
            $table->dropConstrainedForeignId('team_member_id');
            $table->dropConstrainedForeignId('recurrence_source_expense_id');
            $table->dropColumn([
                'reimbursement_status',
                'reimbursement_reference',
                'reimbursed_at',
                'recurrence_frequency',
                'recurrence_interval',
                'recurrence_next_date',
                'recurrence_ends_at',
                'recurrence_last_generated_at',
            ]);
        });
    }
};
