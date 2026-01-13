<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PlanScan;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Sale;
use App\Models\TaskMedia;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WorkMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AccountDeletionService
{
    private const DEFAULT_PUBLIC_FILES = [
        'customers/customer.png',
        'products/product.jpg',
    ];

    public function deleteAccount(User $accountOwner): void
    {
        $this->cancelPaddleSubscriptions($accountOwner);

        $accountId = $accountOwner->id;
        $teamMemberUserIds = TeamMember::query()
            ->where('account_id', $accountId)
            ->pluck('user_id')
            ->filter()
            ->unique();
        $portalUserIds = Customer::query()
            ->where('user_id', $accountId)
            ->whereNotNull('portal_user_id')
            ->pluck('portal_user_id')
            ->unique();
        $candidateUserIds = $teamMemberUserIds->merge($portalUserIds)->unique()->values();

        $usersToDelete = User::query()
            ->whereIn('id', $candidateUserIds)
            ->get()
            ->filter(fn (User $user) => $this->canDeleteUser($user, $accountId));

        $userIds = collect([$accountId])
            ->merge($usersToDelete->pluck('id'))
            ->unique()
            ->values();
        $userEmails = User::query()
            ->whereIn('id', $userIds)
            ->pluck('email');

        $filePaths = $this->collectAccountFilePaths($accountId, $userIds);

        DB::transaction(function () use ($accountId, $userIds, $userEmails, $usersToDelete) {
            $this->purgeUserArtifacts($userIds, $userEmails);
            $this->deleteAccountData($accountId);

            if ($usersToDelete->isNotEmpty()) {
                User::query()
                    ->whereIn('id', $usersToDelete->pluck('id'))
                    ->delete();
            }

            User::query()->whereKey($accountId)->delete();
        });

        $this->deleteFilePaths($filePaths);
    }

    public function deleteUser(User $user): void
    {
        $userIds = collect([$user->id]);
        $userEmails = collect([$user->email]);
        $filePaths = $this->filterDeletablePaths($this->collectUserFilePaths($userIds));

        DB::transaction(function () use ($user, $userIds, $userEmails) {
            $this->purgeUserArtifacts($userIds, $userEmails);
            DB::table('team_members')->where('user_id', $user->id)->delete();
            User::query()->whereKey($user->id)->delete();
        });

        $this->deleteFilePaths($filePaths);
    }

    private function canDeleteUser(User $user, int $accountId): bool
    {
        if ($user->id === $accountId) {
            return false;
        }

        if ($user->isAccountOwner()) {
            return false;
        }

        $hasOtherMemberships = TeamMember::query()
            ->where('user_id', $user->id)
            ->where('account_id', '!=', $accountId)
            ->exists();

        return !$hasOtherMemberships;
    }

    private function deleteAccountData(int $accountId): void
    {
        DB::table('tasks')->where('account_id', $accountId)->delete();
        DB::table('requests')->where('user_id', $accountId)->delete();
        DB::table('quotes')->where('user_id', $accountId)->delete();
        DB::table('works')->where('user_id', $accountId)->delete();
        DB::table('invoices')->where('user_id', $accountId)->delete();
        DB::table('sales')->where('user_id', $accountId)->delete();
        DB::table('plan_scans')->where('user_id', $accountId)->delete();
        DB::table('products')->where('user_id', $accountId)->delete();
        DB::table('warehouses')->where('user_id', $accountId)->delete();
        DB::table('transactions')
            ->whereIn('customer_id', function ($query) use ($accountId) {
                $query->select('id')->from('customers')->where('user_id', $accountId);
            })
            ->delete();
        DB::table('product_categories')->where('user_id', $accountId)->delete();
        DB::table('customers')->where('user_id', $accountId)->delete();
        DB::table('team_members')->where('account_id', $accountId)->delete();
    }

    private function purgeUserArtifacts(Collection $userIds, Collection $userEmails): void
    {
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds)
            ->delete();

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->delete();

        DB::table('activity_logs')
            ->whereIn('user_id', $userIds)
            ->delete();

        DB::table('password_reset_tokens')
            ->whereIn('email', $userEmails)
            ->delete();

        $subscriptionIds = DB::table('paddle_subscriptions')
            ->where('billable_type', User::class)
            ->whereIn('billable_id', $userIds)
            ->pluck('id');

        if ($subscriptionIds->isNotEmpty()) {
            DB::table('paddle_subscription_items')
                ->whereIn('subscription_id', $subscriptionIds)
                ->delete();
        }

        DB::table('paddle_subscriptions')
            ->whereIn('id', $subscriptionIds)
            ->delete();

        DB::table('paddle_customers')
            ->where('billable_type', User::class)
            ->whereIn('billable_id', $userIds)
            ->delete();

        DB::table('paddle_transactions')
            ->where('billable_type', User::class)
            ->whereIn('billable_id', $userIds)
            ->delete();
    }

    private function cancelPaddleSubscriptions(User $accountOwner): void
    {
        if (!config('cashier.api_key')) {
            return;
        }

        $subscriptions = $accountOwner->subscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            if ($subscription->canceled()) {
                continue;
            }

            try {
                $subscription->cancelNow();
            } catch (\Throwable $exception) {
                Log::warning('Unable to cancel Paddle subscription during account deletion.', [
                    'user_id' => $accountOwner->id,
                    'subscription_id' => $subscription->id,
                    'paddle_id' => $subscription->paddle_id,
                    'exception' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }
    }

    private function collectAccountFilePaths(int $accountId, Collection $userIds): array
    {
        $paths = collect()
            ->merge($this->collectUserFilePaths($userIds))
            ->merge(Customer::query()->where('user_id', $accountId)->pluck('logo'))
            ->merge(Customer::query()->where('user_id', $accountId)->pluck('header_image'))
            ->merge(Product::query()->where('user_id', $accountId)->pluck('image'))
            ->merge(ProductImage::query()
                ->whereIn('product_id', function ($query) use ($accountId) {
                    $query->select('id')->from('products')->where('user_id', $accountId);
                })
                ->pluck('path'))
            ->merge(TaskMedia::query()
                ->whereIn('task_id', function ($query) use ($accountId) {
                    $query->select('id')->from('tasks')->where('account_id', $accountId);
                })
                ->pluck('path'))
            ->merge(WorkMedia::query()
                ->whereIn('work_id', function ($query) use ($accountId) {
                    $query->select('id')->from('works')->where('user_id', $accountId);
                })
                ->pluck('path'))
            ->merge(PlanScan::query()->where('user_id', $accountId)->pluck('plan_file_path'))
            ->merge(Sale::query()->where('user_id', $accountId)->pluck('delivery_proof'));

        return $this->filterDeletablePaths($paths);
    }

    private function collectUserFilePaths(Collection $userIds): Collection
    {
        return User::query()
            ->whereIn('id', $userIds)
            ->get(['profile_picture', 'company_logo'])
            ->flatMap(fn (User $user) => [$user->profile_picture, $user->company_logo]);
    }

    private function filterDeletablePaths(Collection $paths): array
    {
        return $paths
            ->filter(fn (?string $path) => $this->isDeletablePath($path))
            ->unique()
            ->values()
            ->all();
    }

    private function isDeletablePath(?string $path): bool
    {
        if (!$path) {
            return false;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return false;
        }

        return !in_array($path, self::DEFAULT_PUBLIC_FILES, true);
    }

    private function deleteFilePaths(array $paths): void
    {
        if (empty($paths)) {
            return;
        }

        Storage::disk('public')->delete($paths);
    }
}
