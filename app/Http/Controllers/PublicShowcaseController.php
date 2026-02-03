<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\CompanyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PublicShowcaseController extends Controller
{
    private function resolveOwner(string $slug): User
    {
        return User::query()
            ->where('company_slug', $slug)
            ->where('company_type', 'services')
            ->where('is_suspended', false)
            ->firstOrFail();
    }

    public function show(Request $request, string $slug): Response
    {
        $owner = $this->resolveOwner($slug);

        $services = Product::query()
            ->services()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->with('category')
            ->orderByDesc('created_at')
            ->get();

        $servicesPayload = $services->map(function (Product $service) {
            return [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'price' => $service->price !== null ? (float) $service->price : null,
                'unit' => $service->unit,
                'image_url' => $service->image_url,
                'category_id' => $service->category_id,
                'category_name' => $service->category?->name,
                'created_at' => $service->created_at?->toIso8601String(),
            ];
        })->values();

        $categories = ProductCategory::forAccount($owner->id)
            ->active()
            ->whereHas('products', fn ($query) => $query
                ->byUser($owner->id)
                ->where('item_type', Product::ITEM_TYPE_SERVICE)
                ->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        $storeSettings = is_array($owner->company_store_settings) ? $owner->company_store_settings : [];
        $featuredId = $storeSettings['featured_product_id'] ?? null;
        $featuredService = $featuredId ? $services->firstWhere('id', (int) $featuredId) : null;
        $heroService = $featuredService ?: $services->first();

        $hasRequests = app(CompanyFeatureService::class)->hasFeature($owner, 'requests');
        $requestUrl = $hasRequests
            ? URL::signedRoute('public.requests.form', ['user' => $owner->id])
            : null;

        return Inertia::render('Public/Showcase', [
            'company' => [
                'name' => $owner->company_name ?: $owner->name,
                'slug' => $owner->company_slug,
                'logo_url' => $owner->company_logo_url,
                'description' => $owner->company_description,
                'city' => $owner->company_city,
                'province' => $owner->company_province,
                'country' => $owner->company_country,
                'phone' => $owner->phone_number,
                'email' => $owner->email,
                'store_settings' => $storeSettings,
            ],
            'services' => $servicesPayload,
            'categories' => $categories,
            'hero_service' => $heroService ? [
                'id' => $heroService->id,
                'name' => $heroService->name,
                'description' => $heroService->description,
                'price' => $heroService->price !== null ? (float) $heroService->price : null,
                'unit' => $heroService->unit,
                'image_url' => $heroService->image_url,
                'category_name' => $heroService->category?->name,
            ] : null,
            'request_url' => $requestUrl,
            'stats' => [
                'services' => $servicesPayload->count(),
                'categories' => $categories->count(),
            ],
        ]);
    }
}
