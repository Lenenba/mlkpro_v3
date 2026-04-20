<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('returns enriched quote recovery analytics on the quote index response', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Analytics',
        'last_name' => 'Customer',
        'company_name' => 'Analytics Recovery Co',
        'email' => 'quote-analytics@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Viewed quote',
            'status' => 'sent',
            'subtotal' => 2400,
            'total' => 2400,
            'last_sent_at' => $referenceTime->copy()->subDays(3),
            'last_viewed_at' => $referenceTime->copy()->subHours(8),
            'follow_up_count' => 1,
        ]);

        Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Due quote',
            'status' => 'sent',
            'subtotal' => 950,
            'total' => 950,
            'last_sent_at' => $referenceTime->copy()->subDays(4),
            'next_follow_up_at' => $referenceTime->copy()->addHours(2),
            'follow_up_count' => 1,
        ]);

        Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'High value quote',
            'status' => 'draft',
            'subtotal' => 5200,
            'total' => 5200,
            'follow_up_count' => 0,
        ]);

        Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Never followed quote',
            'status' => 'sent',
            'subtotal' => 700,
            'total' => 700,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'follow_up_count' => 0,
        ]);

        Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Accepted quote',
            'status' => 'accepted',
            'subtotal' => 1500,
            'total' => 1500,
            'accepted_at' => $referenceTime->copy()->subDay(),
        ]);

        Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Declined quote',
            'status' => 'declined',
            'subtotal' => 800,
            'total' => 800,
        ]);

        $response = $this->actingAs($user)->getJson(route('quote.index'));

        $response->assertOk()
            ->assertJsonPath('stats.total', 6)
            ->assertJsonPath('stats.open', 4)
            ->assertJsonPath('stats.accepted', 1)
            ->assertJsonPath('stats.declined', 1)
            ->assertJsonPath('stats.never_followed', 1)
            ->assertJsonPath('stats.due', 1)
            ->assertJsonPath('stats.viewed_not_accepted', 1)
            ->assertJsonPath('stats.high_value', 1)
            ->assertJsonPath('stats.sent_to_accepted_rate', 20);
    } finally {
        Carbon::setTestNow();
    }
});
