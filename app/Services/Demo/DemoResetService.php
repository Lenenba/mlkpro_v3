<?php

namespace App\Services\Demo;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Models\DemoTourProgress;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;

class DemoResetService
{
    public function reset(User $account): void
    {
        $accountId = $account->id;

        DB::transaction(function () use ($accountId) {
            DemoTourProgress::query()->where('user_id', $accountId)->delete();

            Task::query()->where('account_id', $accountId)->delete();
            $teamMemberUserIds = TeamMember::query()
                ->where('account_id', $accountId)
                ->pluck('user_id')
                ->filter()
                ->values();
            TeamMember::query()->where('account_id', $accountId)->delete();

            Invoice::query()->where('user_id', $accountId)->delete();
            Work::query()->where('user_id', $accountId)->delete();
            Quote::query()->where('user_id', $accountId)->delete();

            Transaction::query()->where('user_id', $accountId)->delete();
            Sale::query()->where('user_id', $accountId)->delete();

            Product::query()->where('user_id', $accountId)->delete();
            ProductCategory::query()->where('user_id', $accountId)->delete();

            Customer::query()->where('user_id', $accountId)->delete();

            ActivityLog::query()->where('user_id', $accountId)->delete();

            if ($teamMemberUserIds->isNotEmpty()) {
                User::query()
                    ->whereIn('id', $teamMemberUserIds)
                    ->where('id', '!=', $accountId)
                    ->delete();
            }
        });
    }
}
