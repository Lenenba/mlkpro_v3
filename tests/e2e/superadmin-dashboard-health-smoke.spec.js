import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

const unknownHealthMetrics = {
    companies_total: 0,
    companies_onboarded: 0,
    acquisition_series: [],
    onboarding_conversion: 0,
    onboarding_conversion_30d: 0,
    wau: 0,
    mau: 0,
    activation_rates: {},
    avg_days_to_first: {},
    services_total: 0,
    products_total: 0,
    cohorts: [],
    data_quality: {},
    usage_trends: [],
    site_traffic: {},
    site_traffic_series: [],
    at_risk_tenants: {
        tenants: [],
    },
    action_center: {},
    health: {
        failed_jobs_24h: null,
        failed_jobs_7d: null,
        failed_mail_jobs_24h: null,
        failed_mail_jobs_measurable: false,
        failed_stripe_jobs_24h: null,
        failed_stripe_jobs_measurable: false,
        pending_jobs: null,
        oldest_job_minutes: null,
        queue_backlog_measurable: false,
        queue_failed_jobs_measurable: false,
        storage_public_bytes: null,
    },
    alerts: {
        limit_warnings: {
            count: 0,
            tenants: [],
        },
        stripe_failures_24h: null,
        stripe_failures_measurable: false,
        smtp_failures_24h: null,
        smtp_failures_measurable: false,
        jobs_backlog: {
            pending: null,
            oldest_minutes: null,
            measurable: false,
        },
        storage: {
            used_bytes: null,
            total_bytes: null,
            used_percent: null,
            critical: false,
        },
    },
};

const decodeHtmlAttribute = (value) => value
    .replaceAll('&quot;', '"')
    .replaceAll('&#039;', "'")
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&amp;', '&');

const encodeHtmlAttribute = (value) => value
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

async function renderSuperAdminDashboard(page) {
    // Reuse the authenticated Inertia shell and replace only the page payload.
    // Authorization and backend metric shaping are covered by their Feature tests.
    await page.route(
        (url) => url.pathname === '/dashboard'
            && url.searchParams.get('_e2e') === 'superadmin-unknown-health',
        async (route) => {
            const sourceUrl = new URL(route.request().url());
            sourceUrl.search = '';

            const response = await route.fetch({ url: sourceUrl.toString() });
            let html = await response.text();
            const dataPageAttribute = html.match(/data-page="([^"]+)"/);

            if (!dataPageAttribute) {
                throw new Error('The authenticated Inertia shell does not expose a data-page attribute.');
            }

            const inertiaPage = JSON.parse(decodeHtmlAttribute(dataPageAttribute[1]));
            inertiaPage.component = 'SuperAdmin/Dashboard';
            inertiaPage.url = '/dashboard?_e2e=superadmin-unknown-health';
            inertiaPage.props = {
                ...inertiaPage.props,
                metrics: unknownHealthMetrics,
                recent_audits: [],
                audit_filters: {},
                audit_options: {
                    admins: [],
                    tenants: [],
                    actions: [],
                },
            };

            const encodedPage = encodeHtmlAttribute(JSON.stringify(inertiaPage));
            html = html.replace(dataPageAttribute[0], `data-page="${encodedPage}"`);

            await route.fulfill({
                response,
                body: html,
            });
        },
    );

    await page.goto('/dashboard?_e2e=superadmin-unknown-health');
}

test('superadmin health renders unavailable queue and failed-job metrics as unknown instead of zero', async ({ page }) => {
    const fixtures = loadFixtures();

    await loginAs(page, fixtures.serviceOwner);
    await renderSuperAdminDashboard(page);

    const unknownMetricTestIds = [
        'superadmin-platform-health',
        'superadmin-stripe-failures',
        'superadmin-smtp-failures',
        'superadmin-jobs-backlog',
        'superadmin-storage-alert',
        'superadmin-health-failed_jobs_24h',
        'superadmin-health-pending_jobs',
        'superadmin-health-email_failures_24h',
    ];

    for (const testId of unknownMetricTestIds) {
        const metric = page.getByTestId(testId);

        await expect(metric).toBeVisible();
        await expect(metric).toHaveAttribute('data-measurement-status', 'unknown');
        await expect(metric).not.toContainText(/\b0\b/);
    }

    await expect(page.getByTestId('superadmin-platform-health'))
        .toContainText(/Unknown|Inconnu|Desconocido/);
    await expect(page.getByTestId('superadmin-jobs-backlog'))
        .toContainText(/Measurement unavailable|Mesure indisponible|Medición no disponible/);
});
