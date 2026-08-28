import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import test from 'node:test';

import { getDomainsForPage } from '../../resources/js/i18n/domains.js';
import {
    collectManifestClosure,
    collectRouteProfile,
    compareBudgetPolicy,
    validateBudgetConfig,
    validateDecisionException,
} from '../../scripts/check-frontend-budgets.mjs';

const projectRoot = resolve('.');
const budgetConfig = JSON.parse(readFileSync(resolve(projectRoot, 'config/frontend-budgets.json'), 'utf8'));
const packageConfig = JSON.parse(readFileSync(resolve(projectRoot, 'package.json'), 'utf8'));
const productQuickForm = readFileSync(
    resolve(projectRoot, 'resources/js/Components/QuickCreate/ProductQuickForm.vue'),
    'utf8',
);
const serviceQuickForm = readFileSync(
    resolve(projectRoot, 'resources/js/Components/QuickCreate/ServiceQuickForm.vue'),
    'utf8',
);
const customerMediaFields = readFileSync(
    resolve(projectRoot, 'resources/js/Components/Customer/CustomerMediaFields.vue'),
    'utf8',
);
const customerQuickForm = readFileSync(
    resolve(projectRoot, 'resources/js/Components/QuickCreate/CustomerQuickForm.vue'),
    'utf8',
);
const quickCreateModals = readFileSync(
    resolve(projectRoot, 'resources/js/Components/QuickCreate/QuickCreateModals.vue'),
    'utf8',
);

const writeAsset = (directory, asset, contents) => {
    const path = join(directory, asset);
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, contents);

    return Buffer.byteLength(contents);
};

test('keeps a versioned, bounded profile for the seven P1-005 routes and local stock formats', () => {
    assert.equal(validateBudgetConfig(budgetConfig), budgetConfig);
    assert.deepEqual(
        budgetConfig.routes.map((route) => route.id),
        ['welcome', 'auth-login', 'dashboard', 'customer-show', 'planning-index', 'public-store', 'public-showcase'],
    );
    assert.deepEqual(
        budgetConfig.stock_image_profiles.map((profile) => `${profile.format}:${profile.width}`).sort(),
        ['avif:1280', 'avif:640', 'webp:1280', 'webp:640'],
    );

    budgetConfig.routes.forEach((route) => {
        ['javascript', 'css', 'i18n'].forEach((family) => {
            ['raw_bytes', 'gzip_bytes'].forEach((metric) => {
                assert.ok(route.baseline[family][metric] <= route.maximum[family][metric]);
            });
        });
    });
});

test('clears compiled Blade views before the budgeted frontend build', () => {
    const qualityBuild = packageConfig.scripts?.['qa:build'] || '';
    const clearViewsPosition = qualityBuild.indexOf('php artisan view:clear');
    const buildPosition = qualityBuild.indexOf('npm run build');

    assert.ok(clearViewsPosition >= 0);
    assert.ok(buildPosition > clearViewsPosition);
});

test('keeps the image dropzone outside the initial quick-create bundle', () => {
    [productQuickForm, serviceQuickForm, customerMediaFields].forEach((source) => {
        assert.match(source, /loader: \(\) => import\('@\/Components\/DropzoneInput\.vue'\)/u);
        assert.match(source, /loadingComponent: AsyncDropzonePlaceholder/u);
        assert.match(source, /delay: 0/u);
        assert.doesNotMatch(source, /import DropzoneInput from '@\/Components\/DropzoneInput\.vue'/u);
    });

    [
        ['customerModalOpened', 'handleCustomerModalOpen', 'CustomerQuickForm'],
        ['productModalOpened', 'handleProductModalOpen', 'ProductQuickForm'],
        ['serviceModalOpened', 'handleServiceModalOpen', 'ServiceQuickForm'],
    ].forEach(([openedState, openHandler, formComponent]) => {
        assert.match(quickCreateModals, new RegExp(`const ${openedState} = ref\\(false\\);`, 'u'));
        assert.match(
            quickCreateModals,
            new RegExp(`const ${openHandler} = \\(\\) => \\{\\s+${openedState}\\.value = true;`, 'u'),
        );
        assert.match(quickCreateModals, new RegExp(`@open="${openHandler}"`, 'u'));
        assert.match(quickCreateModals, new RegExp(`<${formComponent}\\s+v-if="${openedState}"`, 'u'));
    });

    [customerQuickForm, productQuickForm, serviceQuickForm].forEach((source) => {
        assert.match(source, /<button type="button" @click="closeOverlay"/u);
    });
});

test('measures app plus page static closures once and keeps i18n assets distinct', () => {
    const buildDirectory = mkdtempSync(join(tmpdir(), 'mlk-p1005-'));
    const route = budgetConfig.routes.find(({ id }) => id === 'welcome');
    const domains = getDomainsForPage(route.component);
    const manifest = {
        'resources/js/app.js': {
            file: 'assets/app.js',
            imports: ['_shared.js'],
            css: ['assets/app.css'],
        },
        'resources/js/Pages/Welcome.vue': {
            file: 'assets/welcome.js',
            imports: ['_shared.js'],
            css: ['assets/welcome.css'],
            dynamicImports: ['_lazy.js'],
        },
        '_shared.js': {
            file: 'assets/shared.js',
        },
        '_lazy.js': {
            file: 'assets/lazy.js',
        },
    };

    try {
        const appBytes = writeAsset(buildDirectory, 'assets/app.js', 'app');
        const sharedBytes = writeAsset(buildDirectory, 'assets/shared.js', 'shared');
        const pageBytes = writeAsset(buildDirectory, 'assets/welcome.js', 'page');
        writeAsset(buildDirectory, 'assets/lazy.js', 'this must not be counted');
        writeAsset(buildDirectory, 'assets/app.css', 'app-css');
        writeAsset(buildDirectory, 'assets/welcome.css', 'page-css');

        let i18nBytes = 0;
        ['fr', 'en'].forEach((locale) => {
            domains.forEach((domain) => {
                const entry = `resources/js/i18n/modules/${locale}/${domain}.json`;
                const asset = `assets/i18n-${locale}-${domain}.js`;
                const contents = `export default '${locale}-${domain}'`;

                manifest[entry] = {
                    file: asset,
                    imports: ['resources/js/app.js'],
                };
                i18nBytes += writeAsset(buildDirectory, asset, contents);
            });
        });

        const closure = collectManifestClosure(manifest, ['resources/js/app.js', route.entry]);
        assert.equal(closure.assets.includes('assets/lazy.js'), false);
        assert.deepEqual(closure.assets.sort(), [
            'assets/app.css',
            'assets/app.js',
            'assets/shared.js',
            'assets/welcome.css',
            'assets/welcome.js',
        ]);

        const profile = collectRouteProfile({ manifest, buildDirectory, route });
        assert.equal(profile.measured.javascript.raw_bytes, appBytes + sharedBytes + pageBytes);
        assert.equal(profile.measured.javascript.asset_count, 3);
        assert.equal(profile.measured.css.asset_count, 2);
        assert.equal(profile.measured.i18n.raw_bytes, i18nBytes);
        assert.equal(profile.measured.i18n.asset_count, domains.length * 2);
        assert.equal(profile.measured.i18n.assets.some(({ asset }) => asset === 'assets/app.js'), false);
    } finally {
        rmSync(buildDirectory, { recursive: true, force: true });
    }
});

test('requires a dedicated, accepted and non-expired MLK-DEC exception for a relaxed policy', () => {
    const base = structuredClone(budgetConfig);
    const current = structuredClone(budgetConfig);
    current.routes[0].maximum.javascript.gzip_bytes += 1;

    const comparison = compareBudgetPolicy(current, base);
    assert.equal(comparison.requires_exception, true);
    assert.deepEqual(comparison.increases, [{
        key: 'route:welcome:maximum:javascript:gzip_bytes',
        base: base.routes[0].maximum.javascript.gzip_bytes,
        current: current.routes[0].maximum.javascript.gzip_bytes,
    }]);

    const acceptedException = `## MLK-DEC-111 — Dérogation P1-005 budget front-end\n\n- Statut : **acceptée**.\n- Échéance : 2026-08-05.\n- Portée : exception ponctuelle aux plafonds frontend de P1-005.\n`;
    assert.deepEqual(validateDecisionException({
        decisions: acceptedException,
        decisionId: 'MLK-DEC-111',
        today: '2026-08-04',
    }), {
        valid: true,
        decision_id: 'MLK-DEC-111',
        expiry: '2026-08-05',
    });

    assert.equal(validateDecisionException({
        decisions: acceptedException,
        decisionId: 'MLK-DEC-111',
        today: '2026-08-06',
    }).valid, false);

    const unrelatedException = `## MLK-DEC-010 — Dérogation P0\n\n- Statut : **acceptée**.\n- Échéance : 2027-08-04.\n- Portée : staging uniquement.\n`;
    assert.equal(validateDecisionException({
        decisions: unrelatedException,
        decisionId: 'MLK-DEC-010',
        today: '2026-08-04',
    }).valid, false);

    const genericBudgetException = `## MLK-DEC-011 — Dérogation budget P0\n\n- Statut : **acceptée**.\n- Échéance : 2027-08-04.\n- Portée : exception ponctuelle aux budgets de capacité P0.\n`;
    assert.equal(validateDecisionException({
        decisions: genericBudgetException,
        decisionId: 'MLK-DEC-011',
        today: '2026-08-04',
    }).valid, false);
});
