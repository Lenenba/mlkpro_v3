<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mailing_lists')) {
            Schema::create('mailing_lists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('description', 1024)->nullable();
                $table->json('tags')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'name'], 'mailing_lists_user_name_unique');
                $table->index(['user_id', 'updated_at'], 'mailing_lists_user_updated_idx');
            });
        }

        if (!Schema::hasTable('mailing_list_customers')) {
            Schema::create('mailing_list_customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mailing_list_id')->constrained('mailing_lists')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('added_at')->nullable();
                $table->timestamps();

                $table->unique(['mailing_list_id', 'customer_id'], 'mailing_list_customers_unique');
                $table->index(['customer_id', 'mailing_list_id'], 'mailing_list_customers_customer_idx');
            });
        }

        if (!Schema::hasTable('vip_tiers')) {
            Schema::create('vip_tiers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('code', 40);
                $table->string('name', 120);
                $table->json('perks')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'code'], 'vip_tiers_user_code_unique');
                $table->index(['user_id', 'is_active'], 'vip_tiers_user_active_idx');
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (!Schema::hasColumn('customers', 'is_vip')) {
                    $table->boolean('is_vip')->default(false)->after('tags');
                }
                if (!Schema::hasColumn('customers', 'vip_tier_id')) {
                    $table->foreignId('vip_tier_id')
                        ->nullable()
                        ->after('is_vip')
                        ->constrained('vip_tiers')
                        ->nullOnDelete();
                }
                if (!Schema::hasColumn('customers', 'vip_tier_code')) {
                    $table->string('vip_tier_code', 40)->nullable()->after('vip_tier_id');
                }
                if (!Schema::hasColumn('customers', 'vip_since_at')) {
                    $table->timestamp('vip_since_at')->nullable()->after('vip_tier_code');
                }
            });

            Schema::table('customers', function (Blueprint $table) {
                $table->index(['user_id', 'is_vip'], 'customers_user_vip_idx');
                $table->index(['user_id', 'vip_tier_id'], 'customers_user_vip_tier_idx');
                $table->index(['user_id', 'vip_tier_code'], 'customers_user_vip_code_idx');
            });
        }

        if (Schema::hasTable('campaign_audiences')) {
            Schema::table('campaign_audiences', function (Blueprint $table) {
                if (!Schema::hasColumn('campaign_audiences', 'include_mailing_list_ids')) {
                    $table->json('include_mailing_list_ids')->nullable()->after('manual_customer_ids');
                }
                if (!Schema::hasColumn('campaign_audiences', 'exclude_mailing_list_ids')) {
                    $table->json('exclude_mailing_list_ids')->nullable()->after('include_mailing_list_ids');
                }
                if (!Schema::hasColumn('campaign_audiences', 'source_logic')) {
                    $table->string('source_logic', 20)->default('UNION')->after('exclude_mailing_list_ids');
                }
                if (!Schema::hasColumn('campaign_audiences', 'source_summary')) {
                    $table->json('source_summary')->nullable()->after('source_logic');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campaign_audiences')) {
            Schema::table('campaign_audiences', function (Blueprint $table) {
                if (Schema::hasColumn('campaign_audiences', 'source_summary')) {
                    $table->dropColumn('source_summary');
                }
                if (Schema::hasColumn('campaign_audiences', 'source_logic')) {
                    $table->dropColumn('source_logic');
                }
                if (Schema::hasColumn('campaign_audiences', 'exclude_mailing_list_ids')) {
                    $table->dropColumn('exclude_mailing_list_ids');
                }
                if (Schema::hasColumn('campaign_audiences', 'include_mailing_list_ids')) {
                    $table->dropColumn('include_mailing_list_ids');
                }
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if ($this->hasIndex('customers', 'customers_user_vip_idx')) {
                    $table->dropIndex('customers_user_vip_idx');
                }
                if ($this->hasIndex('customers', 'customers_user_vip_tier_idx')) {
                    $table->dropIndex('customers_user_vip_tier_idx');
                }
                if ($this->hasIndex('customers', 'customers_user_vip_code_idx')) {
                    $table->dropIndex('customers_user_vip_code_idx');
                }

                if (Schema::hasColumn('customers', 'vip_tier_id')) {
                    $table->dropConstrainedForeignId('vip_tier_id');
                }
                if (Schema::hasColumn('customers', 'vip_since_at')) {
                    $table->dropColumn('vip_since_at');
                }
                if (Schema::hasColumn('customers', 'vip_tier_code')) {
                    $table->dropColumn('vip_tier_code');
                }
                if (Schema::hasColumn('customers', 'is_vip')) {
                    $table->dropColumn('is_vip');
                }
            });
        }

        Schema::dropIfExists('vip_tiers');
        Schema::dropIfExists('mailing_list_customers');
        Schema::dropIfExists('mailing_lists');
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            return DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $index)
                ->exists();
        }

        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', $index)
                ->exists();
        }

        return false;
    }
};
