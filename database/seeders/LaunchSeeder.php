<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\AvailabilityException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReview;
use App\Models\Payment;
use App\Models\PlatformAdmin;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformNotificationSetting;
use App\Models\PlatformSetting;
use App\Models\PlatformSupportTicket;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\Property;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\QuoteRating;
use App\Models\Reservation;
use App\Models\ReservationCheckIn;
use App\Models\ReservationQueueItem;
use App\Models\ReservationResource;
use App\Models\ReservationResourceAllocation;
use App\Models\ReservationReview;
use App\Models\ReservationSetting;
use App\Models\ReservationWaitlist;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Sale;
use App\Models\ServiceMaterial;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TaskMedia;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\TeamMemberShift;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Models\WorkMedia;
use App\Models\WorkRating;
use App\Services\InventoryService;
use App\Services\TipAllocationService;
use App\Services\WorkBillingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LaunchSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->call(PlatformBaselineSeeder::class);

        $roles = [
            'superadmin' => 'Superadmin role',
            'admin' => 'Admin role',
            'owner' => 'Owner role',
            'employee' => 'Employee role',
            'client' => 'Default client role',
        ];

        foreach ($roles as $name => $description) {
            Role::firstOrCreate(['name' => $name], ['description' => $description]);
        }

        $superadminRoleId = Role::where('name', 'superadmin')->value('id');
        $adminRoleId = Role::where('name', 'admin')->value('id');
        $ownerRoleId = Role::where('name', 'owner')->value('id');
        $clientRoleId = Role::where('name', 'client')->value('id');
        $employeeRoleId = Role::where('name', 'employee')->value('id');

        $superadmin = User::updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role_id' => $superadminRoleId,
                'email_verified_at' => $now,
            ]
        );

        $platformAdmin = User::updateOrCreate(
            ['email' => 'platform.admin@example.com'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'role_id' => $adminRoleId,
                'email_verified_at' => $now,
                'must_change_password' => true,
            ]
        );

        PlatformAdmin::updateOrCreate(
            ['user_id' => $platformAdmin->id],
            [
                'role' => 'ops',
                'permissions' => [
                    'tenants.view',
                    'tenants.manage',
                    'settings.manage',
                    'audit.view',
                    'announcements.manage',
                ],
                'is_active' => true,
                'require_2fa' => false,
            ]
        );

        PlatformNotificationSetting::updateOrCreate(
            ['user_id' => $superadmin->id],
            [
                'channels' => ['email'],
                'categories' => [
                    'new_account',
                    'onboarding_completed',
                    'subscription_started',
                    'plan_changed',
                    'subscription_paused',
                    'subscription_resumed',
                    'subscription_canceled',
                    'payment_succeeded',
                    'payment_failed',
                    'churn_risk',
                ],
                'rules' => [
                    'error_spike' => 10,
                    'payment_failed' => 3,
                    'churn_risk' => 5,
                ],
                'digest_frequency' => 'daily',
            ]
        );

        PlatformSetting::setValue('maintenance', [
            'enabled' => false,
            'message' => '',
        ]);

        PlatformSetting::setValue('templates', [
            'email_default' => 'Merci pour votre confiance.',
            'quote_default' => 'Veuillez trouver votre devis ci-joint.',
            'invoice_default' => 'Votre facture est disponible.',
        ]);

        PlatformSetting::setValue('plan_limits', [
            'free' => [
                'quotes' => 10,
                'requests' => 10,
                'plan_scan_quotes' => 10,
                'invoices' => 10,
                'jobs' => 10,
                'products' => 25,
                'services' => 25,
                'tasks' => 25,
                'team_members' => 1,
            ],
            'starter' => [
                'quotes' => 100,
                'requests' => 100,
                'plan_scan_quotes' => 100,
                'invoices' => 100,
                'jobs' => 100,
                'products' => 200,
                'services' => 200,
                'tasks' => 200,
                'team_members' => 5,
            ],
            'growth' => [
                'quotes' => 300,
                'requests' => 300,
                'plan_scan_quotes' => 300,
                'invoices' => 300,
                'jobs' => 300,
                'products' => 500,
                'services' => 500,
                'tasks' => 600,
                'team_members' => 15,
            ],
            'scale' => [
                'quotes' => 1000,
                'requests' => 1000,
                'plan_scan_quotes' => 1000,
                'invoices' => 1000,
                'jobs' => 1000,
                'products' => 2000,
                'services' => 2000,
                'tasks' => 2500,
                'team_members' => 50,
            ],
        ]);

        $serviceOwner = User::updateOrCreate(
            ['email' => 'owner.services@example.com'],
            [
                'name' => 'Service Owner',
                'password' => Hash::make('password'),
                'role_id' => $ownerRoleId,
                'email_verified_at' => $now,
                'phone_number' => '+15145550000',
                'company_name' => 'Service Demo Co',
                'company_description' => 'Demo service company for workflow testing.',
                'company_country' => 'Canada',
                'company_province' => 'QC',
                'company_city' => 'Montreal',
                'company_timezone' => 'America/Toronto',
                'company_type' => 'services',
                'onboarding_completed_at' => $now,
                'trial_ends_at' => $now->copy()->addMonthNoOverflow(),
                'company_notification_settings' => [
                    'task_day' => [
                        'email' => true,
                        'sms' => false,
                        'whatsapp' => false,
                    ],
                ],
                'payment_methods' => ['cash', 'card'],
            ]
        );
        $serviceOwnerFeatures = (array) ($serviceOwner->company_features ?? []);
        if (!array_key_exists('reservations', $serviceOwnerFeatures)) {
            $serviceOwnerFeatures['reservations'] = true;
        }
        $serviceOwner->update([
            'company_features' => $serviceOwnerFeatures,
        ]);

        $productOwner = User::updateOrCreate(
            ['email' => 'owner.products@example.com'],
            [
                'name' => 'Product Owner',
                'password' => Hash::make('password'),
                'role_id' => $ownerRoleId,
                'email_verified_at' => $now,
                'phone_number' => '+14165550000',
                'company_name' => 'Product Demo Co',
                'company_description' => 'Demo product company for workflow testing.',
                'company_country' => 'Canada',
                'company_province' => 'ON',
                'company_city' => 'Toronto',
                'company_timezone' => 'America/Toronto',
                'company_type' => 'products',
                'onboarding_completed_at' => $now,
                'trial_ends_at' => $now->copy()->addMonthNoOverflow(),
                'company_fulfillment' => [
                    'delivery_enabled' => true,
                    'pickup_enabled' => true,
                    'delivery_fee' => 7.5,
                    'delivery_zone' => 'Greater Toronto Area',
                    'pickup_address' => '125 King St W, Toronto, ON',
                    'prep_time_minutes' => 25,
                    'delivery_notes' => 'Delivery within 24 hours',
                    'pickup_notes' => 'Show your order number at pickup',
                ],
                'company_time_settings' => [
                    'auto_clock_in' => true,
                    'auto_clock_out' => true,
                    'manual_clock' => true,
                ],
                'payment_methods' => ['cash', 'card'],
            ]
        );
        $productOwnerFeatures = (array) ($productOwner->company_features ?? []);
        if (!array_key_exists('sales', $productOwnerFeatures)) {
            $productOwnerFeatures['sales'] = true;
        }
        $productOwner->update([
            'company_features' => $productOwnerFeatures,
        ]);

        $salonOwner = User::updateOrCreate(
            ['email' => 'owner.salon@example.com'],
            [
                'name' => 'Salon Owner',
                'password' => Hash::make('password'),
                'role_id' => $ownerRoleId,
                'email_verified_at' => $now,
                'phone_number' => '+15145550050',
                'company_name' => 'Salon Test Studio',
                'company_description' => 'Salon business test tenant with full reservation scenarios.',
                'company_country' => 'Canada',
                'company_province' => 'QC',
                'company_city' => 'Montreal',
                'company_timezone' => 'America/Toronto',
                'company_type' => 'services',
                'company_sector' => 'salon',
                'onboarding_completed_at' => $now,
                'trial_ends_at' => $now->copy()->addMonthNoOverflow(),
                'payment_methods' => ['cash', 'card'],
                'company_features' => [
                    'quotes' => true,
                    'requests' => true,
                    'reservations' => true,
                    'jobs' => true,
                    'invoices' => true,
                ],
                'company_notification_settings' => [
                    'reservations' => [
                        'enabled' => true,
                        'email' => true,
                        'in_app' => true,
                        'notify_on_created' => true,
                        'notify_on_rescheduled' => true,
                        'notify_on_cancelled' => true,
                        'notify_on_completed' => true,
                        'notify_on_reminder' => true,
                        'notify_on_review_submitted' => true,
                        'review_request_on_completed' => true,
                        'notify_on_queue_pre_call' => true,
                        'notify_on_queue_called' => true,
                        'notify_on_queue_grace_expired' => true,
                        'reminder_hours' => [24, 2],
                    ],
                ],
            ]
        );

        $restaurantOwner = User::updateOrCreate(
            ['email' => 'owner.restaurant@example.com'],
            [
                'name' => 'Restaurant Owner',
                'password' => Hash::make('password'),
                'role_id' => $ownerRoleId,
                'email_verified_at' => $now,
                'phone_number' => '+14165550060',
                'company_name' => 'Restaurant Test Kitchen',
                'company_description' => 'Restaurant business test tenant with full reservation scenarios.',
                'company_country' => 'Canada',
                'company_province' => 'ON',
                'company_city' => 'Toronto',
                'company_timezone' => 'America/Toronto',
                'company_type' => 'services',
                'company_sector' => 'restaurant',
                'onboarding_completed_at' => $now,
                'trial_ends_at' => $now->copy()->addMonthNoOverflow(),
                'payment_methods' => ['cash', 'card'],
                'company_features' => [
                    'quotes' => true,
                    'requests' => true,
                    'reservations' => true,
                    'jobs' => true,
                    'invoices' => true,
                ],
                'company_notification_settings' => [
                    'reservations' => [
                        'enabled' => true,
                        'email' => true,
                        'in_app' => true,
                        'notify_on_created' => true,
                        'notify_on_rescheduled' => true,
                        'notify_on_cancelled' => true,
                        'notify_on_completed' => true,
                        'notify_on_reminder' => true,
                        'notify_on_review_submitted' => true,
                        'review_request_on_completed' => true,
                        'notify_on_queue_pre_call' => true,
                        'notify_on_queue_called' => true,
                        'notify_on_queue_grace_expired' => true,
                        'reminder_hours' => [24, 2],
                    ],
                ],
            ]
        );

        // Module coverage seed accounts.
        $serviceLiteOwner = User::updateOrCreate(
            ['email' => 'owner.services.lite@example.com'],
            [
                'name' => 'Service Owner Lite',
                'password' => Hash::make('password'),
                'role_id' => $ownerRoleId,
                'email_verified_at' => $now,
                'phone_number' => '+15145550020',
                'company_name' => 'Service Lite Co',
                'company_description' => 'Service company with limited modules.',
                'company_country' => 'Canada',
                'company_province' => 'QC',
                'company_city' => 'Quebec',
                'company_type' => 'services',
                'onboarding_completed_at' => $now,
                'trial_ends_at' => $now->copy()->addMonthNoOverflow(),
                'payment_methods' => ['cash', 'card'],
                'company_features' => [
                    'quotes' => true,
                    'requests' => false,
                    'reservations' => false,
                    'jobs' => false,
                    'invoices' => false,
                ],
            ]
        );

        $serviceLiteCustomer = Customer::updateOrCreate(
            [
                'email' => 'lite.client@example.com',
            ],
            [
                'user_id' => $serviceLiteOwner->id,
                'first_name' => 'Lena',
                'last_name' => 'Bouchard',
                'company_name' => 'Lite Client Co',
                'phone' => '+15145550021',
                'description' => 'Customer for quotes-only module coverage.',
                'salutation' => 'Miss',
                'billing_same_as_physical' => true,
            ]
        );

        $serviceLiteProperty = Property::updateOrCreate(
            [
                'customer_id' => $serviceLiteCustomer->id,
                'type' => 'physical',
                'street1' => '12 Lite Way',
            ],
            [
                'is_default' => true,
                'city' => 'Quebec',
                'state' => 'QC',
                'zip' => 'G1A0A1',
                'country' => 'Canada',
            ]
        );

        Quote::updateOrCreate(
            [
                'user_id' => $serviceLiteOwner->id,
                'customer_id' => $serviceLiteCustomer->id,
                'job_title' => 'Lite quote sample',
            ],
            [
                'property_id' => $serviceLiteProperty->id,
                'status' => 'sent',
                'notes' => 'Seeded for quotes-only module.',
                'messages' => null,
                'subtotal' => 320,
                'total' => 320,
                'initial_deposit' => 0,
                'is_fixed' => false,
            ]
        );

        $serviceNoInvoiceOwner = User::updateOrCreate(
            ['email' => 'owner.services.noinvoices@example.com'],
            [
                'name' => 'Service Owner No Invoices',
                'password' => Hash::make('password'),
                'role_id' => $ownerRoleId,
                'email_verified_at' => $now,
                'phone_number' => '+15145550030',
                'company_name' => 'No Invoice Services',
                'company_description' => 'Service company without invoices module.',
                'company_country' => 'Canada',
                'company_province' => 'ON',
                'company_city' => 'Ottawa',
                'company_type' => 'services',
                'onboarding_completed_at' => $now,
                'trial_ends_at' => $now->copy()->addMonthNoOverflow(),
                'payment_methods' => ['cash', 'card'],
                'company_features' => [
                    'invoices' => false,
                ],
            ]
        );

        $noInvoiceCustomer = Customer::updateOrCreate(
            [
                'email' => 'noinvoice.client@example.com',
            ],
            [
                'user_id' => $serviceNoInvoiceOwner->id,
                'first_name' => 'Mason',
                'last_name' => 'Clarke',
                'company_name' => 'No Invoice Client',
                'phone' => '+15145550031',
                'description' => 'Customer for invoice-disabled module coverage.',
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
            ]
        );

        $noInvoiceProperty = Property::updateOrCreate(
            [
                'customer_id' => $noInvoiceCustomer->id,
                'type' => 'physical',
                'street1' => '55 Billing Ave',
            ],
            [
                'is_default' => true,
                'city' => 'Ottawa',
                'state' => 'ON',
                'zip' => 'K1A0B1',
                'country' => 'Canada',
            ]
        );

        $noInvoiceQuote = Quote::updateOrCreate(
            [
                'user_id' => $serviceNoInvoiceOwner->id,
                'customer_id' => $noInvoiceCustomer->id,
                'job_title' => 'No invoice quote',
            ],
            [
                'property_id' => $noInvoiceProperty->id,
                'status' => 'accepted',
                'notes' => 'Seeded for invoice-disabled module.',
                'messages' => null,
                'subtotal' => 540,
                'total' => 540,
                'initial_deposit' => 0,
                'is_fixed' => false,
                'accepted_at' => $now->copy()->subDays(4),
                'signed_at' => $now->copy()->subDays(4),
            ]
        );

        $noInvoiceWork = Work::updateOrCreate(
            [
                'user_id' => $serviceNoInvoiceOwner->id,
                'customer_id' => $noInvoiceCustomer->id,
                'job_title' => 'No invoice work',
            ],
            [
                'quote_id' => $noInvoiceQuote->id,
                'instructions' => 'Seeded work with invoices disabled.',
                'start_date' => $now->copy()->subDays(3)->toDateString(),
                'status' => Work::STATUS_SCHEDULED,
                'subtotal' => 540,
                'total' => 540,
            ]
        );

        Invoice::updateOrCreate(
            [
                'work_id' => $noInvoiceWork->id,
            ],
            [
                'user_id' => $serviceNoInvoiceOwner->id,
                'customer_id' => $noInvoiceCustomer->id,
                'status' => 'sent',
                'total' => 540,
            ]
        );

        $serviceRequestsOwner = User::updateOrCreate(
            ['email' => 'owner.services.requests@example.com'],
            [
                'name' => 'Service Owner Requests',
                'password' => Hash::make('password'),
                'role_id' => $ownerRoleId,
                'email_verified_at' => $now,
                'phone_number' => '+15145550040',
                'company_name' => 'Requests Only Services',
                'company_description' => 'Service company with requests only.',
                'company_country' => 'Canada',
                'company_province' => 'BC',
                'company_city' => 'Vancouver',
                'company_type' => 'services',
                'onboarding_completed_at' => $now,
                'trial_ends_at' => $now->copy()->addMonthNoOverflow(),
                'payment_methods' => ['cash', 'card'],
                'company_features' => [
                    'requests' => true,
                    'quotes' => false,
                    'reservations' => false,
                    'jobs' => false,
                    'invoices' => false,
                ],
            ]
        );

        $requestsCustomer = Customer::updateOrCreate(
            [
                'email' => 'requests.client@example.com',
            ],
            [
                'user_id' => $serviceRequestsOwner->id,
                'first_name' => 'Noah',
                'last_name' => 'Singh',
                'company_name' => 'Requests Client Co',
                'phone' => '+15145550041',
                'description' => 'Customer for requests-only module coverage.',
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
            ]
        );

        Property::updateOrCreate(
            [
                'customer_id' => $requestsCustomer->id,
                'type' => 'physical',
                'street1' => '88 Request Blvd',
            ],
            [
                'is_default' => true,
                'city' => 'Vancouver',
                'state' => 'BC',
                'zip' => 'V5K0A1',
                'country' => 'Canada',
            ]
        );

        LeadRequest::updateOrCreate(
            [
                'user_id' => $serviceRequestsOwner->id,
                'title' => 'Lead - Fence repair',
            ],
            [
                'customer_id' => $requestsCustomer->id,
                'status' => LeadRequest::STATUS_NEW,
                'service_type' => 'Repair',
                'urgency' => 'normal',
                'channel' => 'manual',
                'contact_name' => 'Noah Singh',
                'contact_email' => 'requests.client@example.com',
                'contact_phone' => '+15145550041',
                'country' => 'Canada',
                'state' => 'BC',
                'city' => 'Vancouver',
                'street1' => '88 Request Blvd',
            ]
        );

        $adminUser = User::updateOrCreate(
            ['email' => 'admin.services@example.com'],
            [
                'name' => 'Service Admin',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );

        $memberUser = User::updateOrCreate(
            ['email' => 'member.services@example.com'],
            [
                'name' => 'Service Member',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );

        $adminMember = TeamMember::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'user_id' => $adminUser->id,
            ],
            [
                'role' => 'admin',
                'permissions' => [
                    'jobs.view',
                    'jobs.edit',
                    'tasks.view',
                    'tasks.create',
                    'tasks.edit',
                    'tasks.delete',
                    'reservations.view',
                    'reservations.queue',
                    'reservations.manage',
                ],
                'is_active' => true,
            ]
        );

        $memberMember = TeamMember::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'user_id' => $memberUser->id,
            ],
            [
                'role' => 'member',
                'permissions' => [
                    'jobs.view',
                    'tasks.view',
                    'tasks.edit',
                    'reservations.view',
                    'reservations.queue',
                ],
                'is_active' => true,
            ]
        );

        $productSellerUser = User::updateOrCreate(
            ['email' => 'seller.products@example.com'],
            [
                'name' => 'Product Seller',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );

        $productSellerMember = TeamMember::updateOrCreate(
            [
                'account_id' => $productOwner->id,
                'user_id' => $productSellerUser->id,
            ],
            [
                'role' => 'seller',
                'permissions' => [
                    'sales.pos',
                ],
                'is_active' => true,
            ]
        );

        $productSalesManagerUser = User::updateOrCreate(
            ['email' => 'sales.manager.products@example.com'],
            [
                'name' => 'Sales Manager',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );

        TeamMember::updateOrCreate(
            [
                'account_id' => $productOwner->id,
                'user_id' => $productSalesManagerUser->id,
            ],
            [
                'role' => 'sales_manager',
                'permissions' => [
                    'sales.manage',
                ],
                'is_active' => true,
            ]
        );

        $productSellerUserTwo = User::updateOrCreate(
            ['email' => 'seller2.products@example.com'],
            [
                'name' => 'Product Seller Two',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );

        TeamMember::updateOrCreate(
            [
                'account_id' => $productOwner->id,
                'user_id' => $productSellerUserTwo->id,
            ],
            [
                'role' => 'seller',
                'permissions' => [
                    'sales.pos',
                ],
                'is_active' => true,
            ]
        );

        $productSellerUserThree = User::updateOrCreate(
            ['email' => 'seller3.products@example.com'],
            [
                'name' => 'Product Seller Three',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );

        TeamMember::updateOrCreate(
            [
                'account_id' => $productOwner->id,
                'user_id' => $productSellerUserThree->id,
            ],
            [
                'role' => 'seller',
                'permissions' => [
                    'sales.pos',
                ],
                'is_active' => true,
            ]
        );

        $productSellerUsers = collect([
            $productSellerUser,
            $productSellerUserTwo,
            $productSellerUserThree,
        ])->filter();

        $pendingServiceDate = $now->copy()->addDays(3)->toDateString();
        if ($memberMember?->id && $memberUser?->id) {
            TeamMemberShift::updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'team_member_id' => $memberMember->id,
                    'shift_date' => $pendingServiceDate,
                    'kind' => 'absence',
                    'start_time' => '00:00:00',
                ],
                [
                    'created_by_user_id' => $memberUser->id,
                    'status' => 'pending',
                    'end_time' => '23:59:00',
                    'notes' => 'Seeded pending absence request.',
                ]
            );
        }

        $pendingProductDate = $now->copy()->addDays(5)->toDateString();
        if ($productSellerMember?->id && $productSellerUser?->id) {
            TeamMemberShift::updateOrCreate(
                [
                    'account_id' => $productOwner->id,
                    'team_member_id' => $productSellerMember->id,
                    'shift_date' => $pendingProductDate,
                    'kind' => 'leave',
                    'start_time' => '00:00:00',
                ],
                [
                    'created_by_user_id' => $productSellerUser->id,
                    'status' => 'pending',
                    'end_time' => '23:59:00',
                    'notes' => 'Seeded pending leave request.',
                ]
            );
        }

        $serviceCategory = ProductCategory::resolveForAccount($serviceOwner->id, $serviceOwner->id, 'Services');
        $productCategoryNames = [
            'Products',
            'Cleaning',
            'Safety',
            'Tools',
            'Electrical',
            'Plumbing',
            'Packaging',
            'Retail',
            'Office',
        ];
        $productCategoryMap = [];
        foreach ($productCategoryNames as $name) {
            $productCategoryMap[$name] = ProductCategory::resolveForAccount(
                $productOwner->id,
                $productOwner->id,
                $name
            );
        }
        $productCategory = $productCategoryMap['Products'];

        $serviceProducts = collect([
            ['name' => 'Window cleaning', 'price' => 120],
            ['name' => 'Deep clean package', 'price' => 240],
            ['name' => 'Pressure wash', 'price' => 180],
        ])->map(function ($data) use ($serviceOwner, $serviceCategory) {
            return Product::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'name' => $data['name'],
                ],
                [
                    'category_id' => $serviceCategory->id,
                    'price' => $data['price'],
                    'stock' => 0,
                    'minimum_stock' => 0,
                    'item_type' => Product::ITEM_TYPE_SERVICE,
                ]
            );
        });

        $productSeedData = collect([
            [
                'name' => 'Safety gloves',
                'category' => 'Safety',
                'price' => 15,
                'cost_price' => 4,
                'stock' => 120,
                'minimum_stock' => 20,
                'sku' => 'SUP-GLV-001',
                'barcode' => '0123456789051',
                'unit' => 'pair',
                'supplier_name' => 'SecurePro',
                'tax_rate' => 5.0,
                'tracking_type' => 'none',
                'reserved' => 8,
                'secondary_stock' => 30,
                'bin_locations' => [
                    'main' => 'A-01',
                    'overflow' => 'B-08',
                ],
            ],
            [
                'name' => 'Cleaning kit',
                'category' => 'Cleaning',
                'price' => 85,
                'cost_price' => 45,
                'stock' => 40,
                'minimum_stock' => 10,
                'sku' => 'KIT-CLN-002',
                'barcode' => '0123456789052',
                'unit' => 'kit',
                'supplier_name' => 'CleanCo',
                'tax_rate' => 14.975,
                'tracking_type' => 'lot',
                'damaged' => 2,
                'bin_locations' => [
                    'main' => 'C-03',
                ],
            ],
            [
                'name' => 'Ladder set',
                'category' => 'Tools',
                'price' => 350,
                'cost_price' => 220,
                'stock' => 12,
                'minimum_stock' => 3,
                'sku' => 'EQP-LAD-010',
                'barcode' => '0123456789053',
                'unit' => 'set',
                'supplier_name' => 'LiftIt',
                'tax_rate' => 14.975,
                'tracking_type' => 'serial',
                'reserved' => 1,
                'bin_locations' => [
                    'main' => 'E-12',
                    'overflow' => 'E-15',
                ],
            ],
            [
                'name' => 'Microfiber cloth pack',
                'category' => 'Cleaning',
                'price' => 9.50,
                'cost_price' => 3.50,
                'stock' => 180,
                'minimum_stock' => 25,
                'sku' => 'CLN-MIC-011',
                'barcode' => '0123456789054',
                'unit' => 'pack',
                'supplier_name' => 'CleanCo',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
                'secondary_stock' => 40,
            ],
            [
                'name' => 'Glass cleaner 1L',
                'category' => 'Cleaning',
                'price' => 7.25,
                'cost_price' => 2.90,
                'stock' => 60,
                'minimum_stock' => 20,
                'sku' => 'CLN-GLS-012',
                'barcode' => '0123456789055',
                'unit' => 'bottle',
                'supplier_name' => 'BrightChem',
                'tax_rate' => 14.975,
                'tracking_type' => 'lot',
                'damaged' => 1,
            ],
            [
                'name' => 'Disinfectant spray',
                'category' => 'Cleaning',
                'price' => 6.80,
                'cost_price' => 2.40,
                'stock' => 75,
                'minimum_stock' => 18,
                'sku' => 'CLN-DIS-013',
                'barcode' => '0123456789056',
                'unit' => 'bottle',
                'supplier_name' => 'SafeChem',
                'tax_rate' => 14.975,
                'tracking_type' => 'lot',
            ],
            [
                'name' => 'Trash bags roll',
                'category' => 'Packaging',
                'price' => 5.25,
                'cost_price' => 1.80,
                'stock' => 200,
                'minimum_stock' => 35,
                'sku' => 'PKG-TRH-020',
                'barcode' => '0123456789057',
                'unit' => 'roll',
                'supplier_name' => 'PackRight',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
                'secondary_stock' => 60,
            ],
            [
                'name' => 'Safety goggles',
                'category' => 'Safety',
                'price' => 18,
                'cost_price' => 6,
                'stock' => 80,
                'minimum_stock' => 12,
                'sku' => 'SAF-GOG-021',
                'barcode' => '0123456789058',
                'unit' => 'piece',
                'supplier_name' => 'SecurePro',
                'tax_rate' => 5.0,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Ear protection',
                'category' => 'Safety',
                'price' => 22,
                'cost_price' => 9,
                'stock' => 50,
                'minimum_stock' => 8,
                'sku' => 'SAF-EAR-022',
                'barcode' => '0123456789059',
                'unit' => 'piece',
                'supplier_name' => 'SecurePro',
                'tax_rate' => 5.0,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Work boots',
                'category' => 'Safety',
                'price' => 75,
                'cost_price' => 42,
                'stock' => 25,
                'minimum_stock' => 6,
                'sku' => 'SAF-BOT-023',
                'barcode' => '0123456789060',
                'unit' => 'pair',
                'supplier_name' => 'WorkWear',
                'tax_rate' => 5.0,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Cordless drill',
                'category' => 'Tools',
                'price' => 140,
                'cost_price' => 92,
                'stock' => 8,
                'minimum_stock' => 2,
                'sku' => 'TLS-DRL-030',
                'barcode' => '0123456789061',
                'unit' => 'piece',
                'supplier_name' => 'ToolHub',
                'tax_rate' => 14.975,
                'tracking_type' => 'serial',
            ],
            [
                'name' => 'Hammer',
                'category' => 'Tools',
                'price' => 18,
                'cost_price' => 7,
                'stock' => 70,
                'minimum_stock' => 10,
                'sku' => 'TLS-HMR-031',
                'barcode' => '0123456789062',
                'unit' => 'piece',
                'supplier_name' => 'ToolHub',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Measuring tape',
                'category' => 'Tools',
                'price' => 9.90,
                'cost_price' => 3.90,
                'stock' => 90,
                'minimum_stock' => 12,
                'sku' => 'TLS-TAP-032',
                'barcode' => '0123456789063',
                'unit' => 'piece',
                'supplier_name' => 'ToolHub',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Extension cord 10m',
                'category' => 'Electrical',
                'price' => 24,
                'cost_price' => 11,
                'stock' => 55,
                'minimum_stock' => 10,
                'sku' => 'ELE-COR-040',
                'barcode' => '0123456789064',
                'unit' => 'piece',
                'supplier_name' => 'VoltSupply',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'LED bulb pack',
                'category' => 'Electrical',
                'price' => 12,
                'cost_price' => 5,
                'stock' => 100,
                'minimum_stock' => 15,
                'sku' => 'ELE-LMP-041',
                'barcode' => '0123456789065',
                'unit' => 'pack',
                'supplier_name' => 'VoltSupply',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Fuse kit',
                'category' => 'Electrical',
                'price' => 16,
                'cost_price' => 6.50,
                'stock' => 40,
                'minimum_stock' => 8,
                'sku' => 'ELE-FUS-042',
                'barcode' => '0123456789066',
                'unit' => 'kit',
                'supplier_name' => 'VoltSupply',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Copper pipe 1m',
                'category' => 'Plumbing',
                'price' => 14,
                'cost_price' => 8,
                'stock' => 110,
                'minimum_stock' => 20,
                'sku' => 'PLB-COP-050',
                'barcode' => '0123456789067',
                'unit' => 'piece',
                'supplier_name' => 'PipeWorks',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'PVC elbow 90deg',
                'category' => 'Plumbing',
                'price' => 2.50,
                'cost_price' => 0.70,
                'stock' => 160,
                'minimum_stock' => 30,
                'sku' => 'PLB-ELB-051',
                'barcode' => '0123456789068',
                'unit' => 'piece',
                'supplier_name' => 'PipeWorks',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Plumber tape roll',
                'category' => 'Plumbing',
                'price' => 3.80,
                'cost_price' => 1.10,
                'stock' => 120,
                'minimum_stock' => 25,
                'sku' => 'PLB-TAP-052',
                'barcode' => '0123456789069',
                'unit' => 'roll',
                'supplier_name' => 'PipeWorks',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Barcode scanner',
                'category' => 'Retail',
                'price' => 210,
                'cost_price' => 150,
                'stock' => 6,
                'minimum_stock' => 2,
                'sku' => 'RET-SCN-060',
                'barcode' => '0123456789070',
                'unit' => 'piece',
                'supplier_name' => 'RetailGear',
                'tax_rate' => 14.975,
                'tracking_type' => 'serial',
            ],
            [
                'name' => 'POS terminal',
                'category' => 'Retail',
                'price' => 480,
                'cost_price' => 360,
                'stock' => 4,
                'minimum_stock' => 1,
                'sku' => 'RET-POS-061',
                'barcode' => '0123456789071',
                'unit' => 'piece',
                'supplier_name' => 'RetailGear',
                'tax_rate' => 14.975,
                'tracking_type' => 'serial',
            ],
            [
                'name' => 'Receipt paper roll',
                'category' => 'Retail',
                'price' => 4.20,
                'cost_price' => 1.40,
                'stock' => 150,
                'minimum_stock' => 20,
                'sku' => 'RET-PAP-062',
                'barcode' => '0123456789072',
                'unit' => 'roll',
                'supplier_name' => 'RetailGear',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
                'reserved' => 20,
            ],
            [
                'name' => 'Shelf label pack',
                'category' => 'Retail',
                'price' => 6.60,
                'cost_price' => 2.00,
                'stock' => 90,
                'minimum_stock' => 15,
                'sku' => 'RET-LBL-063',
                'barcode' => '0123456789073',
                'unit' => 'pack',
                'supplier_name' => 'RetailGear',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Packaging tape',
                'category' => 'Packaging',
                'price' => 3.30,
                'cost_price' => 0.90,
                'stock' => 130,
                'minimum_stock' => 25,
                'sku' => 'PKG-TAP-064',
                'barcode' => '0123456789074',
                'unit' => 'roll',
                'supplier_name' => 'PackRight',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Shipping boxes 50x40',
                'category' => 'Packaging',
                'price' => 8.90,
                'cost_price' => 3.40,
                'stock' => 60,
                'minimum_stock' => 12,
                'sku' => 'PKG-BOX-065',
                'barcode' => '0123456789075',
                'unit' => 'box',
                'supplier_name' => 'PackRight',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Office label printer',
                'category' => 'Office',
                'price' => 195,
                'cost_price' => 130,
                'stock' => 5,
                'minimum_stock' => 2,
                'sku' => 'OFF-PRN-070',
                'barcode' => '0123456789076',
                'unit' => 'piece',
                'supplier_name' => 'OfficeLine',
                'tax_rate' => 14.975,
                'tracking_type' => 'serial',
            ],
            [
                'name' => 'Inventory clipboard',
                'category' => 'Office',
                'price' => 7.40,
                'cost_price' => 2.60,
                'stock' => 80,
                'minimum_stock' => 20,
                'sku' => 'OFF-CLP-071',
                'barcode' => '0123456789077',
                'unit' => 'piece',
                'supplier_name' => 'OfficeLine',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Organic granola bars',
                'category' => 'Retail',
                'description' => 'Snack box for checkout displays.',
                'price' => 5.50,
                'cost_price' => 2.10,
                'stock' => 180,
                'minimum_stock' => 30,
                'sku' => 'RET-GRA-072',
                'barcode' => '0123456789078',
                'unit' => 'box',
                'supplier_name' => 'MarketFresh',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
                'reserved' => 10,
                'bin_locations' => [
                    'main' => 'R-12',
                    'overflow' => 'O-4',
                ],
            ],
            [
                'name' => 'Sparkling water 12-pack',
                'category' => 'Retail',
                'description' => 'Assorted flavors for cooler displays.',
                'price' => 9.90,
                'cost_price' => 4.00,
                'stock' => 120,
                'minimum_stock' => 25,
                'sku' => 'RET-WTR-073',
                'barcode' => '0123456789079',
                'unit' => 'pack',
                'supplier_name' => 'MarketFresh',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
                'secondary_stock' => 30,
            ],
            [
                'name' => 'Whole bean coffee 1kg',
                'category' => 'Products',
                'description' => 'Medium roast coffee for bulk sales.',
                'price' => 22.50,
                'cost_price' => 10.50,
                'stock' => 60,
                'minimum_stock' => 10,
                'sku' => 'PRO-COF-074',
                'barcode' => '0123456789080',
                'unit' => 'bag',
                'supplier_name' => 'RoastWorks',
                'tax_rate' => 14.975,
                'tracking_type' => 'lot',
                'reserved' => 6,
            ],
            [
                'name' => 'All-purpose surface cleaner',
                'category' => 'Cleaning',
                'description' => 'Multi-surface spray for retail shelves.',
                'price' => 6.80,
                'cost_price' => 2.40,
                'stock' => 110,
                'minimum_stock' => 20,
                'sku' => 'CLN-SPR-075',
                'barcode' => '0123456789081',
                'unit' => 'bottle',
                'supplier_name' => 'CleanCo',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
                'damaged' => 3,
            ],
            [
                'name' => 'Safety goggles',
                'category' => 'Safety',
                'description' => 'Protective eyewear for workshop use.',
                'price' => 12.50,
                'cost_price' => 4.90,
                'stock' => 45,
                'minimum_stock' => 8,
                'sku' => 'SFT-GOG-076',
                'barcode' => '0123456789082',
                'unit' => 'pair',
                'supplier_name' => 'SafeShield',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'LED bulb pack',
                'category' => 'Electrical',
                'description' => 'Energy efficient bulbs for retail shelves.',
                'price' => 15.00,
                'cost_price' => 6.20,
                'stock' => 70,
                'minimum_stock' => 12,
                'sku' => 'ELC-BLB-077',
                'barcode' => '0123456789083',
                'unit' => 'pack',
                'supplier_name' => 'BrightWire',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'PVC pipe 2m',
                'category' => 'Plumbing',
                'description' => 'Standard pipe for plumbing repairs.',
                'price' => 8.40,
                'cost_price' => 3.10,
                'stock' => 90,
                'minimum_stock' => 15,
                'sku' => 'PLM-PVC-078',
                'barcode' => '0123456789084',
                'unit' => 'piece',
                'supplier_name' => 'PipePro',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Packing bubble wrap',
                'category' => 'Packaging',
                'description' => 'Protective wrap for shipping orders.',
                'price' => 14.20,
                'cost_price' => 5.60,
                'stock' => 55,
                'minimum_stock' => 10,
                'sku' => 'PKG-BUB-079',
                'barcode' => '0123456789085',
                'unit' => 'roll',
                'supplier_name' => 'PackRight',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
                'secondary_stock' => 12,
            ],
            [
                'name' => 'Desk organizer set',
                'category' => 'Office',
                'description' => 'Organizer trays for back office.',
                'price' => 18.90,
                'cost_price' => 7.00,
                'stock' => 40,
                'minimum_stock' => 8,
                'sku' => 'OFF-ORG-080',
                'barcode' => '0123456789086',
                'unit' => 'set',
                'supplier_name' => 'OfficeLine',
                'tax_rate' => 14.975,
                'tracking_type' => 'none',
            ],
            [
                'name' => 'Portable barcode scanner',
                'category' => 'Retail',
                'description' => 'Bluetooth scanner for point of sale.',
                'price' => 145.00,
                'cost_price' => 92.00,
                'stock' => 6,
                'minimum_stock' => 2,
                'sku' => 'RET-SCN-081',
                'barcode' => '0123456789087',
                'unit' => 'piece',
                'supplier_name' => 'RetailGear',
                'tax_rate' => 14.975,
                'tracking_type' => 'serial',
            ],
        ]);

        $unsplashPhotoIds = [
            '1500530855697-b586d89ba3ee',
            '1523275335684-37898b6baf30',
            '1503602642458-232111445657',
            '1496307042754-b4aa456c4a2d',
            '1498050108023-c5249f4df085',
            '1489515217757-5fd1be406fef',
            '1473186578172-c141e6798cf4',
            '1469474968028-56623f02e42e',
            '1488998427799-e3362cec87c3',
            '1497366216548-37526070297c',
        ];

        $resolveSeededPhotoList = function (string $seed, int $count = 4, int $width = 900) use ($unsplashPhotoIds): array {
            $seed = trim($seed);
            $hash = (int) sprintf('%u', crc32($seed));
            $total = count($unsplashPhotoIds);
            $limit = max(1, min($count, $total));
            $urls = [];

            for ($i = 0; $i < $limit; $i += 1) {
                $photoId = $unsplashPhotoIds[($hash + $i) % $total];
                $urls[] = "https://images.unsplash.com/photo-{$photoId}?auto=format&fit=crop&w={$width}&q=80";
            }

            return array_values(array_unique($urls));
        };

        $productSeedMap = $productSeedData->keyBy('name');

        $productPromoMap = [
            'Safety gloves' => [
                'discount' => 12.5,
                'start' => $now->copy()->subDays(2),
                'end' => $now->copy()->addDays(6),
            ],
            'Cleaning kit' => [
                'discount' => 18,
                'start' => $now->copy()->subDays(1),
                'end' => $now->copy()->addDays(4),
            ],
            'Sparkling water 12-pack' => [
                'discount' => 10,
                'start' => $now->copy()->subDays(3),
                'end' => $now->copy()->addDays(2),
            ],
            'Whole bean coffee 1kg' => [
                'discount' => 15,
                'start' => $now->copy()->subDays(5),
                'end' => $now->copy()->addDays(7),
            ],
            'Portable barcode scanner' => [
                'discount' => 8,
                'start' => $now->copy()->subDays(4),
                'end' => $now->copy()->addDays(3),
            ],
        ];

        $productProducts = $productSeedData->map(function ($data) use ($productOwner, $productCategory, $productCategoryMap, $resolveSeededPhotoList, $productPromoMap) {
            $price = (float) $data['price'];
            $cost = (float) $data['cost_price'];
            $margin = $price > 0 ? round((($price - $cost) / $price) * 100, 2) : 0;
            $category = $productCategoryMap[$data['category']] ?? $productCategory;
            $seedKey = trim(($data['sku'] ?? '') . ' ' . ($data['name'] ?? '') . ' ' . ($data['category'] ?? 'product'));
            $imageUrls = $resolveSeededPhotoList($seedKey, 4, 900);
            $imageUrl = $imageUrls[0] ?? null;
            $promo = $productPromoMap[$data['name']] ?? null;
            $promoDiscount = $promo['discount'] ?? null;
            $promoStart = $promo['start'] ?? null;
            $promoEnd = $promo['end'] ?? null;

            $product = Product::updateOrCreate(
                [
                    'user_id' => $productOwner->id,
                    'name' => $data['name'],
                ],
                [
                    'description' => $data['description'] ?? null,
                    'category_id' => $category->id,
                    'price' => $price,
                    'cost_price' => $cost,
                    'margin_percent' => $margin,
                    'stock' => 0,
                    'minimum_stock' => $data['minimum_stock'],
                    'sku' => $data['sku'],
                    'barcode' => $data['barcode'],
                    'unit' => $data['unit'],
                    'supplier_name' => $data['supplier_name'],
                    'tax_rate' => $data['tax_rate'],
                    'image' => $imageUrl,
                    'promo_discount_percent' => $promoDiscount,
                    'promo_start_at' => $promoStart,
                    'promo_end_at' => $promoEnd,
                    'is_active' => true,
                    'tracking_type' => $data['tracking_type'],
                    'item_type' => Product::ITEM_TYPE_PRODUCT,
                ]
            );

            if (!empty($imageUrls)) {
                ProductImage::where('product_id', $product->id)->update(['is_primary' => false]);
                foreach ($imageUrls as $index => $url) {
                    ProductImage::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'path' => $url,
                        ],
                        [
                            'is_primary' => $index === 0,
                            'sort_order' => $index,
                        ]
                    );
                }
            }

            return $product;
        });

        $inventoryService = app(InventoryService::class);
        $productMainWarehouse = $inventoryService->resolveDefaultWarehouse($productOwner->id);
        $productOverflowWarehouse = Warehouse::updateOrCreate(
            ['user_id' => $productOwner->id, 'code' => 'OVERFLOW'],
            [
                'name' => 'Overflow warehouse',
                'is_default' => false,
                'is_active' => true,
            ]
        );

        $productWarehouseMap = [
            'main' => $productMainWarehouse,
            'overflow' => $productOverflowWarehouse,
        ];

        $productInventoryPlans = [
            'Cleaning kit' => [
                'lots' => [
                    [
                        'warehouse' => 'main',
                        'lot_number' => 'LOT-CLN-001',
                        'quantity' => 25,
                        'expires_at' => $now->copy()->addDays(18),
                        'received_at' => $now->copy()->subDays(10),
                    ],
                    [
                        'warehouse' => 'main',
                        'lot_number' => 'LOT-CLN-002',
                        'quantity' => 15,
                        'expires_at' => $now->copy()->subDays(6),
                        'received_at' => $now->copy()->subDays(40),
                    ],
                ],
                'damaged' => [
                    'warehouse' => 'main',
                    'quantity' => 2,
                    'lot_number' => 'LOT-CLN-001',
                ],
            ],
        ];

        foreach ($productProducts as $product) {
            $data = $productSeedMap->get($product->name);
            if (!$data) {
                continue;
            }

            $hasSeedMovements = $product->stockMovements()->exists();
            $hasSeedLots = $product->lots()->exists();

            if ($hasSeedMovements || $hasSeedLots) {
                continue;
            }

            $inventoryService->ensureInventory($product, $productMainWarehouse);
            $inventoryService->ensureInventory($product, $productOverflowWarehouse);

            $plan = $productInventoryPlans[$product->name] ?? null;
            $seedStock = (int) $data['stock'];
            $trackingType = $data['tracking_type'] ?? 'none';
            $primaryLotNumber = null;

            if ($plan && !empty($plan['lots'])) {
                foreach ($plan['lots'] as $lot) {
                    $warehouse = $productWarehouseMap[$lot['warehouse']] ?? $productMainWarehouse;
                    $inventoryService->adjust($product, (int) $lot['quantity'], 'in', [
                        'warehouse' => $warehouse,
                        'reason' => 'seed',
                        'note' => 'Seeded lot stock',
                        'lot_number' => $lot['lot_number'] ?? null,
                        'expires_at' => $lot['expires_at'] ?? null,
                        'received_at' => $lot['received_at'] ?? null,
                    ]);
                }
            } elseif ($trackingType === 'lot' && $seedStock > 0) {
                $mainLot = max(1, (int) round($seedStock * 0.6));
                $overflowLot = max(0, $seedStock - $mainLot);
                $primaryLotNumber = $data['sku'] . '-A';

                $inventoryService->adjust($product, $mainLot, 'in', [
                    'warehouse' => $productMainWarehouse,
                    'reason' => 'seed',
                    'note' => 'Seeded lot stock',
                    'lot_number' => $primaryLotNumber,
                    'expires_at' => $now->copy()->addDays(20),
                    'received_at' => $now->copy()->subDays(12),
                ]);

                if ($overflowLot > 0) {
                    $inventoryService->adjust($product, $overflowLot, 'in', [
                        'warehouse' => $productOverflowWarehouse,
                        'reason' => 'seed',
                        'note' => 'Seeded lot stock',
                        'lot_number' => $data['sku'] . '-B',
                        'expires_at' => $now->copy()->addMonths(6),
                        'received_at' => $now->copy()->subDays(30),
                    ]);
                }
            } elseif ($trackingType === 'serial' && $seedStock > 0) {
                $mainCount = max(1, (int) round($seedStock * 0.7));
                for ($i = 1; $i <= $seedStock; $i += 1) {
                    $warehouse = $i <= $mainCount ? $productMainWarehouse : $productOverflowWarehouse;
                    $inventoryService->adjust($product, 1, 'in', [
                        'warehouse' => $warehouse,
                        'reason' => 'seed',
                        'note' => 'Seeded serial stock',
                        'serial_number' => sprintf('%s-%03d', $data['sku'], $i),
                    ]);
                }
            } elseif ($seedStock > 0) {
                $secondaryStock = (int) ($data['secondary_stock'] ?? 0);
                if ($secondaryStock <= 0) {
                    $secondaryStock = (int) floor($seedStock * 0.25);
                }
                $mainStock = max(0, $seedStock - $secondaryStock);

                if ($mainStock > 0) {
                    $inventoryService->adjust($product, $mainStock, 'in', [
                        'warehouse' => $productMainWarehouse,
                        'reason' => 'seed',
                        'note' => 'Seeded stock',
                    ]);
                }

                if ($secondaryStock > 0) {
                    $inventoryService->adjust($product, $secondaryStock, 'in', [
                        'warehouse' => $productOverflowWarehouse,
                        'reason' => 'seed',
                        'note' => 'Seeded overflow stock',
                    ]);
                }
            }

            if (!empty($plan['damaged'])) {
                $warehouse = $productWarehouseMap[$plan['damaged']['warehouse']] ?? $productMainWarehouse;
                $inventoryService->adjust($product, (int) $plan['damaged']['quantity'], 'damage', [
                    'warehouse' => $warehouse,
                    'reason' => 'seed',
                    'note' => 'Seeded damaged stock',
                    'lot_number' => $plan['damaged']['lot_number'] ?? null,
                ]);
            } elseif (!empty($data['damaged'])) {
                $inventoryService->adjust($product, (int) $data['damaged'], 'damage', [
                    'warehouse' => $productMainWarehouse,
                    'reason' => 'seed',
                    'note' => 'Seeded damaged stock',
                    'lot_number' => $primaryLotNumber,
                ]);
            }

            if (!empty($data['reserved'])) {
                $inventory = $inventoryService->ensureInventory($product, $productMainWarehouse);
                $inventory->update([
                    'reserved' => (int) $data['reserved'],
                ]);
            }

            if (!empty($data['bin_locations'])) {
                foreach ($data['bin_locations'] as $warehouseKey => $binLocation) {
                    $warehouse = $productWarehouseMap[$warehouseKey] ?? null;
                    if (!$warehouse) {
                        continue;
                    }
                    $inventory = $inventoryService->ensureInventory($product, $warehouse);
                    $inventory->update([
                        'bin_location' => $binLocation,
                    ]);
                }
            }

            $inventoryService->recalculateProductStock($product);
        }

        $serviceCustomer = Customer::updateOrCreate(
            [
                'email' => 'north-co@example.com',
            ],
            [
                'user_id' => $serviceOwner->id,
                'first_name' => 'Ava',
                'last_name' => 'Lefebvre',
                'company_name' => 'North & Co',
                'phone' => '+15145550001',
                'description' => 'Seeded customer for launch scenario.',
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
            ]
        );

        $servicePortalUser = User::updateOrCreate(
            ['email' => 'client.north@example.com'],
            [
                'name' => 'Ava Lefebvre',
                'password' => Hash::make('password'),
                'role_id' => $clientRoleId,
                'email_verified_at' => $now,
            ]
        );

        if ($serviceCustomer->portal_user_id !== $servicePortalUser->id) {
            $serviceCustomer->update(['portal_user_id' => $servicePortalUser->id]);
        }

        $serviceProperty = Property::updateOrCreate(
            [
                'customer_id' => $serviceCustomer->id,
                'type' => 'physical',
                'street1' => '100 Service Ave',
            ],
            [
                'is_default' => true,
                'city' => 'Montreal',
                'state' => 'QC',
                'zip' => 'H1H1H1',
                'country' => 'Canada',
            ]
        );

        $serviceCustomerAlt = Customer::updateOrCreate(
            [
                'email' => 'launch-owlwood@example.com',
            ],
            [
                'user_id' => $serviceOwner->id,
                'first_name' => 'Jordan',
                'last_name' => 'Moreau',
                'company_name' => 'Owlwood Properties',
                'phone' => '+15145550110',
                'description' => 'Secondary customer for extended workflow coverage.',
                'salutation' => 'Mrs',
                'billing_same_as_physical' => true,
            ]
        );

        $servicePropertyAlt = Property::updateOrCreate(
            [
                'customer_id' => $serviceCustomerAlt->id,
                'type' => 'physical',
                'street1' => '250 Demo Rd',
            ],
            [
                'is_default' => true,
                'city' => 'Laval',
                'state' => 'QC',
                'zip' => 'H7N5H9',
                'country' => 'Canada',
            ]
        );

        $productCustomer = Customer::updateOrCreate(
            [
                'email' => 'product-buyer@example.com',
            ],
            [
                'user_id' => $productOwner->id,
                'first_name' => 'Mia',
                'last_name' => 'Roy',
                'company_name' => 'Mia Builds',
                'phone' => '+14165550001',
                'description' => 'Seeded customer for product demo.',
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
                'discount_rate' => 5,
                'tags' => ['vip', 'delivery', 'repeat'],
                'logo' => $resolveSeededPhotoList('product-buyer@example.com-logo', 1, 320)[0] ?? null,
                'header_image' => $resolveSeededPhotoList('product-buyer@example.com-header', 1, 1200)[0] ?? null,
            ]
        );

        $productPortalUser = User::updateOrCreate(
            ['email' => 'client.products@example.com'],
            [
                'name' => 'Mia Roy',
                'password' => Hash::make('password'),
                'role_id' => $clientRoleId,
                'email_verified_at' => $now,
            ]
        );

        if ($productCustomer->portal_user_id !== $productPortalUser->id) {
            $productCustomer->update(['portal_user_id' => $productPortalUser->id]);
        }

        $productProperty = Property::updateOrCreate(
            [
                'customer_id' => $productCustomer->id,
                'type' => 'physical',
                'street1' => '42 Product St',
            ],
            [
                'is_default' => true,
                'city' => 'Toronto',
                'state' => 'ON',
                'zip' => 'M5V2T6',
                'country' => 'Canada',
            ]
        );

        Property::updateOrCreate(
            [
                'customer_id' => $productCustomer->id,
                'type' => 'billing',
                'street1' => '99 Billing Ave',
            ],
            [
                'is_default' => false,
                'city' => 'Toronto',
                'state' => 'ON',
                'zip' => 'M4B1B4',
                'country' => 'Canada',
            ]
        );

        $lead = LeadRequest::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'title' => 'Lead - Window cleaning',
            ],
            [
                'customer_id' => $serviceCustomer->id,
                'status' => LeadRequest::STATUS_NEW,
                'service_type' => 'Cleaning',
                'urgency' => 'normal',
                'channel' => 'manual',
                'contact_name' => 'Ava Lefebvre',
                'contact_email' => 'north-co@example.com',
                'contact_phone' => '+15145550001',
                'country' => 'Canada',
                'state' => 'QC',
                'city' => 'Montreal',
                'street1' => '100 Service Ave',
            ]
        );

        LeadRequest::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'title' => 'Lead - Gutter cleaning',
            ],
            [
                'customer_id' => $serviceCustomerAlt->id,
                'status' => LeadRequest::STATUS_NEW,
                'service_type' => 'Maintenance',
                'urgency' => 'low',
                'channel' => 'web',
                'contact_name' => 'Jordan Moreau',
                'contact_email' => 'launch-owlwood@example.com',
                'contact_phone' => '+15145550110',
                'country' => 'Canada',
                'state' => 'QC',
                'city' => 'Laval',
                'street1' => '250 Demo Rd',
            ]
        );

        $quote = Quote::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomer->id,
                'job_title' => 'Window cleaning package',
            ],
            [
                'property_id' => $serviceProperty->id,
                'status' => 'accepted',
                'notes' => 'Seeded quote converted from lead.',
                'messages' => 'Thank you for choosing our services.',
                'request_id' => $lead->id,
                'signed_at' => $now->copy()->subDays(3),
                'accepted_at' => $now->copy()->subDays(3),
                'is_fixed' => false,
            ]
        );

        $quoteItems = [
            [
                'product' => $serviceProducts[0],
                'quantity' => 1,
                'price' => (float) $serviceProducts[0]->price,
                'description' => 'Exterior windows',
            ],
            [
                'product' => $serviceProducts[1],
                'quantity' => 1,
                'price' => (float) $serviceProducts[1]->price,
                'description' => 'Interior deep clean',
            ],
        ];

        $quotePivot = [];
        foreach ($quoteItems as $item) {
            $total = round($item['quantity'] * $item['price'], 2);
            $quotePivot[$item['product']->id] = [
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $total,
                'description' => $item['description'],
            ];
        }

        $quoteSubtotal = collect($quotePivot)->sum('total');
        $quoteTotal = $quoteSubtotal;
        $quoteDeposit = round($quoteTotal * 0.3, 2);

        $quote->update([
            'subtotal' => $quoteSubtotal,
            'total' => $quoteTotal,
            'initial_deposit' => $quoteDeposit,
        ]);
        $quote->products()->sync($quotePivot);

        $lead->update([
            'status' => LeadRequest::STATUS_CONVERTED,
            'converted_at' => $now->copy()->subDays(3),
        ]);

        $work = Work::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomer->id,
                'job_title' => 'Window cleaning package',
            ],
            [
                'quote_id' => $quote->id,
                'instructions' => $quote->notes ?? '',
                'start_date' => $now->copy()->subDays(2)->toDateString(),
                'status' => Work::STATUS_VALIDATED,
                'subtotal' => $quoteSubtotal,
                'total' => $quoteTotal,
            ]
        );

        if ($work->quote_id !== $quote->id) {
            $work->update(['quote_id' => $quote->id]);
        }

        if ($quote->work_id !== $work->id) {
            $quote->update(['work_id' => $work->id]);
        }

        $work->teamMembers()->sync([$adminMember->id, $memberMember->id]);

        Transaction::updateOrCreate(
            [
                'quote_id' => $quote->id,
                'type' => 'deposit',
                'reference' => 'SEED-DEP-001',
            ],
            [
                'work_id' => $work->id,
                'invoice_id' => null,
                'customer_id' => $serviceCustomer->id,
                'user_id' => $serviceOwner->id,
                'amount' => $quoteDeposit,
                'method' => 'card',
                'status' => 'completed',
                'paid_at' => $now->copy()->subDays(3),
            ]
        );

        $quoteProducts = QuoteProduct::query()
            ->where('quote_id', $quote->id)
            ->with('product')
            ->orderBy('id')
            ->get();

        foreach ($quoteProducts as $index => $item) {
            WorkChecklistItem::updateOrCreate(
                [
                    'work_id' => $work->id,
                    'quote_product_id' => $item->id,
                ],
                [
                    'quote_id' => $quote->id,
                    'title' => $item->product?->name ?? 'Line item',
                    'description' => $item->description,
                    'status' => 'done',
                    'sort_order' => $index,
                    'completed_at' => $now->copy()->subDays(2),
                ]
            );
        }

        $extraQuote = Quote::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomer->id,
                'job_title' => 'Extra - Screen repair',
            ],
            [
                'property_id' => $serviceProperty->id,
                'status' => 'accepted',
                'notes' => 'Seeded extra quote (change order).',
                'messages' => null,
                'parent_id' => $quote->id,
                'work_id' => $work->id,
                'signed_at' => $now->copy()->subDays(1),
                'accepted_at' => $now->copy()->subDays(1),
                'is_fixed' => false,
            ]
        );

        $extraItem = [
            'product' => $serviceProducts[2],
            'quantity' => 1,
            'price' => (float) $serviceProducts[2]->price,
            'description' => 'Screen repair add-on',
        ];

        $extraTotal = round($extraItem['quantity'] * $extraItem['price'], 2);
        $extraQuote->update([
            'subtotal' => $extraTotal,
            'total' => $extraTotal,
            'initial_deposit' => 0,
        ]);

        $extraQuote->products()->sync([
            $extraItem['product']->id => [
                'quantity' => $extraItem['quantity'],
                'price' => $extraItem['price'],
                'total' => $extraTotal,
                'description' => $extraItem['description'],
            ],
        ]);

        $extraProducts = QuoteProduct::query()
            ->where('quote_id', $extraQuote->id)
            ->with('product')
            ->orderBy('id')
            ->get();

        foreach ($extraProducts as $index => $item) {
            WorkChecklistItem::updateOrCreate(
                [
                    'work_id' => $work->id,
                    'quote_product_id' => $item->id,
                ],
                [
                    'quote_id' => $extraQuote->id,
                    'title' => $item->product?->name ?? 'Line item',
                    'description' => $item->description,
                    'status' => 'done',
                    'sort_order' => $index + 10,
                    'completed_at' => $now->copy()->subDays(1),
                ]
            );
        }

        $portalQuote = Quote::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomer->id,
                'job_title' => 'Seasonal maintenance quote',
            ],
            [
                'property_id' => $serviceProperty->id,
                'status' => 'sent',
                'notes' => 'Seeded quote awaiting client approval.',
                'messages' => 'Please review and accept this quote.',
                'is_fixed' => false,
            ]
        );

        $portalItems = [
            [
                'product' => $serviceProducts[0],
                'quantity' => 1,
                'price' => (float) $serviceProducts[0]->price,
                'description' => 'Seasonal maintenance',
            ],
        ];

        $portalPivot = [];
        foreach ($portalItems as $item) {
            $total = round($item['quantity'] * $item['price'], 2);
            $portalPivot[$item['product']->id] = [
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $total,
                'description' => $item['description'],
            ];
        }

        $portalSubtotal = collect($portalPivot)->sum('total');
        $portalQuote->update([
            'subtotal' => $portalSubtotal,
            'total' => $portalSubtotal,
            'initial_deposit' => 0,
        ]);
        $portalQuote->products()->sync($portalPivot);

        $draftQuote = Quote::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'job_title' => 'Draft - Exterior prep',
            ],
            [
                'property_id' => $servicePropertyAlt->id,
                'status' => 'draft',
                'notes' => 'Draft quote for future review.',
                'messages' => null,
                'is_fixed' => false,
            ]
        );

        $draftItems = [
            [
                'product' => $serviceProducts[1],
                'quantity' => 1,
                'price' => (float) $serviceProducts[1]->price,
                'description' => 'Exterior prep package',
            ],
        ];

        $draftPivot = [];
        foreach ($draftItems as $item) {
            $total = round($item['quantity'] * $item['price'], 2);
            $draftPivot[$item['product']->id] = [
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $total,
                'description' => $item['description'],
            ];
        }

        $draftSubtotal = collect($draftPivot)->sum('total');
        $draftQuote->update([
            'subtotal' => $draftSubtotal,
            'total' => $draftSubtotal,
            'initial_deposit' => 0,
        ]);
        $draftQuote->products()->sync($draftPivot);

        $declinedQuote = Quote::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'job_title' => 'Declined - Fence wash',
            ],
            [
                'property_id' => $servicePropertyAlt->id,
                'status' => 'declined',
                'notes' => 'Client declined due to timing.',
                'messages' => null,
                'signed_at' => $now->copy()->subDays(5),
                'accepted_at' => null,
                'is_fixed' => false,
            ]
        );

        $declinedItems = [
            [
                'product' => $serviceProducts[2],
                'quantity' => 1,
                'price' => (float) $serviceProducts[2]->price,
                'description' => 'Fence pressure wash',
            ],
        ];

        $declinedPivot = [];
        foreach ($declinedItems as $item) {
            $total = round($item['quantity'] * $item['price'], 2);
            $declinedPivot[$item['product']->id] = [
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $total,
                'description' => $item['description'],
            ];
        }

        $declinedSubtotal = collect($declinedPivot)->sum('total');
        $declinedQuote->update([
            'subtotal' => $declinedSubtotal,
            'total' => $declinedSubtotal,
            'initial_deposit' => 0,
        ]);
        $declinedQuote->products()->sync($declinedPivot);

        $reviewQuote = Quote::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomer->id,
                'job_title' => 'Review - Exterior refresh',
            ],
            [
                'property_id' => $serviceProperty->id,
                'status' => 'accepted',
                'notes' => 'Accepted quote waiting on client validation.',
                'messages' => null,
                'signed_at' => $now->copy()->subDays(2),
                'accepted_at' => $now->copy()->subDays(2),
                'is_fixed' => false,
            ]
        );

        $reviewItems = [
            [
                'product' => $serviceProducts[1],
                'quantity' => 1,
                'price' => (float) $serviceProducts[1]->price,
                'description' => 'Exterior refresh',
            ],
        ];

        $reviewPivot = [];
        foreach ($reviewItems as $item) {
            $total = round($item['quantity'] * $item['price'], 2);
            $reviewPivot[$item['product']->id] = [
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $total,
                'description' => $item['description'],
            ];
        }

        $reviewSubtotal = collect($reviewPivot)->sum('total');
        $reviewQuote->update([
            'subtotal' => $reviewSubtotal,
            'total' => $reviewSubtotal,
            'initial_deposit' => 0,
        ]);
        $reviewQuote->products()->sync($reviewPivot);

        $reviewWork = Work::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomer->id,
                'job_title' => 'Review - Exterior refresh',
            ],
            [
                'quote_id' => $reviewQuote->id,
                'instructions' => $reviewQuote->notes ?? '',
                'start_date' => $now->copy()->subDay()->toDateString(),
                'status' => Work::STATUS_PENDING_REVIEW,
                'subtotal' => $reviewSubtotal,
                'total' => $reviewSubtotal,
            ]
        );

        if ($reviewQuote->work_id !== $reviewWork->id) {
            $reviewQuote->update(['work_id' => $reviewWork->id]);
        }

        $reviewProducts = QuoteProduct::query()
            ->where('quote_id', $reviewQuote->id)
            ->with('product')
            ->orderBy('id')
            ->get();

        foreach ($reviewProducts as $index => $item) {
            WorkChecklistItem::updateOrCreate(
                [
                    'work_id' => $reviewWork->id,
                    'quote_product_id' => $item->id,
                ],
                [
                    'quote_id' => $reviewQuote->id,
                    'title' => $item->product?->name ?? 'Line item',
                    'description' => $item->description,
                    'status' => 'done',
                    'sort_order' => $index,
                    'completed_at' => $now->copy()->subDay(),
                ]
            );
        }

        foreach (range(1, 3) as $index) {
            WorkMedia::updateOrCreate(
                [
                    'work_id' => $reviewWork->id,
                    'type' => 'after',
                    'path' => 'work_media/review-after-' . $index . '.jpg',
                ],
                [
                    'user_id' => $serviceOwner->id,
                    'meta' => ['seeded' => true],
                ]
            );
        }

        $scheduledTotal = (float) $serviceProducts[0]->price;
        $inProgressTotal = (float) $serviceProducts[1]->price;
        $disputeTotal = (float) $serviceProducts[2]->price;
        $cancelledTotal = (float) ($serviceProducts[0]->price + $serviceProducts[2]->price);
        $closedTotal = (float) ($serviceProducts[1]->price + $serviceProducts[2]->price);

        $scheduledWork = Work::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'job_title' => 'Scheduled - Seasonal checkup',
            ],
            [
                'instructions' => 'Seeded scheduled job.',
                'start_date' => $now->copy()->addDays(2)->toDateString(),
                'status' => Work::STATUS_SCHEDULED,
                'subtotal' => $scheduledTotal,
                'total' => $scheduledTotal,
            ]
        );

        $inProgressWork = Work::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'job_title' => 'In progress - Driveway wash',
            ],
            [
                'instructions' => 'Seeded in-progress job.',
                'start_date' => $now->copy()->subDay()->toDateString(),
                'status' => Work::STATUS_IN_PROGRESS,
                'subtotal' => $inProgressTotal,
                'total' => $inProgressTotal,
            ]
        );

        foreach (range(1, 3) as $index) {
            WorkMedia::updateOrCreate(
                [
                    'work_id' => $inProgressWork->id,
                    'type' => 'before',
                    'path' => 'work_media/in-progress-before-' . $index . '.jpg',
                ],
                [
                    'user_id' => $serviceOwner->id,
                    'meta' => ['seeded' => true],
                ]
            );
        }

        $disputeWork = Work::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'job_title' => 'Dispute - Balcony cleanup',
            ],
            [
                'instructions' => 'Seeded disputed job.',
                'start_date' => $now->copy()->subDays(4)->toDateString(),
                'status' => Work::STATUS_DISPUTE,
                'subtotal' => $disputeTotal,
                'total' => $disputeTotal,
            ]
        );

        $cancelledWork = Work::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'job_title' => 'Cancelled - Patio wash',
            ],
            [
                'instructions' => 'Seeded cancelled job.',
                'start_date' => $now->copy()->subDays(6)->toDateString(),
                'status' => Work::STATUS_CANCELLED,
                'subtotal' => $cancelledTotal,
                'total' => $cancelledTotal,
            ]
        );

        $closedWork = Work::updateOrCreate(
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'job_title' => 'Closed - Full service',
            ],
            [
                'instructions' => 'Seeded paid job.',
                'start_date' => $now->copy()->subDays(8)->toDateString(),
                'status' => Work::STATUS_CLOSED,
                'subtotal' => $closedTotal,
                'total' => $closedTotal,
            ]
        );

        Invoice::updateOrCreate(
            [
                'work_id' => $scheduledWork->id,
            ],
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'status' => 'sent',
                'total' => $scheduledWork->total,
            ]
        );

        Invoice::updateOrCreate(
            [
                'work_id' => $disputeWork->id,
            ],
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'status' => 'overdue',
                'total' => $disputeWork->total,
            ]
        );

        $paidInvoice = Invoice::updateOrCreate(
            [
                'work_id' => $closedWork->id,
            ],
            [
                'user_id' => $serviceOwner->id,
                'customer_id' => $serviceCustomerAlt->id,
                'status' => 'sent',
                'total' => $closedWork->total,
            ]
        );
        $paymentTipColumns = [
            'tip_amount' => Schema::hasColumn('payments', 'tip_amount'),
            'tip_type' => Schema::hasColumn('payments', 'tip_type'),
            'tip_percent' => Schema::hasColumn('payments', 'tip_percent'),
            'tip_base_amount' => Schema::hasColumn('payments', 'tip_base_amount'),
            'charged_total' => Schema::hasColumn('payments', 'charged_total'),
            'tip_assignee_user_id' => Schema::hasColumn('payments', 'tip_assignee_user_id'),
            'tip_reversed_amount' => Schema::hasColumn('payments', 'tip_reversed_amount'),
            'tip_reversed_at' => Schema::hasColumn('payments', 'tip_reversed_at'),
            'tip_reversal_rule' => Schema::hasColumn('payments', 'tip_reversal_rule'),
            'tip_reversal_reason' => Schema::hasColumn('payments', 'tip_reversal_reason'),
        ];
        $withTipData = static function (array $values, array $tipData) use ($paymentTipColumns): array {
            foreach ($tipData as $column => $value) {
                if (($paymentTipColumns[$column] ?? false) === true) {
                    $values[$column] = $value;
                }
            }

            return $values;
        };
        $tipAllocationsEnabled = Schema::hasTable('payment_tip_allocations');
        $syncTipAllocations = static function (?Payment $payment) use ($tipAllocationsEnabled): void {
            if (!$tipAllocationsEnabled || !$payment) {
                return;
            }

            app(TipAllocationService::class)->syncForPayment($payment->fresh());
        };
        $fullTipAmount = 15.00;

        $fullTipPayment = Payment::updateOrCreate(
            [
                'invoice_id' => $paidInvoice->id,
                'reference' => 'SEED-PAY-PAID-001',
            ],
            $withTipData([
                'customer_id' => $serviceCustomerAlt->id,
                'user_id' => $serviceOwner->id,
                'amount' => $paidInvoice->total,
                'method' => 'card',
                'status' => 'completed',
                'notes' => 'Seeded full payment',
                'paid_at' => $now->copy()->subDays(7),
            ], [
                'tip_amount' => $fullTipAmount,
                'tip_type' => 'fixed',
                'tip_percent' => null,
                'tip_base_amount' => (float) $paidInvoice->total,
                'charged_total' => round((float) $paidInvoice->total + $fullTipAmount, 2),
                'tip_assignee_user_id' => $adminUser->id,
                'tip_reversed_amount' => 0,
                'tip_reversed_at' => null,
                'tip_reversal_rule' => null,
                'tip_reversal_reason' => null,
            ])
        );
        $syncTipAllocations($fullTipPayment);
        $paidInvoice->refreshPaymentStatus();

        $billingService = app(WorkBillingService::class);
        $invoice = $billingService->createInvoiceFromWork($work);

        $paymentAmount = round($invoice->total * 0.5, 2);
        $partialTipPercent = 10.0;
        $partialTipAmount = round($paymentAmount * ($partialTipPercent / 100), 2);
        $partialTipPayment = Payment::updateOrCreate(
            [
                'invoice_id' => $invoice->id,
                'reference' => 'SEED-PAY-001',
            ],
            $withTipData([
                'customer_id' => $serviceCustomer->id,
                'user_id' => $serviceOwner->id,
                'amount' => $paymentAmount,
                'method' => 'card',
                'status' => 'reversed',
                'notes' => 'Seeded partial payment',
                'paid_at' => $now->copy()->subDays(1),
            ], [
                'tip_amount' => $partialTipAmount,
                'tip_type' => 'percent',
                'tip_percent' => $partialTipPercent,
                'tip_base_amount' => $paymentAmount,
                'charged_total' => round($paymentAmount + $partialTipAmount, 2),
                'tip_assignee_user_id' => $memberUser->id,
                'tip_reversed_amount' => round($partialTipAmount * 0.5, 2),
                'tip_reversed_at' => $now->copy()->subHours(18),
                'tip_reversal_rule' => 'prorata',
                'tip_reversal_reason' => 'Seeded partial tip reversal',
            ])
        );
        $syncTipAllocations($partialTipPayment);
        $invoice->refreshPaymentStatus();

        Task::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'title' => 'Prepare follow up call',
            ],
            [
                'created_by_user_id' => $serviceOwner->id,
                'assigned_team_member_id' => $adminMember->id,
                'customer_id' => $serviceCustomer->id,
                'product_id' => $serviceProducts[0]->id,
                'description' => 'Call the customer after job completion.',
                'status' => 'todo',
                'due_date' => $now->copy()->addDays(2)->toDateString(),
            ]
        );

        Task::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'title' => 'Upload before photos',
            ],
            [
                'created_by_user_id' => $serviceOwner->id,
                'assigned_team_member_id' => $memberMember->id,
                'customer_id' => $serviceCustomer->id,
                'product_id' => null,
                'description' => 'Remember to upload before photos on site.',
                'status' => 'in_progress',
                'due_date' => $now->toDateString(),
            ]
        );

        Task::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'title' => 'Send thank you note',
            ],
            [
                'created_by_user_id' => $serviceOwner->id,
                'assigned_team_member_id' => $adminMember->id,
                'customer_id' => $serviceCustomer->id,
                'product_id' => $serviceProducts[2]->id,
                'description' => 'Follow up after payment is complete.',
                'status' => 'done',
                'due_date' => $now->copy()->subDay()->toDateString(),
                'completed_at' => $now->copy()->subDay()->setTime(15, 0),
                'completion_reason' => null,
            ]
        );

        Task::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'title' => 'Early completion test',
            ],
            [
                'created_by_user_id' => $serviceOwner->id,
                'assigned_team_member_id' => $adminMember->id,
                'customer_id' => $serviceCustomer->id,
                'product_id' => $serviceProducts[0]->id,
                'description' => 'Seeded to test early completion with a reason.',
                'status' => 'done',
                'due_date' => $now->copy()->addDays(2)->toDateString(),
                'completed_at' => $now->copy()->addDay()->setTime(10, 30),
                'completion_reason' => 'optimized_planning',
            ]
        );

        Task::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'title' => 'Overdue task test',
            ],
            [
                'created_by_user_id' => $serviceOwner->id,
                'assigned_team_member_id' => $memberMember->id,
                'customer_id' => $serviceCustomer->id,
                'product_id' => $serviceProducts[1]->id,
                'description' => 'Seeded overdue task for status testing.',
                'status' => 'todo',
                'due_date' => $now->copy()->subDay()->toDateString(),
            ]
        );

        Task::updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'title' => 'Task notification test',
            ],
            [
                'created_by_user_id' => $serviceOwner->id,
                'assigned_team_member_id' => $adminMember->id,
                'customer_id' => $serviceCustomer->id,
                'product_id' => $serviceProducts[0]->id,
                'description' => 'Seeded task for same-day notification testing.',
                'status' => 'todo',
                'due_date' => $now->toDateString(),
                'start_time' => '09:30:00',
                'end_time' => '10:15:00',
            ]
        );

        QuoteRating::updateOrCreate(
            [
                'quote_id' => $quote->id,
                'user_id' => $servicePortalUser->id,
            ],
            [
                'rating' => 5,
                'feedback' => 'Clear quote and great experience.',
            ]
        );

        WorkRating::updateOrCreate(
            [
                'work_id' => $work->id,
                'user_id' => $servicePortalUser->id,
            ],
            [
                'rating' => 4,
                'feedback' => 'Good job overall.',
            ]
        );

        $productQuote = Quote::updateOrCreate(
            [
                'user_id' => $productOwner->id,
                'customer_id' => $productCustomer->id,
                'job_title' => 'Starter supply pack',
            ],
            [
                'property_id' => $productProperty->id,
                'status' => 'sent',
                'notes' => 'Seeded product quote.',
                'messages' => 'Please confirm the order.',
                'is_fixed' => false,
            ]
        );

        $productItems = [
            [
                'product' => $productProducts[0],
                'quantity' => 10,
                'price' => (float) $productProducts[0]->price,
                'description' => 'Bulk gloves',
            ],
            [
                'product' => $productProducts[1],
                'quantity' => 2,
                'price' => (float) $productProducts[1]->price,
                'description' => 'Cleaning kits',
            ],
        ];

        $productPivot = [];
        foreach ($productItems as $item) {
            $total = round($item['quantity'] * $item['price'], 2);
            $productPivot[$item['product']->id] = [
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $total,
                'description' => $item['description'],
            ];
        }

        $productSubtotal = collect($productPivot)->sum('total');
        $productQuote->update([
            'subtotal' => $productSubtotal,
            'total' => $productSubtotal,
            'initial_deposit' => 0,
        ]);
        $productQuote->products()->sync($productPivot);

        LeadRequest::updateOrCreate(
            [
                'user_id' => $productOwner->id,
                'title' => 'Lead - Supply order',
            ],
            [
                'customer_id' => $productCustomer->id,
                'status' => LeadRequest::STATUS_NEW,
                'service_type' => 'Supplies',
                'urgency' => 'low',
                'channel' => 'manual',
                'contact_name' => 'Mia Roy',
                'contact_email' => 'product-buyer@example.com',
                'contact_phone' => '+14165550001',
                'country' => 'Canada',
                'state' => 'ON',
                'city' => 'Toronto',
                'street1' => '42 Product St',
            ]
        );

        $setTimestamps = function ($model, $timestamp) {
            $model->forceFill([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->saveQuietly();
        };

        $productCustomerRetail = Customer::updateOrCreate(
            [
                'email' => 'product-retail@example.com',
            ],
            [
                'user_id' => $productOwner->id,
                'first_name' => 'Lea',
                'last_name' => 'Benoit',
                'company_name' => 'City Market',
                'phone' => '+14165550011',
                'description' => 'Retail storefront customer.',
                'salutation' => 'Mrs',
                'billing_same_as_physical' => true,
                'discount_rate' => 2.5,
                'tags' => ['retail', 'walk-in'],
                'logo' => $resolveSeededPhotoList('product-retail@example.com-logo', 1, 320)[0] ?? null,
                'header_image' => $resolveSeededPhotoList('product-retail@example.com-header', 1, 1200)[0] ?? null,
            ]
        );

        $productCustomerWholesale = Customer::updateOrCreate(
            [
                'email' => 'product-wholesale@example.com',
            ],
            [
                'user_id' => $productOwner->id,
                'first_name' => 'Theo',
                'last_name' => 'Martin',
                'company_name' => 'North Supplies',
                'phone' => '+14165550012',
                'description' => 'Wholesale customer account.',
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
                'discount_rate' => 8,
                'tags' => ['wholesale', 'b2b'],
                'logo' => $resolveSeededPhotoList('product-wholesale@example.com-logo', 1, 320)[0] ?? null,
                'header_image' => $resolveSeededPhotoList('product-wholesale@example.com-header', 1, 1200)[0] ?? null,
            ]
        );

        Property::updateOrCreate(
            [
                'customer_id' => $productCustomerRetail->id,
                'type' => 'physical',
                'street1' => '10 Retail Row',
            ],
            [
                'is_default' => true,
                'city' => 'Toronto',
                'state' => 'ON',
                'zip' => 'M5V1K4',
                'country' => 'Canada',
            ]
        );

        Property::updateOrCreate(
            [
                'customer_id' => $productCustomerWholesale->id,
                'type' => 'physical',
                'street1' => '88 Supply Rd',
            ],
            [
                'is_default' => true,
                'city' => 'Ottawa',
                'state' => 'ON',
                'zip' => 'K1A0B1',
                'country' => 'Canada',
            ]
        );

        $productSalesCatalog = $productProducts
            ->filter(fn($product) => ($product->tracking_type ?? 'none') === 'none' && (int) $product->stock > 0)
            ->values();
        $canSeedSales = Schema::hasTable('sales') && Schema::hasTable('sale_items');

        $buildSalePayload = function (array $lines): array {
            $items = [];
            $subtotal = 0;
            $taxTotal = 0;

            foreach ($lines as $line) {
                $product = $line['product'] ?? null;
                if (!$product) {
                    continue;
                }

                $quantity = max(1, (int) ($line['quantity'] ?? 1));
                $price = (float) ($line['price'] ?? $product->price);
                $lineTotal = round($price * $quantity, 2);
                $subtotal += $lineTotal;

                $taxRate = (float) ($product->tax_rate ?? 0);
                $taxTotal += $taxRate > 0 ? round($lineTotal * ($taxRate / 100), 2) : 0;

                $items[] = [
                    'product_id' => $product->id,
                    'description' => $line['description'] ?? $product->name,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $lineTotal,
                ];
            }

            return [$items, $subtotal, $taxTotal, round($subtotal + $taxTotal, 2)];
        };

        $createSale = function (string $label, ?Customer $customer, string $status, array $lines, $createdAt, ?int $createdByUserId = null, array $fulfillment = [], array $extras = []) use ($productOwner, $buildSalePayload, $setTimestamps) {
            [$items, $subtotal, $taxTotal] = $buildSalePayload($lines);
            if (empty($items)) {
                return null;
            }

            $fulfillmentMethod = $fulfillment['method'] ?? null;
            $fulfillmentStatus = $fulfillment['status'] ?? null;
            $discountRate = (float) ($extras['discount_rate'] ?? ($customer?->discount_rate ?? 0));
            $discountRate = min(100, max(0, $discountRate));
            $discountTotal = round($subtotal * ($discountRate / 100), 2);
            $discountedSubtotal = max(0, $subtotal - $discountTotal);
            $discountedTaxTotal = round($taxTotal * (1 - ($discountRate / 100)), 2);
            $total = round($discountedSubtotal + $discountedTaxTotal, 2);

            $salePayload = [
                'created_by_user_id' => $createdByUserId ?? $productOwner->id,
                'customer_id' => $customer?->id,
                'status' => $status,
                'subtotal' => $subtotal,
                'tax_total' => $discountedTaxTotal,
                'discount_rate' => $discountRate,
                'discount_total' => $discountTotal,
                'source' => $extras['source'] ?? 'pos',
                'total' => $total,
                'fulfillment_method' => $fulfillmentMethod,
                'fulfillment_status' => $fulfillmentStatus,
                'delivery_fee' => (float) ($fulfillment['delivery_fee'] ?? 0),
                'delivery_address' => $fulfillment['delivery_address'] ?? null,
                'delivery_notes' => $fulfillment['delivery_notes'] ?? null,
                'pickup_notes' => $fulfillment['pickup_notes'] ?? null,
                'scheduled_for' => $fulfillment['scheduled_for'] ?? null,
                'paid_at' => $status === Sale::STATUS_PAID ? $createdAt : null,
                'notes' => $label,
            ];

            if (array_key_exists('created_by_user_id', $extras)) {
                $salePayload['created_by_user_id'] = $extras['created_by_user_id'];
            }
            if (array_key_exists('customer_notes', $extras)) {
                $salePayload['customer_notes'] = $extras['customer_notes'];
            }
            if (array_key_exists('substitution_allowed', $extras)) {
                $salePayload['substitution_allowed'] = (bool) $extras['substitution_allowed'];
            }
            if (array_key_exists('substitution_notes', $extras)) {
                $salePayload['substitution_notes'] = $extras['substitution_notes'];
            }
            if (array_key_exists('pickup_code', $extras)) {
                $salePayload['pickup_code'] = $extras['pickup_code'];
            }
            if (array_key_exists('pickup_confirmed_at', $extras)) {
                $salePayload['pickup_confirmed_at'] = $extras['pickup_confirmed_at'];
            }
            if (array_key_exists('pickup_confirmed_by_user_id', $extras)) {
                $salePayload['pickup_confirmed_by_user_id'] = $extras['pickup_confirmed_by_user_id'];
            }

            $sale = Sale::updateOrCreate(
                [
                    'user_id' => $productOwner->id,
                    'notes' => $label,
                ],
                $salePayload
            );

            $sale->items()->delete();
            foreach ($items as $payload) {
                $sale->items()->create($payload);
            }

            $sale->refresh();
            $setTimestamps($sale, $createdAt);
            foreach ($sale->items as $item) {
                $setTimestamps($item, $createdAt);
            }

            return $sale;
        };

        $seedSaleTimeline = function (?User $actor, Sale $sale, array $entries) use ($setTimestamps) {
            foreach ($entries as $entry) {
                $action = $entry['action'] ?? null;
                if (!$action) {
                    continue;
                }
                $log = ActivityLog::record(
                    $actor,
                    $sale,
                    $action,
                    $entry['properties'] ?? [],
                    $entry['description'] ?? null
                );
                if (!empty($entry['at'])) {
                    $setTimestamps($log, $entry['at']);
                }
            }
        };

        if ($productSalesCatalog->isNotEmpty()) {
            if ($canSeedSales) {
                $createSale(
                'Seeded POS sale - Mia Builds',
                $productCustomer,
                Sale::STATUS_PAID,
                [
                    ['product' => $productSalesCatalog->get(0), 'quantity' => 3],
                    ['product' => $productSalesCatalog->get(1), 'quantity' => 2],
                    ['product' => $productSalesCatalog->get(2), 'quantity' => 1],
                ],
                $now->copy()->subDays(5),
                null,
                [
                    'method' => 'delivery',
                    'status' => Sale::FULFILLMENT_OUT_FOR_DELIVERY,
                    'delivery_fee' => 7.5,
                    'delivery_address' => '42 Product St, Toronto, ON',
                    'delivery_notes' => 'Leave at reception',
                    'scheduled_for' => $now->copy()->addHours(3),
                ]
            );

            $createSale(
                'Seeded POS sale - City Market',
                $productCustomerRetail,
                Sale::STATUS_PENDING,
                [
                    ['product' => $productSalesCatalog->get(3), 'quantity' => 4],
                    ['product' => $productSalesCatalog->get(4), 'quantity' => 2],
                ],
                $now->copy()->subDays(2)
            );

            $createSale(
                'Seeded POS sale - Walk-in',
                null,
                Sale::STATUS_PAID,
                [
                    ['product' => $productSalesCatalog->get(5), 'quantity' => 1],
                    ['product' => $productSalesCatalog->get(6), 'quantity' => 2],
                ],
                $now->copy()->subDay(),
                $productSellerUser->id
            );

            $createSale(
                'Seeded POS sale - Draft cart',
                $productCustomerWholesale,
                Sale::STATUS_DRAFT,
                [
                    ['product' => $productSalesCatalog->get(7), 'quantity' => 3],
                    ['product' => $productSalesCatalog->get(8), 'quantity' => 1],
                ],
                $now->copy()->subHours(6)
            );

            $createSale(
                'Seeded POS sale - Cancelled order',
                null,
                Sale::STATUS_CANCELED,
                [
                    ['product' => $productSalesCatalog->get(9), 'quantity' => 2],
                ],
                $now->copy()->subDays(3)
            );

            $portalPending = $createSale(
                'Seeded portal order - Preparing',
                $productCustomer,
                Sale::STATUS_PENDING,
                [
                    ['product' => $productSalesCatalog->get(0), 'quantity' => 1],
                    ['product' => $productSalesCatalog->get(3), 'quantity' => 2],
                ],
                $now->copy()->subHours(8),
                null,
                [
                    'method' => 'delivery',
                    'status' => Sale::FULFILLMENT_PREPARING,
                    'delivery_fee' => 7.5,
                    'delivery_address' => '42 Product St, Toronto, ON',
                    'delivery_notes' => 'Leave at reception',
                    'scheduled_for' => $now->copy()->addHours(4),
                ],
                [
                    'source' => 'portal',
                    'created_by_user_id' => null,
                    'customer_notes' => 'Call before delivery.',
                    'substitution_allowed' => true,
                    'substitution_notes' => 'Swap with store brand if needed.',
                ]
            );

            if ($portalPending) {
                $seedSaleTimeline($productPortalUser, $portalPending, [
                    [
                        'action' => 'sale_created',
                        'at' => $portalPending->created_at,
                        'properties' => ['source' => 'portal'],
                    ],
                    [
                        'action' => 'sale_fulfillment_changed',
                        'at' => $portalPending->created_at->copy()->addHours(1),
                        'properties' => [
                            'fulfillment_from' => 'pending',
                            'fulfillment_to' => 'preparing',
                        ],
                    ],
                    [
                        'action' => 'sale_eta_updated',
                        'at' => $portalPending->created_at->copy()->addHours(2),
                        'properties' => [
                            'scheduled_for' => $portalPending->scheduled_for?->format('Y-m-d H:i'),
                        ],
                    ],
                ]);
            }

            $portalDelivery = $createSale(
                'Seeded portal order - Out for delivery',
                $productCustomer,
                Sale::STATUS_PENDING,
                [
                    ['product' => $productSalesCatalog->get(2), 'quantity' => 1],
                    ['product' => $productSalesCatalog->get(4), 'quantity' => 3],
                ],
                $now->copy()->subHours(4),
                null,
                [
                    'method' => 'delivery',
                    'status' => Sale::FULFILLMENT_OUT_FOR_DELIVERY,
                    'delivery_fee' => 7.5,
                    'delivery_address' => '42 Product St, Toronto, ON',
                    'delivery_notes' => 'Leave at reception',
                    'scheduled_for' => $now->copy()->addHours(2),
                ],
                [
                    'source' => 'portal',
                    'created_by_user_id' => null,
                    'customer_notes' => 'Ring the buzzer.',
                    'substitution_allowed' => true,
                ]
            );

            if ($portalDelivery) {
                $seedSaleTimeline($productPortalUser, $portalDelivery, [
                    [
                        'action' => 'sale_created',
                        'at' => $portalDelivery->created_at,
                        'properties' => ['source' => 'portal'],
                    ],
                    [
                        'action' => 'sale_fulfillment_changed',
                        'at' => $portalDelivery->created_at->copy()->addHours(2),
                        'properties' => [
                            'fulfillment_from' => 'pending',
                            'fulfillment_to' => 'out_for_delivery',
                        ],
                    ],
                ]);
            }

            $portalPickupReady = $createSale(
                'Seeded portal order - Ready pickup',
                $productCustomer,
                Sale::STATUS_PENDING,
                [
                    ['product' => $productSalesCatalog->get(1), 'quantity' => 2],
                ],
                $now->copy()->subHours(6),
                null,
                [
                    'method' => 'pickup',
                    'status' => Sale::FULFILLMENT_READY_FOR_PICKUP,
                    'pickup_notes' => 'Pickup counter 2',
                ],
                [
                    'source' => 'portal',
                    'created_by_user_id' => null,
                    'pickup_code' => 'PK-SEED-READY',
                    'customer_notes' => 'Arrive at 17:00.',
                    'substitution_allowed' => false,
                    'substitution_notes' => 'No substitutions.',
                ]
            );

            if ($portalPickupReady) {
                $seedSaleTimeline($productPortalUser, $portalPickupReady, [
                    [
                        'action' => 'sale_created',
                        'at' => $portalPickupReady->created_at,
                        'properties' => ['source' => 'portal'],
                    ],
                    [
                        'action' => 'sale_fulfillment_changed',
                        'at' => $portalPickupReady->created_at->copy()->addHours(2),
                        'properties' => [
                            'fulfillment_from' => 'pending',
                            'fulfillment_to' => 'ready_for_pickup',
                        ],
                    ],
                ]);
            }

            $portalPickupDone = $createSale(
                'Seeded portal order - Pickup complete',
                $productCustomer,
                Sale::STATUS_PAID,
                [
                    ['product' => $productSalesCatalog->get(5), 'quantity' => 1],
                ],
                $now->copy()->subHours(3),
                null,
                [
                    'method' => 'pickup',
                    'status' => Sale::FULFILLMENT_COMPLETED,
                    'pickup_notes' => 'Fast pickup lane',
                ],
                [
                    'source' => 'portal',
                    'created_by_user_id' => null,
                    'pickup_code' => 'PK-SEED-DONE',
                    'pickup_confirmed_at' => $now->copy()->subHours(1),
                    'pickup_confirmed_by_user_id' => $productSellerUser->id,
                    'customer_notes' => 'No bag needed.',
                    'substitution_allowed' => false,
                ]
            );

            if ($portalPickupDone) {
                $seedSaleTimeline($productPortalUser, $portalPickupDone, [
                    [
                        'action' => 'sale_created',
                        'at' => $portalPickupDone->created_at,
                        'properties' => ['source' => 'portal'],
                    ],
                    [
                        'action' => 'sale_fulfillment_changed',
                        'at' => $portalPickupDone->created_at->copy()->addHours(1),
                        'properties' => [
                            'fulfillment_from' => 'pending',
                            'fulfillment_to' => 'ready_for_pickup',
                        ],
                    ],
                    [
                        'action' => 'sale_pickup_confirmed',
                        'at' => $portalPickupDone->pickup_confirmed_at,
                    ],
                    [
                        'action' => 'sale_fulfillment_changed',
                        'at' => $portalPickupDone->pickup_confirmed_at,
                        'properties' => [
                            'fulfillment_from' => 'ready_for_pickup',
                            'fulfillment_to' => 'completed',
                        ],
                    ],
                ]);
            }

            $reviewSamples = [
                [
                    'rating' => 5,
                    'title' => 'Excellent',
                    'comment' => 'Great quality and fast shipping.',
                ],
                [
                    'rating' => 4,
                    'title' => 'Very good',
                    'comment' => 'Good value for the price.',
                ],
                [
                    'rating' => 3,
                    'title' => 'Solid',
                    'comment' => 'Works as expected.',
                ],
            ];

            $orderReviewSamples = [
                [
                    'rating' => 5,
                    'comment' => 'Delivery was on time.',
                ],
                [
                    'rating' => 4,
                    'comment' => 'Pickup was quick and easy.',
                ],
            ];

            $paidSales = Sale::query()
                ->with('items')
                ->where('user_id', $productOwner->id)
                ->where('status', Sale::STATUS_PAID)
                ->whereNotNull('customer_id')
                ->get();

            $blockedOrderSaleId = $paidSales->get(1)?->id;
            $blockedProductId = $paidSales->get(1)?->items->first()?->product_id;
            $reviewIndex = 0;

            foreach ($paidSales as $saleIndex => $sale) {
                $customer = Customer::find($sale->customer_id);
                if (!$customer) {
                    continue;
                }

                $orderSample = $orderReviewSamples[$saleIndex % count($orderReviewSamples)];
                $isOrderBlocked = $blockedOrderSaleId !== null && $sale->id === $blockedOrderSaleId;
                $orderReview = OrderReview::updateOrCreate(
                    [
                        'sale_id' => $sale->id,
                        'customer_id' => $customer->id,
                    ],
                    [
                        'rating' => $orderSample['rating'],
                        'comment' => $orderSample['comment'],
                        'is_approved' => !$isOrderBlocked,
                        'blocked_reason' => $isOrderBlocked ? 'blocked_terms' : null,
                    ]
                );

                $reviewTimestamp = $sale->paid_at ?? $sale->created_at ?? $now;
                $setTimestamps($orderReview, $reviewTimestamp);

                foreach ($sale->items as $item) {
                    if (!$item->product_id) {
                        continue;
                    }

                    $sample = $reviewSamples[$reviewIndex % count($reviewSamples)];
                    $isProductBlocked = $blockedProductId !== null && $item->product_id === $blockedProductId;
                    $productReview = ProductReview::updateOrCreate(
                        [
                            'product_id' => $item->product_id,
                            'customer_id' => $customer->id,
                        ],
                        [
                            'sale_id' => $sale->id,
                            'rating' => $sample['rating'],
                            'title' => $sample['title'],
                            'comment' => $sample['comment'],
                            'is_approved' => !$isProductBlocked,
                            'blocked_reason' => $isProductBlocked ? 'blocked_terms' : null,
                        ]
                    );

                    $setTimestamps($productReview, $reviewTimestamp->copy()->addMinutes($reviewIndex + 1));
                    $reviewIndex++;
                }
            }

            $performanceSellerUsers = $productSellerUsers->values();
            $performanceCustomers = collect([
                $productCustomer,
                $productCustomerRetail,
                $productCustomerWholesale,
            ])->filter()->values();
            $performanceDayOffsets = [2, 5, 9, 12, 16, 20, 24, 27];
            $monthsToSeed = 12;

            for ($monthOffset = 0; $monthOffset < $monthsToSeed; $monthOffset += 1) {
                $monthDate = $now->copy()->subMonths($monthOffset);
                $monthBase = $monthDate->copy()->startOfMonth();
                $monthKey = $monthDate->format('Y-m');

                foreach ($performanceSellerUsers as $sellerIndex => $seller) {
                    for ($saleIndex = 0; $saleIndex < 2; $saleIndex += 1) {
                        $dayOffset = $performanceDayOffsets[($sellerIndex + $saleIndex + $monthOffset) % count($performanceDayOffsets)];
                        $saleDate = $monthBase->copy()->addDays($dayOffset)->setTime(10 + ($saleIndex * 2), 15);
                        $customer = $performanceCustomers->isNotEmpty()
                            ? $performanceCustomers[($sellerIndex + $saleIndex + $monthOffset) % $performanceCustomers->count()]
                            : null;
                        $productIndex = ($sellerIndex + $saleIndex + $monthOffset) % $productSalesCatalog->count();
                        $secondaryIndex = ($productIndex + 3) % $productSalesCatalog->count();
                        $lines = [
                            [
                                'product' => $productSalesCatalog->get($productIndex),
                                'quantity' => 1 + (($sellerIndex + $monthOffset) % 3),
                            ],
                            [
                                'product' => $productSalesCatalog->get($secondaryIndex),
                                'quantity' => 1 + (($saleIndex + $monthOffset) % 2),
                            ],
                        ];

                        $createSale(
                            "Seeded performance sale {$monthKey} Seller {$seller->id} #{$saleIndex}",
                            $customer,
                            Sale::STATUS_PAID,
                            $lines,
                            $saleDate,
                            $seller->id
                        );
                    }
                }

                if ($performanceCustomers->isNotEmpty()) {
                    $onlineDayOffset = $performanceDayOffsets[($monthOffset + 3) % count($performanceDayOffsets)];
                    $onlineDate = $monthBase->copy()->addDays($onlineDayOffset)->setTime(15, 30);
                    $onlineProductIndex = ($monthOffset + 1) % $productSalesCatalog->count();
                    $onlineLines = [
                        [
                            'product' => $productSalesCatalog->get($onlineProductIndex),
                            'quantity' => 1 + ($monthOffset % 3),
                        ],
                    ];

                    $createSale(
                        "Seeded performance sale {$monthKey} Online #1",
                        $performanceCustomers[$monthOffset % $performanceCustomers->count()],
                        Sale::STATUS_PAID,
                        $onlineLines,
                        $onlineDate,
                        null,
                        [],
                        [
                            'source' => 'portal',
                            'created_by_user_id' => null,
                        ]
                    );
                }
            }

            if ($performanceSellerUsers->isNotEmpty() && $productSalesCatalog->isNotEmpty()) {
                $highlightCustomer = $performanceCustomers->first();
                $daySeller = $performanceSellerUsers->first();
                $weekSeller = $performanceSellerUsers->get(1) ?? $daySeller;

                if ($daySeller) {
                    $dayLines = [
                        ['product' => $productSalesCatalog->get(0), 'quantity' => 4],
                        ['product' => $productSalesCatalog->get(1), 'quantity' => 2],
                    ];

                    $createSale(
                        'Seeded performance highlight - Today',
                        $highlightCustomer,
                        Sale::STATUS_PAID,
                        $dayLines,
                        $now->copy()->subHours(2),
                        $daySeller->id
                    );
                }

                if ($weekSeller) {
                    $weekDate = $now->copy()->startOfWeek()->addDays(2)->setTime(11, 30);
                    if ($weekDate->isSameDay($now)) {
                        $alternateDate = $now->copy()->startOfWeek()->addDays(1)->setTime(11, 30);
                        if ($alternateDate->isSameDay($now)) {
                            $alternateDate = $now->copy()->startOfWeek()->setTime(11, 30);
                        }
                        $weekDate = $alternateDate;
                    }

                    $weekLines = [
                        ['product' => $productSalesCatalog->get(2), 'quantity' => 8],
                        ['product' => $productSalesCatalog->get(3), 'quantity' => 5],
                    ];

                    $createSale(
                        'Seeded performance highlight - Week',
                        $highlightCustomer,
                        Sale::STATUS_PAID,
                        $weekLines,
                        $weekDate,
                        $weekSeller->id
                    );
                }
            }
            } elseif ($this->command) {
                $this->command->warn('Skipping sales seed in LaunchSeeder because sales tables are not available.');
            }

            $attendanceMembers = TeamMember::query()
                ->where('account_id', $productOwner->id)
                ->whereIn('user_id', $performanceSellerUsers->pluck('id')->all())
                ->get()
                ->keyBy('user_id');
            $attendanceDayOffsets = [1, 2, 3, 5, 7, 9, 11, 14];
            $attendanceShifts = [
                ['08:45:00', '16:30:00'],
                ['09:15:00', '17:10:00'],
            ];

            foreach ($performanceSellerUsers as $sellerIndex => $seller) {
                $member = $attendanceMembers->get($seller->id);
                foreach ($attendanceDayOffsets as $dayIndex => $dayOffset) {
                    [$startTime, $endTime] = $attendanceShifts[($sellerIndex + $dayIndex) % count($attendanceShifts)];
                    [$startHour, $startMinute, $startSecond] = array_pad(explode(':', $startTime), 3, 0);
                    [$endHour, $endMinute, $endSecond] = array_pad(explode(':', $endTime), 3, 0);
                    $clockIn = $now->copy()->subDays($dayOffset)->setTime((int) $startHour, (int) $startMinute, (int) $startSecond);
                    $clockOut = $now->copy()->subDays($dayOffset)->setTime((int) $endHour, (int) $endMinute, (int) $endSecond);

                    $attendance = TeamMemberAttendance::updateOrCreate(
                        [
                            'account_id' => $productOwner->id,
                            'user_id' => $seller->id,
                            'clock_in_at' => $clockIn,
                        ],
                        [
                            'team_member_id' => $member?->id,
                            'clock_out_at' => $clockOut,
                            'method' => $dayIndex % 2 === 0 ? 'auto' : 'manual',
                            'clock_out_method' => $dayIndex % 2 === 0 ? 'auto' : 'manual',
                        ]
                    );
                    $setTimestamps($attendance, $clockOut);
                }
            }

            $openSeller = $performanceSellerUsers->first();
            if ($openSeller) {
                $member = $attendanceMembers->get($openSeller->id);
                $openClockIn = $now->copy()->subHours(2);
                $attendance = TeamMemberAttendance::updateOrCreate(
                    [
                        'account_id' => $productOwner->id,
                        'user_id' => $openSeller->id,
                        'clock_in_at' => $openClockIn,
                    ],
                    [
                        'team_member_id' => $member?->id,
                        'clock_out_at' => null,
                        'method' => 'auto',
                        'clock_out_method' => null,
                    ]
                );
                $setTimestamps($attendance, $openClockIn);
            }

            $shiftMembers = $attendanceMembers->values();
            if ($shiftMembers->isNotEmpty()) {
                $shiftStart = $now->copy()->startOfWeek();
                $shiftTemplates = [
                    ['offset' => 0, 'start' => '09:00:00', 'end' => '17:00:00', 'title' => 'Shift matin'],
                    ['offset' => 2, 'start' => '12:00:00', 'end' => '20:00:00', 'title' => 'Shift apres-midi'],
                    ['offset' => 4, 'start' => '08:30:00', 'end' => '16:30:00', 'title' => 'Shift complet'],
                ];
                $weeksToSeed = 4;

                foreach ($shiftMembers as $member) {
                    $groupId = Str::uuid()->toString();
                    for ($week = 0; $week < $weeksToSeed; $week += 1) {
                        foreach ($shiftTemplates as $templateIndex => $template) {
                            $shiftDate = $shiftStart->copy()->addWeeks($week)->addDays($template['offset']);
                            $shift = TeamMemberShift::updateOrCreate(
                                [
                                    'account_id' => $productOwner->id,
                                    'team_member_id' => $member->id,
                                    'shift_date' => $shiftDate->toDateString(),
                                    'start_time' => $template['start'],
                                ],
                                [
                                    'created_by_user_id' => $productOwner->id,
                                    'title' => $template['title'],
                                    'notes' => $templateIndex === 0 ? 'Seeded weekly shift.' : null,
                                    'end_time' => $template['end'],
                                    'recurrence_group_id' => $groupId,
                                ]
                            );
                            $shiftTimestamp = $shiftDate->copy()->setTimeFromTimeString($template['start']);
                            $setTimestamps($shift, $shiftTimestamp);
                        }
                    }
                }
            }
        }

        $suppliesCategory = ProductCategory::resolveForAccount(
            $serviceOwner->id,
            $serviceOwner->id,
            'Supplies'
        );

        $trendSeries = [
            [
                'quote_total' => 380,
                'outstanding_total' => 540,
                'payment_total' => 320,
                'inventory_stock' => 14,
                'inventory_price' => 120,
                'low_stock_stock' => 3,
                'low_stock_min' => 6,
                'low_stock_price' => 18,
                'work_status' => Work::STATUS_EN_ROUTE,
                'tasks' => ['todo' => 2, 'in_progress' => 1, 'done' => 1],
            ],
            [
                'quote_total' => 420,
                'outstanding_total' => 760,
                'payment_total' => 410,
                'inventory_stock' => 18,
                'inventory_price' => 110,
                'low_stock_stock' => 4,
                'low_stock_min' => 7,
                'low_stock_price' => 22,
                'work_status' => Work::STATUS_IN_PROGRESS,
                'tasks' => ['todo' => 2, 'in_progress' => 2, 'done' => 1],
            ],
            [
                'quote_total' => 520,
                'outstanding_total' => 620,
                'payment_total' => 480,
                'inventory_stock' => 20,
                'inventory_price' => 140,
                'low_stock_stock' => 2,
                'low_stock_min' => 5,
                'low_stock_price' => 16,
                'work_status' => Work::STATUS_EN_ROUTE,
                'tasks' => ['todo' => 3, 'in_progress' => 2, 'done' => 2],
            ],
            [
                'quote_total' => 460,
                'outstanding_total' => 880,
                'payment_total' => 560,
                'inventory_stock' => 12,
                'inventory_price' => 160,
                'low_stock_stock' => 3,
                'low_stock_min' => 6,
                'low_stock_price' => 24,
                'work_status' => Work::STATUS_IN_PROGRESS,
                'tasks' => ['todo' => 3, 'in_progress' => 3, 'done' => 2],
            ],
            [
                'quote_total' => 610,
                'outstanding_total' => 720,
                'payment_total' => 700,
                'inventory_stock' => 24,
                'inventory_price' => 130,
                'low_stock_stock' => 4,
                'low_stock_min' => 8,
                'low_stock_price' => 20,
                'work_status' => Work::STATUS_EN_ROUTE,
                'tasks' => ['todo' => 2, 'in_progress' => 2, 'done' => 3],
            ],
            [
                'quote_total' => 690,
                'outstanding_total' => 540,
                'payment_total' => 820,
                'inventory_stock' => 30,
                'inventory_price' => 150,
                'low_stock_stock' => 3,
                'low_stock_min' => 7,
                'low_stock_price' => 19,
                'work_status' => Work::STATUS_IN_PROGRESS,
                'tasks' => ['todo' => 2, 'in_progress' => 1, 'done' => 4],
            ],
        ];

        $monthDayOffsets = [2, 6, 10, 14, 18, 22, 26];
        $extraPerMonth = 3;
        $pastBoost = 2;

        foreach ($trendSeries as $index => $seed) {
            $monthOffset = (count($trendSeries) - 1) - $index;
            $monthDate = $now->copy()->subMonths($monthOffset);
            $monthBase = $monthDate->copy()->startOfMonth();
            $monthStart = $monthBase->copy()->addDays(3);
            $monthMid = $monthBase->copy()->addDays(12);
            $monthLate = $monthBase->copy()->addDays(22);
            $monthLabel = $monthDate->format('M');
            $monthExtra = $extraPerMonth + ($monthOffset >= 3 ? $pastBoost : 0);

            $trendCustomer = Customer::updateOrCreate(
                [
                    'email' => "trend-customer-{$index}@example.com",
                ],
                [
                    'user_id' => $serviceOwner->id,
                    'first_name' => 'Trend',
                    'last_name' => "Customer {$index}",
                    'company_name' => "Trend {$monthLabel} Co",
                    'phone' => '+15145550120',
                    'description' => 'Seeded trend customer.',
                    'salutation' => 'Mr',
                    'billing_same_as_physical' => true,
                ]
            );
            $setTimestamps($trendCustomer, $monthStart);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $extraCustomer = Customer::updateOrCreate(
                    [
                        'email' => "trend-extra-{$index}-{$i}@example.com",
                    ],
                    [
                        'user_id' => $serviceOwner->id,
                        'first_name' => 'Trend',
                        'last_name' => "Extra {$index}-{$i}",
                        'company_name' => "Trend {$monthLabel} Extra {$i}",
                        'phone' => '+15145550121',
                        'description' => 'Seeded trend extra customer.',
                        'salutation' => 'Mr',
                        'billing_same_as_physical' => true,
                    ]
                );
                $dayOffset = $monthDayOffsets[$i % count($monthDayOffsets)];
                $setTimestamps($extraCustomer, $monthBase->copy()->addDays($dayOffset));
            }

            $trendProperty = Property::updateOrCreate(
                [
                    'customer_id' => $trendCustomer->id,
                    'type' => 'physical',
                    'street1' => "Trend {$monthLabel} St",
                ],
                [
                    'is_default' => true,
                    'city' => 'Montreal',
                    'state' => 'QC',
                    'zip' => 'H2H2H2',
                    'country' => 'Canada',
                ]
            );
            $setTimestamps($trendProperty, $monthStart);

            $trendQuote = Quote::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $trendCustomer->id,
                    'job_title' => "Monthly service quote {$monthLabel}",
                ],
                [
                    'property_id' => $trendProperty->id,
                    'status' => 'sent',
                    'notes' => 'Seeded trend quote.',
                    'messages' => null,
                    'subtotal' => $seed['quote_total'],
                    'total' => $seed['quote_total'],
                    'initial_deposit' => 0,
                    'is_fixed' => false,
                ]
            );
            $setTimestamps($trendQuote, $monthMid);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $quoteTotal = round($seed['quote_total'] * (0.8 + (0.12 * $i)), 2);
                $extraQuote = Quote::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $trendCustomer->id,
                        'job_title' => "Monthly service quote {$monthLabel} #{$i}",
                    ],
                    [
                        'property_id' => $trendProperty->id,
                        'status' => $i % 2 === 0 ? 'draft' : 'sent',
                        'notes' => 'Seeded trend quote.',
                        'messages' => null,
                        'subtotal' => $quoteTotal,
                        'total' => $quoteTotal,
                        'initial_deposit' => 0,
                        'is_fixed' => false,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 1) % count($monthDayOffsets)];
                $setTimestamps($extraQuote, $monthBase->copy()->addDays($dayOffset));
            }

            $trendWork = Work::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $trendCustomer->id,
                    'job_title' => "Monthly service {$monthLabel}",
                ],
                [
                    'instructions' => 'Seeded trend work.',
                    'start_date' => $monthMid->toDateString(),
                    'status' => $seed['work_status'],
                    'subtotal' => $seed['quote_total'],
                    'total' => $seed['quote_total'],
                ]
            );
            $setTimestamps($trendWork, $monthMid);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $workTotal = round($seed['quote_total'] * (0.75 + (0.1 * $i)), 2);
                $extraWork = Work::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $trendCustomer->id,
                        'job_title' => "Monthly service {$monthLabel} Extra #{$i}",
                    ],
                    [
                        'instructions' => 'Seeded trend work.',
                        'start_date' => $monthMid->toDateString(),
                        'status' => $i % 2 === 0 ? Work::STATUS_EN_ROUTE : Work::STATUS_IN_PROGRESS,
                        'subtotal' => $workTotal,
                        'total' => $workTotal,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 2) % count($monthDayOffsets)];
                $setTimestamps($extraWork, $monthBase->copy()->addDays($dayOffset));
            }

            $outstandingWork = Work::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $trendCustomer->id,
                    'job_title' => "Outstanding invoice {$monthLabel}",
                ],
                [
                    'instructions' => 'Seeded outstanding invoice work.',
                    'start_date' => $monthLate->toDateString(),
                    'status' => Work::STATUS_SCHEDULED,
                    'subtotal' => $seed['outstanding_total'],
                    'total' => $seed['outstanding_total'],
                ]
            );
            $setTimestamps($outstandingWork, $monthLate);

            $outstandingInvoice = Invoice::updateOrCreate(
                [
                    'work_id' => $outstandingWork->id,
                ],
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $trendCustomer->id,
                    'status' => $index % 2 === 0 ? 'sent' : 'overdue',
                    'total' => $seed['outstanding_total'],
                ]
            );
            $setTimestamps($outstandingInvoice, $monthLate);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $outstandingTotal = round($seed['outstanding_total'] * (0.6 + (0.2 * $i)), 2);
                $extraOutstandingWork = Work::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $trendCustomer->id,
                        'job_title' => "Outstanding invoice {$monthLabel} #{$i}",
                    ],
                    [
                        'instructions' => 'Seeded outstanding invoice work.',
                        'start_date' => $monthLate->toDateString(),
                        'status' => Work::STATUS_SCHEDULED,
                        'subtotal' => $outstandingTotal,
                        'total' => $outstandingTotal,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 3) % count($monthDayOffsets)];
                $setTimestamps($extraOutstandingWork, $monthBase->copy()->addDays($dayOffset));

                $extraOutstandingInvoice = Invoice::updateOrCreate(
                    [
                        'work_id' => $extraOutstandingWork->id,
                    ],
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $trendCustomer->id,
                        'status' => $i % 2 === 0 ? 'sent' : 'overdue',
                        'total' => $outstandingTotal,
                    ]
                );
                $setTimestamps($extraOutstandingInvoice, $monthBase->copy()->addDays($dayOffset));
            }

            $paidWork = Work::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $trendCustomer->id,
                    'job_title' => "Paid work {$monthLabel}",
                ],
                [
                    'instructions' => 'Seeded paid work.',
                    'start_date' => $monthLate->toDateString(),
                    'status' => Work::STATUS_CLOSED,
                    'subtotal' => $seed['payment_total'],
                    'total' => $seed['payment_total'],
                    'completed_at' => $monthLate,
                ]
            );
            $setTimestamps($paidWork, $monthLate);

            $paidInvoice = Invoice::updateOrCreate(
                [
                    'work_id' => $paidWork->id,
                ],
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $trendCustomer->id,
                    'status' => 'sent',
                    'total' => $seed['payment_total'],
                ]
            );
            $trendTipPercent = 8.0;
            $trendTipAmount = round((float) $seed['payment_total'] * ($trendTipPercent / 100), 2);
            $trendTipReversedAmount = $index === 1 ? round($trendTipAmount * 0.25, 2) : 0.0;

            $payment = Payment::updateOrCreate(
                [
                    'invoice_id' => $paidInvoice->id,
                    'reference' => "SEED-PAY-TREND-{$index}",
                ],
                $withTipData([
                    'customer_id' => $trendCustomer->id,
                    'user_id' => $serviceOwner->id,
                    'amount' => $seed['payment_total'],
                    'method' => 'card',
                    'status' => $trendTipReversedAmount > 0 ? 'reversed' : 'completed',
                    'notes' => 'Seeded trend payment.',
                    'paid_at' => $monthLate,
                ], [
                    'tip_amount' => $trendTipAmount,
                    'tip_type' => 'percent',
                    'tip_percent' => $trendTipPercent,
                    'tip_base_amount' => (float) $seed['payment_total'],
                    'charged_total' => round((float) $seed['payment_total'] + $trendTipAmount, 2),
                    'tip_assignee_user_id' => $index % 2 === 0 ? $memberUser->id : $adminUser->id,
                    'tip_reversed_amount' => $trendTipReversedAmount,
                    'tip_reversed_at' => $trendTipReversedAmount > 0 ? $monthLate->copy()->addHours(4) : null,
                    'tip_reversal_rule' => $trendTipReversedAmount > 0 ? 'prorata' : null,
                    'tip_reversal_reason' => $trendTipReversedAmount > 0 ? 'Seeded trend tip reversal' : null,
                ])
            );
            $syncTipAllocations($payment);
            $paidInvoice->refreshPaymentStatus();
            $setTimestamps($paidInvoice, $monthLate);
            $setTimestamps($payment, $monthLate);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $paidTotal = round($seed['payment_total'] * (0.7 + (0.15 * $i)), 2);
                $extraPaidWork = Work::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $trendCustomer->id,
                        'job_title' => "Paid work {$monthLabel} #{$i}",
                    ],
                    [
                        'instructions' => 'Seeded paid work.',
                        'start_date' => $monthLate->toDateString(),
                        'status' => Work::STATUS_CLOSED,
                        'subtotal' => $paidTotal,
                        'total' => $paidTotal,
                        'completed_at' => $monthLate,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 4) % count($monthDayOffsets)];
                $setTimestamps($extraPaidWork, $monthBase->copy()->addDays($dayOffset));

                $extraPaidInvoice = Invoice::updateOrCreate(
                    [
                        'work_id' => $extraPaidWork->id,
                    ],
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $trendCustomer->id,
                        'status' => 'sent',
                        'total' => $paidTotal,
                    ]
                );

                $extraPayment = Payment::updateOrCreate(
                    [
                        'invoice_id' => $extraPaidInvoice->id,
                        'reference' => "SEED-PAY-TREND-{$index}-{$i}",
                    ],
                    $withTipData([
                        'customer_id' => $trendCustomer->id,
                        'user_id' => $serviceOwner->id,
                        'amount' => $paidTotal,
                        'method' => 'card',
                        'status' => 'completed',
                        'notes' => 'Seeded trend payment.',
                        'paid_at' => $monthBase->copy()->addDays($dayOffset),
                    ], [
                        'tip_amount' => (float) (5 + $i),
                        'tip_type' => 'fixed',
                        'tip_percent' => null,
                        'tip_base_amount' => $paidTotal,
                        'charged_total' => round($paidTotal + (5 + $i), 2),
                        'tip_assignee_user_id' => $i % 2 === 0 ? $adminUser->id : $memberUser->id,
                        'tip_reversed_amount' => 0,
                        'tip_reversed_at' => null,
                        'tip_reversal_rule' => null,
                        'tip_reversal_reason' => null,
                    ])
                );
                $syncTipAllocations($extraPayment);
                $extraPaidInvoice->refreshPaymentStatus();
                $setTimestamps($extraPaidInvoice, $monthBase->copy()->addDays($dayOffset));
                $setTimestamps($extraPayment, $monthBase->copy()->addDays($dayOffset));
            }

            $inventoryProduct = Product::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'name' => "Supply pack {$monthLabel}",
                ],
                [
                    'category_id' => $suppliesCategory->id,
                    'price' => $seed['inventory_price'],
                    'stock' => $seed['inventory_stock'],
                    'minimum_stock' => 5,
                    'item_type' => Product::ITEM_TYPE_PRODUCT,
                ]
            );
            $setTimestamps($inventoryProduct, $monthMid);

            $lowStockProduct = Product::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'name' => "Low stock filter {$monthLabel}",
                ],
                [
                    'category_id' => $suppliesCategory->id,
                    'price' => $seed['low_stock_price'],
                    'stock' => $seed['low_stock_stock'],
                    'minimum_stock' => $seed['low_stock_min'],
                    'item_type' => Product::ITEM_TYPE_PRODUCT,
                ]
            );
            $setTimestamps($lowStockProduct, $monthLate);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $inventoryPrice = round($seed['inventory_price'] * (0.9 + (0.1 * $i)), 2);
                $inventoryStock = $seed['inventory_stock'] + ($i * 3);
                $extraInventoryProduct = Product::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'name' => "Supply pack {$monthLabel} #{$i}",
                    ],
                    [
                        'category_id' => $suppliesCategory->id,
                        'price' => $inventoryPrice,
                        'stock' => $inventoryStock,
                        'minimum_stock' => 5,
                        'item_type' => Product::ITEM_TYPE_PRODUCT,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 1) % count($monthDayOffsets)];
                $setTimestamps($extraInventoryProduct, $monthBase->copy()->addDays($dayOffset));

                $lowStockPrice = round($seed['low_stock_price'] * (0.85 + (0.1 * $i)), 2);
                $lowStockProductExtra = Product::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'name' => "Low stock filter {$monthLabel} #{$i}",
                    ],
                    [
                        'category_id' => $suppliesCategory->id,
                        'price' => $lowStockPrice,
                        'stock' => max(1, $seed['low_stock_stock'] - $i),
                        'minimum_stock' => $seed['low_stock_min'] + $i,
                        'item_type' => Product::ITEM_TYPE_PRODUCT,
                    ]
                );
                $setTimestamps($lowStockProductExtra, $monthBase->copy()->addDays($dayOffset));
            }

            foreach ($seed['tasks'] as $status => $count) {
                $taskTotal = $count + $monthExtra;
                for ($i = 1; $i <= $taskTotal; $i += 1) {
                    $taskDueDate = $monthLate->copy();
                    if ($status !== 'todo' && $taskDueDate->gt($now)) {
                        $taskDueDate = $now->copy();
                    }
                    $taskCompletedAt = null;
                    if ($status === 'done') {
                        $taskCompletedAt = $taskDueDate->copy()->setTime(16, 0);
                    }

                    $task = Task::updateOrCreate(
                        [
                            'account_id' => $serviceOwner->id,
                            'title' => "Trend {$status} {$monthLabel} #{$i}",
                        ],
                        [
                            'created_by_user_id' => $serviceOwner->id,
                            'assigned_team_member_id' => $status === 'done' ? $adminMember->id : $memberMember->id,
                            'customer_id' => $trendCustomer->id,
                            'product_id' => $inventoryProduct->id,
                            'description' => 'Seeded trend task.',
                            'status' => $status,
                            'due_date' => $taskDueDate->toDateString(),
                            'completed_at' => $taskCompletedAt,
                        ]
                    );
                    $setTimestamps($task, $monthLate->copy()->addMinutes($i));
                }
            }

            $clientPendingQuote = Quote::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $serviceCustomer->id,
                    'job_title' => "Client pending quote {$monthLabel}",
                ],
                [
                    'property_id' => $serviceProperty->id,
                    'status' => 'sent',
                    'notes' => 'Seeded client pending quote.',
                    'messages' => null,
                    'subtotal' => $seed['quote_total'],
                    'total' => $seed['quote_total'],
                    'initial_deposit' => 0,
                    'is_fixed' => false,
                ]
            );
            $setTimestamps($clientPendingQuote, $monthMid);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $clientQuoteTotal = round($seed['quote_total'] * (0.7 + (0.1 * $i)), 2);
                $clientPendingExtra = Quote::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $serviceCustomer->id,
                        'job_title' => "Client pending quote {$monthLabel} #{$i}",
                    ],
                    [
                        'property_id' => $serviceProperty->id,
                        'status' => $i % 2 === 0 ? 'sent' : 'draft',
                        'notes' => 'Seeded client pending quote.',
                        'messages' => null,
                        'subtotal' => $clientQuoteTotal,
                        'total' => $clientQuoteTotal,
                        'initial_deposit' => 0,
                        'is_fixed' => false,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 2) % count($monthDayOffsets)];
                $setTimestamps($clientPendingExtra, $monthBase->copy()->addDays($dayOffset));
            }

            $clientPendingWork = Work::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $serviceCustomer->id,
                    'job_title' => "Client pending work {$monthLabel}",
                ],
                [
                    'instructions' => 'Seeded client pending work.',
                    'start_date' => $monthMid->toDateString(),
                    'status' => Work::STATUS_PENDING_REVIEW,
                    'subtotal' => $seed['quote_total'],
                    'total' => $seed['quote_total'],
                ]
            );
            $setTimestamps($clientPendingWork, $monthMid);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $clientWorkTotal = round($seed['quote_total'] * (0.65 + (0.1 * $i)), 2);
                $clientPendingExtraWork = Work::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $serviceCustomer->id,
                        'job_title' => "Client pending work {$monthLabel} #{$i}",
                    ],
                    [
                        'instructions' => 'Seeded client pending work.',
                        'start_date' => $monthMid->toDateString(),
                        'status' => $i % 2 === 0 ? Work::STATUS_PENDING_REVIEW : Work::STATUS_TECH_COMPLETE,
                        'subtotal' => $clientWorkTotal,
                        'total' => $clientWorkTotal,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 3) % count($monthDayOffsets)];
                $setTimestamps($clientPendingExtraWork, $monthBase->copy()->addDays($dayOffset));
            }

            $clientInvoiceWork = Work::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $serviceCustomer->id,
                    'job_title' => "Client invoice {$monthLabel}",
                ],
                [
                    'instructions' => 'Seeded client invoice work.',
                    'start_date' => $monthMid->toDateString(),
                    'status' => Work::STATUS_SCHEDULED,
                    'subtotal' => $seed['outstanding_total'],
                    'total' => $seed['outstanding_total'],
                ]
            );
            $setTimestamps($clientInvoiceWork, $monthMid);

            $clientInvoice = Invoice::updateOrCreate(
                [
                    'work_id' => $clientInvoiceWork->id,
                ],
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $serviceCustomer->id,
                    'status' => $index % 2 === 0 ? 'sent' : 'overdue',
                    'total' => $seed['outstanding_total'],
                ]
            );
            $setTimestamps($clientInvoice, $monthLate);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $clientInvoiceTotal = round($seed['outstanding_total'] * (0.6 + (0.15 * $i)), 2);
                $clientInvoiceWorkExtra = Work::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $serviceCustomer->id,
                        'job_title' => "Client invoice {$monthLabel} #{$i}",
                    ],
                    [
                        'instructions' => 'Seeded client invoice work.',
                        'start_date' => $monthMid->toDateString(),
                        'status' => Work::STATUS_SCHEDULED,
                        'subtotal' => $clientInvoiceTotal,
                        'total' => $clientInvoiceTotal,
                    ]
                );
                $dayOffset = $monthDayOffsets[($i + 4) % count($monthDayOffsets)];
                $setTimestamps($clientInvoiceWorkExtra, $monthBase->copy()->addDays($dayOffset));

                $clientInvoiceExtra = Invoice::updateOrCreate(
                    [
                        'work_id' => $clientInvoiceWorkExtra->id,
                    ],
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $serviceCustomer->id,
                        'status' => $i % 2 === 0 ? 'sent' : 'overdue',
                        'total' => $clientInvoiceTotal,
                    ]
                );
                $setTimestamps($clientInvoiceExtra, $monthBase->copy()->addDays($dayOffset));
            }

            $clientRatingQuote = Quote::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $serviceCustomer->id,
                    'job_title' => "Client rating quote {$monthLabel}",
                ],
                [
                    'property_id' => $serviceProperty->id,
                    'status' => 'accepted',
                    'notes' => 'Seeded rating quote.',
                    'messages' => null,
                    'subtotal' => $seed['quote_total'],
                    'total' => $seed['quote_total'],
                    'initial_deposit' => 0,
                    'is_fixed' => false,
                    'accepted_at' => $monthLate,
                    'signed_at' => $monthLate,
                ]
            );
            $setTimestamps($clientRatingQuote, $monthLate);

            $clientRatingWork = Work::updateOrCreate(
                [
                    'user_id' => $serviceOwner->id,
                    'customer_id' => $serviceCustomer->id,
                    'job_title' => "Client rating work {$monthLabel}",
                ],
                [
                    'instructions' => 'Seeded rating work.',
                    'start_date' => $monthMid->toDateString(),
                    'status' => Work::STATUS_VALIDATED,
                    'subtotal' => $seed['payment_total'],
                    'total' => $seed['payment_total'],
                    'completed_at' => $monthLate,
                ]
            );
            $setTimestamps($clientRatingWork, $monthLate);

            for ($i = 1; $i <= $monthExtra; $i += 1) {
                $ratingTotal = round($seed['payment_total'] * (0.6 + (0.1 * $i)), 2);
                $clientRatingQuoteExtra = Quote::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $serviceCustomer->id,
                        'job_title' => "Client rating quote {$monthLabel} #{$i}",
                    ],
                    [
                        'property_id' => $serviceProperty->id,
                        'status' => $i % 2 === 0 ? 'accepted' : 'declined',
                        'notes' => 'Seeded rating quote.',
                        'messages' => null,
                        'subtotal' => $ratingTotal,
                        'total' => $ratingTotal,
                        'initial_deposit' => 0,
                        'is_fixed' => false,
                        'accepted_at' => $monthLate,
                        'signed_at' => $monthLate,
                    ]
                );
                $dayOffset = $monthDayOffsets[$i % count($monthDayOffsets)];
                $setTimestamps($clientRatingQuoteExtra, $monthBase->copy()->addDays($dayOffset));

                $clientRatingWorkExtra = Work::updateOrCreate(
                    [
                        'user_id' => $serviceOwner->id,
                        'customer_id' => $serviceCustomer->id,
                        'job_title' => "Client rating work {$monthLabel} #{$i}",
                    ],
                    [
                        'instructions' => 'Seeded rating work.',
                        'start_date' => $monthMid->toDateString(),
                        'status' => $i % 2 === 0 ? Work::STATUS_VALIDATED : Work::STATUS_AUTO_VALIDATED,
                        'subtotal' => $ratingTotal,
                        'total' => $ratingTotal,
                        'completed_at' => $monthLate,
                    ]
                );
                $setTimestamps($clientRatingWorkExtra, $monthBase->copy()->addDays($dayOffset));
            }
        }

        $ensureTeamMembers = function (User $owner, int $minCount) use ($employeeRoleId, $now) {
            $existingCount = TeamMember::query()
                ->where('account_id', $owner->id)
                ->count();

            $toCreate = max(0, $minCount - $existingCount);
            if ($toCreate <= 0) {
                return;
            }

            $slug = Str::slug($owner->company_name ?: ('company-' . $owner->id));
            for ($i = 1; $i <= $toCreate; $i += 1) {
                $email = "member.{$slug}.{$i}@example.com";
                $memberUser = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => trim(($owner->company_name ?: 'Company') . " Member {$i}"),
                        'password' => Hash::make('password'),
                        'role_id' => $employeeRoleId,
                        'email_verified_at' => $now,
                    ]
                );

                TeamMember::updateOrCreate(
                    [
                        'account_id' => $owner->id,
                        'user_id' => $memberUser->id,
                    ],
                    [
                        'role' => 'member',
                        'permissions' => [
                            'jobs.view',
                            'tasks.view',
                        ],
                        'is_active' => true,
                    ]
                );
            }
        };

        $resolveServiceProduct = function (User $owner) {
            $service = Product::query()
                ->services()
                ->where('user_id', $owner->id)
                ->orderBy('id')
                ->first();

            if ($service) {
                return $service;
            }

            $category = ProductCategory::resolveForAccount($owner->id, $owner->id, 'Services');

            return Product::updateOrCreate(
                [
                    'user_id' => $owner->id,
                    'name' => 'Standard service',
                ],
                [
                    'category_id' => $category->id,
                    'price' => 120,
                    'stock' => 0,
                    'minimum_stock' => 0,
                    'item_type' => Product::ITEM_TYPE_SERVICE,
                ]
            );
        };

        $ensureQuoteService = function (Quote $quote, Product $serviceProduct) {
            $hasService = $quote->products()
                ->where('products.item_type', Product::ITEM_TYPE_SERVICE)
                ->exists();

            if ($hasService) {
                return;
            }

            $lineTotal = (float) $quote->total;
            if ($lineTotal <= 0) {
                $lineTotal = (float) $serviceProduct->price;
                $quote->update([
                    'subtotal' => $lineTotal,
                    'total' => $lineTotal,
                    'initial_deposit' => (float) ($quote->initial_deposit ?? 0),
                ]);
            }

            $quote->products()->syncWithoutDetaching([
                $serviceProduct->id => [
                    'quantity' => 1,
                    'price' => $lineTotal,
                    'total' => $lineTotal,
                    'description' => $serviceProduct->description,
                ],
            ]);
        };

        $ensureWorkService = function (Work $work, Product $serviceProduct) {
            $hasService = $work->products()
                ->where('products.item_type', Product::ITEM_TYPE_SERVICE)
                ->exists();

            if ($hasService) {
                return;
            }

            $lineTotal = (float) $work->total;
            if ($lineTotal <= 0) {
                $lineTotal = (float) $serviceProduct->price;
                $work->update([
                    'subtotal' => $lineTotal,
                    'total' => $lineTotal,
                ]);
            }

            $work->products()->syncWithoutDetaching([
                $serviceProduct->id => [
                    'quantity' => 1,
                    'price' => $lineTotal,
                    'total' => $lineTotal,
                    'description' => $serviceProduct->description,
                ],
            ]);
        };

        $seedInvoiceItems = function (Invoice $invoice, ?Work $work, Product $serviceProduct) {
            if ($invoice->items()->exists()) {
                return;
            }

            $invoiceTotal = (float) $invoice->total;
            if ($invoiceTotal <= 0) {
                return;
            }

            $scheduledDate = null;
            if ($work?->start_date) {
                $scheduledDate = $work->start_date instanceof \Carbon\Carbon
                    ? $work->start_date->toDateString()
                    : \Carbon\Carbon::parse($work->start_date)->toDateString();
            }

            $invoice->items()->create([
                'work_id' => $work?->id,
                'title' => $serviceProduct->name,
                'description' => $serviceProduct->description,
                'scheduled_date' => $scheduledDate,
                'start_time' => $work?->start_time,
                'end_time' => $work?->end_time,
                'quantity' => 1,
                'unit_price' => $invoiceTotal,
                'total' => $invoiceTotal,
                'meta' => [
                    'source' => 'seed',
                    'product_id' => $serviceProduct->id,
                    'item_type' => $serviceProduct->item_type,
                ],
            ]);
        };

        $owners = collect([
            $serviceOwner,
            $productOwner,
            $salonOwner,
            $restaurantOwner,
            $serviceLiteOwner,
            $serviceNoInvoiceOwner,
            $serviceRequestsOwner,
        ])->filter();

        foreach ($owners as $owner) {
            $ensureTeamMembers($owner, 8);
        }

        $calendarMembers = TeamMember::query()
            ->forAccount($serviceOwner->id)
            ->active()
            ->orderBy('id')
            ->get(['id']);

        if ($calendarMembers->isNotEmpty()) {
            $calendarStart = $now->copy()->addWeek()->startOfWeek();
            $calendarSlots = [
                [
                    ['09:00:00', '10:30:00'],
                    ['14:00:00', '15:30:00'],
                ],
                [
                    ['09:00:00', '10:30:00'],
                    ['11:00:00', '12:30:00'],
                    ['14:00:00', '15:30:00'],
                ],
                [
                    ['11:00:00', '12:30:00'],
                    ['14:00:00', '15:30:00'],
                ],
                [
                    ['09:00:00', '10:30:00'],
                    ['14:00:00', '15:30:00'],
                ],
                [
                    ['09:00:00', '10:30:00'],
                ],
            ];
            $calendarProductId = $serviceProducts->first()?->id;
            $calendarCustomerId = $serviceCustomer->id ?? null;

            foreach ($calendarMembers as $memberIndex => $member) {
                foreach ($calendarSlots as $dayOffset => $slots) {
                    $date = $calendarStart->copy()->addDays($dayOffset);
                    $dateString = $date->toDateString();

                    foreach ($slots as $slotIndex => $slot) {
                        if ($dayOffset === 1 && $slotIndex === 1 && $memberIndex % 3 === 0) {
                            continue;
                        }

                        [$startTime, $endTime] = $slot;
                        $title = 'Weekly schedule ' . $date->format('D') . ' #' . ($slotIndex + 1) . ' (M' . $member->id . ')';

                        Task::updateOrCreate(
                            [
                                'account_id' => $serviceOwner->id,
                                'assigned_team_member_id' => $member->id,
                                'due_date' => $dateString,
                                'start_time' => $startTime,
                                'title' => $title,
                            ],
                            [
                                'created_by_user_id' => $serviceOwner->id,
                                'customer_id' => $calendarCustomerId,
                                'product_id' => $calendarProductId,
                                'description' => 'Seeded weekly calendar task.',
                                'status' => 'todo',
                                'end_time' => $endTime,
                                'completed_at' => null,
                            ]
                        );
                    }
                }
            }

            $conflictDate = $calendarStart->copy();
            $conflictDateString = $conflictDate->toDateString();
            $conflictSlots = [
                ['09:00:00', '10:30:00'],
                ['14:00:00', '15:30:00'],
            ];
            foreach ($conflictSlots as $slotIndex => $slot) {
                [$startTime, $endTime] = $slot;
                Task::updateOrCreate(
                    [
                        'account_id' => $serviceOwner->id,
                        'due_date' => $conflictDateString,
                        'start_time' => $startTime,
                        'title' => 'Conflict test ' . $conflictDate->format('D') . ' #' . ($slotIndex + 1),
                    ],
                    [
                        'created_by_user_id' => $serviceOwner->id,
                        'assigned_team_member_id' => null,
                        'customer_id' => $calendarCustomerId,
                        'product_id' => $calendarProductId,
                        'description' => 'Seeded for schedule conflict testing.',
                        'status' => 'todo',
                        'end_time' => $endTime,
                        'completed_at' => null,
                    ]
                );
            }

            Task::updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'due_date' => $conflictDateString,
                    'start_time' => '12:00:00',
                    'title' => 'Open slot test ' . $conflictDate->format('D'),
                ],
                [
                    'created_by_user_id' => $serviceOwner->id,
                    'assigned_team_member_id' => null,
                    'customer_id' => $calendarCustomerId,
                    'product_id' => $calendarProductId,
                    'description' => 'Seeded for available slot testing.',
                    'status' => 'todo',
                    'end_time' => '13:00:00',
                    'completed_at' => null,
                ]
            );
        }

        ReservationSetting::query()->updateOrCreate(
            [
                'account_id' => $serviceOwner->id,
                'team_member_id' => null,
            ],
            [
                'business_preset' => 'salon',
                'buffer_minutes' => 15,
                'slot_interval_minutes' => 30,
                'min_notice_minutes' => 120,
                'max_advance_days' => 60,
                'cancellation_cutoff_hours' => 24,
                'late_release_minutes' => 10,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'global_pull',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => true,
                'deposit_required' => true,
                'deposit_amount' => 20,
                'no_show_fee_enabled' => true,
                'no_show_fee_amount' => 15,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
            ]
        );

        $reservationMembers = TeamMember::query()
            ->forAccount($serviceOwner->id)
            ->active()
            ->orderBy('id')
            ->get(['id']);
        $primaryReservationMember = $reservationMembers->get(0);
        $secondaryReservationMember = $reservationMembers->get(1) ?? $primaryReservationMember;

        $primaryReservationResource = null;
        $secondaryReservationResource = null;
        $sharedReservationResource = null;
        if ($primaryReservationMember) {
            $primaryReservationResource = ReservationResource::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'name' => 'Styling chair A',
                ],
                [
                    'team_member_id' => $primaryReservationMember->id,
                    'type' => 'chair',
                    'capacity' => 1,
                    'is_active' => true,
                ]
            );

            $sharedReservationResource = ReservationResource::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'name' => 'Wash station 1',
                ],
                [
                    'team_member_id' => null,
                    'type' => 'wash_station',
                    'capacity' => 2,
                    'is_active' => true,
                ]
            );
        }

        if ($secondaryReservationMember) {
            $secondaryReservationResource = ReservationResource::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'name' => 'Styling chair B',
                ],
                [
                    'team_member_id' => $secondaryReservationMember->id,
                    'type' => 'chair',
                    'capacity' => 1,
                    'is_active' => true,
                ]
            );
        }

        if ($reservationMembers->isNotEmpty()) {
            foreach ($reservationMembers->take(3) as $index => $member) {
                ReservationSetting::query()->updateOrCreate(
                    [
                        'account_id' => $serviceOwner->id,
                        'team_member_id' => $member->id,
                    ],
                    [
                        'buffer_minutes' => $index === 0 ? 10 : 15,
                        'slot_interval_minutes' => 30,
                        'min_notice_minutes' => 60,
                        'max_advance_days' => 45,
                        'cancellation_cutoff_hours' => 24,
                        'allow_client_cancel' => true,
                        'allow_client_reschedule' => true,
                    ]
                );

                foreach ([1, 2, 3, 4, 5] as $dayOfWeek) {
                    WeeklyAvailability::query()->updateOrCreate(
                        [
                            'account_id' => $serviceOwner->id,
                            'team_member_id' => $member->id,
                            'day_of_week' => $dayOfWeek,
                            'start_time' => '09:00:00',
                            'end_time' => '17:00:00',
                        ],
                        [
                            'is_active' => true,
                        ]
                    );
                }
            }

            $holidayDate = $now->copy()->addDays(10)->toDateString();
            AvailabilityException::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'team_member_id' => null,
                    'date' => $holidayDate,
                    'type' => AvailabilityException::TYPE_CLOSED,
                ],
                [
                    'start_time' => null,
                    'end_time' => null,
                    'reason' => 'Company holiday',
                ]
            );

            AvailabilityException::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'team_member_id' => $reservationMembers->first()->id,
                    'date' => $holidayDate,
                    'type' => AvailabilityException::TYPE_OPEN,
                ],
                [
                    'start_time' => '18:00:00',
                    'end_time' => '20:00:00',
                    'reason' => 'Evening special slots',
                ]
            );
        }

        $reservationService = $serviceProducts->first();
        if ($primaryReservationMember && $secondaryReservationMember && $reservationService) {
            $reservationTimezone = $serviceOwner->company_timezone ?: config('app.timezone', 'UTC');
            $pendingStart = $now->copy()->addDays(3)->setTime(14, 0);
            $confirmedStart = $now->copy()->addDays(4)->setTime(10, 0);
            $completedStart = $now->copy()->subDays(2)->setTime(11, 0);
            $cancelledStart = $now->copy()->addDays(6)->setTime(16, 0);

            $pendingReservation = Reservation::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'team_member_id' => $primaryReservationMember->id,
                    'starts_at' => $pendingStart->toDateTimeString(),
                ],
                [
                    'client_id' => $serviceCustomer->id,
                    'client_user_id' => $servicePortalUser->id,
                    'service_id' => $reservationService->id,
                    'created_by_user_id' => $servicePortalUser->id,
                    'status' => Reservation::STATUS_PENDING,
                    'source' => Reservation::SOURCE_CLIENT,
                    'timezone' => $reservationTimezone,
                    'ends_at' => $pendingStart->copy()->addMinutes(60),
                    'duration_minutes' => 60,
                    'buffer_minutes' => 10,
                    'client_notes' => 'Client booking seeded from launch seeder.',
                    'metadata' => [
                        'seed' => 'launch',
                        'party_size' => 1,
                    ],
                ]
            );

            $confirmedReservation = Reservation::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'team_member_id' => $secondaryReservationMember->id,
                    'starts_at' => $confirmedStart->toDateTimeString(),
                ],
                [
                    'client_id' => $serviceCustomerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $reservationService->id,
                    'created_by_user_id' => $serviceOwner->id,
                    'status' => Reservation::STATUS_CONFIRMED,
                    'source' => Reservation::SOURCE_STAFF,
                    'timezone' => $reservationTimezone,
                    'ends_at' => $confirmedStart->copy()->addMinutes(90),
                    'duration_minutes' => 90,
                    'buffer_minutes' => 15,
                    'internal_notes' => 'Confirmed staff booking seeded for calendar coverage.',
                    'metadata' => [
                        'seed' => 'launch',
                        'party_size' => 2,
                    ],
                ]
            );

            $completedReservation = Reservation::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'team_member_id' => $primaryReservationMember->id,
                    'starts_at' => $completedStart->toDateTimeString(),
                ],
                [
                    'client_id' => $serviceCustomer->id,
                    'client_user_id' => $servicePortalUser->id,
                    'service_id' => $reservationService->id,
                    'created_by_user_id' => $serviceOwner->id,
                    'status' => Reservation::STATUS_COMPLETED,
                    'source' => Reservation::SOURCE_STAFF,
                    'timezone' => $reservationTimezone,
                    'ends_at' => $completedStart->copy()->addMinutes(60),
                    'duration_minutes' => 60,
                    'buffer_minutes' => 10,
                    'internal_notes' => 'Completed reservation seeded for history.',
                    'metadata' => [
                        'seed' => 'launch',
                    ],
                ]
            );

            ReservationReview::query()->updateOrCreate(
                ['reservation_id' => $completedReservation->id],
                [
                    'account_id' => $serviceOwner->id,
                    'client_id' => $serviceCustomer->id,
                    'client_user_id' => $servicePortalUser->id,
                    'rating' => 5,
                    'feedback' => 'Great service and on-time arrival.',
                    'reviewed_at' => $completedStart->copy()->addHours(3),
                ]
            );

            Reservation::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'team_member_id' => $secondaryReservationMember->id,
                    'starts_at' => $cancelledStart->toDateTimeString(),
                ],
                [
                    'client_id' => $serviceCustomerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $reservationService->id,
                    'created_by_user_id' => $serviceOwner->id,
                    'cancelled_by_user_id' => $serviceOwner->id,
                    'status' => Reservation::STATUS_CANCELLED,
                    'source' => Reservation::SOURCE_STAFF,
                    'timezone' => $reservationTimezone,
                    'ends_at' => $cancelledStart->copy()->addMinutes(60),
                    'duration_minutes' => 60,
                    'buffer_minutes' => 15,
                    'cancelled_at' => $now->copy()->subDay(),
                    'cancel_reason' => 'Customer requested cancellation.',
                    'metadata' => [
                        'seed' => 'launch',
                    ],
                ]
            );

            if ($primaryReservationResource) {
                ReservationResourceAllocation::query()->updateOrCreate(
                    [
                        'account_id' => $serviceOwner->id,
                        'reservation_id' => $pendingReservation->id,
                        'reservation_resource_id' => $primaryReservationResource->id,
                    ],
                    [
                        'quantity' => 1,
                    ]
                );
            }

            if ($secondaryReservationResource) {
                ReservationResourceAllocation::query()->updateOrCreate(
                    [
                        'account_id' => $serviceOwner->id,
                        'reservation_id' => $confirmedReservation->id,
                        'reservation_resource_id' => $secondaryReservationResource->id,
                    ],
                    [
                        'quantity' => 1,
                    ]
                );
            }

            if ($sharedReservationResource) {
                ReservationResourceAllocation::query()->updateOrCreate(
                    [
                        'account_id' => $serviceOwner->id,
                        'reservation_id' => $confirmedReservation->id,
                        'reservation_resource_id' => $sharedReservationResource->id,
                    ],
                    [
                        'quantity' => 2,
                    ]
                );
            }

            ReservationWaitlist::query()->updateOrCreate(
                [
                    'account_id' => $serviceOwner->id,
                    'client_id' => $serviceCustomer->id,
                    'client_user_id' => $servicePortalUser->id,
                    'service_id' => $reservationService->id,
                    'requested_start_at' => $now->copy()->addDays(5)->startOfDay()->toDateTimeString(),
                ],
                [
                    'team_member_id' => $primaryReservationMember->id,
                    'status' => ReservationWaitlist::STATUS_PENDING,
                    'requested_end_at' => $now->copy()->addDays(7)->endOfDay()->toDateTimeString(),
                    'duration_minutes' => 60,
                    'party_size' => 1,
                    'notes' => 'Client asked to be notified if a morning slot opens.',
                    'resource_filters' => [
                        'types' => ['chair'],
                        'resource_ids' => [],
                    ],
                    'metadata' => [
                        'seed' => 'launch',
                    ],
                ]
            );
        }

        $this->seedIndustryReservationTenant(
            $salonOwner,
            'salon',
            'salon',
            'salon',
            [
                ['name' => 'Haircut premium', 'price' => 65],
                ['name' => 'Color treatment', 'price' => 120],
                ['name' => 'Keratin care', 'price' => 180],
            ],
            [
                ['key' => 'primary', 'name' => 'Salon chair A', 'type' => 'chair', 'capacity' => 1, 'team' => 'admin'],
                ['key' => 'secondary', 'name' => 'Salon chair B', 'type' => 'chair', 'capacity' => 1, 'team' => 'member'],
                ['key' => 'shared', 'name' => 'Wash station 1', 'type' => 'wash_station', 'capacity' => 2, 'team' => null],
            ],
            1,
            $now,
            $employeeRoleId,
            $clientRoleId
        );

        $this->seedIndustryReservationTenant(
            $restaurantOwner,
            'restaurant',
            'restaurant',
            'restaurant',
            [
                ['name' => 'Lunch reservation', 'price' => 110],
                ['name' => 'Dinner reservation', 'price' => 180],
                ['name' => 'Chef tasting menu', 'price' => 260],
            ],
            [
                ['key' => 'primary', 'name' => 'Table A1', 'type' => 'table', 'capacity' => 4, 'team' => null],
                ['key' => 'secondary', 'name' => 'Table B2', 'type' => 'table', 'capacity' => 6, 'team' => null],
                ['key' => 'shared', 'name' => 'Patio zone', 'type' => 'zone', 'capacity' => 20, 'team' => null],
            ],
            4,
            $now,
            $employeeRoleId,
            $clientRoleId
        );

        $serviceOwners = $owners->filter(fn($owner) => $owner->company_type === 'services');
        foreach ($serviceOwners as $owner) {
            $serviceProduct = $resolveServiceProduct($owner);

            Quote::query()
                ->where('user_id', $owner->id)
                ->each(fn($quote) => $ensureQuoteService($quote, $serviceProduct));

            Work::query()
                ->where('user_id', $owner->id)
                ->each(fn($work) => $ensureWorkService($work, $serviceProduct));

            Invoice::query()
                ->where('user_id', $owner->id)
                ->with('work')
                ->each(function ($invoice) use ($serviceProduct, $seedInvoiceItems) {
                    $seedInvoiceItems($invoice, $invoice->work, $serviceProduct);
                });
        }
    }

    private function seedIndustryReservationTenant(
        User $owner,
        string $slug,
        string $businessPreset,
        string $companySector,
        array $serviceSeeds,
        array $resourceSeeds,
        int $partySize,
        \Illuminate\Support\Carbon $now,
        int $employeeRoleId,
        int $clientRoleId
    ): void {
        $timezone = (string) ($owner->company_timezone ?: config('app.timezone', 'UTC'));
        $isRestaurant = $businessPreset === 'restaurant';

        $owner->update([
            'company_type' => 'services',
            'company_sector' => $companySector,
            'company_features' => array_replace([
                'quotes' => true,
                'requests' => true,
                'reservations' => true,
                'jobs' => true,
                'invoices' => true,
            ], (array) ($owner->company_features ?? [])),
        ]);

        $adminUser = User::updateOrCreate(
            ['email' => "{$slug}.admin@example.com"],
            [
                'name' => Str::title(str_replace('-', ' ', $slug)) . ' Admin',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );
        $memberUser = User::updateOrCreate(
            ['email' => "{$slug}.member@example.com"],
            [
                'name' => Str::title(str_replace('-', ' ', $slug)) . ' Member',
                'password' => Hash::make('password'),
                'role_id' => $employeeRoleId,
                'email_verified_at' => $now,
            ]
        );
        $portalUser = User::updateOrCreate(
            ['email' => "{$slug}.client.portal@example.com"],
            [
                'name' => Str::title(str_replace('-', ' ', $slug)) . ' Portal Client',
                'password' => Hash::make('password'),
                'role_id' => $clientRoleId,
                'email_verified_at' => $now,
            ]
        );

        $adminMember = TeamMember::updateOrCreate(
            ['account_id' => $owner->id, 'user_id' => $adminUser->id],
            [
                'role' => 'admin',
                'permissions' => [
                    'jobs.view',
                    'jobs.edit',
                    'tasks.view',
                    'tasks.create',
                    'tasks.edit',
                    'tasks.delete',
                    'reservations.view',
                    'reservations.queue',
                    'reservations.manage',
                ],
                'is_active' => true,
            ]
        );
        $memberMember = TeamMember::updateOrCreate(
            ['account_id' => $owner->id, 'user_id' => $memberUser->id],
            [
                'role' => 'member',
                'permissions' => [
                    'jobs.view',
                    'tasks.view',
                    'tasks.edit',
                    'reservations.view',
                    'reservations.queue',
                ],
                'is_active' => true,
            ]
        );

        $customerPrimary = Customer::updateOrCreate(
            ['user_id' => $owner->id, 'email' => "{$slug}.client.primary@example.com"],
            [
                'portal_user_id' => $portalUser->id,
                'first_name' => 'Alex',
                'last_name' => 'Primary',
                'company_name' => Str::title($slug) . ' Primary Client',
                'phone' => '+15145550201',
                'description' => '[seed] primary customer',
                'billing_same_as_physical' => true,
            ]
        );
        if ((int) ($customerPrimary->portal_user_id ?? 0) !== (int) $portalUser->id) {
            $customerPrimary->update(['portal_user_id' => $portalUser->id]);
        }

        $customerAlt = Customer::updateOrCreate(
            ['user_id' => $owner->id, 'email' => "{$slug}.client.alt@example.com"],
            [
                'portal_user_id' => null,
                'first_name' => 'Taylor',
                'last_name' => 'Alternate',
                'company_name' => Str::title($slug) . ' Alternate Client',
                'phone' => '+15145550202',
                'description' => '[seed] alternate customer',
                'billing_same_as_physical' => true,
            ]
        );

        Property::updateOrCreate(
            ['customer_id' => $customerPrimary->id, 'type' => 'physical', 'street1' => "100 {$slug} avenue"],
            [
                'is_default' => true,
                'city' => $owner->company_city ?: 'Montreal',
                'state' => $owner->company_province ?: 'QC',
                'zip' => 'H1A1A1',
                'country' => $owner->company_country ?: 'Canada',
            ]
        );
        Property::updateOrCreate(
            ['customer_id' => $customerAlt->id, 'type' => 'physical', 'street1' => "200 {$slug} boulevard"],
            [
                'is_default' => true,
                'city' => $owner->company_city ?: 'Montreal',
                'state' => $owner->company_province ?: 'QC',
                'zip' => 'H2B2B2',
                'country' => $owner->company_country ?: 'Canada',
            ]
        );

        $serviceCategory = ProductCategory::resolveForAccount($owner->id, $owner->id, 'Services');
        $services = collect($serviceSeeds)->map(function (array $seed) use ($owner, $serviceCategory) {
            return Product::updateOrCreate(
                ['user_id' => $owner->id, 'name' => (string) ($seed['name'] ?? 'Service')],
                [
                    'category_id' => $serviceCategory->id,
                    'price' => (float) ($seed['price'] ?? 100),
                    'stock' => 0,
                    'minimum_stock' => 0,
                    'item_type' => Product::ITEM_TYPE_SERVICE,
                    'is_active' => true,
                ]
            );
        })->values();

        $mainService = $services->get(0);
        $secondaryService = $services->get(1) ?: $mainService;
        $thirdService = $services->get(2) ?: $mainService;
        if (!$mainService) {
            return;
        }

        ReservationSetting::query()->updateOrCreate(
            ['account_id' => $owner->id, 'team_member_id' => null],
            [
                'business_preset' => $businessPreset,
                'buffer_minutes' => 15,
                'slot_interval_minutes' => 30,
                'min_notice_minutes' => 60,
                'max_advance_days' => 90,
                'cancellation_cutoff_hours' => 24,
                'late_release_minutes' => $isRestaurant ? 15 : 10,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'global_pull',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => !$isRestaurant,
                'deposit_required' => true,
                'deposit_amount' => $isRestaurant ? 40 : 25,
                'no_show_fee_enabled' => true,
                'no_show_fee_amount' => $isRestaurant ? 35 : 20,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
            ]
        );

        foreach ([$adminMember, $memberMember] as $memberIndex => $member) {
            ReservationSetting::query()->updateOrCreate(
                ['account_id' => $owner->id, 'team_member_id' => $member->id],
                [
                    'buffer_minutes' => $memberIndex === 0 ? 10 : 15,
                    'slot_interval_minutes' => 30,
                    'min_notice_minutes' => 30,
                    'max_advance_days' => 60,
                    'cancellation_cutoff_hours' => 24,
                    'allow_client_cancel' => true,
                    'allow_client_reschedule' => true,
                ]
            );
        }

        $availabilityDays = $isRestaurant ? [0, 1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5, 6];
        $availabilityStart = $isRestaurant ? '11:00:00' : '09:00:00';
        $availabilityEnd = $isRestaurant ? '22:00:00' : '19:00:00';
        foreach ([$adminMember, $memberMember] as $member) {
            foreach ($availabilityDays as $day) {
                WeeklyAvailability::query()->updateOrCreate(
                    [
                        'account_id' => $owner->id,
                        'team_member_id' => $member->id,
                        'day_of_week' => $day,
                        'start_time' => $availabilityStart,
                        'end_time' => $availabilityEnd,
                    ],
                    ['is_active' => true]
                );
            }
        }

        $exceptionDate = $now->copy()->addDays(10)->toDateString();
        AvailabilityException::query()->updateOrCreate(
            ['account_id' => $owner->id, 'team_member_id' => null, 'date' => $exceptionDate, 'type' => AvailabilityException::TYPE_CLOSED],
            ['start_time' => null, 'end_time' => null, 'reason' => '[seed] full day closed']
        );
        AvailabilityException::query()->updateOrCreate(
            ['account_id' => $owner->id, 'team_member_id' => $adminMember->id, 'date' => $exceptionDate, 'type' => AvailabilityException::TYPE_OPEN],
            ['start_time' => '18:00:00', 'end_time' => '20:00:00', 'reason' => '[seed] special opening']
        );

        $resourceMap = [];
        foreach ($resourceSeeds as $seed) {
            $teamRef = $seed['team'] ?? null;
            $teamMemberId = $teamRef === 'admin' ? $adminMember->id : ($teamRef === 'member' ? $memberMember->id : null);
            $resource = ReservationResource::query()->updateOrCreate(
                ['account_id' => $owner->id, 'name' => (string) ($seed['name'] ?? 'Resource')],
                [
                    'team_member_id' => $teamMemberId,
                    'type' => (string) ($seed['type'] ?? 'general'),
                    'capacity' => max(1, (int) ($seed['capacity'] ?? 1)),
                    'is_active' => true,
                ]
            );
            if (!empty($seed['key'])) {
                $resourceMap[(string) $seed['key']] = $resource;
            }
        }

        $policy = [
            'deposit_required' => true,
            'deposit_amount' => $isRestaurant ? 40 : 25,
            'no_show_fee_enabled' => true,
            'no_show_fee_amount' => $isRestaurant ? 35 : 20,
        ];

        $resourceTypeFilters = collect($resourceSeeds)
            ->map(fn(array $seed) => (string) ($seed['type'] ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $shortDuration = $isRestaurant ? 90 : 60;
        $longDuration = $isRestaurant ? 120 : 90;
        $pendingStart = $now->copy()->addDays(2)->setTime($isRestaurant ? 18 : 14, 0);
        $confirmedStart = $now->copy()->addDays(3)->setTime($isRestaurant ? 19 : 11, 30);
        $rescheduledFromStart = $now->copy()->addDays(4)->setTime($isRestaurant ? 17 : 10, 0);
        $rescheduledToStart = $now->copy()->addDays(5)->setTime($isRestaurant ? 20 : 13, 30);
        $completedStart = $now->copy()->subDays(2)->setTime($isRestaurant ? 20 : 15, 0);
        $noShowStart = $now->copy()->subDay()->setTime($isRestaurant ? 21 : 9, 30);
        $cancelledStart = $now->copy()->addDays(6)->setTime($isRestaurant ? 18 : 16, 30);

        $pendingReservation = Reservation::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => $adminMember->id,
                'starts_at' => $pendingStart->toDateTimeString(),
            ],
            [
                'client_id' => $customerPrimary->id,
                'client_user_id' => $portalUser->id,
                'service_id' => $mainService->id,
                'created_by_user_id' => $portalUser->id,
                'status' => Reservation::STATUS_PENDING,
                'source' => Reservation::SOURCE_CLIENT,
                'timezone' => $timezone,
                'ends_at' => $pendingStart->copy()->addMinutes($shortDuration)->toDateTimeString(),
                'duration_minutes' => $shortDuration,
                'buffer_minutes' => 10,
                'client_notes' => '[seed] pending appointment from client portal',
                'metadata' => [
                    'seed' => 'launch',
                    'tenant' => $slug,
                    'scenario' => 'pending',
                    'party_size' => $partySize,
                ],
            ]
        );

        $confirmedReservation = Reservation::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => $memberMember->id,
                'starts_at' => $confirmedStart->toDateTimeString(),
            ],
            [
                'client_id' => $customerAlt->id,
                'client_user_id' => null,
                'service_id' => $secondaryService->id,
                'created_by_user_id' => $owner->id,
                'status' => Reservation::STATUS_CONFIRMED,
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $timezone,
                'ends_at' => $confirmedStart->copy()->addMinutes($longDuration)->toDateTimeString(),
                'duration_minutes' => $longDuration,
                'buffer_minutes' => 15,
                'internal_notes' => '[seed] confirmed by staff',
                'metadata' => [
                    'seed' => 'launch',
                    'tenant' => $slug,
                    'scenario' => 'confirmed',
                    'party_size' => max($partySize, $isRestaurant ? 4 : 1),
                ],
            ]
        );

        $rescheduledFromReservation = Reservation::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => $adminMember->id,
                'starts_at' => $rescheduledFromStart->toDateTimeString(),
            ],
            [
                'client_id' => $customerPrimary->id,
                'client_user_id' => $portalUser->id,
                'service_id' => $secondaryService->id,
                'created_by_user_id' => $portalUser->id,
                'status' => Reservation::STATUS_RESCHEDULED,
                'source' => Reservation::SOURCE_CLIENT,
                'timezone' => $timezone,
                'ends_at' => $rescheduledFromStart->copy()->addMinutes($shortDuration)->toDateTimeString(),
                'duration_minutes' => $shortDuration,
                'buffer_minutes' => 10,
                'client_notes' => '[seed] original slot moved by client',
                'metadata' => [
                    'seed' => 'launch',
                    'tenant' => $slug,
                    'scenario' => 'rescheduled_from',
                    'rescheduled_to' => $rescheduledToStart->toIso8601String(),
                ],
            ]
        );

        $rescheduledToReservation = Reservation::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => $adminMember->id,
                'starts_at' => $rescheduledToStart->toDateTimeString(),
            ],
            [
                'client_id' => $customerPrimary->id,
                'client_user_id' => $portalUser->id,
                'service_id' => $thirdService->id,
                'created_by_user_id' => $owner->id,
                'rescheduled_from_id' => $rescheduledFromReservation->id,
                'status' => Reservation::STATUS_CONFIRMED,
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $timezone,
                'ends_at' => $rescheduledToStart->copy()->addMinutes($longDuration)->toDateTimeString(),
                'duration_minutes' => $longDuration,
                'buffer_minutes' => 15,
                'internal_notes' => '[seed] new slot after reschedule',
                'metadata' => [
                    'seed' => 'launch',
                    'tenant' => $slug,
                    'scenario' => 'rescheduled_to',
                    'from_reservation_id' => $rescheduledFromReservation->id,
                ],
            ]
        );

        $completedReservation = Reservation::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => $memberMember->id,
                'starts_at' => $completedStart->toDateTimeString(),
            ],
            [
                'client_id' => $customerPrimary->id,
                'client_user_id' => $portalUser->id,
                'service_id' => $mainService->id,
                'created_by_user_id' => $owner->id,
                'status' => Reservation::STATUS_COMPLETED,
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $timezone,
                'ends_at' => $completedStart->copy()->addMinutes($shortDuration)->toDateTimeString(),
                'duration_minutes' => $shortDuration,
                'buffer_minutes' => 10,
                'internal_notes' => '[seed] completed reservation for history and analytics',
                'metadata' => [
                    'seed' => 'launch',
                    'tenant' => $slug,
                    'scenario' => 'completed',
                    'policy' => $policy,
                ],
            ]
        );

        $noShowReservation = Reservation::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => $adminMember->id,
                'starts_at' => $noShowStart->toDateTimeString(),
            ],
            [
                'client_id' => $customerAlt->id,
                'client_user_id' => null,
                'service_id' => $mainService->id,
                'created_by_user_id' => $owner->id,
                'status' => Reservation::STATUS_NO_SHOW,
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $timezone,
                'ends_at' => $noShowStart->copy()->addMinutes($shortDuration)->toDateTimeString(),
                'duration_minutes' => $shortDuration,
                'buffer_minutes' => 10,
                'internal_notes' => '[seed] no-show case',
                'metadata' => [
                    'seed' => 'launch',
                    'tenant' => $slug,
                    'scenario' => 'no_show',
                    'policy' => $policy,
                ],
            ]
        );

        $cancelledReservation = Reservation::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => $memberMember->id,
                'starts_at' => $cancelledStart->toDateTimeString(),
            ],
            [
                'client_id' => $customerAlt->id,
                'client_user_id' => null,
                'service_id' => $secondaryService->id,
                'created_by_user_id' => $owner->id,
                'cancelled_by_user_id' => $owner->id,
                'status' => Reservation::STATUS_CANCELLED,
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $timezone,
                'ends_at' => $cancelledStart->copy()->addMinutes($shortDuration)->toDateTimeString(),
                'duration_minutes' => $shortDuration,
                'buffer_minutes' => 10,
                'cancelled_at' => $now->copy()->subHours(4),
                'cancel_reason' => '[seed] client requested cancellation',
                'metadata' => [
                    'seed' => 'launch',
                    'tenant' => $slug,
                    'scenario' => 'cancelled',
                ],
            ]
        );

        ReservationReview::query()->updateOrCreate(
            ['reservation_id' => $completedReservation->id],
            [
                'account_id' => $owner->id,
                'client_id' => $customerPrimary->id,
                'client_user_id' => $portalUser->id,
                'rating' => $isRestaurant ? 4 : 5,
                'feedback' => $isRestaurant
                    ? 'Excellent service and very smooth check-in.'
                    : 'Great stylist and clear communication.',
                'reviewed_at' => $completedStart->copy()->addHours(2),
            ]
        );

        $primaryResource = $resourceMap['primary'] ?? null;
        $secondaryResource = $resourceMap['secondary'] ?? null;
        $sharedResource = $resourceMap['shared'] ?? null;

        if (Schema::hasTable('reservation_resource_allocations')) {
            if ($primaryResource) {
                ReservationResourceAllocation::query()->updateOrCreate(
                    [
                        'account_id' => $owner->id,
                        'reservation_id' => $pendingReservation->id,
                        'reservation_resource_id' => $primaryResource->id,
                    ],
                    ['quantity' => 1]
                );
            }

            if ($secondaryResource) {
                ReservationResourceAllocation::query()->updateOrCreate(
                    [
                        'account_id' => $owner->id,
                        'reservation_id' => $confirmedReservation->id,
                        'reservation_resource_id' => $secondaryResource->id,
                    ],
                    ['quantity' => 1]
                );
            }

            if ($sharedResource) {
                $sharedCapacity = max(1, (int) ($sharedResource->capacity ?? 1));
                ReservationResourceAllocation::query()->updateOrCreate(
                    [
                        'account_id' => $owner->id,
                        'reservation_id' => $rescheduledToReservation->id,
                        'reservation_resource_id' => $sharedResource->id,
                    ],
                    ['quantity' => min(max(1, $partySize), $sharedCapacity)]
                );
            }
        }

        if (Schema::hasTable('reservation_waitlists')) {
            $waitlistBase = $now->copy()->addDays(7)->startOfDay();

            ReservationWaitlist::query()->updateOrCreate(
                [
                    'account_id' => $owner->id,
                    'client_id' => $customerPrimary->id,
                    'status' => ReservationWaitlist::STATUS_PENDING,
                    'requested_start_at' => $waitlistBase->copy()->addHours(9)->toDateTimeString(),
                ],
                [
                    'client_user_id' => $portalUser->id,
                    'service_id' => $mainService->id,
                    'team_member_id' => $adminMember->id,
                    'requested_end_at' => $waitlistBase->copy()->addHours(12)->toDateTimeString(),
                    'duration_minutes' => $shortDuration,
                    'party_size' => $partySize,
                    'notes' => '[seed] pending waitlist request',
                    'resource_filters' => ['types' => $resourceTypeFilters, 'resource_ids' => []],
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'waitlist_pending'],
                ]
            );

            ReservationWaitlist::query()->updateOrCreate(
                [
                    'account_id' => $owner->id,
                    'client_id' => $customerAlt->id,
                    'status' => ReservationWaitlist::STATUS_RELEASED,
                    'requested_start_at' => $waitlistBase->copy()->addDay()->addHours(13)->toDateTimeString(),
                ],
                [
                    'client_user_id' => null,
                    'service_id' => $secondaryService->id,
                    'team_member_id' => $memberMember->id,
                    'requested_end_at' => $waitlistBase->copy()->addDay()->addHours(16)->toDateTimeString(),
                    'duration_minutes' => $shortDuration,
                    'party_size' => $partySize,
                    'notes' => '[seed] released waitlist entry',
                    'resource_filters' => ['types' => $resourceTypeFilters, 'resource_ids' => []],
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'waitlist_released'],
                    'released_at' => $now->copy()->subHours(6),
                ]
            );

            ReservationWaitlist::query()->updateOrCreate(
                [
                    'account_id' => $owner->id,
                    'client_id' => $customerPrimary->id,
                    'status' => ReservationWaitlist::STATUS_BOOKED,
                    'requested_start_at' => $waitlistBase->copy()->addDays(2)->addHours(17)->toDateTimeString(),
                ],
                [
                    'client_user_id' => $portalUser->id,
                    'service_id' => $thirdService->id,
                    'team_member_id' => $adminMember->id,
                    'matched_reservation_id' => $rescheduledToReservation->id,
                    'requested_end_at' => $waitlistBase->copy()->addDays(2)->addHours(19)->toDateTimeString(),
                    'duration_minutes' => $longDuration,
                    'party_size' => $partySize,
                    'notes' => '[seed] waitlist booked into reservation',
                    'resource_filters' => ['types' => $resourceTypeFilters, 'resource_ids' => []],
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'waitlist_booked'],
                    'released_at' => $now->copy()->subDays(1),
                    'resolved_at' => $now->copy()->subHours(3),
                ]
            );

            ReservationWaitlist::query()->updateOrCreate(
                [
                    'account_id' => $owner->id,
                    'client_id' => $customerAlt->id,
                    'status' => ReservationWaitlist::STATUS_CANCELLED,
                    'requested_start_at' => $waitlistBase->copy()->addDays(3)->addHours(11)->toDateTimeString(),
                ],
                [
                    'client_user_id' => null,
                    'service_id' => $mainService->id,
                    'team_member_id' => $memberMember->id,
                    'requested_end_at' => $waitlistBase->copy()->addDays(3)->addHours(13)->toDateTimeString(),
                    'duration_minutes' => $shortDuration,
                    'party_size' => $partySize,
                    'notes' => '[seed] waitlist cancelled by client',
                    'resource_filters' => ['types' => $resourceTypeFilters, 'resource_ids' => []],
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'waitlist_cancelled'],
                    'cancelled_at' => $now->copy()->subHours(2),
                    'resolved_at' => $now->copy()->subHours(2),
                ]
            );

            ReservationWaitlist::query()->updateOrCreate(
                [
                    'account_id' => $owner->id,
                    'client_id' => $customerPrimary->id,
                    'status' => ReservationWaitlist::STATUS_EXPIRED,
                    'requested_start_at' => $waitlistBase->copy()->addDays(4)->addHours(8)->toDateTimeString(),
                ],
                [
                    'client_user_id' => $portalUser->id,
                    'service_id' => $secondaryService->id,
                    'team_member_id' => $adminMember->id,
                    'requested_end_at' => $waitlistBase->copy()->addDays(4)->addHours(10)->toDateTimeString(),
                    'duration_minutes' => $shortDuration,
                    'party_size' => $partySize,
                    'notes' => '[seed] waitlist expired without match',
                    'resource_filters' => ['types' => $resourceTypeFilters, 'resource_ids' => []],
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'waitlist_expired'],
                    'resolved_at' => $now->copy()->subHours(1),
                ]
            );
        }

        if (Schema::hasTable('reservation_queue_items')) {
            $queuePrefix = Str::upper(Str::substr($slug, 0, 3));
            $queueNow = $now->copy();

            $queueSeeds = [
                [
                    'queue_number' => "{$queuePrefix}-A001",
                    'reservation_id' => $pendingReservation->id,
                    'client_id' => $customerPrimary->id,
                    'client_user_id' => $portalUser->id,
                    'service_id' => $mainService->id,
                    'team_member_id' => $adminMember->id,
                    'created_by_user_id' => $portalUser->id,
                    'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                    'source' => Reservation::SOURCE_CLIENT,
                    'status' => ReservationQueueItem::STATUS_NOT_ARRIVED,
                    'priority' => 20,
                    'estimated_duration_minutes' => $shortDuration,
                    'position' => 4,
                    'eta_minutes' => 45,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_not_arrived'],
                ],
                [
                    'queue_number' => "{$queuePrefix}-A002",
                    'reservation_id' => $confirmedReservation->id,
                    'client_id' => $customerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $secondaryService->id,
                    'team_member_id' => $memberMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                    'source' => Reservation::SOURCE_STAFF,
                    'status' => ReservationQueueItem::STATUS_CHECKED_IN,
                    'priority' => 15,
                    'estimated_duration_minutes' => $longDuration,
                    'checked_in_at' => $queueNow->copy()->subMinutes(22)->toDateTimeString(),
                    'position' => 3,
                    'eta_minutes' => 30,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_checked_in'],
                    'check_in' => [
                        'channel' => 'staff',
                        'checked_in_at' => $queueNow->copy()->subMinutes(22)->toDateTimeString(),
                        'grace_deadline_at' => $queueNow->copy()->subMinutes(12)->toDateTimeString(),
                        'checked_in_by_user_id' => $owner->id,
                    ],
                ],
                [
                    'queue_number' => "{$queuePrefix}-A003",
                    'reservation_id' => $rescheduledToReservation->id,
                    'client_id' => $customerPrimary->id,
                    'client_user_id' => $portalUser->id,
                    'service_id' => $thirdService->id,
                    'team_member_id' => $adminMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                    'source' => Reservation::SOURCE_STAFF,
                    'status' => ReservationQueueItem::STATUS_PRE_CALLED,
                    'priority' => 18,
                    'estimated_duration_minutes' => $longDuration,
                    'checked_in_at' => $queueNow->copy()->subMinutes(18)->toDateTimeString(),
                    'pre_called_at' => $queueNow->copy()->subMinutes(7)->toDateTimeString(),
                    'position' => 2,
                    'eta_minutes' => 12,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_pre_called'],
                    'check_in' => [
                        'channel' => 'self',
                        'checked_in_at' => $queueNow->copy()->subMinutes(18)->toDateTimeString(),
                        'grace_deadline_at' => $queueNow->copy()->subMinutes(8)->toDateTimeString(),
                        'checked_in_by_user_id' => null,
                    ],
                ],
                [
                    'queue_number' => "{$queuePrefix}-A004",
                    'reservation_id' => $confirmedReservation->id,
                    'client_id' => $customerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $secondaryService->id,
                    'team_member_id' => $memberMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                    'source' => Reservation::SOURCE_STAFF,
                    'status' => ReservationQueueItem::STATUS_CALLED,
                    'priority' => 25,
                    'estimated_duration_minutes' => $longDuration,
                    'checked_in_at' => $queueNow->copy()->subMinutes(14)->toDateTimeString(),
                    'called_at' => $queueNow->copy()->subMinutes(4)->toDateTimeString(),
                    'call_expires_at' => $queueNow->copy()->addMinutes(1)->toDateTimeString(),
                    'position' => 1,
                    'eta_minutes' => 4,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_called'],
                    'check_in' => [
                        'channel' => 'staff',
                        'checked_in_at' => $queueNow->copy()->subMinutes(14)->toDateTimeString(),
                        'grace_deadline_at' => $queueNow->copy()->subMinutes(4)->toDateTimeString(),
                        'checked_in_by_user_id' => $owner->id,
                    ],
                ],
                [
                    'queue_number' => "{$queuePrefix}-T001",
                    'reservation_id' => null,
                    'client_id' => $customerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $mainService->id,
                    'team_member_id' => $memberMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_TICKET,
                    'source' => Reservation::SOURCE_CLIENT,
                    'status' => ReservationQueueItem::STATUS_SKIPPED,
                    'priority' => 5,
                    'estimated_duration_minutes' => $shortDuration,
                    'checked_in_at' => $queueNow->copy()->subMinutes(35)->toDateTimeString(),
                    'skipped_at' => $queueNow->copy()->subMinutes(20)->toDateTimeString(),
                    'position' => 5,
                    'eta_minutes' => 50,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_skipped_ticket'],
                    'check_in' => [
                        'channel' => 'kiosk',
                        'checked_in_at' => $queueNow->copy()->subMinutes(35)->toDateTimeString(),
                        'grace_deadline_at' => $queueNow->copy()->subMinutes(25)->toDateTimeString(),
                        'checked_in_by_user_id' => $owner->id,
                    ],
                ],
                [
                    'queue_number' => "{$queuePrefix}-T002",
                    'reservation_id' => null,
                    'client_id' => $customerPrimary->id,
                    'client_user_id' => $portalUser->id,
                    'service_id' => $thirdService->id,
                    'team_member_id' => $adminMember->id,
                    'created_by_user_id' => $portalUser->id,
                    'item_type' => ReservationQueueItem::TYPE_TICKET,
                    'source' => Reservation::SOURCE_CLIENT,
                    'status' => ReservationQueueItem::STATUS_IN_SERVICE,
                    'priority' => 10,
                    'estimated_duration_minutes' => $longDuration,
                    'checked_in_at' => $queueNow->copy()->subMinutes(16)->toDateTimeString(),
                    'started_at' => $queueNow->copy()->subMinutes(6)->toDateTimeString(),
                    'position' => 0,
                    'eta_minutes' => 0,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_ticket_in_service'],
                    'check_in' => [
                        'channel' => 'self',
                        'checked_in_at' => $queueNow->copy()->subMinutes(16)->toDateTimeString(),
                        'grace_deadline_at' => $queueNow->copy()->subMinutes(6)->toDateTimeString(),
                        'checked_in_by_user_id' => null,
                    ],
                ],
                [
                    'queue_number' => "{$queuePrefix}-A005",
                    'reservation_id' => $completedReservation->id,
                    'client_id' => $customerPrimary->id,
                    'client_user_id' => $portalUser->id,
                    'service_id' => $mainService->id,
                    'team_member_id' => $memberMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                    'source' => Reservation::SOURCE_STAFF,
                    'status' => ReservationQueueItem::STATUS_DONE,
                    'priority' => 30,
                    'estimated_duration_minutes' => $shortDuration,
                    'checked_in_at' => $completedStart->copy()->subMinutes(8)->toDateTimeString(),
                    'started_at' => $completedStart->copy()->toDateTimeString(),
                    'finished_at' => $completedStart->copy()->addMinutes($shortDuration)->toDateTimeString(),
                    'position' => null,
                    'eta_minutes' => null,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_done'],
                    'check_in' => [
                        'channel' => 'self',
                        'checked_in_at' => $completedStart->copy()->subMinutes(8)->toDateTimeString(),
                        'grace_deadline_at' => $completedStart->copy()->addMinutes(2)->toDateTimeString(),
                        'checked_in_by_user_id' => null,
                    ],
                ],
                [
                    'queue_number' => "{$queuePrefix}-A006",
                    'reservation_id' => $noShowReservation->id,
                    'client_id' => $customerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $mainService->id,
                    'team_member_id' => $adminMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                    'source' => Reservation::SOURCE_STAFF,
                    'status' => ReservationQueueItem::STATUS_NO_SHOW,
                    'priority' => 22,
                    'estimated_duration_minutes' => $shortDuration,
                    'call_expires_at' => $noShowStart->copy()->addMinutes(10)->toDateTimeString(),
                    'position' => null,
                    'eta_minutes' => null,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_no_show'],
                ],
                [
                    'queue_number' => "{$queuePrefix}-A007",
                    'reservation_id' => $cancelledReservation->id,
                    'client_id' => $customerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $secondaryService->id,
                    'team_member_id' => $memberMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                    'source' => Reservation::SOURCE_STAFF,
                    'status' => ReservationQueueItem::STATUS_CANCELLED,
                    'priority' => 18,
                    'estimated_duration_minutes' => $shortDuration,
                    'cancelled_at' => $now->copy()->subHours(4)->toDateTimeString(),
                    'position' => null,
                    'eta_minutes' => null,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_cancelled'],
                ],
                [
                    'queue_number' => "{$queuePrefix}-T003",
                    'reservation_id' => null,
                    'client_id' => $customerAlt->id,
                    'client_user_id' => null,
                    'service_id' => $thirdService->id,
                    'team_member_id' => $adminMember->id,
                    'created_by_user_id' => $owner->id,
                    'item_type' => ReservationQueueItem::TYPE_TICKET,
                    'source' => Reservation::SOURCE_CLIENT,
                    'status' => ReservationQueueItem::STATUS_LEFT,
                    'priority' => 3,
                    'estimated_duration_minutes' => $shortDuration,
                    'checked_in_at' => $queueNow->copy()->subMinutes(28)->toDateTimeString(),
                    'left_at' => $queueNow->copy()->subMinutes(15)->toDateTimeString(),
                    'position' => null,
                    'eta_minutes' => null,
                    'metadata' => ['seed' => 'launch', 'tenant' => $slug, 'scenario' => 'queue_ticket_left'],
                    'check_in' => [
                        'channel' => 'kiosk',
                        'checked_in_at' => $queueNow->copy()->subMinutes(28)->toDateTimeString(),
                        'grace_deadline_at' => $queueNow->copy()->subMinutes(18)->toDateTimeString(),
                        'checked_in_by_user_id' => $owner->id,
                    ],
                ],
            ];

            foreach ($queueSeeds as $seed) {
                $checkInSeed = $seed['check_in'] ?? null;
                unset($seed['check_in']);

                $queueItem = ReservationQueueItem::query()->updateOrCreate(
                    [
                        'account_id' => $owner->id,
                        'queue_number' => $seed['queue_number'],
                    ],
                    array_merge($seed, [
                        'account_id' => $owner->id,
                    ])
                );

                if ($checkInSeed && Schema::hasTable('reservation_check_ins')) {
                    ReservationCheckIn::query()->updateOrCreate(
                        [
                            'account_id' => $owner->id,
                            'reservation_queue_item_id' => $queueItem->id,
                            'channel' => (string) ($checkInSeed['channel'] ?? 'self'),
                        ],
                        [
                            'reservation_id' => $queueItem->reservation_id,
                            'client_user_id' => $queueItem->client_user_id,
                            'checked_in_by_user_id' => $checkInSeed['checked_in_by_user_id'] ?? null,
                            'checked_in_at' => $checkInSeed['checked_in_at'],
                            'grace_deadline_at' => $checkInSeed['grace_deadline_at'] ?? null,
                            'metadata' => [
                                'seed' => 'launch',
                                'tenant' => $slug,
                                'queue_number' => $queueItem->queue_number,
                            ],
                        ]
                    );
                }
            }
        }

        if (!Schema::hasTable('works') || !Schema::hasTable('invoices') || !Schema::hasTable('payments')) {
            return;
        }

        $paidTotal = $isRestaurant ? 260.00 : 180.00;
        $partialTotal = $isRestaurant ? 320.00 : 220.00;
        $reversedTotal = $isRestaurant ? 180.00 : 140.00;

        $paidWork = Work::query()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'customer_id' => $customerPrimary->id,
                'job_title' => Str::title($slug) . ' Paid invoice scenario',
            ],
            [
                'instructions' => '[seed] completed work with full payment and tip',
                'start_date' => $completedStart->toDateString(),
                'status' => Work::STATUS_CLOSED,
                'subtotal' => $paidTotal,
                'total' => $paidTotal,
            ]
        );

        $partialWork = Work::query()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'customer_id' => $customerAlt->id,
                'job_title' => Str::title($slug) . ' Partial invoice scenario',
            ],
            [
                'instructions' => '[seed] completed work with partial payment and percent tip',
                'start_date' => $now->copy()->subDay()->toDateString(),
                'status' => Work::STATUS_VALIDATED,
                'subtotal' => $partialTotal,
                'total' => $partialTotal,
            ]
        );

        $reversedWork = Work::query()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'customer_id' => $customerAlt->id,
                'job_title' => Str::title($slug) . ' Reversed payment scenario',
            ],
            [
                'instructions' => '[seed] payment reversal and tip reversal coverage',
                'start_date' => $now->copy()->subDays(2)->toDateString(),
                'status' => Work::STATUS_DISPUTE,
                'subtotal' => $reversedTotal,
                'total' => $reversedTotal,
            ]
        );

        $paidInvoice = Invoice::query()->updateOrCreate(
            ['work_id' => $paidWork->id],
            [
                'user_id' => $owner->id,
                'customer_id' => $customerPrimary->id,
                'status' => 'sent',
                'total' => $paidTotal,
            ]
        );

        $partialInvoice = Invoice::query()->updateOrCreate(
            ['work_id' => $partialWork->id],
            [
                'user_id' => $owner->id,
                'customer_id' => $customerAlt->id,
                'status' => 'sent',
                'total' => $partialTotal,
            ]
        );

        $reversedInvoice = Invoice::query()->updateOrCreate(
            ['work_id' => $reversedWork->id],
            [
                'user_id' => $owner->id,
                'customer_id' => $customerAlt->id,
                'status' => 'sent',
                'total' => $reversedTotal,
            ]
        );

        if (Schema::hasTable('invoice_items')) {
            InvoiceItem::query()->updateOrCreate(
                ['invoice_id' => $paidInvoice->id, 'title' => $mainService->name . ' (paid scenario)'],
                [
                    'work_id' => $paidWork->id,
                    'assigned_team_member_id' => $adminMember->id,
                    'description' => '[seed] invoice item for full payment',
                    'scheduled_date' => $paidWork->start_date,
                    'quantity' => 1,
                    'unit_price' => $paidTotal,
                    'total' => $paidTotal,
                    'meta' => ['seed' => 'launch', 'tenant' => $slug],
                ]
            );

            InvoiceItem::query()->updateOrCreate(
                ['invoice_id' => $partialInvoice->id, 'title' => $secondaryService->name . ' (partial scenario)'],
                [
                    'work_id' => $partialWork->id,
                    'assigned_team_member_id' => $memberMember->id,
                    'description' => '[seed] invoice item for partial payment',
                    'scheduled_date' => $partialWork->start_date,
                    'quantity' => 1,
                    'unit_price' => $partialTotal,
                    'total' => $partialTotal,
                    'meta' => ['seed' => 'launch', 'tenant' => $slug],
                ]
            );

            InvoiceItem::query()->updateOrCreate(
                ['invoice_id' => $reversedInvoice->id, 'title' => $thirdService->name . ' (reversal scenario)'],
                [
                    'work_id' => $reversedWork->id,
                    'assigned_team_member_id' => $adminMember->id,
                    'description' => '[seed] invoice item for reversal',
                    'scheduled_date' => $reversedWork->start_date,
                    'quantity' => 1,
                    'unit_price' => $reversedTotal,
                    'total' => $reversedTotal,
                    'meta' => ['seed' => 'launch', 'tenant' => $slug],
                ]
            );
        }

        $paymentTipColumns = [
            'tip_amount' => Schema::hasColumn('payments', 'tip_amount'),
            'tip_type' => Schema::hasColumn('payments', 'tip_type'),
            'tip_percent' => Schema::hasColumn('payments', 'tip_percent'),
            'tip_base_amount' => Schema::hasColumn('payments', 'tip_base_amount'),
            'charged_total' => Schema::hasColumn('payments', 'charged_total'),
            'tip_assignee_user_id' => Schema::hasColumn('payments', 'tip_assignee_user_id'),
            'tip_reversed_amount' => Schema::hasColumn('payments', 'tip_reversed_amount'),
            'tip_reversed_at' => Schema::hasColumn('payments', 'tip_reversed_at'),
            'tip_reversal_rule' => Schema::hasColumn('payments', 'tip_reversal_rule'),
            'tip_reversal_reason' => Schema::hasColumn('payments', 'tip_reversal_reason'),
        ];

        $withTipData = static function (array $values, array $tipData) use ($paymentTipColumns): array {
            foreach ($tipData as $column => $value) {
                if (($paymentTipColumns[$column] ?? false) === true) {
                    $values[$column] = $value;
                }
            }

            return $values;
        };

        $tipAllocationsEnabled = Schema::hasTable('payment_tip_allocations');
        $syncTipAllocations = static function (?Payment $payment) use ($tipAllocationsEnabled): void {
            if (!$tipAllocationsEnabled || !$payment) {
                return;
            }

            app(TipAllocationService::class)->syncForPayment($payment->fresh());
        };

        $fullTipAmount = $isRestaurant ? 32.00 : 18.00;
        $fullPayment = Payment::query()->updateOrCreate(
            ['invoice_id' => $paidInvoice->id, 'reference' => Str::upper($slug) . '-PAY-FULL'],
            $withTipData([
                'customer_id' => $customerPrimary->id,
                'user_id' => $owner->id,
                'amount' => $paidTotal,
                'method' => 'card',
                'status' => 'completed',
                'notes' => '[seed] full payment with fixed tip',
                'paid_at' => $now->copy()->subHours(20),
            ], [
                'tip_amount' => $fullTipAmount,
                'tip_type' => 'fixed',
                'tip_percent' => null,
                'tip_base_amount' => $paidTotal,
                'charged_total' => round($paidTotal + $fullTipAmount, 2),
                'tip_assignee_user_id' => $adminUser->id,
                'tip_reversed_amount' => 0,
                'tip_reversed_at' => null,
                'tip_reversal_rule' => null,
                'tip_reversal_reason' => null,
            ])
        );
        $syncTipAllocations($fullPayment);
        $paidInvoice->refreshPaymentStatus();

        $partialAmount = round($partialTotal * 0.45, 2);
        $partialTipPercent = 10.0;
        $partialTipAmount = round($partialAmount * ($partialTipPercent / 100), 2);
        $partialPayment = Payment::query()->updateOrCreate(
            ['invoice_id' => $partialInvoice->id, 'reference' => Str::upper($slug) . '-PAY-PARTIAL'],
            $withTipData([
                'customer_id' => $customerAlt->id,
                'user_id' => $owner->id,
                'amount' => $partialAmount,
                'method' => 'card',
                'status' => 'completed',
                'notes' => '[seed] partial payment with percent tip',
                'paid_at' => $now->copy()->subHours(10),
            ], [
                'tip_amount' => $partialTipAmount,
                'tip_type' => 'percent',
                'tip_percent' => $partialTipPercent,
                'tip_base_amount' => $partialAmount,
                'charged_total' => round($partialAmount + $partialTipAmount, 2),
                'tip_assignee_user_id' => $memberUser->id,
                'tip_reversed_amount' => 0,
                'tip_reversed_at' => null,
                'tip_reversal_rule' => null,
                'tip_reversal_reason' => null,
            ])
        );
        $syncTipAllocations($partialPayment);
        $partialInvoice->refreshPaymentStatus();

        $reversedAmount = round($reversedTotal * 0.4, 2);
        $reversedTipPercent = 12.0;
        $reversedTipAmount = round($reversedAmount * ($reversedTipPercent / 100), 2);
        $reversedTipPart = round($reversedTipAmount * 0.5, 2);
        $reversedPayment = Payment::query()->updateOrCreate(
            ['invoice_id' => $reversedInvoice->id, 'reference' => Str::upper($slug) . '-PAY-REVERSED'],
            $withTipData([
                'customer_id' => $customerAlt->id,
                'user_id' => $owner->id,
                'amount' => $reversedAmount,
                'method' => 'card',
                'status' => 'reversed',
                'notes' => '[seed] reversed payment with partial tip reversal',
                'paid_at' => $now->copy()->subHours(6),
            ], [
                'tip_amount' => $reversedTipAmount,
                'tip_type' => 'percent',
                'tip_percent' => $reversedTipPercent,
                'tip_base_amount' => $reversedAmount,
                'charged_total' => round($reversedAmount + $reversedTipAmount, 2),
                'tip_assignee_user_id' => $adminUser->id,
                'tip_reversed_amount' => $reversedTipPart,
                'tip_reversed_at' => $now->copy()->subHours(4),
                'tip_reversal_rule' => 'prorata',
                'tip_reversal_reason' => '[seed] partial reversal for dispute',
            ])
        );
        $syncTipAllocations($reversedPayment);
        $reversedInvoice->refreshPaymentStatus();
    }
}
