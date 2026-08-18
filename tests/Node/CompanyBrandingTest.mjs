import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import {
    resolveAccountCompanyBrand,
    resolveCompanyBrand,
    resolveCompanyBrandAccessibleLabel,
    resolveCompanyLogoUrl,
    resolveContextualCompany,
} from '../../resources/js/utils/companyBranding.js';

const source = (path) => readFileSync(resolve(path), 'utf8');
const occurrences = (value, needle) => value.split(needle).length - 1;

const sourceFiles = (directory) => readdirSync(resolve(directory), { withFileTypes: true })
    .flatMap((entry) => {
        const path = `${directory}/${entry.name}`;

        if (entry.isDirectory()) {
            return sourceFiles(path);
        }

        return /\.(?:js|vue)$/.test(entry.name) ? [path] : [];
    });

test('company branding prefers the explicit custom logo and respects the custom-logo flag', () => {
    assert.equal(resolveCompanyLogoUrl({
        custom_logo_url: ' /storage/company.svg ',
        logo_url: '/legacy.svg',
        has_custom_logo: true,
    }), '/storage/company.svg');

    assert.equal(resolveCompanyLogoUrl({
        logo_url: '/customers/customer.png',
        has_custom_logo: false,
    }), '');

    assert.equal(resolveCompanyLogoUrl({ logo_url: '/customers/customer.png?v=1' }), '');
    assert.equal(resolveCompanyLogoUrl({ logo_url: 'https://app.test/customers/customer.png' }), '');
    assert.equal(resolveCompanyLogoUrl({ logo_url: '/legacy-company.svg' }), '/legacy-company.svg');
    assert.equal(resolveCompanyLogoUrl({ custom_logo_url: '   ', has_custom_logo: true }), '');

    assert.equal(resolveCompanyBrandAccessibleLabel({ name: 'Atelier Boréal' }), 'Atelier Boréal');
    assert.equal(
        resolveCompanyBrandAccessibleLabel({ name: 'Atelier Boréal' }, { fallback: true }),
        'Atelier Boréal · Malikia Pro',
    );
    assert.equal(resolveCompanyBrandAccessibleLabel(null, { fallback: true }), 'Malikia Pro');
});

test('account branding keeps platform surfaces on Malikia but uses the tenant during impersonation', () => {
    const company = {
        name: ' Atelier Boréal ',
        custom_logo_url: '/storage/boreal.svg',
        has_custom_logo: true,
    };

    assert.deepEqual(resolveCompanyBrand(company), {
        name: 'Atelier Boréal',
        logoUrl: '/storage/boreal.svg',
    });
    assert.equal(resolveAccountCompanyBrand({ is_superadmin: true, company }), null);
    assert.equal(resolveAccountCompanyBrand({ is_platform_admin: true, company }), null);
    assert.equal(resolveContextualCompany({ is_superadmin: true }, company), null);
    assert.equal(resolveContextualCompany(null, company), company);
    assert.deepEqual(
        resolveAccountCompanyBrand(
            { is_platform_admin: true, company },
            { impersonating: true },
        ),
        { name: 'Atelier Boréal', logoUrl: '/storage/boreal.svg' },
    );
});

test('the reusable logo owns image failure, fallback, ratio, neutral container and optional link handling', () => {
    const component = source('resources/js/Components/CompanyBrandLogo.vue');

    assert.match(component, /@error="imageFailed = true"/);
    assert.match(component, /<ApplicationLogo/);
    assert.match(component, /v-if="showFallbackTenantName"/);
    assert.match(component, /\{\{ brand\.name \}\}/);
    assert.match(component, /object-contain/);
    assert.match(component, /company-brand-logo--custom/);
    assert.match(component, /linear-gradient/);
    assert.match(component, /drop-shadow/);
    assert.match(component, /:is="props\.href \? Link : 'div'"/);
    assert.match(component, /:alt="brand\.name \|\| 'Company'"/);
    assert.match(component, /:role="!props\.href && !showCompanyLogo \? 'img' : undefined"/);
    assert.match(component, /:aria-label="props\.href \|\| !showCompanyLogo \? accessibleLabel : undefined"/);
    assert.match(component, /v-else[\s\S]*?aria-hidden="true"/);
    assert.match(component, /color-scheme="light"/);
});

test('tenant public and contextual auth pages delegate their main logo to GuestLayout', () => {
    const publicPages = [
        'InvoicePay',
        'QuoteAction',
        'WorkAction',
        'WorkProofs',
    ];

    for (const page of publicPages) {
        const pageSource = source(`resources/js/Pages/Public/${page}.vue`);
        assert.match(pageSource, /<GuestLayout[\s\S]*?:company="company"/);
        assert.doesNotMatch(pageSource, /v-if="company\?\.logo_url"/);
    }

    const requestForm = source('resources/js/Pages/Public/RequestForm.vue');
    assert.match(requestForm, /company: props\.company/);
    assert.match(requestForm, /<CompanyBrandLogo[\s\S]*?v-if="isEmbedded"/);
    assert.match(requestForm, /account\.branding\.powered_by/);

    for (const page of ['VerifyEmail', 'ConfirmPassword', 'TwoFactorChallenge']) {
        const pageSource = source(`resources/js/Pages/Auth/${page}.vue`);
        assert.match(pageSource, /<GuestLayout :company="contextualCompany">/);
        assert.match(pageSource, /resolveContextualCompany/);
    }

    const guestLayout = source('resources/js/Layouts/GuestLayout.vue');
    assert.match(guestLayout, /<CompanyBrandLogo[\s\S]*?v-if="tenantCompany"/);
    assert.match(guestLayout, /<ApplicationLogo[\s\S]*?class="h-14 w-44 sm:h-16 sm:w-52"/);
    assert.match(guestLayout, /account\.branding\.powered_by/);
});

test('booking and AI chat keep one tenant brand while preserving tenant routing', () => {
    const booking = source('resources/js/Pages/Public/PublicBooking.vue');
    const assistantPage = source('resources/js/Pages/Public/AiAssistantChat.vue');
    const chatWidget = source('resources/js/Components/AiAssistant/PublicChatWidget.vue');

    for (const pageSource of [booking, assistantPage]) {
        assert.match(pageSource, /<GuestLayout :company="company" logo-href=""/);
        assert.doesNotMatch(pageSource, /v-if="company\.logo_url"/);
        assert.doesNotMatch(pageSource, /company-logo-url/);
    }

    assert.match(booking, /:company-slug="aiAssistant\.company_slug"/);
    assert.match(assistantPage, /:company-slug="company\.slug"/);
    assert.match(chatWidget, /company: props\.companySlug/);
    assert.doesNotMatch(chatWidget, /<img/);
    assert.match(chatWidget, /<Bot class="size-5" aria-hidden="true"/);
});

test('the sidebar switches to tenant branding without replacing the platform fallback', () => {
    const sidebar = source('resources/js/Layouts/UI/Sidebar.vue');
    const authenticatedLayout = source('resources/js/Layouts/AuthenticatedLayout.vue');

    assert.match(sidebar, /resolveAccountCompanyBrand/);
    assert.match(sidebar, /impersonating: isImpersonating\.value/);
    assert.match(sidebar, /<CompanyBrandLogo[\s\S]*?v-if="tenantBrand"/);
    assert.doesNotMatch(
        sidebar,
        /<CompanyBrandLogo[\s\S]{0,320}:show-fallback-name="false"/,
    );
    assert.match(sidebar, /<ApplicationLogo/);
    assert.match(authenticatedLayout, /v-if="impersonator"/);
    assert.match(authenticatedLayout, /superadmin\.impersonate\.stop/);
});

test('historical tenant surfaces use the shared logo component instead of direct logo reads', () => {
    const tenantSurfaces = [
        'resources/js/Pages/Public/Store.vue',
        'resources/js/Pages/Public/Showcase.vue',
        'resources/js/Pages/Public/ReservationKiosk.vue',
        'resources/js/Pages/Portal/InvoiceShow.vue',
        'resources/js/Pages/Portal/Products/Shop.vue',
        'resources/js/Pages/Portal/Products/OrderShow.vue',
        'resources/js/Pages/Invoice/Show.vue',
        'resources/js/Pages/Quote/Create.vue',
        'resources/js/Pages/Quote/Show.vue',
        'resources/js/Pages/Sales/Show.vue',
        'resources/js/Pages/Work/Create.vue',
        'resources/js/Pages/Work/Proofs.vue',
        'resources/js/Pages/Work/Show.vue',
    ];

    for (const path of tenantSurfaces) {
        const pageSource = source(path);

        assert.match(pageSource, /import CompanyBrandLogo from '@\/Components\/CompanyBrandLogo\.vue'/, path);
        assert.match(pageSource, /<CompanyBrandLogo/, path);
        assert.doesNotMatch(pageSource, /<img[\s\S]{0,240}(?:companyLogo|company(?:\?|\.)?\.logo_url)/, path);
        assert.doesNotMatch(pageSource, /object-cover[\s\S]{0,160}(?:companyLogo|company(?:\?|\.)?\.logo_url)/, path);
    }

    const forbiddenDirectReads = [
        'company_logo_url',
        'company.logo_url',
        'company?.logo_url',
        'companyLogoUrl',
        'company-logo-url',
        'account?.company?.logo_url',
    ];
    const violations = sourceFiles('resources/js')
        .filter((path) => path !== 'resources/js/utils/companyBranding.js')
        .flatMap((path) => {
            const pageSource = source(path);
            return forbiddenDirectReads
                .filter((needle) => pageSource.includes(needle))
                .map((needle) => `${path}: ${needle}`);
        });

    assert.deepEqual(violations, []);
});

test('public store, showcase and kiosk expose one tenant brand and one platform attribution', () => {
    const store = source('resources/js/Pages/Public/Store.vue');
    const showcase = source('resources/js/Pages/Public/Showcase.vue');
    const kiosk = source('resources/js/Pages/Public/ReservationKiosk.vue');

    assert.equal(occurrences(store, '<CompanyBrandLogo'), 1);
    assert.match(store, /route\('public\.store\.show', \{ slug: company\.slug \}, false\)/);
    assert.doesNotMatch(store, /<Link :href="route\('welcome'\)" class="flex items-center gap-3">/);
    assert.match(store, /class="flex min-w-0 max-w-32 flex-col sm:max-w-48"[\s\S]*?\{\{ companyName \}\}/);
    assert.match(store, /:alt="heroProduct\?\.name \|\| pageTitle"/);

    assert.equal(occurrences(showcase, '<CompanyBrandLogo'), 1);
    assert.match(showcase, /:alt="heroService\?\.name \|\| pageTitle"/);

    for (const [path, pageSource] of [
        ['resources/js/Pages/Public/Store.vue', store],
        ['resources/js/Pages/Public/Showcase.vue', showcase],
        ['resources/js/Pages/Public/ReservationKiosk.vue', kiosk],
    ]) {
        assert.equal(occurrences(pageSource, 'account.branding.powered_by'), 1, path);
    }
});

test('client portal attribution and metadata branding remain context-aware', () => {
    const authenticatedLayout = source('resources/js/Layouts/AuthenticatedLayout.vue');
    const seo = source('resources/js/Components/Seo/AppSeo.vue');
    const productTable = source('resources/js/Components/ProductTableList.vue');
    const portalShop = source('resources/js/Pages/Portal/Products/Shop.vue');

    assert.match(authenticatedLayout, /v-if="isClient"[\s\S]*?account\.branding\.powered_by/);
    assert.match(seo, /resolveCompanyLogoUrl/);
    assert.match(seo, /const companyLogo = computed\(\(\) => resolveCompanyLogoUrl\(page\.props\.company\)\)/);
    assert.doesNotMatch(productTable, /companyLogo/);
    assert.match(portalShop, /<CompanyBrandLogo[\s\S]*?\/>\s*\{\{ companyName \}\}/);
});
