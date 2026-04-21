<?php

use App\Models\Customer;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('customer detail counts only open work statuses as active works while keeping all jobs available in history', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Active',
        'last_name' => 'Works',
        'company_name' => 'Active Works Co.',
        'email' => 'active-works@example.com',
    ]);

    foreach ([
        Work::STATUS_SCHEDULED,
        Work::STATUS_IN_PROGRESS,
        Work::STATUS_DISPUTE,
        Work::STATUS_CLOSED,
        Work::STATUS_CANCELLED,
    ] as $index => $status) {
        Work::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'job_title' => 'Customer detail work '.$index,
            'instructions' => 'Customer detail work instructions '.$index,
            'status' => $status,
            'start_date' => now()->copy()->addDays($index),
        ]);
    }

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customer.id', $customer->id)
            ->has('customer.works', 5)
            ->where('stats.jobs', 5)
            ->where('stats.active_works', 3)
        );
});
