import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

import {
    auditBrandingColorSources,
    collectBrandingColorSources,
    compareBrandingColorAuditConfigs,
    normalizeCssFunctionalColor,
    pathMatchesPattern,
    scanBrandingColorSource,
    validateBrandingColorAuditConfig,
} from '../../scripts/check-company-branding-colors.mjs';
import { validateCompanyBrandingE2ERegistry } from '../e2e/helpers/companyBranding.mjs';

const projectRoot = resolve('.');
const scriptPath = resolve(projectRoot, 'scripts/check-company-branding-colors.mjs');
const realConfig = JSON.parse(readFileSync(
    resolve(projectRoot, 'config/company-branding-color-audit.json'),
    'utf8',
));

const scan = {
    roots: ['resources/js'],
    extensions: ['.vue', '.js', '.css'],
    tokenFamilies: ['green', 'emerald', 'lime', 'teal'],
    brandHexColors: ['#16A34A', '#84CC16', '#14B8A6'],
};

const moduleFixture = ({
    key = 'M01',
    status = 'pending',
    includes = ['resources/**'],
    excludes = [],
    baseline = { candidates: 0, highRisk: 0, files: {} },
    exceptions = [],
} = {}) => ({
    key,
    label: `Fixture ${key}`,
    status,
    includes,
    excludes,
    baseline,
    exceptions,
});

const configFixture = (modules) => ({ version: 1, scan, modules });

test('detects every configured Tailwind utility, configured hex and strong interactive risks', () => {
    const utilitySource = [
        'bg-green-500',
        'text-emerald-600',
        'border-lime-400',
        'ring-teal-500',
        'outline-green-600',
        'from-emerald-500',
        'via-lime-500',
        'to-teal-500',
        'accent-green-600',
        'caret-emerald-600',
        'decoration-lime-600',
        'shadow-teal-600',
    ].join(' ');
    const source = `${utilitySource}\n`
        + `const primary = 'bg-green-600 text-white hover:bg-green-700';\n`
        + '<input class="focus:ring-emerald-500 checked:bg-lime-500" />\n'
        + '<div class="group-focus:border-teal-500 focus-within:bg-lime-500"></div>\n'
        + '<button class="bg-teal-600 text-white hover:bg-teal-700">Envoyer</button>\n'
        + '<button class="bg-green-600">Continuer</button>\n'
        + '<Link class="bg-green-600 text-white">Ouvrir</Link>\n'
        + 'const legacy = ["#16a34a", "#84cc16", "#14b8a6"];';
    const findings = scanBrandingColorSource({ path: 'fixture.vue', source, scan });

    assert.deepEqual(
        new Set(findings.filter(({ utility }) => utility).map(({ utility }) => utility)),
        new Set(['bg', 'text', 'border', 'ring', 'outline', 'from', 'via', 'to', 'accent', 'caret', 'decoration', 'shadow']),
    );
    assert.equal(findings.find(({ token }) => token === '#16A34A').highRisk, true);
    assert.ok(findings.find(({ token }) => token === 'focus:ring-emerald-500').riskReasons.includes('brand_focus_or_ring'));
    assert.ok(findings.find(({ token }) => token === 'group-focus:border-teal-500').riskReasons.includes('brand_focus_or_ring'));
    assert.ok(findings.find(({ token }) => token === 'focus-within:bg-lime-500').riskReasons.includes('brand_focus_or_ring'));
    assert.ok(findings.find(({ token }) => token === 'checked:bg-lime-500').riskReasons.includes('brand_checked_or_selected'));
    assert.ok(findings.find(({ token }) => token === 'bg-teal-600').riskReasons.includes('brand_interactive_white_text'));
    assert.ok(findings.find(({ token, excerpt }) => (
        token === 'bg-green-600' && excerpt.includes('Continuer')
    )).riskReasons.includes('brand_interactive_background'));
    assert.ok(findings.find(({ token, excerpt }) => (
        token === 'bg-green-600' && excerpt.includes('<Link')
    )).riskReasons.includes('brand_interactive_background'));
    assert.equal(findings.find(({ token }) => token === '#84CC16').highRisk, true);
    assert.equal(findings.find(({ token }) => token === '#14B8A6').highRisk, true);
});

test('normalizes opaque RGB and HSL functional colors while ignoring alpha', () => {
    assert.equal(normalizeCssFunctionalColor('rgb(132, 204, 22)'), '#84CC16');
    assert.equal(normalizeCssFunctionalColor('rgba(20 184 166 / 20%)'), '#14B8A6');
    assert.equal(
        normalizeCssFunctionalColor('hsl(142.0859 76.2162% 36.2745%)'),
        '#16A34A',
    );
    assert.equal(
        normalizeCssFunctionalColor('hsla(142.0859, 76.2162%, 36.2745%, 0.1)'),
        '#16A34A',
    );

    const source = [
        'color: rgb(132, 204, 22);',
        'color: rgba(20 184 166 / 20%);',
        'color: hsl(142.0859 76.2162% 36.2745%);',
        'color: hsla(142.0859, 76.2162%, 36.2745%, 0.1);',
        'color: rgb(1, 2, 3);',
    ].join('\n');
    const findings = scanBrandingColorSource({ path: 'fixture.css', source, scan });

    assert.equal(findings.length, 4);
    assert.equal(findings.every(({ kind }) => kind === 'raw_function'), true);
    assert.equal(findings.every(({ highRisk }) => highRisk), true);
});

test('detects arbitrary green-range colors without flagging red, amber or gray', () => {
    const source = [
        'color: #12A454;',
        'color: #0f0;',
        'color: rgb(18 164 84);',
        'color: oklch(62% .18 145);',
        'color: forestgreen;',
        'color: seagreen;',
        'color: #DC2626;',
        'color: hsl(38 92% 50%);',
        'color: #6B7280;',
    ].join('\n');
    const findings = scanBrandingColorSource({ path: 'fixture.css', source, scan });

    assert.deepEqual(findings.map(({ token }) => token), [
        '#12A454',
        '#0F0',
        'rgb(18 164 84)',
        'oklch(62% .18 145)',
        'forestgreen',
        'seagreen',
    ]);
    assert.equal(findings.every(({ highRisk }) => highRisk), true);
});

test('detects green CTA plus hover pairs inside JavaScript class strings', () => {
    const findings = scanBrandingColorSource({
        path: 'resources/js/utils/crmButtonStyles.js',
        source: `export const primary = 'bg-green-600 text-white hover:bg-green-700';`,
        scan,
    });

    assert.equal(findings.length, 2);
    assert.equal(findings.every(({ highRisk }) => highRisk), true);
    assert.equal(findings.every(({ riskReasons }) => riskReasons.includes('brand_cta_with_hover')), true);
});

test('allows an exact paid-status exception in a verified module', () => {
    const path = 'resources/js/Pages/Invoice/Index.vue';
    const source = '<span data-status="paid" class="bg-emerald-100 text-emerald-700">Payée</span>';
    const config = configFixture([moduleFixture({
        key: 'M20',
        status: 'verified',
        includes: [path],
        exceptions: [{
            path,
            anchor: 'data-status="paid"',
            tokens: ['bg-emerald-100', 'text-emerald-700'],
            expected_matches: 2,
            classification: 'statut',
            reason: 'Le vert indique une facture payée avec succès.',
        }],
    })]);
    const report = auditBrandingColorSources({ config, sources: { [path]: source }, strict: true });

    assert.equal(report.ok, true);
    assert.deepEqual(report.modules[0].totals, {
        candidates: 2,
        highRisk: 0,
        excepted: 2,
        unknown: 0,
        files: 1,
    });
});

test('rejects orphaned, count-drifted and overlapping granular exceptions', () => {
    const path = 'resources/js/Pages/Invoice/Index.vue';
    const exception = {
        path,
        anchor: 'data-status="paid"',
        tokens: ['bg-emerald-100'],
        expected_matches: 2,
        classification: 'statut',
        reason: 'Le vert indique une facture payée avec succès.',
    };
    const config = configFixture([moduleFixture({
        key: 'M20',
        status: 'verified',
        includes: [path],
        exceptions: [exception, { ...exception, expected_matches: 1 }],
    })]);
    const report = auditBrandingColorSources({
        config,
        sources: { [path]: '<span data-status="paid" class="bg-emerald-100">Payée</span>' },
        strict: true,
    });

    assert.equal(report.ok, false);
    assert.ok(report.errors.some((error) => error.includes('2 attendu')));
    assert.ok(report.errors.some((error) => error.includes('déjà exceptée')));

    const orphanConfig = JSON.parse(JSON.stringify(config));
    orphanConfig.modules[0].exceptions = [{ ...exception, anchor: 'data-status="refunded"', expected_matches: 1 }];
    const orphan = auditBrandingColorSources({
        config: orphanConfig,
        sources: { [path]: '<span data-status="paid" class="bg-emerald-100">Payée</span>' },
    });
    assert.equal(orphan.ok, false);
    assert.ok(orphan.errors.some((error) => error.includes('token orphelin')));
});

test('strict baselines reject per-file growth and new candidate files while allowing reductions', () => {
    const firstPath = 'resources/js/Components/Primary.vue';
    const secondPath = 'resources/js/Components/Secondary.vue';
    const baseline = {
        candidates: 2,
        highRisk: 2,
        files: { [firstPath]: { candidates: 2, highRisk: 2 } },
    };
    const config = configFixture([moduleFixture({ baseline })]);
    const original = `const primary = 'bg-green-600 text-white hover:bg-green-700';`;

    assert.equal(auditBrandingColorSources({
        config,
        sources: { [firstPath]: original },
        strict: true,
    }).ok, true);

    const growth = auditBrandingColorSources({
        config,
        sources: { [firstPath]: `${original} text-emerald-700` },
        strict: true,
    });
    assert.equal(growth.ok, false);
    assert.ok(growth.errors.some((error) => error.includes('candidates en hausse')));

    const newFile = auditBrandingColorSources({
        config,
        sources: {
            [firstPath]: 'text-green-700',
            [secondPath]: 'text-emerald-700',
        },
        strict: true,
    });
    assert.equal(newFile.ok, false);
    assert.ok(newFile.errors.some((error) => error.includes('Nouveau fichier candidat')));

    assert.equal(auditBrandingColorSources({
        config,
        sources: { [firstPath]: 'text-green-700' },
        strict: true,
    }).ok, true);
});

test('coverage assigns every Vue Page and candidate file to exactly one module', () => {
    const page = 'resources/js/Pages/Customer/Index.vue';
    const component = 'resources/js/Components/Status.vue';
    const sources = { [page]: '<template />', [component]: 'text-green-700' };
    const missing = auditBrandingColorSources({
        config: configFixture([moduleFixture({ includes: [component] })]),
        sources,
    });
    assert.equal(missing.coverage.ok, false);
    assert.ok(missing.coverage.errors.some((error) => error.includes(`sans module: ${page}`)));

    const duplicate = auditBrandingColorSources({
        config: configFixture([
            moduleFixture({ key: 'M01', includes: ['resources/**'] }),
            moduleFixture({ key: 'M04', includes: ['resources/js/**'] }),
        ]),
        sources,
    });
    assert.equal(duplicate.coverage.ok, false);
    assert.ok(duplicate.coverage.errors.some((error) => error.includes('plusieurs modules')));

    const covered = auditBrandingColorSources({
        config: configFixture([
            moduleFixture({ key: 'M01', includes: ['resources/**'], excludes: ['resources/js/Pages/**'] }),
            moduleFixture({ key: 'M04', includes: ['resources/js/Pages/**'] }),
        ]),
        sources,
    });
    assert.equal(covered.coverage.ok, true);
    assert.deepEqual(covered.coverage.assignments[page], ['M04']);
});

test('verified modules fail strict mode until every candidate is classified exactly', () => {
    const path = 'resources/js/Pages/Customer/Index.vue';
    const config = configFixture([moduleFixture({
        key: 'M04',
        status: 'verified',
        includes: [path],
    })]);
    const report = auditBrandingColorSources({
        config,
        sources: { [path]: '<button class="bg-green-600 text-white">Créer</button>' },
        strict: true,
    });

    assert.equal(report.ok, false);
    assert.equal(report.modules[0].totals.unknown, 1);
    assert.ok(report.errors.some((error) => error.includes('Module vérifié')));
});

test('glob matching supports exact paths, recursive directories and single-level wildcards', () => {
    assert.equal(pathMatchesPattern('resources/js/Pages/Customer/Index.vue', 'resources/js/Pages/**'), true);
    assert.equal(pathMatchesPattern('resources/js/Pages/Index.vue', 'resources/js/Pages/**/*.vue'), true);
    assert.equal(pathMatchesPattern('resources/js/Pages/Customer/Index.vue', 'resources/js/Pages/*.vue'), false);
    assert.equal(pathMatchesPattern('tailwind.config.js', 'tailwind.config.js'), true);
});

test('collects .blade.php files through the configured .php extension and scans lime and teal hex', () => {
    const fixtureRoot = mkdtempSync(join(tmpdir(), 'mlk-branding-colors-'));
    const viewDirectory = join(fixtureRoot, 'resources/views/emails');
    const viewPath = join(viewDirectory, 'status.blade.php');
    const fixtureConfig = configFixture([moduleFixture({
        key: 'M34',
        includes: ['resources/views/**'],
    })]);
    fixtureConfig.scan.roots = ['resources/views'];
    fixtureConfig.scan.extensions = ['.php'];

    try {
        mkdirSync(viewDirectory, { recursive: true });
        writeFileSync(
            viewPath,
            '<a class="bg-lime-600">Action</a><span style="color:#14b8a6">Statut</span>',
        );
        const sources = collectBrandingColorSources({ projectRoot: fixtureRoot, config: fixtureConfig });
        assert.deepEqual([...sources.keys()], ['resources/views/emails/status.blade.php']);

        const report = auditBrandingColorSources({ config: fixtureConfig, sources, moduleKey: 'M34' });
        assert.equal(report.modules[0].totals.candidates, 2);
        assert.equal(report.modules[0].totals.highRisk, 2);
        assert.ok(report.modules[0].findings.some(({ token }) => token === '#14B8A6'));
    } finally {
        rmSync(fixtureRoot, { recursive: true, force: true });
    }
});

test('repository integrity rejects unmatched includes and stale or incorrectly owned baselines', () => {
    const realPath = 'resources/js/Components/Real.vue';
    const missingInclude = auditBrandingColorSources({
        config: configFixture([moduleFixture({
            status: 'verified',
            includes: ['resources/js/Components/Missing.vue'],
        })]),
        sources: {},
        knownPaths: [realPath],
    });
    assert.equal(missingInclude.ok, false);
    assert.ok(missingInclude.manifest.errors.some((error) => error.includes('include sans chemin réel')));

    const stalePath = 'resources/js/Components/Deleted.vue';
    const staleBaseline = auditBrandingColorSources({
        config: configFixture([moduleFixture({
            includes: ['resources/js/**'],
            baseline: {
                candidates: 1,
                highRisk: 0,
                files: { [stalePath]: { candidates: 1, highRisk: 0 } },
            },
        })]),
        sources: {},
        knownPaths: [realPath],
    });
    assert.ok(staleBaseline.manifest.errors.some((error) => error.includes('baseline stale')));

    const nonOwned = auditBrandingColorSources({
        config: configFixture([
            moduleFixture({
                key: 'M01',
                includes: ['resources/css/**'],
                baseline: {
                    candidates: 1,
                    highRisk: 0,
                    files: { [realPath]: { candidates: 1, highRisk: 0 } },
                },
            }),
            moduleFixture({ key: 'M02', includes: ['resources/js/**'] }),
        ]),
        sources: { [realPath]: 'text-green-700', 'resources/css/app.css': ':root {}' },
        knownPaths: [realPath, 'resources/css/app.css'],
    });
    assert.ok(nonOwned.manifest.errors.some((error) => error.includes('baseline non possédée')));

    const duplicateOwner = auditBrandingColorSources({
        config: configFixture([
            moduleFixture({
                key: 'M01',
                includes: ['resources/js/**'],
                baseline: {
                    candidates: 1,
                    highRisk: 0,
                    files: { [realPath]: { candidates: 1, highRisk: 0 } },
                },
            }),
            moduleFixture({ key: 'M02', includes: [realPath] }),
        ]),
        sources: { [realPath]: 'text-green-700' },
        knownPaths: [realPath],
    });
    assert.ok(duplicateOwner.manifest.errors.some((error) => error.includes('a 2 owner(s)')));
});

test('base-manifest comparison blocks baseline inflation, status regressions and verified exception edits', () => {
    const path = 'resources/js/Components/Primary.vue';
    const addedPath = 'resources/js/Components/Added.vue';
    const base = configFixture([moduleFixture({
        status: 'in_progress',
        baseline: {
            candidates: 2,
            highRisk: 1,
            files: { [path]: { candidates: 2, highRisk: 1 } },
        },
    })]);
    const inflated = configFixture([moduleFixture({
        status: 'pending',
        baseline: {
            candidates: 4,
            highRisk: 2,
            files: {
                [path]: { candidates: 3, highRisk: 2 },
                [addedPath]: { candidates: 1, highRisk: 0 },
            },
        },
    })]);
    const comparison = compareBrandingColorAuditConfigs(inflated, base);

    assert.equal(comparison.ok, false);
    assert.ok(comparison.violations.some((violation) => violation.includes('régression de statut')));
    assert.ok(comparison.violations.some((violation) => violation.includes('baseline totale candidates en hausse')));
    assert.ok(comparison.violations.some((violation) => violation.includes('nouveau fichier baseline')));

    const exception = {
        path,
        anchor: 'paid',
        tokens: ['text-green-700'],
        expected_matches: 1,
        classification: 'statut',
        reason: 'Le vert représente un statut payé confirmé.',
    };
    const verifiedBase = configFixture([moduleFixture({ status: 'verified', exceptions: [exception] })]);
    const edited = configFixture([moduleFixture({
        status: 'verified',
        exceptions: [{ ...exception, reason: 'Une raison modifiée après certification.' }],
    })]);
    assert.ok(compareBrandingColorAuditConfigs(edited, verifiedBase).violations.some(
        (violation) => violation.includes('exceptions modifiées'),
    ));
    assert.deepEqual(compareBrandingColorAuditConfigs(inflated, null), {
        ok: true,
        bootstrap: true,
        violations: [],
    });
});

test('canonical manifests contain exactly M00 through M35', () => {
    assert.equal(validateBrandingColorAuditConfig(realConfig, { canonical: true }), realConfig);

    const missing = JSON.parse(JSON.stringify(realConfig));
    missing.modules.pop();
    assert.throws(
        () => validateBrandingColorAuditConfig(missing, { canonical: true }),
        /exactement M00 à M35/,
    );

    const unexpected = JSON.parse(JSON.stringify(realConfig));
    unexpected.modules.at(-1).key = 'M99';
    assert.throws(
        () => validateBrandingColorAuditConfig(unexpected, { canonical: true }),
        /exactement M00 à M35/,
    );
});

test('the repository manifest is valid, exhaustive and executable through the real CLI', () => {
    assert.equal(validateBrandingColorAuditConfig(realConfig), realConfig);
    assert.deepEqual(realConfig.scan.tokenFamilies, ['green', 'emerald', 'lime', 'teal']);
    assert.ok(realConfig.scan.roots.includes('resources/views'));
    assert.ok(realConfig.scan.extensions.includes('.php'));
    assert.ok(realConfig.scan.brandHexColors.includes('#84CC16'));
    assert.ok(realConfig.scan.brandHexColors.includes('#14B8A6'));
    assert.equal(realConfig.modules.length, 36);
    const e2eRegistry = validateCompanyBrandingE2ERegistry(realConfig);
    assert.equal(e2eRegistry.ok, true, e2eRegistry.errors.join('\n'));
    assert.deepEqual(e2eRegistry.scenarioIds, e2eRegistry.verifiedModuleIds);

    const execution = spawnSync(
        process.execPath,
        [scriptPath, '--strict', '--json'],
        {
            cwd: projectRoot,
            encoding: 'utf8',
            maxBuffer: 20 * 1024 * 1024,
            env: { ...process.env, BRANDING_COLOR_BASE_REF: '' },
        },
    );
    assert.equal(execution.status, 0, execution.stderr || execution.stdout);
    const report = JSON.parse(execution.stdout);
    assert.equal(report.ok, true);
    assert.equal(report.coverage.ok, true);
    assert.equal(report.coverage.vuePages, 220);
    assert.equal(report.coverage.candidateFiles, 277);
    assert.equal(report.totals.candidates, 5169);
    assert.equal(report.totals.highRisk, 2003);
    assert.equal(report.modules.length, 36);
    assert.equal(report.modules[0].key, 'M00');
});
