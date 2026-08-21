<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerHistoryExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        Carbon::setTestNow(Carbon::parse('2026-08-21 10:00:00', 'America/Toronto'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_timeline_merges_authorized_sources_and_excludes_deleted_or_foreign_finance(): void
    {
        $owner = $this->owner();
        $foreignOwner = $this->owner();
        $customer = $this->customer($owner);
        [$member, $service] = $this->appointmentResources($owner);
        $reservation = $this->reservation($owner, $customer, $member, $service, now()->addDays(3));
        $invoice = $this->invoice($owner, $customer, 125, 'partial');
        $payment = $this->payment($owner, $customer, $invoice, 25, Payment::STATUS_REFUNDED);
        $deletedInvoice = $this->invoice($owner, $customer, 999, 'sent');
        $deletedInvoice->forceFill(['deleted_at' => now()])->saveQuietly();
        $foreignInvoice = $this->invoice($foreignOwner, $customer, 700, 'sent');
        $this->payment($foreignOwner, $customer, $foreignInvoice, 700, Payment::STATUS_COMPLETED);

        $note = ActivityLog::record($owner, $customer, 'sales_note_added', [
            'note' => 'Prefers morning appointments.',
        ], 'Customer note');
        $note->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->saveQuietly();
        $profile = ActivityLog::record($owner, $customer, 'updated', [
            'before' => ['phone' => '111'],
            'after' => ['phone' => '222'],
            'changes' => ['phone' => ['before' => '111', 'after' => '222']],
        ], 'Customer updated');
        $profile->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->saveQuietly();

        $campaign = Campaign::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Summer follow-up',
            'type' => Campaign::TYPE_ANNOUNCEMENT,
            'status' => Campaign::STATUS_COMPLETED,
            'schedule_type' => Campaign::SCHEDULE_MANUAL,
        ]);
        $campaignEvent = CampaignEvent::query()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'channel' => Campaign::CHANNEL_EMAIL,
            'event_type' => CampaignEvent::EVENT_DELIVERED,
            'occurred_at' => now()->subHours(3),
        ]);
        $foreignCampaign = Campaign::query()->create([
            'user_id' => $foreignOwner->id,
            'created_by_user_id' => $foreignOwner->id,
            'name' => 'Foreign campaign',
            'type' => Campaign::TYPE_ANNOUNCEMENT,
            'status' => Campaign::STATUS_COMPLETED,
            'schedule_type' => Campaign::SCHEDULE_MANUAL,
        ]);
        $hostileCampaignEvent = CampaignEvent::query()->create([
            'campaign_id' => $foreignCampaign->id,
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'channel' => Campaign::CHANNEL_EMAIL,
            'event_type' => CampaignEvent::EVENT_OPENED,
            'occurred_at' => now()->subHour(),
        ]);

        $response = $this->timeline($owner, $customer, ['period' => 'all', 'per_page' => 50]);
        $items = collect($response->json('data'));

        $this->assertEqualsCanonicalizing(
            ['appointments', 'invoices', 'payments', 'notes', 'communications', 'profile_changes'],
            $items->pluck('type')->unique()->all()
        );
        $this->assertNotNull($items->firstWhere('id', 'reservation:'.$reservation->id));
        $this->assertNotNull($items->firstWhere('id', 'campaign:'.$campaignEvent->id));
        $this->assertNull($items->firstWhere('id', 'campaign:'.$hostileCampaignEvent->id));
        $this->assertSame(-25.0, (float) data_get($items->firstWhere('id', 'payment:'.$payment->id), 'amount.value'));
        $this->assertNull($items->firstWhere('id', 'invoice:'.$deletedInvoice->id));
        $this->assertNull($items->firstWhere('id', 'invoice:'.$foreignInvoice->id));
        $response
            ->assertJsonPath('meta.types', [])
            ->assertJsonPath('meta.timezone', 'America/Toronto')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_timeline_cursor_is_stable_without_duplicates_and_rejects_tampering(): void
    {
        $owner = $this->owner();
        $customer = $this->customer($owner);
        $at = now()->subDay();

        foreach (range(1, 5) as $index) {
            $log = ActivityLog::record($owner, $customer, 'updated', [
                'changes' => ['first_name' => ['before' => 'Before', 'after' => 'After '.$index]],
            ], 'Profile update '.$index);
            $log->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }

        $first = $this->timeline($owner, $customer, [
            'period' => 'all',
            'types' => ['profile_changes'],
            'per_page' => 2,
        ])->assertJsonPath('meta.has_more', true);
        $second = $this->timeline($owner, $customer, [
            'period' => 'all',
            'types' => ['profile_changes'],
            'per_page' => 2,
            'cursor' => $first->json('meta.next_cursor'),
        ])->assertJsonPath('meta.has_more', true);
        $third = $this->timeline($owner, $customer, [
            'period' => 'all',
            'types' => ['profile_changes'],
            'per_page' => 2,
            'cursor' => $second->json('meta.next_cursor'),
        ])->assertJsonPath('meta.has_more', false);

        $ids = collect([$first, $second, $third])
            ->flatMap(fn (TestResponse $page): array => $page->json('data'))
            ->pluck('id')
            ->all();
        $this->assertCount(5, $ids);
        $this->assertCount(5, array_unique($ids));

        $this->actingAs($owner)
            ->getJson(route('customer.activity_index', [
                'customer' => $customer,
                'period' => 'all',
                'cursor' => $first->json('meta.next_cursor').'tampered',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    public function test_custom_period_uses_inclusive_company_days_across_dst(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00', 'America/Toronto'));
        $owner = $this->owner();
        $customer = $this->customer($owner);
        $timestamps = [
            '2026-03-08 04:59:59 UTC',
            '2026-03-08 05:00:00 UTC',
            '2026-03-09 03:59:59 UTC',
            '2026-03-09 04:00:00 UTC',
        ];

        foreach ($timestamps as $index => $timestamp) {
            $log = ActivityLog::record($owner, $customer, 'updated', [
                'changes' => ['phone' => ['before' => (string) $index, 'after' => (string) ($index + 1)]],
            ], 'Boundary '.$index);
            $at = Carbon::parse($timestamp);
            $log->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }

        $response = $this->timeline($owner, $customer, [
            'period' => 'custom',
            'from' => '2026-03-08',
            'to' => '2026-03-08',
            'types' => ['profile_changes'],
        ]);

        $this->assertSame(
            ['Boundary 2 · Phone', 'Boundary 1 · Phone'],
            collect($response->json('data'))->pluck('description')->all()
        );
        $response
            ->assertJsonPath('meta.from', '2026-03-08')
            ->assertJsonPath('meta.to', '2026-03-08');
    }

    public function test_member_history_hides_finance_and_limits_reservations_to_their_assignment(): void
    {
        $owner = $this->owner();
        $customer = $this->customer($owner);
        [$ownMember, $service, $employee] = $this->appointmentResources($owner, ['reservations.view']);
        [$otherMember] = $this->appointmentResources($owner);
        $ownReservation = $this->reservation($owner, $customer, $ownMember, $service, now()->addDay());
        $otherReservation = $this->reservation($owner, $customer, $otherMember, $service, now()->addDays(2));
        $invoice = $this->invoice($owner, $customer, 250, 'sent');
        $this->payment($owner, $customer, $invoice, 50, Payment::STATUS_PAID);

        $response = $this->timeline($employee, $customer, ['period' => 'all']);
        $items = collect($response->json('data'));

        $this->assertSame(['reservation:'.$ownReservation->id], $items->pluck('id')->all());
        $this->assertNotContains('reservation:'.$otherReservation->id, $items->pluck('id')->all());
        $this->assertNotContains('invoices', $response->json('meta.available_types'));
        $this->assertNotContains('payments', $response->json('meta.available_types'));

        $this->actingAs($employee)
            ->getJson(route('customer.show', $customer))
            ->assertOk()
            ->assertJsonPath('canLogSalesActivity', false)
            ->assertJsonPath('billing.summary.total_invoiced', 0)
            ->assertJsonPath('billing.recentPayments', [])
            ->assertJsonMissingPath('customer.invoices');

        $this->actingAs($employee)
            ->postJson(route('crm.sales-activities.customers.store', $customer), [
                'action' => 'sales_note_added',
                'note' => 'Forbidden note',
            ])
            ->assertForbidden();
    }

    public function test_view_all_reservations_and_sales_manage_enable_the_expected_scope_and_writer(): void
    {
        $owner = $this->owner();
        $customer = $this->customer($owner);
        [$viewerMember, $service, $employee] = $this->appointmentResources($owner, [
            'view_all_reservations',
            'sales.manage',
        ]);
        [$otherMember] = $this->appointmentResources($owner);
        $this->reservation($owner, $customer, $viewerMember, $service, now()->addDay());
        $this->reservation($owner, $customer, $otherMember, $service, now()->addDays(2));

        $response = $this->timeline($employee, $customer, [
            'period' => 'all',
            'types' => ['appointments'],
        ]);
        $this->assertCount(2, $response->json('data'));

        $this->actingAs($employee)
            ->postJson(route('crm.sales-activities.customers.store', $customer), [
                'action' => 'sales_note_added',
                'note' => 'Authorized note',
            ])
            ->assertCreated();
    }

    public function test_customer_mutations_record_exact_before_after_and_skip_no_ops(): void
    {
        $owner = $this->owner();
        $customer = $this->customer($owner);

        $this->actingAs($owner)
            ->patchJson(route('customer.notes.update', $customer), ['description' => 'Call after 4 PM.'])
            ->assertOk();
        $this->actingAs($owner)
            ->patchJson(route('customer.notes.update', $customer), ['description' => 'Call after 4 PM.'])
            ->assertOk();
        $noteLogs = ActivityLog::query()->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('action', 'notes_updated')
            ->get();
        $this->assertCount(1, $noteLogs);
        $this->assertNull(data_get($noteLogs->first()->properties, 'before.description'));
        $this->assertSame('Call after 4 PM.', data_get($noteLogs->first()->properties, 'after.description'));

        $this->actingAs($owner)
            ->putJson(route('customer.update', $customer), [
                'client_type' => 'individual',
                'first_name' => 'Updated',
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'portal_access' => false,
                'billing_mode' => 'end_of_job',
                'billing_grouping' => 'single',
            ])
            ->assertOk();
        $updated = ActivityLog::query()->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Timeline', data_get($updated->properties, 'before.first_name'));
        $this->assertSame('Updated', data_get($updated->properties, 'after.first_name'));

        $this->actingAs($owner)
            ->postJson(route('customer.bulk'), ['action' => 'archive', 'ids' => [$customer->id]])
            ->assertOk();
        $this->actingAs($owner)
            ->postJson(route('customer.bulk'), ['action' => 'archive', 'ids' => [$customer->id]])
            ->assertOk();
        $archiveLogs = ActivityLog::query()->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('action', 'customer_archived')
            ->get();
        $this->assertCount(1, $archiveLogs);
        $this->assertTrue((bool) data_get($archiveLogs->first()->properties, 'before.is_active'));
        $this->assertFalse((bool) data_get($archiveLogs->first()->properties, 'after.is_active'));
        $this->assertSame('bulk', data_get($archiveLogs->first()->properties, 'source'));

        $this->actingAs($owner)
            ->patchJson(route('marketing.vip.customer.update', $customer), ['is_vip' => true])
            ->assertOk();
        $vipLog = ActivityLog::query()->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('action', 'customer_vip_updated')
            ->firstOrFail();
        $this->assertFalse((bool) data_get($vipLog->properties, 'before.is_vip'));
        $this->assertTrue((bool) data_get($vipLog->properties, 'after.is_vip'));
    }

    public function test_customer_show_exposes_the_unified_contract_and_keeps_legacy_activity(): void
    {
        $owner = $this->owner();
        $customer = $this->customer($owner);
        ActivityLog::record($owner, $customer, 'sales_note_added', ['note' => 'Legacy-compatible'], 'Legacy-compatible');

        $this->actingAs($owner)
            ->getJson(route('customer.show', $customer))
            ->assertOk()
            ->assertJsonPath('canLogSalesActivity', true)
            ->assertJsonPath('customerActivityEndpoint', route('customer.activity_index', $customer, false))
            ->assertJsonStructure([
                'activity',
                'customerActivity' => [
                    'data',
                    'meta' => ['period', 'types', 'available_types', 'timezone', 'has_more', 'next_cursor'],
                    'links' => ['next'],
                ],
            ]);
    }

    public function test_api_timeline_exposes_the_same_authorized_contract(): void
    {
        $owner = $this->owner();
        $customer = $this->customer($owner);
        ActivityLog::record($owner, $customer, 'sales_note_added', ['note' => 'API timeline'], 'API timeline');
        $token = $owner->createToken('customer-history-test')->plainTextToken;

        $this->withToken($token)
            ->getJson(route('api.customer.activity_index', [
                'customer' => $customer,
                'period' => 'all',
                'types' => ['notes'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.type', 'notes')
            ->assertJsonPath('data.0.description', 'API timeline')
            ->assertJsonPath('meta.types', ['notes'])
            ->assertJsonStructure([
                'data',
                'meta' => ['available_types', 'timezone', 'has_more', 'next_cursor'],
                'links' => ['next'],
            ]);
    }

    public function test_web_and_api_timelines_reject_a_foreign_customer(): void
    {
        $owner = $this->owner();
        $foreignCustomer = $this->customer($this->owner());

        $this->actingAs($owner)
            ->getJson(route('customer.activity_index', $foreignCustomer))
            ->assertForbidden();

        $token = $owner->createToken('foreign-customer-history-test')->plainTextToken;
        $this->withToken($token)
            ->getJson(route('api.customer.activity_index', $foreignCustomer))
            ->assertForbidden();
    }

    /** @param array<string, mixed> $query */
    private function timeline(User $actor, Customer $customer, array $query = []): TestResponse
    {
        return $this->actingAs($actor)
            ->getJson(route('customer.activity_index', ['customer' => $customer, ...$query]))
            ->assertOk();
    }

    private function owner(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'owner'], ['description' => 'Owner']);

        return User::factory()->create([
            'role_id' => $role->id,
            'company_type' => 'services',
            'company_sector' => 'salon',
            'company_timezone' => 'America/Toronto',
            'currency_code' => 'CAD',
            'onboarding_completed_at' => now(),
            'company_features' => [
                'reservations' => true,
                'team_members' => true,
                'invoices' => true,
                'sales' => true,
                'campaigns' => true,
                'services' => true,
                'products' => true,
            ],
        ]);
    }

    private function customer(User $owner): Customer
    {
        return Customer::query()->create([
            'user_id' => $owner->id,
            'client_type' => 'individual',
            'first_name' => 'Timeline',
            'last_name' => 'Customer',
            'email' => 'timeline-'.$owner->id.'-'.str()->random(6).'@example.com',
            'portal_access' => false,
            'is_active' => true,
            'is_vip' => false,
        ]);
    }

    /** @param array<int, string> $permissions @return array{TeamMember, Product, User} */
    private function appointmentResources(User $owner, array $permissions = []): array
    {
        $role = Role::query()->firstOrCreate(['name' => 'employee'], ['description' => 'Employee']);
        $employee = User::factory()->create([
            'role_id' => $role->id,
            'company_type' => 'services',
            'onboarding_completed_at' => now(),
        ]);
        $member = TeamMember::query()->create([
            'account_id' => $owner->id,
            'user_id' => $employee->id,
            'role' => 'member',
            'title' => 'Stylist',
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        $category = ProductCategory::query()->create([
            'name' => 'Timeline services '.$employee->id,
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);
        $service = Product::query()->create([
            'name' => 'Timeline service '.$employee->id,
            'description' => 'Timeline service',
            'category_id' => $category->id,
            'user_id' => $owner->id,
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'tracking_type' => 'none',
            'price' => 50,
            'currency_code' => 'CAD',
            'stock' => 0,
            'minimum_stock' => 0,
            'is_active' => true,
        ]);

        return [$member, $service, $employee];
    }

    private function reservation(
        User $owner,
        Customer $customer,
        TeamMember $member,
        Product $service,
        Carbon $startsAt
    ): Reservation {
        return Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $member->id,
            'client_id' => $customer->id,
            'service_id' => $service->id,
            'created_by_user_id' => $owner->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'America/Toronto',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);
    }

    private function invoice(User $owner, Customer $customer, float $total, string $status): Invoice
    {
        return Invoice::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'currency_code' => 'CAD',
        ]);
    }

    private function payment(
        User $owner,
        Customer $customer,
        Invoice $invoice,
        float $amount,
        string $status
    ): Payment {
        return Payment::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency_code' => 'CAD',
            'tip_amount' => 0,
            'charged_total' => $amount,
            'method' => 'card',
            'provider' => 'test',
            'status' => $status,
            'paid_at' => now()->subHours(2),
        ]);
    }
}
