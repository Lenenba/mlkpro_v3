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
import {
    COMPANY_BRAND_CSS_VARIABLES,
    applyCompanyBrandTheme,
    buildCompanyBrandPalette,
    contrastRatio,
    normalizeCompanyPrimaryColor,
    resolveCompanyPrimaryColor,
    resolvePageBrandCompany,
} from '../../resources/js/utils/companyBrandTheme.js';

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

test('company primary colors are canonical, injection safe and support the transitional nested payload', () => {
    assert.equal(normalizeCompanyPrimaryColor(' #7c3aed '), '#7C3AED');
    assert.equal(normalizeCompanyPrimaryColor('#ABCDEF'), '#ABCDEF');
    assert.equal(normalizeCompanyPrimaryColor('#abc'), '');
    assert.equal(normalizeCompanyPrimaryColor('red'), '');
    assert.equal(normalizeCompanyPrimaryColor('#123456; background: red'), '');
    assert.equal(normalizeCompanyPrimaryColor('url(https://example.test/color)'), '');
    assert.equal(normalizeCompanyPrimaryColor(null), '');

    assert.equal(resolveCompanyPrimaryColor({ primary_color: '#7c3aed' }), '#7C3AED');
    assert.equal(resolveCompanyPrimaryColor({
        primary_color: '#0f766e',
        branding_settings: { primary_color: '#f59e0b' },
    }), '#0F766E');
    assert.equal(resolveCompanyPrimaryColor({
        primary_color: 'invalid',
        branding_settings: { primary_color: '#f59e0b' },
    }), '#F59E0B');
    assert.equal(resolveCompanyPrimaryColor({ branding_settings: { primary_color: 'invalid' } }), '');
});

test('company color palettes keep controls, readable text and focus indicators accessible', () => {
    for (const primary of ['#FDE047', '#7C3AED', '#15803D', '#111827', '#F8FAFC']) {
        const palette = buildCompanyBrandPalette({ primary_color: primary });

        assert.equal(palette.primary, primary);
        assert.ok(contrastRatio(palette.primary, palette.foreground) >= 4.5, primary);
        assert.ok(contrastRatio(palette.hover, palette.foreground) >= 4.5, primary);
        assert.ok(contrastRatio(palette.focus, palette.foreground) >= 4.5, primary);
        assert.ok(contrastRatio(palette.softLight, palette.softForegroundLight) >= 4.5, primary);
        assert.ok(contrastRatio(palette.softDark, palette.softForegroundDark) >= 4.5, primary);
        assert.ok(contrastRatio(palette.readableLight, '#FFFFFF') >= 4.5, primary);
        assert.ok(contrastRatio(palette.readableDark, '#0F172A') >= 4.5, primary);
        assert.ok(contrastRatio(palette.lineLight, '#FFFFFF') >= 3, primary);
        assert.ok(contrastRatio(palette.lineDark, '#0F172A') >= 3, primary);
        assert.ok(contrastRatio(palette.checked, '#FFFFFF') >= 3, primary);
    }

    assert.equal(buildCompanyBrandPalette(null), null);
    assert.equal(buildCompanyBrandPalette({ primary_color: 'transparent' }), null);

    assert.deepEqual(
        {
            primary: buildCompanyBrandPalette({ primary_color: '#16A34A' }).primary,
            hover: buildCompanyBrandPalette({ primary_color: '#16A34A' }).hover,
            focus: buildCompanyBrandPalette({ primary_color: '#16A34A' }).focus,
            foreground: buildCompanyBrandPalette({ primary_color: '#16A34A' }).foreground,
        },
        {
            primary: '#16A34A',
            hover: '#32AE60',
            focus: '#49B772',
            foreground: '#111827',
        },
    );

    const serverPalette = buildCompanyBrandPalette({
        primary_color: '#7C3AED',
        primary_hover_color: '#6D28D9',
        primary_focus_color: '#5B21B6',
        primary_foreground_color: '#FFFFFF',
    });
    assert.equal(serverPalette.hover, '#6D28D9');
    assert.equal(serverPalette.focus, '#5B21B6');
    assert.equal(serverPalette.foreground, '#FFFFFF');
});

test('page brand resolution protects platform surfaces while honoring explicit public tenants', () => {
    const tenant = { name: 'Atelier Boréal', primary_color: '#7C3AED' };
    const platformAccount = {
        is_platform_admin: true,
        company: tenant,
    };

    assert.equal(resolvePageBrandCompany({
        component: 'SuperAdmin/Tenants/Show',
        props: { auth: { account: platformAccount }, company: tenant },
    }), null);
    assert.equal(resolvePageBrandCompany({
        component: 'Public/Store',
        props: { auth: { account: platformAccount }, company: tenant },
    }), tenant);
    assert.equal(resolvePageBrandCompany({
        component: 'DashboardOwner',
        props: { auth: { account: platformAccount, impersonator: { id: 1 } } },
    }), tenant);
    assert.equal(resolvePageBrandCompany({
        component: 'Portal/InvoicesIndex',
        props: { auth: { account: { company: tenant } } },
    }), tenant);
});

test('the document brand theme is replaced and fully cleared between tenant contexts', () => {
    const properties = new Map();
    const root = {
        dataset: {},
        style: {
            setProperty: (name, value) => properties.set(name, value),
            removeProperty: (name) => properties.delete(name),
        },
    };

    const palette = applyCompanyBrandTheme({
        component: 'Public/Showcase',
        props: { company: { primary_color: '#7C3AED' } },
    }, root);

    assert.equal(palette.primary, '#7C3AED');
    assert.equal(properties.get('--app-primary'), '#7C3AED');
    assert.equal(root.dataset.tenantBrandTheme, 'true');

    const replacement = applyCompanyBrandTheme({
        component: 'Public/Store',
        props: { company: { primary_color: '#F59E0B' } },
    }, root);

    assert.equal(replacement.primary, '#F59E0B');
    assert.equal(properties.get('--app-primary'), '#F59E0B');

    const cleared = applyCompanyBrandTheme({
        component: 'SuperAdmin/Dashboard',
        props: { auth: { account: { is_superadmin: true } } },
    }, root);

    assert.equal(cleared, null);
    assert.equal(root.dataset.tenantBrandTheme, undefined);
    assert.deepEqual([...properties.keys()], []);
    assert.equal(COMPANY_BRAND_CSS_VARIABLES.length, 13);
});

test('the Inertia lifecycle applies tenant colors before mount and after every navigation', () => {
    const app = source('resources/js/app.js');
    const css = source('resources/css/app.css');
    const theme = source('resources/js/utils/companyBrandTheme.js');

    assert.match(app, /applyCompanyBrandTheme\(props\.initialPage\)/);
    assert.match(app, /router\.on\('navigate',[\s\S]*?applyCompanyBrandTheme\(event\?\.detail\?\.page\)/);
    assert.ok(
        app.indexOf('applyCompanyBrandTheme(props.initialPage)')
            < app.indexOf('await createI18nInstance(initialLocale'),
    );
    assert.match(css, /--app-primary-line:\s*var\(--app-primary-line-light\)/);
    assert.match(css, /html\.dark[\s\S]*?--app-primary-line:\s*var\(--app-primary-line-dark\)/);
    assert.match(css, /--app-primary-soft:\s*var\(--app-primary-soft-light\)/);
    assert.match(css, /html\.dark[\s\S]*?--app-primary-soft:\s*var\(--app-primary-soft-dark\)/);
    assert.doesNotMatch(theme, /['"]--app-primary-line['"]/);
    assert.doesNotMatch(theme, /['"]--app-primary-soft['"]/);
    assert.doesNotMatch(theme, /['"]--app-primary-soft-foreground['"]/);
});

test('company settings submit the nested color contract and reset to the Malikia default', () => {
    const settings = source('resources/js/Pages/Settings/Company.vue');

    assert.match(settings, /company_branding_settings:\s*\{\s*primary_color:\s*savedCompanyPrimaryColor/);
    assert.match(settings, /company_branding_settings:\s*primaryColor \? \{ primary_color: primaryColor \} : null/);
    assert.match(settings, /data-testid="company-primary-color-picker"/);
    assert.match(settings, /data-testid="company-primary-color-hex"/);
    assert.match(settings, /data-testid="company-primary-color-preview"/);
    assert.match(settings, /form\.company_branding_settings\.primary_color = ''/);
    assert.match(settings, /\|\| DEFAULT_COMPANY_PRIMARY_COLOR/);

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(source(`resources/js/i18n/modules/${locale}/settings.json`));
        assert.ok(messages.settings.company.branding.primary_color_title, locale);
        assert.ok(messages.settings.company.branding.primary_color_reset, locale);
    }
});

test('shared controls and priority tenant surfaces consume brand tokens without replacing statuses', () => {
    const sharedControls = [
        'resources/js/Components/PrimaryButton.vue',
        'resources/js/Components/Checkbox.vue',
        'resources/js/Components/TextInput.vue',
        'resources/js/Components/FloatingInput.vue',
        'resources/js/Components/FloatingTextarea.vue',
        'resources/js/Components/FloatingSelect.vue',
        'resources/js/Components/DatePicker.vue',
        'resources/js/Components/DateTimePicker.vue',
        'resources/js/Components/SettingsTabs.vue',
        'resources/js/Components/Portal/ClientPortalTabs.vue',
    ];
    for (const file of sharedControls) {
        assert.match(source(file), /primary-(?:line|checked|foreground|hover|readable)|bg-primary\b/, file);
    }

    const primaryCtaPages = [
        'resources/js/Pages/Public/InvoicePay.vue',
        'resources/js/Pages/Public/QuoteAction.vue',
        'resources/js/Pages/Public/WorkAction.vue',
        'resources/js/Pages/Public/WorkProofs.vue',
        'resources/js/Pages/Public/RequestForm.vue',
        'resources/js/Pages/Public/PublicBooking.vue',
        'resources/js/Pages/Public/Store.vue',
        'resources/js/Pages/Public/ReservationKiosk.vue',
        'resources/js/Pages/Portal/InvoicesIndex.vue',
        'resources/js/Pages/Portal/Loyalty/Index.vue',
        'resources/js/Pages/Portal/Packages/Index.vue',
        'resources/js/Pages/Portal/Products/Shop.vue',
        'resources/js/Pages/Portal/Products/OrderShow.vue',
    ];
    for (const file of primaryCtaPages) {
        assert.match(source(file), /bg-primary\b/, file);
    }

    const kiosk = source('resources/js/Pages/Public/ReservationKiosk.vue');
    assert.doesNotMatch(kiosk, /#0f9a68|#0b865b/i);
    assert.match(kiosk, /walkInSuccess[\s\S]*?text-\[#0b7e55\]/);

    const invoices = source('resources/js/Pages/Portal/InvoicesIndex.vue');
    assert.match(invoices, /paid:\s*'bg-emerald-100 text-emerald-700/);
});

test('store and showcase prefer their explicit header accent then the tenant primary color', () => {
    const store = source('resources/js/Pages/Public/Store.vue');
    const showcase = source('resources/js/Pages/Public/Showcase.vue');
    const sections = source('resources/js/utils/publicCatalogSections.js');

    assert.match(store, /headerColor\.value \|\| company\.value\?\.primary_color \|\| DEFAULT_COMPANY_PRIMARY_COLOR/);
    assert.match(showcase, /store_settings\?\.header_color[\s\S]*?company\.value\?\.primary_color[\s\S]*?DEFAULT_COMPANY_PRIMARY_COLOR/);
    assert.match(sections, /primary_soft_color:\s*palette\.softLight/);
    assert.match(sections, /primary_contrast_color:\s*palette\.foreground/);
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
