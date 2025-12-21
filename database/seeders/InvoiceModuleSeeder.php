<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;

class InvoiceModuleSeeder extends Seeder
{
    /**
     * Seed data that exercises invoice and payment workflows.
     */
    public function run(): void
    {
        $user = User::first();
        if (!$user) {
            $user = User::factory()->create([
                'name' => 'Invoice Demo',
                'email' => 'invoice.demo@example.com',
                'role_id' => 1,
            ]);
        }

        $customer = Customer::byUser($user->id)->first();
        if (!$customer) {
            $customer = Customer::factory()->create([
                'user_id' => $user->id,
                'email' => 'invoice.customer@example.com',
            ]);
        }

        $quote = Quote::byUser($user->id)->first();

        $jobs = [
            [
                'title' => 'Seeded job - Exterior refresh',
                'status' => 'completed',
                'total' => 1800,
            ],
            [
                'title' => 'Seeded job - Interior prep',
                'status' => 'in_progress',
                'total' => 950,
            ],
        ];

        foreach ($jobs as $index => $job) {
            $work = Work::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                    'job_title' => $job['title'],
                ],
                [
                    'quote_id' => $quote?->id,
                    'instructions' => 'Seeded job for invoice workflow.',
                    'start_date' => now()->toDateString(),
                    'status' => $job['status'],
                    'subtotal' => $job['total'],
                    'total' => $job['total'],
                ]
            );

            $invoice = Invoice::firstOrCreate(
                [
                    'work_id' => $work->id,
                ],
                [
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                    'status' => $index === 0 ? 'paid' : 'sent',
                    'total' => $work->total ?? $job['total'],
                ]
            );

            $paymentAmount = $index === 0 ? $invoice->total : round($invoice->total / 2, 2);

            Payment::firstOrCreate(
                [
                    'invoice_id' => $invoice->id,
                    'reference' => 'SEED-PAY-' . ($index + 1),
                ],
                [
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'amount' => $paymentAmount,
                    'method' => $index === 0 ? 'card' : 'transfer',
                    'status' => 'completed',
                    'notes' => 'Seeded payment',
                    'paid_at' => now()->subDays($index + 1),
                ]
            );

            $invoice->refreshPaymentStatus();
        }
    }
}
