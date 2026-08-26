<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Services\Demo\DemoScenarioFingerprint;
use App\Services\Demo\DemoScenarioModuleEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @return array{
 *     owner: User,
 *     property: Property,
 *     lead_request: LeadRequest,
 *     service_request: ServiceRequest,
 *     work: Work,
 *     checklist_item: WorkChecklistItem,
 *     team_member: TeamMember
 * }
 */
function createFieldOperationsFingerprintFixture(string $suffix): array
{
    $createdAt = CarbonImmutable::parse('2026-01-12 14:00:00', 'UTC');
    $serviceAt = CarbonImmutable::parse('2026-08-18 12:30:00', 'UTC');
    $owner = User::factory()->create([
        'name' => 'Alex Morgan',
        'email' => "owner-{$suffix}@example.test",
        'company_name' => 'Nettoyage Horizon',
        'company_type' => 'services',
        'company_sector' => 'cleaning',
        'company_timezone' => 'America/Toronto',
        'currency_code' => 'CAD',
    ]);
    $staffUser = User::factory()->create([
        'name' => 'Sofia Tremblay',
        'email' => "sofia-{$suffix}@example.test",
        'profile_picture' => '/images/presets/avatar-2.svg',
    ]);
    $teamMember = TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $staffUser->id,
        'role' => 'member',
        'title' => 'Cheffe d’équipe',
        'permissions' => ['jobs.view', 'jobs.edit'],
        'planning_rules' => [
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ],
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Camille',
        'last_name' => 'Roy',
        'company_name' => 'Clinique du Parc',
        'email' => "camille-{$suffix}@example.test",
        'phone' => '+1 514 555 0142',
        'description' => 'Entretien récurrent des espaces communs.',
        'tags' => ['commercial', 'recurring'],
        'logo' => '/images/presets/company-2.svg',
        'billing_same_as_physical' => true,
        'is_vip' => false,
        'loyalty_points_balance' => 0,
    ]);
    backdateFieldOperationsRecord($customer, $createdAt);

    $property = Property::query()->create([
        'customer_id' => $customer->id,
        'type' => 'physical',
        'is_default' => true,
        'country' => 'Canada',
        'street1' => '1250, avenue du Parc',
        'street2' => 'Bureau 300',
        'city' => 'Montréal',
        'state' => 'QC',
        'zip' => 'H2X 2J4',
    ]);
    backdateFieldOperationsRecord($property, $createdAt->addHour());

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Entretien hebdomadaire — Clinique du Parc',
        'instructions' => 'Désinfecter les surfaces, nettoyer les sols et vérifier les consommables.',
        'start_date' => '2026-08-18',
        'end_date' => '2026-08-18',
        'start_time' => '18:00:00',
        'end_time' => '21:00:00',
        'is_all_day' => false,
        'later' => false,
        'ends' => 'After',
        'frequencyNumber' => 1,
        'frequency' => 'Weekly',
        'totalVisits' => 52,
        'repeatsOn' => ['tuesday'],
        'type' => 'commercial',
        'category' => 'recurring_cleaning',
        'status' => Work::STATUS_COMPLETED,
        'is_completed' => true,
        'completed_at' => $serviceAt->addHours(3),
        'subtotal' => 420,
        'total' => 482.90,
        'billing_mode' => 'per_job',
        'billing_cycle' => 'weekly',
        'billing_grouping' => 'per_visit',
        'billing_delay_days' => 7,
        'billing_date_rule' => 'completion_date',
        'auto_started_at' => $serviceAt,
        'auto_completed_at' => $serviceAt->addHours(3),
        'start_alerted_at' => $serviceAt->subMinutes(15),
        'end_alerted_at' => $serviceAt->addHours(2)->addMinutes(45),
    ]);
    backdateFieldOperationsRecord($work, $createdAt->addMonths(7));

    DB::table('work_team_members')->insert([
        'work_id' => $work->id,
        'team_member_id' => $teamMember->id,
        'role' => 'lead',
        'created_at' => $createdAt->addMonths(7),
        'updated_at' => $createdAt->addMonths(7),
    ]);

    $checklistItem = WorkChecklistItem::query()->create([
        'work_id' => $work->id,
        'title' => 'Désinfecter les zones de contact',
        'description' => 'Poignées, comptoirs et interrupteurs.',
        'status' => 'completed',
        'sort_order' => 1,
        'completed_at' => $serviceAt->addHours(2),
    ]);
    backdateFieldOperationsRecord($checklistItem, $serviceAt);

    $leadRequest = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'assigned_team_member_id' => $teamMember->id,
        'external_customer_id' => "external-{$suffix}",
        'channel' => 'phone',
        'status' => LeadRequest::STATUS_QUALIFIED,
        'service_type' => 'commercial_cleaning',
        'urgency' => 'normal',
        'title' => 'Demande d’entretien récurrent',
        'description' => 'Nettoyage de la clinique trois soirs par semaine.',
        'contact_name' => 'Camille Roy',
        'contact_email' => "lead-{$suffix}@example.test",
        'contact_phone' => '+1 514 555 0142',
        'country' => 'Canada',
        'state' => 'QC',
        'city' => 'Montréal',
        'street1' => '1250, avenue du Parc',
        'street2' => 'Bureau 300',
        'postal_code' => 'H2X 2J4',
        'lat' => 45.5102000,
        'lng' => -73.5755000,
        'is_serviceable' => true,
        'first_response_at' => $createdAt->addMinutes(12),
        'last_activity_at' => $createdAt->addHour(),
        'sla_due_at' => $createdAt->addHours(4),
        'triage_priority' => 2,
        'risk_level' => 'low',
        'status_updated_at' => $createdAt->addHour(),
        'next_follow_up_at' => $createdAt->addDay(),
        'meta' => [
            'scenario' => 'cleaning',
            'budget' => 1500,
            'customer_id' => $customer->id,
            'suggested_service_ids' => [$customer->id],
            'services_sur_devis' => [$customer->id],
            'dispatch' => [
                'work_id' => $work->id,
                'zone' => 'centre-ville',
            ],
        ],
    ]);
    backdateFieldOperationsRecord($leadRequest, $createdAt);

    $serviceRequest = ServiceRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'prospect_id' => $leadRequest->id,
        'source' => 'lead_conversion',
        'channel' => 'phone',
        'status' => ServiceRequest::STATUS_ACCEPTED,
        'request_type' => 'recurring_contract',
        'service_type' => 'commercial_cleaning',
        'title' => 'Entretien récurrent de la clinique',
        'description' => 'Visites les lundi, mercredi et vendredi après la fermeture.',
        'requester_name' => 'Camille Roy',
        'requester_email' => "service-request-{$suffix}@example.test",
        'requester_phone' => '+1 514 555 0142',
        'street1' => '1250, avenue du Parc',
        'street2' => 'Bureau 300',
        'city' => 'Montréal',
        'state' => 'QC',
        'postal_code' => 'H2X 2J4',
        'country' => 'Canada',
        'source_ref' => 'lead:'.$leadRequest->id,
        'source_meta' => [
            'lead_id' => $leadRequest->id,
            'origin_ref' => 'lead:'.$leadRequest->id,
            'campaign' => 'inbound_phone',
        ],
        'submitted_at' => $createdAt,
        'accepted_at' => $createdAt->addHours(2),
        'meta' => [
            'budget' => 1500,
            'work_id' => $work->id,
            'access_window' => 'after_hours',
        ],
    ]);
    backdateFieldOperationsRecord($serviceRequest, $createdAt->addHours(2));

    return [
        'owner' => $owner,
        'property' => $property,
        'lead_request' => $leadRequest,
        'service_request' => $serviceRequest,
        'work' => $work,
        'checklist_item' => $checklistItem,
        'team_member' => $teamMember,
    ];
}

function backdateFieldOperationsRecord(Model $model, CarbonImmutable $createdAt): void
{
    DB::table($model->getTable())
        ->where($model->getKeyName(), $model->getKey())
        ->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    $model->refresh();
}

test('request and job evidence counts both intake records and persisted works', function () {
    $fixture = createFieldOperationsFingerprintFixture('evidence');

    $evidence = app(DemoScenarioModuleEvidence::class)->inspect(
        $fixture['owner'],
        ['requests', 'jobs'],
    );

    expect($evidence)->toBe([
        'requests' => [
            'records' => 2,
            'source' => 'requests+service_requests',
            'demonstrable' => true,
        ],
        'jobs' => [
            'records' => 1,
            'source' => 'works',
            'demonstrable' => true,
        ],
    ]);
});

test('field operation fingerprints are tenant independent and cover every operational projection', function () {
    $left = createFieldOperationsFingerprintFixture('left');
    $right = createFieldOperationsFingerprintFixture('right');
    $fingerprint = app(DemoScenarioFingerprint::class);

    $current = $fingerprint->forOwner($left['owner']);

    expect($current)->toBe($fingerprint->forOwner($right['owner']));

    $left['property']->update(['street1' => '1252, avenue du Parc']);
    $next = $fingerprint->forOwner($left['owner']);
    expect($next)->not->toBe($current);
    $current = $next;

    $left['lead_request']->update(['risk_level' => 'medium']);
    $next = $fingerprint->forOwner($left['owner']);
    expect($next)->not->toBe($current);
    $current = $next;

    $left['service_request']->update(['status' => ServiceRequest::STATUS_IN_PROGRESS]);
    $next = $fingerprint->forOwner($left['owner']);
    expect($next)->not->toBe($current);
    $current = $next;

    $left['work']->update(['job_title' => 'Entretien approfondi — Clinique du Parc']);
    $next = $fingerprint->forOwner($left['owner']);
    expect($next)->not->toBe($current);
    $current = $next;

    DB::table('work_team_members')
        ->where('work_id', $left['work']->id)
        ->where('team_member_id', $left['team_member']->id)
        ->update(['role' => 'quality_reviewer']);
    $next = $fingerprint->forOwner($left['owner']);
    expect($next)->not->toBe($current);
    $current = $next;

    $left['checklist_item']->update(['status' => 'needs_attention']);

    expect($fingerprint->forOwner($left['owner']))->not->toBe($current);
});
