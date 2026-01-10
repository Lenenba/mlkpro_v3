<?php

namespace App\Services\Demo;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Work;
use App\Models\User;

class DemoContextService
{
    public function resolvePlaceholders(User $user): array
    {
        $accountId = $user->accountOwnerId();
        $domain = config('demo.accounts_email_domain', 'example.test');

        $customer = Customer::query()
            ->where('user_id', $accountId)
            ->where('email', $this->guidedCustomerEmail($accountId, $domain))
            ->first();

        $quote = Quote::query()
            ->where('user_id', $accountId)
            ->where('job_title', $this->guidedQuoteTitle())
            ->latest('id')
            ->first();
        if (!$quote) {
            $quote = Quote::query()
                ->where('user_id', $accountId)
                ->latest('id')
                ->first();
        }

        $work = null;
        if ($quote && $quote->work_id) {
            $work = Work::query()
                ->where('user_id', $accountId)
                ->whereKey($quote->work_id)
                ->first();
        }

        if (!$work) {
            $work = Work::query()
                ->where('user_id', $accountId)
                ->where('job_title', $this->guidedWorkTitle())
                ->latest('id')
                ->first();
        }
        if (!$work) {
            $work = Work::query()
                ->where('user_id', $accountId)
                ->latest('id')
                ->first();
        }

        $invoiceQuery = Invoice::query()->where('user_id', $accountId);
        if ($work) {
            $invoiceQuery->where('work_id', $work->id);
        }
        $invoice = $invoiceQuery->latest('id')->first();
        if (!$invoice && $work) {
            $invoice = Invoice::query()
                ->where('user_id', $accountId)
                ->latest('id')
                ->first();
        }

        return [
            'demo_customer' => $customer?->id,
            'demo_quote' => $quote?->id,
            'demo_work' => $work?->id,
            'demo_invoice' => $invoice?->id,
        ];
    }

    public function resolveRouteParams(User $user, ?array $params): array
    {
        $params = $params ?? [];
        if (!$params) {
            return [];
        }

        $placeholders = $this->resolvePlaceholders($user);
        $resolved = [];

        foreach ($params as $key => $value) {
            if (is_string($value) && array_key_exists($value, $placeholders)) {
                $resolved[$key] = $placeholders[$value];
                continue;
            }
            $resolved[$key] = $value;
        }

        return $resolved;
    }

    private function guidedCustomerEmail(int $accountId, string $domain): string
    {
        return "guided-demo-customer-{$accountId}@{$domain}";
    }

    private function guidedQuoteTitle(): string
    {
        return 'Guided Demo Quote';
    }

    private function guidedWorkTitle(): string
    {
        return 'Guided Demo Job';
    }
}
