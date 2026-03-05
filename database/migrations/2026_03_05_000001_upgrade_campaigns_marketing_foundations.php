<?php

use App\Enums\CampaignOfferMode;
use App\Enums\CampaignType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('message_templates')) {
            Schema::create('message_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('channel', 20);
                $table->string('campaign_type', 40)->nullable();
                $table->string('language', 10)->nullable();
                $table->json('content');
                $table->boolean('is_default')->default(false);
                $table->json('tags')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'channel']);
                $table->index(['user_id', 'campaign_type', 'channel', 'language'], 'msg_tpl_user_type_channel_lang_idx');
                $table->index(['user_id', 'is_default']);
            });
        }

        if (!Schema::hasTable('marketing_settings')) {
            Schema::create('marketing_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->json('channels')->nullable();
                $table->json('consent')->nullable();
                $table->json('audience')->nullable();
                $table->json('templates')->nullable();
                $table->json('tracking')->nullable();
                $table->json('offers')->nullable();
                $table->timestamps();

                $table->unique('user_id');
            });
        }

        if (!Schema::hasTable('campaign_offers')) {
            Schema::create('campaign_offers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
                $table->string('offer_type', 20);
                $table->unsignedBigInteger('offer_id');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['campaign_id', 'offer_type', 'offer_id'], 'campaign_offers_unique_offer_per_campaign');
                $table->index(['offer_type', 'offer_id'], 'campaign_offers_lookup_idx');
                $table->index(['campaign_id', 'offer_type'], 'campaign_offers_campaign_type_idx');
            });
        }

        if (Schema::hasTable('audience_segments')) {
            Schema::table('audience_segments', function (Blueprint $table) {
                if (!Schema::hasColumn('audience_segments', 'description')) {
                    $table->string('description', 1024)->nullable()->after('name');
                }
                if (!Schema::hasColumn('audience_segments', 'tags')) {
                    $table->json('tags')->nullable()->after('exclusions');
                }
                if (!Schema::hasColumn('audience_segments', 'last_computed_at')) {
                    $table->timestamp('last_computed_at')->nullable()->after('tags');
                }
                if (!Schema::hasColumn('audience_segments', 'cached_count')) {
                    $table->unsignedInteger('cached_count')->nullable()->after('last_computed_at');
                }
            });
        }

        if (Schema::hasTable('campaigns')) {
            Schema::table('campaigns', function (Blueprint $table) {
                if (!Schema::hasColumn('campaigns', 'campaign_type')) {
                    $table->string('campaign_type', 40)->nullable()->after('name');
                }
                if (!Schema::hasColumn('campaigns', 'offer_mode')) {
                    $table->string('offer_mode', 20)
                        ->default(CampaignOfferMode::PRODUCTS->value)
                        ->after('campaign_type');
                }
                if (!Schema::hasColumn('campaigns', 'language_mode')) {
                    $table->string('language_mode', 20)->nullable()->after('offer_mode');
                }
            });

            DB::table('campaigns')
                ->whereNull('campaign_type')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $normalized = CampaignType::normalize((string) ($row->type ?? null));
                        DB::table('campaigns')
                            ->where('id', $row->id)
                            ->update([
                                'campaign_type' => $normalized?->value ?? CampaignType::PROMOTION->value,
                            ]);
                    }
                });
        }

        if (Schema::hasTable('campaign_channels')) {
            Schema::table('campaign_channels', function (Blueprint $table) {
                if (!Schema::hasColumn('campaign_channels', 'message_template_id')) {
                    $table->foreignId('message_template_id')
                        ->nullable()
                        ->after('campaign_id')
                        ->constrained('message_templates')
                        ->nullOnDelete();
                }
                if (!Schema::hasColumn('campaign_channels', 'content_override')) {
                    $table->json('content_override')->nullable()->after('body_template');
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'tags')) {
                    $table->json('tags')->nullable()->after('description');
                }
            });

            if (!$this->hasIndex('products', 'products_offer_search_idx')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->index(['user_id', 'item_type', 'is_active', 'id'], 'products_offer_search_idx');
                });
            }

            if (!$this->hasIndex('products', 'products_offer_category_idx')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->index(['user_id', 'item_type', 'category_id'], 'products_offer_category_idx');
                });
            }

            if (!$this->hasIndex('products', 'products_offer_created_idx')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->index(['user_id', 'item_type', 'created_at'], 'products_offer_created_idx');
                });
            }

            if (!$this->hasIndex('products', 'products_offer_price_idx')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->index(['user_id', 'item_type', 'price'], 'products_offer_price_idx');
                });
            }
        }

        $this->backfillCampaignOffers();
        $this->backfillCampaignOfferModes();
    }

    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if ($this->hasIndex('products', 'products_offer_search_idx')) {
                    $table->dropIndex('products_offer_search_idx');
                }
                if ($this->hasIndex('products', 'products_offer_category_idx')) {
                    $table->dropIndex('products_offer_category_idx');
                }
                if ($this->hasIndex('products', 'products_offer_created_idx')) {
                    $table->dropIndex('products_offer_created_idx');
                }
                if ($this->hasIndex('products', 'products_offer_price_idx')) {
                    $table->dropIndex('products_offer_price_idx');
                }

                if (Schema::hasColumn('products', 'tags')) {
                    $table->dropColumn('tags');
                }
            });
        }

        if (Schema::hasTable('campaign_channels')) {
            Schema::table('campaign_channels', function (Blueprint $table) {
                if (Schema::hasColumn('campaign_channels', 'message_template_id')) {
                    $table->dropConstrainedForeignId('message_template_id');
                }
                if (Schema::hasColumn('campaign_channels', 'content_override')) {
                    $table->dropColumn('content_override');
                }
            });
        }

        if (Schema::hasTable('campaigns')) {
            Schema::table('campaigns', function (Blueprint $table) {
                if (Schema::hasColumn('campaigns', 'campaign_type')) {
                    $table->dropColumn('campaign_type');
                }
                if (Schema::hasColumn('campaigns', 'offer_mode')) {
                    $table->dropColumn('offer_mode');
                }
                if (Schema::hasColumn('campaigns', 'language_mode')) {
                    $table->dropColumn('language_mode');
                }
            });
        }

        if (Schema::hasTable('audience_segments')) {
            Schema::table('audience_segments', function (Blueprint $table) {
                if (Schema::hasColumn('audience_segments', 'description')) {
                    $table->dropColumn('description');
                }
                if (Schema::hasColumn('audience_segments', 'tags')) {
                    $table->dropColumn('tags');
                }
                if (Schema::hasColumn('audience_segments', 'last_computed_at')) {
                    $table->dropColumn('last_computed_at');
                }
                if (Schema::hasColumn('audience_segments', 'cached_count')) {
                    $table->dropColumn('cached_count');
                }
            });
        }

        Schema::dropIfExists('campaign_offers');
        Schema::dropIfExists('marketing_settings');
        Schema::dropIfExists('message_templates');
    }

    private function backfillCampaignOffers(): void
    {
        if (!Schema::hasTable('campaign_product') || !Schema::hasTable('campaign_offers')) {
            return;
        }

        DB::table('campaign_product')
            ->join('products', 'products.id', '=', 'campaign_product.product_id')
            ->select([
                'campaign_product.id',
                'campaign_product.campaign_id',
                'campaign_product.product_id',
                'campaign_product.metadata',
                'campaign_product.created_at',
                'campaign_product.updated_at',
                'products.item_type',
            ])
            ->orderBy('campaign_product.id')
            ->chunk(250, function ($rows): void {
                $payload = [];
                foreach ($rows as $row) {
                    $type = strtolower((string) ($row->item_type ?? 'product'));
                    if (!in_array($type, ['product', 'service'], true)) {
                        $type = 'product';
                    }

                    $payload[] = [
                        'campaign_id' => (int) $row->campaign_id,
                        'offer_type' => $type,
                        'offer_id' => (int) $row->product_id,
                        'metadata' => $row->metadata,
                        'created_at' => $row->created_at ?: now(),
                        'updated_at' => $row->updated_at ?: now(),
                    ];
                }

                if ($payload !== []) {
                    DB::table('campaign_offers')->upsert(
                        $payload,
                        ['campaign_id', 'offer_type', 'offer_id'],
                        ['metadata', 'updated_at']
                    );
                }
            });
    }

    private function backfillCampaignOfferModes(): void
    {
        if (!Schema::hasTable('campaigns') || !Schema::hasTable('campaign_offers')) {
            return;
        }

        DB::table('campaigns')
            ->select(['id', 'offer_mode'])
            ->orderBy('id')
            ->chunkById(250, function ($campaigns): void {
                foreach ($campaigns as $campaign) {
                    $types = DB::table('campaign_offers')
                        ->where('campaign_id', $campaign->id)
                        ->distinct()
                        ->pluck('offer_type')
                        ->map(fn ($value) => strtolower((string) $value))
                        ->values()
                        ->all();

                    if ($types === []) {
                        continue;
                    }

                    $mode = CampaignOfferMode::PRODUCTS->value;
                    $hasProduct = in_array('product', $types, true);
                    $hasService = in_array('service', $types, true);

                    if ($hasProduct && $hasService) {
                        $mode = CampaignOfferMode::MIXED->value;
                    } elseif ($hasService) {
                        $mode = CampaignOfferMode::SERVICES->value;
                    }

                    DB::table('campaigns')
                        ->where('id', $campaign->id)
                        ->update([
                            'offer_mode' => $mode,
                        ]);
                }
            });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $result = DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();

            return (bool) $result;
        }

        if ($driver === 'pgsql') {
            $result = DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $index)
                ->exists();

            return (bool) $result;
        }

        if ($driver === 'sqlite') {
            $result = DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', $index)
                ->exists();

            return (bool) $result;
        }

        return false;
    }
};
