<?php

use App\Enums\BillingPeriod;
use App\Enums\CurrencyCode;
use App\Support\Billing\DefaultPlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'currency_code')) {
                $table->string('currency_code', 3)->default(CurrencyCode::default()->value)->after('locale');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('price');
            }
        });

        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('subtotal');
            }
        });

        Schema::table('quote_products', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_products', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('price');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('total');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('unit_price');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('total');
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('price');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('amount');
            }
        });

        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('stripe_subscriptions', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('price_id');
            }
            if (! Schema::hasColumn('stripe_subscriptions', 'plan_code')) {
                $table->string('plan_code')->nullable()->after('currency_code');
            }
            if (! Schema::hasColumn('stripe_subscriptions', 'plan_price_id')) {
                $table->unsignedBigInteger('plan_price_id')->nullable()->after('plan_code');
            }
            if (! Schema::hasColumn('stripe_subscriptions', 'billing_period')) {
                $table->string('billing_period')->nullable()->after('plan_price_id');
            }
        });

        Schema::table('reservation_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_settings', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('deposit_amount');
            }
        });

        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('contact_only')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('plan_prices')) {
            Schema::create('plan_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->string('currency_code', 3);
                $table->string('billing_period');
                $table->decimal('amount', 12, 2);
                $table->string('stripe_price_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['plan_id', 'currency_code', 'billing_period'], 'plan_prices_unique_plan_currency_period');
                $table->index(['currency_code', 'billing_period', 'is_active'], 'plan_prices_currency_period_active_idx');
            });
        }

        $this->backfillTenantCurrencies();
        $this->backfillCatalogCurrencies();
        $this->backfillDocumentCurrencies();
        $this->seedPlanCatalog();
        $this->backfillSubscriptionPlanContext();
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');

        Schema::table('reservation_settings', function (Blueprint $table) {
            if (Schema::hasColumn('reservation_settings', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            $columns = ['billing_period', 'plan_price_id', 'plan_code', 'currency_code'];
            $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('stripe_subscriptions', $column)));
            if ($existing) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('quote_products', function (Blueprint $table) {
            if (Schema::hasColumn('quote_products', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('quotes', function (Blueprint $table) {
            if (Schema::hasColumn('quotes', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });
    }

    private function backfillTenantCurrencies(): void
    {
        DB::table('users')
            ->whereNull('currency_code')
            ->update(['currency_code' => CurrencyCode::default()->value]);
    }

    private function backfillCatalogCurrencies(): void
    {
        $this->backfillByOwnerCurrency('products', 'user_id');
    }

    private function backfillDocumentCurrencies(): void
    {
        $this->backfillByOwnerCurrency('quotes', 'user_id');
        $this->backfillFromParentCurrency('quote_products', 'quote_id', 'quotes');
        $this->backfillByOwnerCurrency('invoices', 'user_id');
        $this->backfillFromParentCurrency('invoice_items', 'invoice_id', 'invoices');
        $this->backfillByOwnerCurrency('sales', 'user_id');
        $this->backfillFromParentCurrency('sale_items', 'sale_id', 'sales');
        $this->backfillPayments();
        $this->backfillStripeSubscriptions();
        $this->backfillByOwnerCurrency('reservation_settings', 'account_id');
    }

    private function seedPlanCatalog(): void
    {
        $sortOrder = 0;

        foreach (DefaultPlanCatalog::definitions() as $definition) {
            $existingPlan = DB::table('plans')->where('code', $definition['code'])->first();
            $planId = $existingPlan?->id;

            if (! $planId) {
                $planId = DB::table('plans')->insertGetId([
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_active' => (bool) ($definition['is_active'] ?? true),
                    'contact_only' => (bool) ($definition['contact_only'] ?? false),
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('plans')
                    ->where('id', $planId)
                    ->update([
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'is_active' => (bool) ($definition['is_active'] ?? true),
                        'contact_only' => (bool) ($definition['contact_only'] ?? false),
                        'sort_order' => $sortOrder,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($definition['prices'] as $currencyCode => $price) {
                $existingPrice = DB::table('plan_prices')
                    ->where('plan_id', $planId)
                    ->where('currency_code', $currencyCode)
                    ->where('billing_period', BillingPeriod::MONTHLY->value)
                    ->first();

                if ($existingPrice) {
                    DB::table('plan_prices')
                        ->where('id', $existingPrice->id)
                        ->update([
                            'amount' => $price['amount'],
                            'stripe_price_id' => $price['stripe_price_id'],
                            'is_active' => true,
                            'updated_at' => now(),
                        ]);

                    continue;
                }

                DB::table('plan_prices')->insert([
                    'plan_id' => $planId,
                    'currency_code' => $currencyCode,
                    'billing_period' => BillingPeriod::MONTHLY->value,
                    'amount' => $price['amount'],
                    'stripe_price_id' => $price['stripe_price_id'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $sortOrder++;
        }
    }

    private function backfillSubscriptionPlanContext(): void
    {
        $priceMap = DB::table('plan_prices')
            ->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->select('plan_prices.id', 'plan_prices.stripe_price_id', 'plan_prices.currency_code', 'plan_prices.billing_period', 'plans.code as plan_code')
            ->whereNotNull('plan_prices.stripe_price_id')
            ->get()
            ->keyBy('stripe_price_id');

        DB::table('stripe_subscriptions')
            ->select('id', 'price_id')
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) use ($priceMap) {
                foreach ($subscriptions as $subscription) {
                    $planPrice = $priceMap->get($subscription->price_id);
                    if (! $planPrice) {
                        continue;
                    }

                    DB::table('stripe_subscriptions')
                        ->where('id', $subscription->id)
                        ->update([
                            'currency_code' => $planPrice->currency_code,
                            'plan_code' => $planPrice->plan_code,
                            'plan_price_id' => $planPrice->id,
                            'billing_period' => $planPrice->billing_period,
                        ]);
                }
            });
    }

    private function backfillByOwnerCurrency(string $table, string $ownerColumn): void
    {
        DB::table($table)
            ->select('id', $ownerColumn)
            ->whereNull('currency_code')
            ->orderBy('id')
            ->chunkById(200, function (Collection $rows) use ($table, $ownerColumn): void {
                $ownerIds = $rows
                    ->pluck($ownerColumn)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $currencies = DB::table('users')
                    ->whereIn('id', $ownerIds)
                    ->pluck('currency_code', 'id');

                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            'currency_code' => $currencies[$row->{$ownerColumn}] ?? CurrencyCode::default()->value,
                        ]);
                }
            });
    }

    private function backfillFromParentCurrency(string $table, string $parentColumn, string $parentTable): void
    {
        DB::table($table)
            ->select('id', $parentColumn)
            ->whereNull('currency_code')
            ->orderBy('id')
            ->chunkById(200, function (Collection $rows) use ($table, $parentColumn, $parentTable): void {
                $parentIds = $rows
                    ->pluck($parentColumn)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $currencies = DB::table($parentTable)
                    ->whereIn('id', $parentIds)
                    ->pluck('currency_code', 'id');

                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            'currency_code' => $currencies[$row->{$parentColumn}] ?? CurrencyCode::default()->value,
                        ]);
                }
            });
    }

    private function backfillPayments(): void
    {
        DB::table('payments')
            ->select('id', 'invoice_id', 'sale_id')
            ->whereNull('currency_code')
            ->orderBy('id')
            ->chunkById(200, function (Collection $rows): void {
                $invoiceCurrencies = DB::table('invoices')
                    ->whereIn('id', $rows->pluck('invoice_id')->filter()->unique()->values()->all())
                    ->pluck('currency_code', 'id');

                $saleCurrencies = DB::table('sales')
                    ->whereIn('id', $rows->pluck('sale_id')->filter()->unique()->values()->all())
                    ->pluck('currency_code', 'id');

                foreach ($rows as $row) {
                    DB::table('payments')
                        ->where('id', $row->id)
                        ->update([
                            'currency_code' => $invoiceCurrencies[$row->invoice_id]
                                ?? $saleCurrencies[$row->sale_id]
                                ?? CurrencyCode::default()->value,
                        ]);
                }
            });
    }

    private function backfillStripeSubscriptions(): void
    {
        DB::table('stripe_subscriptions')
            ->select('id', 'user_id', 'billing_period')
            ->where(function ($query): void {
                $query->whereNull('currency_code')
                    ->orWhereNull('billing_period');
            })
            ->orderBy('id')
            ->chunkById(200, function (Collection $rows): void {
                $ownerCurrencies = DB::table('users')
                    ->whereIn('id', $rows->pluck('user_id')->filter()->unique()->values()->all())
                    ->pluck('currency_code', 'id');

                foreach ($rows as $row) {
                    DB::table('stripe_subscriptions')
                        ->where('id', $row->id)
                        ->update([
                            'currency_code' => $ownerCurrencies[$row->user_id] ?? CurrencyCode::default()->value,
                            'billing_period' => $row->billing_period ?: BillingPeriod::MONTHLY->value,
                        ]);
                }
            });
    }
};
