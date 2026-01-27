<?php

namespace App\Http\Controllers;

use App\Models\PlatformPage;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\BillingSubscriptionService;
use App\Services\PlatformPageContentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicPageController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $page = PlatformPage::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $service = app(PlatformPageContentService::class);
        $locales = $service->locales();
        $locale = in_array(app()->getLocale(), $locales, true) ? app()->getLocale() : $locales[0];
        $planKey = null;

        $user = $request->user();
        if ($user) {
            $accountOwner = null;
            if ($user->isClient()) {
                $customer = $user->customerProfile()->first();
                if ($customer) {
                    $accountOwner = User::query()->find($customer->user_id);
                }
            }
            if (!$accountOwner) {
                $ownerId = $user->accountOwnerId();
                if ($ownerId) {
                    $accountOwner = $ownerId === $user->id ? $user : User::query()->find($ownerId);
                }
            }

            if ($accountOwner) {
                $planModules = PlatformSetting::getValue('plan_modules', []);
                $planKey = app(BillingSubscriptionService::class)->resolvePlanKey($accountOwner, $planModules);
            }
        }

        return Inertia::render('Public/Page', [
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
            ],
            'content' => $service->resolveForLocale($page, $locale),
            'plan_key' => $planKey,
        ]);
    }
}
