<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PlatformAdmin;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformNotificationSetting;
use App\Models\PlatformSetting;
use App\Models\PlatformSupportTicket;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Property;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\QuoteRating;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\ServiceMaterial;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TaskMedia;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Models\WorkMedia;
use App\Models\WorkRating;
use App\Services\WorkBillingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LaunchSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

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
                'categories' => ['payment_failed', 'error_spike'],
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
                'invoices' => 10,
                'jobs' => 10,
                'products' => 25,
                'services' => 25,
                'tasks' => 25,
                'team_members' => 1,
            ],
            'starter' => [
                'quotes' => 100,
                'invoices' => 100,
                'jobs' => 100,
                'products' => 200,
                'services' => 200,
                'tasks' => 200,
                'team_members' => 5,
            ],
            'growth' => [
                'quotes' => 300,
                'invoices' => 300,
                'jobs' => 300,
                'products' => 500,
                'services' => 500,
                'tasks' => 600,
                'team_members' => 15,
            ],
            'scale' => [
                'quotes' => 1000,
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
                'company_type' => 'services',
                'onboarding_completed_at' => $now,
                'payment_methods' => ['cash', 'card'],
            ]
        );

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
                'company_type' => 'products',
                'onboarding_completed_at' => $now,
                'payment_methods' => ['cash', 'card'],
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
                ],
                'is_active' => true,
            ]
        );

        $serviceCategory = ProductCategory::firstOrCreate(['name' => 'Services']);
        $productCategory = ProductCategory::firstOrCreate(['name' => 'Products']);

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

        $productProducts = collect([
            ['name' => 'Safety gloves', 'price' => 15, 'stock' => 120],
            ['name' => 'Cleaning kit', 'price' => 85, 'stock' => 40],
            ['name' => 'Ladder set', 'price' => 350, 'stock' => 12],
        ])->map(function ($data) use ($productOwner, $productCategory) {
            return Product::updateOrCreate(
                [
                    'user_id' => $productOwner->id,
                    'name' => $data['name'],
                ],
                [
                    'category_id' => $productCategory->id,
                    'price' => $data['price'],
                    'stock' => $data['stock'],
                    'minimum_stock' => 5,
                    'item_type' => Product::ITEM_TYPE_PRODUCT,
                ]
            );
        });

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

        Payment::updateOrCreate(
            [
                'invoice_id' => $paidInvoice->id,
                'reference' => 'SEED-PAY-PAID-001',
            ],
            [
                'customer_id' => $serviceCustomerAlt->id,
                'user_id' => $serviceOwner->id,
                'amount' => $paidInvoice->total,
                'method' => 'card',
                'status' => 'completed',
                'notes' => 'Seeded full payment',
                'paid_at' => $now->copy()->subDays(7),
            ]
        );
        $paidInvoice->refreshPaymentStatus();

        $billingService = app(WorkBillingService::class);
        $invoice = $billingService->createInvoiceFromWork($work);

        $paymentAmount = round($invoice->total * 0.5, 2);
        Payment::updateOrCreate(
            [
                'invoice_id' => $invoice->id,
                'reference' => 'SEED-PAY-001',
            ],
            [
                'customer_id' => $serviceCustomer->id,
                'user_id' => $serviceOwner->id,
                'amount' => $paymentAmount,
                'method' => 'card',
                'status' => 'completed',
                'notes' => 'Seeded partial payment',
                'paid_at' => $now->copy()->subDays(1),
            ]
        );
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
                'due_date' => $now->copy()->addDay()->toDateString(),
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
    }
}
