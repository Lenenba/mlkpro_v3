<?php

namespace App\Services;

use App\Models\PlanScan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;

class PlanScanService
{
    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    private const LIVE_LOOKUP_LIMIT = 3;
    private const LIVE_LOOKUP_BUDGET_SECONDS = 18;

    public function __construct(
        private PriceLookupService $priceLookupService,
        private SupplierDirectory $supplierDirectory
    ) {
    }

    public function tradeOptions(): array
    {
        return [
            ['id' => 'plumbing', 'name' => 'Plomberie'],
            ['id' => 'carpentry', 'name' => 'Menuiserie'],
            ['id' => 'electricity', 'name' => 'Electricite'],
            ['id' => 'painting', 'name' => 'Peinture'],
            ['id' => 'masonry', 'name' => 'Maconnerie'],
            ['id' => 'general', 'name' => 'General'],
        ];
    }

    public function priorityOptions(): array
    {
        return [
            ['id' => 'cost', 'name' => 'Cout bas'],
            ['id' => 'balanced', 'name' => 'Equilibre'],
            ['id' => 'quality', 'name' => 'Qualite'],
        ];
    }

    public function analyze(PlanScan $scan, array $metrics = [], ?string $priority = null): array
    {
        $normalized = $this->normalizeMetrics($metrics);
        $trade = $scan->trade_type ?: 'general';
        $catalog = $this->catalogForTrade($trade);
        $items = $this->buildItems($catalog, $normalized);
        $pricingContext = $this->buildPricingContext($scan);
        $itemsWithSources = $this->attachSources($items, $trade, $pricingContext);

        $variants = $this->buildVariants($itemsWithSources, $priority, $normalized, $pricingContext);
        $analysis = $this->buildAnalysisSummary($trade, $normalized, $itemsWithSources, $pricingContext);

        return [
            'metrics' => $normalized,
            'analysis' => $analysis,
            'variants' => $variants,
            'confidence_score' => $this->confidenceScore($scan, $normalized),
        ];
    }

    public function resolveOrCreateProduct(int $userId, string $itemType, array $line): Product
    {
        $name = trim((string) ($line['name'] ?? ''));
        $query = Product::byUser($userId)
            ->where('item_type', $itemType)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)]);

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        $category = $this->resolveCategory($itemType);

        return Product::create([
            'user_id' => $userId,
            'name' => $name ?: 'Plan scan line',
            'description' => $line['description'] ?? 'Auto-generated from plan scan.',
            'category_id' => $category->id,
            'price' => (float) ($line['unit_price'] ?? 0),
            'cost_price' => (float) ($line['unit_cost'] ?? 0),
            'margin_percent' => (float) ($line['margin_percent'] ?? 0),
            'unit' => $line['unit'] ?? null,
            'supplier_name' => 'PlanScan',
            'stock' => 0,
            'minimum_stock' => 0,
            'is_active' => true,
            'item_type' => $itemType,
        ]);
    }

    private function resolveCategory(string $itemType): ProductCategory
    {
        $name = $itemType === 'product' ? 'Products' : 'Services';

        return ProductCategory::firstOrCreate(['name' => $name]);
    }

    private function normalizeMetrics(array $metrics): array
    {
        $surface = isset($metrics['surface_m2']) ? (float) $metrics['surface_m2'] : 0.0;
        $rooms = isset($metrics['rooms']) ? (int) $metrics['rooms'] : 0;

        if ($surface <= 0 && $rooms > 0) {
            $surface = $rooms * 20;
        }

        if ($rooms <= 0 && $surface > 0) {
            $rooms = (int) ceil($surface / 20);
        }

        if ($rooms <= 0 && $surface <= 0) {
            $rooms = 4;
            $surface = 80;
        }

        $priority = $metrics['priority'] ?? 'balanced';

        return [
            'surface_m2' => round($surface, 2),
            'rooms' => $rooms,
            'priority' => $priority,
        ];
    }

    private function catalogForTrade(string $trade): array
    {
        $catalog = [
            'plumbing' => [
                ['name' => 'Installation point d eau', 'unit' => 'u', 'qty_per_room' => 1, 'base_cost' => 120],
                ['name' => 'Pose WC', 'unit' => 'u', 'qty_per_room' => 0.3, 'base_cost' => 250],
                ['name' => 'Raccordement evacuation', 'unit' => 'u', 'qty_per_room' => 0.6, 'base_cost' => 140],
                ['name' => 'Pose lavabo', 'unit' => 'u', 'qty_per_room' => 0.6, 'base_cost' => 180],
                ['name' => 'Tuyauterie', 'unit' => 'ml', 'qty_per_m2' => 0.6, 'base_cost' => 35],
                ['name' => 'Robinetterie', 'unit' => 'u', 'qty_per_room' => 0.6, 'base_cost' => 90],
                ['name' => 'Douche kit', 'unit' => 'u', 'qty' => 1, 'base_cost' => 320],
                ['name' => 'Baignoire', 'unit' => 'u', 'qty_per_room' => 0.2, 'base_cost' => 450],
                ['name' => 'Vannes arret', 'unit' => 'u', 'qty_per_room' => 1, 'base_cost' => 25],
                ['name' => 'Raccords PEX', 'unit' => 'set', 'qty_per_room' => 1, 'base_cost' => 65],
                ['name' => 'Isolation tuyaux', 'unit' => 'ml', 'qty_per_m2' => 0.2, 'base_cost' => 8],
            ],
            'electricity' => [
                ['name' => 'Prise electrique', 'unit' => 'u', 'qty_per_room' => 3, 'base_cost' => 45],
                ['name' => 'Interrupteur', 'unit' => 'u', 'qty_per_room' => 2, 'base_cost' => 18],
                ['name' => 'Point lumineux', 'unit' => 'u', 'qty_per_room' => 2, 'base_cost' => 55],
                ['name' => 'Tableau electrique', 'unit' => 'u', 'qty' => 1, 'base_cost' => 480],
                ['name' => 'Disjoncteur', 'unit' => 'u', 'qty_per_room' => 1, 'base_cost' => 22],
                ['name' => 'Mise a la terre', 'unit' => 'u', 'qty' => 1, 'base_cost' => 220],
                ['name' => 'Detecteur fumee', 'unit' => 'u', 'qty_per_room' => 1, 'base_cost' => 35],
                ['name' => 'Boite de jonction', 'unit' => 'u', 'qty_per_room' => 2, 'base_cost' => 8],
                ['name' => 'Gaine', 'unit' => 'ml', 'qty_per_m2' => 0.5, 'base_cost' => 6],
                ['name' => 'Cablage', 'unit' => 'ml', 'qty_per_m2' => 0.8, 'base_cost' => 12],
                ['name' => 'Coffret communication', 'unit' => 'u', 'qty' => 1, 'base_cost' => 180],
                ['name' => 'Prise data', 'unit' => 'u', 'qty_per_room' => 1, 'base_cost' => 28],
            ],
            'carpentry' => [
                ['name' => 'Pose porte', 'unit' => 'u', 'qty_per_room' => 1, 'base_cost' => 190],
                ['name' => 'Quincaillerie porte', 'unit' => 'set', 'qty_per_room' => 1, 'base_cost' => 45],
                ['name' => 'Pose fenetre', 'unit' => 'u', 'qty_per_room' => 1, 'base_cost' => 240],
                ['name' => 'Pose placard', 'unit' => 'u', 'qty' => 1, 'base_cost' => 380],
                ['name' => 'Plinthe', 'unit' => 'ml', 'qty_per_m2' => 0.7, 'base_cost' => 18],
                ['name' => 'Moulures', 'unit' => 'ml', 'qty_per_m2' => 0.4, 'base_cost' => 12],
                ['name' => 'Panneaux MDF', 'unit' => 'm2', 'qty_per_m2' => 0.5, 'base_cost' => 22],
                ['name' => 'Structure bois', 'unit' => 'm2', 'qty_per_m2' => 0.6, 'base_cost' => 28],
            ],
            'painting' => [
                ['name' => 'Preparation murs', 'unit' => 'm2', 'qty_per_m2' => 1, 'base_cost' => 6],
                ['name' => 'Enduit rebouchage', 'unit' => 'm2', 'qty_per_m2' => 0.3, 'base_cost' => 3],
                ['name' => 'Poncage', 'unit' => 'm2', 'qty_per_m2' => 0.4, 'base_cost' => 2],
                ['name' => 'Primaire', 'unit' => 'm2', 'qty_per_m2' => 1, 'base_cost' => 4],
                ['name' => 'Peinture murs', 'unit' => 'm2', 'qty_per_m2' => 1, 'base_cost' => 12],
                ['name' => 'Peinture plafond', 'unit' => 'm2', 'qty_per_m2' => 0.5, 'base_cost' => 14],
                ['name' => 'Peinture boiseries', 'unit' => 'm2', 'qty_per_m2' => 0.2, 'base_cost' => 9],
                ['name' => 'Bande masquage', 'unit' => 'ml', 'qty_per_m2' => 0.5, 'base_cost' => 1],
                ['name' => 'Protection sol', 'unit' => 'm2', 'qty_per_m2' => 0.6, 'base_cost' => 2],
            ],
            'masonry' => [
                ['name' => 'Demolition', 'unit' => 'm2', 'qty_per_m2' => 0.4, 'base_cost' => 18],
                ['name' => 'Cloisons', 'unit' => 'm2', 'qty_per_m2' => 0.5, 'base_cost' => 42],
                ['name' => 'Ragreage', 'unit' => 'm2', 'qty_per_m2' => 0.6, 'base_cost' => 22],
                ['name' => 'Blocs beton', 'unit' => 'u', 'qty_per_room' => 3, 'base_cost' => 12],
                ['name' => 'Ciment mortier', 'unit' => 'bag', 'qty_per_room' => 1, 'base_cost' => 14],
                ['name' => 'Ferraillage', 'unit' => 'kg', 'qty_per_room' => 3, 'base_cost' => 5],
                ['name' => 'Enduit facade', 'unit' => 'm2', 'qty_per_m2' => 0.3, 'base_cost' => 32],
                ['name' => 'Parement', 'unit' => 'm2', 'qty_per_m2' => 0.4, 'base_cost' => 45],
            ],
        ];

        $defaultCatalog = [
            ['name' => 'Preparation chantier', 'unit' => 'u', 'qty' => 1, 'base_cost' => 180, 'is_labor' => true],
            ['name' => 'Nettoyage chantier', 'unit' => 'u', 'qty' => 1, 'base_cost' => 120, 'is_labor' => true],
            ['name' => 'Main oeuvre', 'unit' => 'h', 'qty_per_room' => 6, 'base_cost' => 45, 'is_labor' => true],
        ];

        return $catalog[$trade] ?? $defaultCatalog;
    }

    private function buildItems(array $catalog, array $metrics): array
    {
        $surface = (float) ($metrics['surface_m2'] ?? 0);
        $rooms = (int) ($metrics['rooms'] ?? 0);

        return collect($catalog)->map(function (array $item) use ($surface, $rooms) {
            $quantity = (float) ($item['qty'] ?? 0);

            if (isset($item['qty_per_room'])) {
                $quantity += $rooms * (float) $item['qty_per_room'];
            }

            if (isset($item['qty_per_m2'])) {
                $quantity += $surface * (float) $item['qty_per_m2'];
            }

            $quantity = max(1, (float) ceil($quantity));

            return [
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? 'u',
                'quantity' => $quantity,
                'base_cost' => (float) ($item['base_cost'] ?? 0),
                'is_labor' => (bool) ($item['is_labor'] ?? false),
            ];
        })->values()->all();
    }

    private function attachSources(array $items, string $trade, array $pricingContext): array
    {
        $country = $pricingContext['country'] ?? config('suppliers.default_country', 'Canada');
        $province = $pricingContext['province'] ?? null;
        $city = $pricingContext['city'] ?? null;
        $enabledKeys = $pricingContext['enabled_keys'] ?? [];
        $tradeLabel = $this->tradeLabel($trade);
        $limit = $pricingContext['live_lookup_limit'] ?? self::LIVE_LOOKUP_LIMIT;
        $budgetSeconds = $pricingContext['live_lookup_budget'] ?? self::LIVE_LOOKUP_BUDGET_SECONDS;
        $lookupIndexes = $this->selectLookupIndexes($items, $limit);
        $startedAt = microtime(true);

        return collect($items)->map(function (array $item, int $index) use (
            $country,
            $province,
            $city,
            $enabledKeys,
            $tradeLabel,
            $lookupIndexes,
            $limit,
            $budgetSeconds,
            $startedAt
        ) {
            if (!empty($item['is_labor'])) {
                return [
                    ...$item,
                    'sources' => [],
                    'source_query' => null,
                    'source_status' => 'labor',
                ];
            }

            if ($limit <= 0 || !in_array($index, $lookupIndexes, true)) {
                return [
                    ...$item,
                    'sources' => [],
                    'source_query' => null,
                    'source_status' => 'skipped',
                ];
            }

            if ($budgetSeconds > 0 && (microtime(true) - $startedAt) >= $budgetSeconds) {
                return [
                    ...$item,
                    'sources' => [],
                    'source_query' => null,
                    'source_status' => 'skipped',
                ];
            }

            $query = $this->buildSearchQuery($item, $tradeLabel, $city, $province, $country);
            $sources = $this->priceLookupService->search($query, $enabledKeys, [
                'country' => $country,
                'province' => $province,
                'city' => $city,
            ]);
            $status = $sources ? 'live' : 'missing';

            return [
                ...$item,
                'sources' => $sources,
                'source_query' => $query,
                'source_status' => $status,
            ];
        })->values()->all();
    }

    private function buildVariants(array $items, ?string $priority = null, array $metrics = [], array $pricingContext = []): array
    {
        $variantConfig = [
            'eco' => ['label' => 'Eco', 'margin' => 0.12],
            'standard' => ['label' => 'Standard', 'margin' => 0.2],
            'premium' => ['label' => 'Premium', 'margin' => 0.32],
        ];

        $priority = $priority ?: 'balanced';
        $recommendedMap = [
            'cost' => 'eco',
            'quality' => 'premium',
            'balanced' => 'standard',
        ];
        $recommendedKey = $recommendedMap[$priority] ?? 'standard';

        $detailsMap = [
            'eco' => [
                'summary' => 'Lowest price with essential scope.',
                'best_for' => 'Budget-first projects',
                'materials' => 'Standard',
                'support' => 'Standard',
                'schedule_bias' => -1,
            ],
            'standard' => [
                'summary' => 'Balanced cost and quality for most projects.',
                'best_for' => 'Balanced needs',
                'materials' => 'Balanced',
                'support' => 'Priority',
                'schedule_bias' => 0,
            ],
            'premium' => [
                'summary' => 'High-end finish with priority scheduling.',
                'best_for' => 'Quality-first projects',
                'materials' => 'High-end',
                'support' => 'Premium',
                'schedule_bias' => 1,
            ],
        ];

        $baseDays = $this->estimateBaseDays($metrics, $items);

        $preferredSupplierKeys = $pricingContext['preferred_keys'] ?? [];
        $variants = [];
        foreach ($variantConfig as $key => $config) {
            $lines = [];
            $subtotal = 0.0;
            $referenceTotal = 0.0;
            $detail = $detailsMap[$key] ?? $detailsMap['standard'];

            foreach ($items as $item) {
                $sources = $item['sources'] ?? [];
                $hasSources = !empty($sources);
                $sortedSources = $this->sortSourcesByPrice($sources);
                $selected = $hasSources ? $this->selectSource($sortedSources, $key, $preferredSupplierKeys) : null;
                $selectedPrice = is_array($selected) ? ($selected['price'] ?? null) : null;
                $unitCost = (float) ($selectedPrice ?? $item['base_cost']);
                $unitPrice = round($unitCost * (1 + $config['margin']), 2);
                $lineTotal = round($unitPrice * $item['quantity'], 2);
                $subtotal += $lineTotal;

                $sourcePrices = collect($sortedSources)->pluck('price')->filter()->map(fn ($price) => (float) $price)->sort()->values();
                $minPrice = $sourcePrices->first() ?? $unitCost;
                $maxPrice = $sourcePrices->last() ?? $unitCost;
                $medianPrice = $sourcePrices->isNotEmpty()
                    ? $sourcePrices[(int) floor(($sourcePrices->count() - 1) / 2)]
                    : $unitCost;

                $selectionBasis = $hasSources
                    ? $this->resolveSelectionBasis($unitCost, $minPrice, $medianPrice, $maxPrice)
                    : 'internal';
                $bestSource = $sortedSources[0] ?? null;
                $preferredSources = array_values(array_filter($sources, function ($source) use ($preferredSupplierKeys) {
                    return in_array($source['supplier_key'] ?? null, $preferredSupplierKeys, true);
                }));
                $preferredSorted = $this->sortSourcesByPrice($preferredSources);
                $preferredBestSource = $preferredSorted[0] ?? null;
                $isPreferred = is_array($selected) && in_array($selected['supplier_key'] ?? null, $preferredSupplierKeys, true);
                $selectionReason = $hasSources
                    ? $this->buildSelectionReason($selectionBasis, $selected, $detail, $key, $bestSource, $isPreferred)
                    : (($item['source_status'] ?? null) === 'skipped'
                        ? 'Live pricing skipped to keep scan fast; using baseline cost.'
                        : 'No live price found; using baseline cost.');

                $medianSource = $sortedSources[1] ?? $selected;
                $medianPrice = is_array($medianSource) ? ($medianSource['price'] ?? null) : null;
                $referenceUnit = (float) ($medianPrice ?? $unitCost);
                $referenceTotal += round($referenceUnit * $item['quantity'], 2);

                $sourceStatus = $item['source_status'] ?? ($sources ? 'live' : ($item['is_labor'] ? 'labor' : 'missing'));

                $lines[] = [
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $unitCost,
                    'unit_price' => $unitPrice,
                    'margin_percent' => round($config['margin'] * 100, 2),
                    'total' => $lineTotal,
                    'sources' => $sources,
                    'source_query' => $item['source_query'] ?? null,
                    'selected_source' => $selected,
                    'selection_basis' => $selectionBasis,
                    'selection_reason' => $selectionReason,
                    'best_source' => $bestSource,
                    'preferred_source' => $preferredBestSource,
                    'preferred_suppliers' => $preferredSupplierKeys,
                    'source_status' => $sourceStatus,
                    'source_benchmarks' => [
                        'min' => round($minPrice, 2),
                        'median' => round($medianPrice, 2),
                        'max' => round($maxPrice, 2),
                        'preferred_min' => $preferredBestSource ? round((float) $preferredBestSource['price'], 2) : null,
                    ],
                ];
            }

            $subtotal = round($subtotal, 2);
            $referenceTotal = round($referenceTotal, 2);

            $leadDays = max(2, $baseDays + (int) ($detail['schedule_bias'] ?? 0));
            $leadRange = $leadDays . '-' . ($leadDays + 2);

            $variants[] = [
                'key' => $key,
                'label' => $config['label'],
                'margin_percent' => round($config['margin'] * 100, 2),
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'reference_total' => $referenceTotal,
                'savings_vs_reference' => round($referenceTotal - $subtotal, 2),
                'recommended' => $key === $recommendedKey,
                'details' => [
                    'summary' => $detail['summary'],
                    'best_for' => $detail['best_for'],
                    'lead_time_days' => $leadRange,
                    'materials' => $detail['materials'],
                    'support' => $detail['support'],
                ],
                'highlights' => [
                    'Best for: ' . $detail['best_for'],
                    'Lead time: ' . $leadRange . ' days',
                    'Materials: ' . $detail['materials'],
                ],
                'items' => $lines,
            ];
        }

        return $variants;
    }

    private function estimateBaseDays(array $metrics, array $items): int
    {
        $surface = (float) ($metrics['surface_m2'] ?? 0);
        $rooms = (int) ($metrics['rooms'] ?? 0);

        $surfaceScore = $surface > 0 ? $surface / 35 : 1;
        $roomScore = $rooms > 0 ? $rooms / 3 : 1;
        $itemScore = count($items) > 0 ? count($items) / 4 : 1;

        $base = (int) ceil($surfaceScore + $roomScore + $itemScore);

        return max(2, $base);
    }

    private function resolveSelectionBasis(float $selected, float $min, float $median, float $max): string
    {
        $epsilon = 0.01;
        if ($selected <= $min + $epsilon) {
            return 'lowest';
        }
        if ($selected >= $max - $epsilon) {
            return 'highest';
        }
        if (abs($selected - $median) <= $epsilon) {
            return 'median';
        }

        return 'balanced';
    }

    private function buildSelectionReason(
        string $basis,
        ?array $selected,
        array $detail,
        string $variantKey,
        ?array $bestSource = null,
        bool $isPreferred = false
    ): string
    {
        $supplier = is_array($selected) ? ($selected['name'] ?? 'Selected supplier') : 'Selected supplier';
        $focus = match ($variantKey) {
            'eco' => 'cost efficiency',
            'premium' => 'quality and priority scheduling',
            default => 'balanced value',
        };

        $basisLabel = match ($basis) {
            'lowest' => 'lowest',
            'highest' => 'highest',
            'median' => 'median',
            default => 'balanced',
        };

        $parts = [
            sprintf('Selected %s price from %s to optimize %s.', $basisLabel, $supplier, $focus),
        ];

        if ($isPreferred) {
            $parts[] = 'Preferred supplier selected.';
        }

        if ($bestSource && is_array($selected) && ($bestSource['supplier_key'] ?? null) !== ($selected['supplier_key'] ?? null)) {
            $delta = round((float) ($selected['price'] ?? 0) - (float) ($bestSource['price'] ?? 0), 2);
            if ($delta > 0) {
                $parts[] = 'Best price available at ' . ($bestSource['name'] ?? 'another supplier') . ' (-$' . number_format($delta, 2) . ').';
            }
        }

        return implode(' ', $parts);
    }

    private function buildAnalysisSummary(string $trade, array $metrics, array $items, array $pricingContext = []): array
    {
        $elements = collect($items)->pluck('name')->values()->all();
        $surface = $metrics['surface_m2'] ?? 0;
        $rooms = $metrics['rooms'] ?? 0;
        $suppliers = $pricingContext['suppliers'] ?? [];
        $enabledKeys = $pricingContext['enabled_keys'] ?? [];
        $preferredKeys = $pricingContext['preferred_keys'] ?? [];
        $provider = $pricingContext['provider'] ?? 'unknown';
        $providerReady = (bool) ($pricingContext['provider_ready'] ?? false);
        $missingSources = collect($items)->filter(function (array $item) {
            return ($item['source_status'] ?? null) === 'missing';
        })->count();
        $skippedSources = collect($items)->filter(function (array $item) {
            return ($item['source_status'] ?? null) === 'skipped';
        })->count();
        $lookupsAttempted = collect($items)->filter(function (array $item) {
            return in_array(($item['source_status'] ?? null), ['live', 'missing'], true);
        })->count();
        $lookupLimit = (int) ($pricingContext['live_lookup_limit'] ?? self::LIVE_LOOKUP_LIMIT);

        return [
            'trade' => $trade,
            'summary' => 'Estimation basee sur un plan et des metriques saisies.',
            'metrics' => [
                'surface_m2' => $surface,
                'rooms' => $rooms,
            ],
            'elements' => $elements,
            'assumptions' => [
                'Quantites deduites du nombre de pieces et de la surface.',
                'Prix compares sur plusieurs sources.',
                'Marges ajustees par niveau de devis.',
                $lookupLimit > 0
                    ? 'Live pricing limited to ' . $lookupLimit . ' items for faster scans.'
                    : 'Live pricing disabled for scans.',
                $providerReady
                    ? 'Sources live via ' . $provider . '.'
                    : 'Aucune source live configuree pour le moment.',
            ],
            'pricing' => [
                'provider' => $provider,
                'provider_ready' => $providerReady,
                'enabled_suppliers' => $this->resolveSupplierNames($suppliers, $enabledKeys),
                'preferred_suppliers' => $this->resolveSupplierNames($suppliers, $preferredKeys),
                'live_lookup_limit' => $lookupLimit,
                'lookups_attempted' => $lookupsAttempted,
                'missing_sources' => $missingSources,
                'skipped_sources' => $skippedSources,
            ],
        ];
    }

    private function confidenceScore(PlanScan $scan, array $metrics): int
    {
        $score = 50;

        if ($scan->plan_file_path) {
            $score += 20;
        }

        if (!empty($metrics['surface_m2'])) {
            $score += 15;
        }

        if (!empty($metrics['rooms'])) {
            $score += 10;
        }

        return (int) min($score, 95);
    }

    private function buildPricingContext(PlanScan $scan): array
    {
        $owner = User::query()->find($scan->user_id);
        $country = $owner?->company_country ?: config('suppliers.default_country', 'Canada');
        $suppliers = $this->supplierDirectory->all($country);
        $preferences = $this->resolveSupplierPreferences($owner?->company_supplier_preferences, $suppliers);

        return [
            'owner_id' => $owner?->id,
            'country' => $country,
            'province' => $owner?->company_province,
            'city' => $owner?->company_city,
            'suppliers' => $suppliers,
            'enabled_keys' => $preferences['enabled'],
            'preferred_keys' => $preferences['preferred'],
            'provider' => $this->priceLookupService->providerName(),
            'provider_ready' => $this->priceLookupService->isConfigured(),
            'live_lookup_limit' => self::LIVE_LOOKUP_LIMIT,
            'live_lookup_budget' => self::LIVE_LOOKUP_BUDGET_SECONDS,
        ];
    }

    private function selectLookupIndexes(array $items, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $candidates = [];
        foreach ($items as $index => $item) {
            if (!empty($item['is_labor'])) {
                continue;
            }
            $quantity = (float) ($item['quantity'] ?? 1);
            $base = (float) ($item['base_cost'] ?? 0);
            $score = $quantity * $base;
            $candidates[] = [
                'index' => $index,
                'score' => $score,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $selected = array_slice($candidates, 0, $limit);

        return array_values(array_map(fn ($item) => $item['index'], $selected));
    }

    private function resolveSupplierPreferences(?array $preferences, array $suppliers): array
    {
        $preferences = is_array($preferences) ? $preferences : [];
        $keys = collect($suppliers)->pluck('key')->filter()->values()->all();
        $defaultEnabled = collect($suppliers)
            ->filter(fn (array $supplier) => !empty($supplier['default_enabled']))
            ->pluck('key')
            ->values()
            ->all();
        $enabled = isset($preferences['enabled']) ? (array) $preferences['enabled'] : ($defaultEnabled ?: $keys);
        $enabled = array_values(array_intersect($keys, (array) $enabled));
        if (!$enabled) {
            $enabled = $keys;
        }

        $preferred = $preferences['preferred'] ?? array_slice($enabled, 0, 2);
        $preferred = array_values(array_intersect($enabled, (array) $preferred));
        $preferred = array_slice($preferred, 0, 2);

        return [
            'enabled' => $enabled,
            'preferred' => $preferred,
        ];
    }

    private function resolveSupplierNames(array $suppliers, array $keys): array
    {
        $map = collect($suppliers)->keyBy('key');

        return array_values(array_filter(array_map(function ($key) use ($map) {
            return $map[$key]['name'] ?? null;
        }, $keys)));
    }

    private function tradeLabel(string $trade): string
    {
        foreach ($this->tradeOptions() as $option) {
            if ($option['id'] === $trade) {
                return $option['name'];
            }
        }

        return $trade;
    }

    private function buildSearchQuery(array $item, string $tradeLabel, ?string $city, ?string $province, ?string $country): string
    {
        $parts = [
            $item['name'] ?? '',
            $tradeLabel ?: null,
            $city ?: null,
            $province ?: null,
            $country ?: null,
        ];

        $parts = array_values(array_filter($parts, fn ($value) => $value !== null && $value !== ''));

        return trim(implode(' ', $parts));
    }

    private function sortSourcesByPrice(array $sources): array
    {
        $filtered = array_values(array_filter($sources, function ($source) {
            return isset($source['price']) && is_numeric($source['price']);
        }));

        usort($filtered, fn ($a, $b) => (float) $a['price'] <=> (float) $b['price']);

        return $filtered;
    }

    private function selectSource(array $sources, string $variantKey, array $preferredSupplierKeys): ?array
    {
        if (!$sources) {
            return null;
        }

        if ($variantKey === 'eco') {
            return $sources[0] ?? null;
        }

        if ($variantKey === 'premium') {
            return $sources[array_key_last($sources)] ?? null;
        }

        if ($preferredSupplierKeys) {
            $preferredSources = array_values(array_filter($sources, function ($source) use ($preferredSupplierKeys) {
                return in_array($source['supplier_key'] ?? null, $preferredSupplierKeys, true);
            }));
            if ($preferredSources) {
                return $this->sortSourcesByPrice($preferredSources)[0] ?? null;
            }
        }

        $medianIndex = (int) floor((count($sources) - 1) / 2);

        return $sources[$medianIndex] ?? ($sources[0] ?? null);
    }
}
