import fs from 'node:fs';
import { expect, test } from '@playwright/test';
import { installLocalAppUi } from './helpers/local-app-ui.mjs';

const origin = 'http://reservation-ui.test';
const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
const asset = (source) => `/build/${manifest[source].file}`;
const translationAsset = (domain, locale = 'fr') => asset(`resources/js/i18n/modules/${locale}/${domain}.json`);
const forms = [
    { name: 'Client', id: 'customer', component: 'CustomerQuickForm' },
    { name: 'Produit', id: 'product', component: 'ProductQuickForm' },
    { name: 'Prestation', id: 'service', component: 'ServiceQuickForm' },
    { name: 'Devis', id: 'quote', component: 'QuoteQuickDialog' },
    { name: 'Demande', id: 'request', component: 'RequestQuickForm' },
];
const formAsset = (form) => asset(`resources/js/Components/QuickCreate/${form.component}.vue`);

const accountProps = {
    auth: {
        user: { id: 90001, name: 'Compte de test', email: 'browser@example.test' },
        account: {
            owner_id: 90001, is_owner: true, is_client: false,
            features: { products: true, services: true, requests: true, quotes: true, team_members: true },
            company: { name: 'Salon de test', type: 'services' },
        },
    },
    assistant: { enabled: false },
};

async function installFixture(page, { pathname, component, authenticated = false, locale: initialLocale = 'fr', failFirstForm = null }) {
    let locale = initialLocale;
    let failedForm = false;
    const props = () => ({
        locale, locales: ['fr', 'en', 'es'], errors: {}, flash: {},
        ...(authenticated ? accountProps : { auth: { user: null } }),
        ...(component === 'Dashboard' ? { stats: {} } : {}),
        ...(component === 'Auth/Login' ? { canResetPassword: true } : {}),
    });
    return installLocalAppUi(page, {
        origin, pathname, component,
        ziggyGroup: component.startsWith('Auth/') ? 'full' : (authenticated ? 'app' : 'public'),
        props,
        observations: { options: [] },
        intercept: async ({ route, request, url, ziggy, requests }) => {
            if (failFirstForm && !failedForm && url.pathname === formAsset(failFirstForm)) {
                failedForm = true;
                await route.fulfill({ status: 503, contentType: 'text/plain', body: 'Temporary test failure' });
                return true;
            }
            if (request.method() === 'POST' && url.pathname === `/${ziggy.routes['locale.update'].uri}`) {
                const data = request.postDataJSON();
                expect(['fr', 'en', 'es']).toContain(data.locale);
                locale = data.locale;
                // Supply the final Inertia response directly so a redirect cannot escape interception.
                await route.fulfill({ headers: { 'X-Inertia': 'true' }, json: {
                    component, props: props(), url: pathname, version: 'fixture',
                } });
                return true;
            }
            if (request.method() !== 'GET') {
                return false;
            }
            const optionPayloads = {
                'customer.options': { customers: [{ id: 90101, first_name: 'Client', last_name: 'Essai', properties: [] }] },
                'prospects.options': { prospects: [] },
                'product.options': { categories: [] },
                'service.options': { categories: [], material_products: [] },
                'planning.events': { events: [] },
            };
            const entry = Object.entries(optionPayloads).find(([name]) => url.pathname === `/${ziggy.routes[name].uri}`);
            if (!entry) {
                return false;
            }
            requests.options.push(entry[0]);
            await route.fulfill({ json: entry[1] });
            return true;
        },
    });
}

const openQuickForm = async (page, form) => {
    await page.locator('.quick-toggle:visible').first().click();
    await page.locator('.quick-menu-item').getByRole('button', { name: form.name, exact: true }).click();
    await expect(page.locator(`#hs-quick-create-${form.id}`)).toBeVisible();
};

for (const scenario of [
    ...forms.map((form) => ({ form, component: 'Dashboard', pathname: '/app/dashboard' })),
    { form: forms[4], component: 'Planning/Index', pathname: '/app/planning' },
]) {
    test(`${scenario.component}: ${scenario.form.name} loads on opening and retains the draft`, async ({ page }) => {
        const requests = await installFixture(page, { ...scenario, authenticated: true });
        await page.goto(scenario.pathname);
        await expect(page.locator('.quick-toggle:visible').first()).toBeVisible();
        await page.waitForLoadState('networkidle');
        expect(requests.assets.filter((file) => forms.some((form) => file === formAsset(form)))).toEqual([]);
        expect(requests.options).toEqual([]);

        await openQuickForm(page, scenario.form);
        const modal = page.locator(`#hs-quick-create-${scenario.form.id}`);
        const field = modal.locator('input[type="text"]:visible').first();
        await expect(field).toBeVisible();
        await field.fill('Brouillon conservé');
        expect(requests.assets).toContain(formAsset(scenario.form));
        const optionsAfterOpening = [...requests.options];

        await modal.getByRole('button', { name: 'Close', exact: true }).click();
        await expect(modal).toBeHidden();
        await openQuickForm(page, scenario.form);
        await expect(field).toHaveValue('Brouillon conservé');
        expect(requests.assets.filter((file) => file === formAsset(scenario.form))).toHaveLength(1);
        expect(requests.options).toEqual(optionsAfterOpening);
        expect(requests.pageErrors).toEqual([]);
        expect(requests.unexpected).toEqual([]);
    });
}

test('a failed form download can be retried without reloading the page', async ({ page }) => {
    const form = forms[1];
    const requests = await installFixture(page, {
        pathname: '/app/dashboard', component: 'Dashboard', authenticated: true, failFirstForm: form,
    });
    await page.goto('/app/dashboard');
    await openQuickForm(page, forms[0]);
    const customerModal = page.locator('#hs-quick-create-customer');
    const customerDraft = customerModal.locator('input[type="text"]:visible').first();
    await customerDraft.fill('Autre brouillon conservé');
    await customerModal.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(customerModal).toBeHidden();
    await openQuickForm(page, form);
    const modal = page.locator(`#hs-quick-create-${form.id}`);
    await expect(modal.getByRole('alert')).toContainText('Impossible de charger le formulaire');
    await modal.getByRole('button', { name: 'Réessayer', exact: true }).click();
    await expect(modal.locator('input[type="text"]:visible').first()).toBeVisible();
    await expect(modal.getByRole('alert')).toHaveCount(0);
    expect(requests.assets).toContain(formAsset(form));
    await modal.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(modal).toBeHidden();
    await openQuickForm(page, forms[0]);
    await expect(customerDraft).toHaveValue('Autre brouillon conservé');
    expect(requests.assets.filter((file) => file === asset('resources/js/app.js'))).toHaveLength(1);
    expect(requests.inertia).toEqual([]);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('Welcome switches FR → ES → EN with only its own translations and the public shell', async ({ page }) => {
    const requests = await installFixture(page, { pathname: '/', component: 'Welcome' });
    await page.goto('/');
    await expect(page.getByTestId('public-language-switcher-toggle')).toBeVisible();
    for (const locale of ['es', 'en']) {
        await page.getByTestId('public-language-switcher-toggle').click();
        await page.locator(`[role="option"][data-locale="${locale}"]`).click();
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await expect(page.locator('body')).not.toContainText(/(?:welcome|pricing|public_footer|account|language)\.[a-z_]/u);
        expect(requests.assets).toContain(translationAsset('site_shell', locale));
        expect(requests.assets).toContain(translationAsset('welcome', locale));
    }
    for (const domain of ['pricing', 'terms', 'privacy', 'refund', 'public_store', 'public_showcase']) {
        expect(requests.assets).not.toContain(translationAsset(domain));
    }
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

for (const locale of ['fr', 'en', 'es']) {
    test(`Login ${locale} renders without loading the complete Welcome catalog`, async ({ page }) => {
        const requests = await installFixture(page, { pathname: '/login', component: 'Auth/Login', locale });
        await page.goto('/login');
        await expect(page.locator('#email')).toBeVisible();
        await expect(page.locator('#password')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(/(?:welcome|auth_pages|account)\.[a-z_]/u);
        await expect(page.locator('meta[name="description"]')).not.toHaveAttribute('content', /welcome\./u);
        expect(requests.assets).toContain(translationAsset('site_shell', locale));
        expect(requests.assets).not.toContain(translationAsset('welcome', locale));
        expect(requests.pageErrors).toEqual([]);
        expect(requests.unexpected).toEqual([]);
    });
}
