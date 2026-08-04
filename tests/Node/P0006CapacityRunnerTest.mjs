import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';

import {
    RunnerFailure,
    calculateBaselineFingerprint,
    calculateManifestHash,
    executeBusinessRequest,
    executeScenario,
    runCli,
    validateRunConfiguration,
} from '../../scripts/capacity/p0-006-runner.mjs';

const TEST_HASH = 'a'.repeat(64);
const TEST_ORIGIN = 'https://staging.example.test';
const RESULT_FIELDS = [
    'schema_version',
    'run_id',
    'environment',
    'commit',
    'scenario_key',
    'manifest_hash',
    'fixture_hash',
    'baseline_fingerprint',
    'target_origin_hash',
    'runner',
    'runner_hash',
    'started_at',
    'ended_at',
    'virtual_users',
    'duration_seconds',
    'ramp_up_seconds',
    'request_interval_ms',
    'request_timeout_ms',
    'attempted_requests',
    'completed_requests',
    'transport_errors',
    'assertion_failures',
    'client_latency_ms',
];

function sha256(value) {
    return createHash('sha256').update(value).digest('hex');
}

function bindFixture(plan, fixtures, fixtureText = JSON.stringify(fixtures)) {
    const fixtureHash = sha256(fixtureText);
    plan.fixture_hash = fixtureHash;
    plan.baseline_context.fixture_hash = fixtureHash;
    plan.baseline_fingerprint = calculateBaselineFingerprint(plan.baseline_context);

    return { fixtureHash, fixtureText };
}

function documentsFor({
    origin = TEST_ORIGIN,
    approvedOrigins = [origin],
    routeUri = '/success',
    method = 'GET',
    acceptedStatusCodes = [200],
    outcome = { strategy: 'status_code' },
    runnerHash = TEST_HASH,
} = {}) {
    const now = Date.now();
    const scenario = {
        key: 'node_runner_test',
        method,
        route_names: ['test.route'],
        route_uris: { 'test.route': routeUri },
        accepted_status_codes: acceptedStatusCodes,
        protocol: {
            transport: 'http',
            request_format: 'json',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            follow_redirects: false,
            runner_policy: 'external_approved_harness',
            authentication: 'public',
            csrf: false,
            fixture_reference: 'external:node-test',
            outcome,
        },
        profile: {
            virtual_users: 1,
            duration: '1s',
            ramp_up: '0s',
            request_interval_ms: 50,
            request_timeout_ms: 500,
            minimum_completed_requests: 1,
        },
        safety: {
            mode: 'read_only',
            requires_isolated_tenant: false,
        },
        blocker: {
            reason: null,
            owner: null,
            review_at: null,
        },
        targets: {
            p95_ms: 1000,
            p99_ms: 1500,
            min_samples: 1,
        },
    };
    scenario.manifest_hash = calculateManifestHash(scenario);

    const period = {
        started_at: new Date(now - 60_000).toISOString(),
        ended_at: new Date(now + 300_000).toISOString(),
    };
    const baselineContext = {
        status: 'complete',
        release: 'p0-006-node-test-release',
        run_id: 'p0-006-node-test',
        environment: 'testing',
        commit: '0123456789abcdef0123456789abcdef01234567',
        period: { ...period },
        traffic: 'synthetic',
        runner: 'node20-p0-006-v1',
        runner_hash: runnerHash,
        fixture_hash: '0'.repeat(64),
        allowed_origins: [...approvedOrigins],
        exclusions: ['none'],
        mode: 'staging',
        representative: true,
        approved: true,
        approval_reference: 'P0-006-NODE-TEST',
        queue_canaries_verified: true,
        isolated_tenant_verified: false,
        owner: 'node-test-owner',
        validator: 'node-test-validator',
    };
    const plan = {
        status: 'ready_for_approved_harness',
        preflight: { ready: true, issues: [], errors: [] },
        configuration_issues: [],
        issues: [],
        runner: baselineContext.runner,
        runner_hash: runnerHash,
        fixture_hash: baselineContext.fixture_hash,
        allowed_origins: [...approvedOrigins],
        runner_result_contract: { schema_version: 3 },
        run_id: baselineContext.run_id,
        environment: baselineContext.environment,
        commit: baselineContext.commit,
        period: { ...period },
        baseline_context: baselineContext,
        scenarios: [scenario],
    };
    const fixtures = {
        schema_version: 2,
        base_url: origin,
        scenarios: {
            node_runner_test: {
                method: scenario.method,
                route_name: 'test.route',
                route_uri: scenario.route_uris['test.route'],
                path_parameters: {},
                query: {},
                headers: {},
                body: ['GET', 'HEAD'].includes(scenario.method) ? null : { synthetic: true },
            },
        },
    };
    const binding = bindFixture(plan, fixtures);

    return { plan, fixtures, ...binding };
}

function configurationFor(documents) {
    return validateRunConfiguration({
        plan: documents.plan,
        fixtures: documents.fixtures,
        scenarioKey: 'node_runner_test',
        scriptHash: documents.plan.runner_hash,
        fixtureHash: documents.fixtureHash,
    });
}

function expectFailure(code, callback) {
    assert.throws(
        callback,
        (error) => error instanceof RunnerFailure && error.code === code
    );
}

function refreshScenarioBinding(documents) {
    const scenario = documents.plan.scenarios[0];
    scenario.manifest_hash = calculateManifestHash(scenario);
    return Object.assign(documents, bindFixture(documents.plan, documents.fixtures));
}

function jsonResponse(payload, status = 200) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

test('the manifest hash matches PHP canonical JSON and binds the blocker', () => {
    const scenario = {
        targets: { p95_ms: 200 },
        safety: { mode: 'read_only' },
        route_uris: { 'route.é': '/café' },
        route_names: ['route.é'],
        protocol: { z: 'é', a: '/' },
        profile: { request_interval_ms: 50, duration: '1s' },
        method: 'GET',
        key: 'scenario/é',
        accepted_status_codes: [200],
        blocker: { reason: null, owner: null, review_at: null },
        ignored_field: 'not-part-of-the-manifest',
    };
    const phpCanonicalJson = String.raw`{"accepted_status_codes":[200],"blocker":{"owner":null,"reason":null,"review_at":null},"key":"scenario\/\u00e9","method":"GET","profile":{"duration":"1s","request_interval_ms":50},"protocol":{"a":"\/","z":"\u00e9"},"route_names":["route.\u00e9"],"route_uris":{"route.\u00e9":"\/caf\u00e9"},"safety":{"mode":"read_only"},"targets":{"p95_ms":200}}`;
    const original = calculateManifestHash(scenario);

    assert.equal(original, sha256(phpCanonicalJson));
    scenario.blocker.reason = 'active operational blocker';
    assert.notEqual(calculateManifestHash(scenario), original);
});

test('the baseline fingerprint matches the normalized PHP v3 identity contract', () => {
    const context = {
        release: 'release/é',
        run_id: 'run',
        environment: 'staging',
        commit: 'abc',
        period: {
            started_at: '2026-07-27T12:00:00Z',
            ended_at: '2026-07-27T12:10:00Z',
        },
        traffic: 'synthetic',
        runner: 'node',
        runner_hash: 'A'.repeat(64),
        fixture_hash: 'B'.repeat(64),
        allowed_origins: ['https://staging.example.test'],
        exclusions: ' none, raw ',
        mode: 'staging',
        representative: true,
        approved: true,
        approval_reference: 'CHANGE/1',
        queue_canaries_verified: true,
        isolated_tenant_verified: true,
        owner: 'owner',
        validator: 'validator',
    };
    const phpCanonicalJson = String.raw`{"allowed_origins":["https:\/\/staging.example.test"],"approval_reference":"CHANGE\/1","approved":true,"commit":"abc","environment":"staging","exclusions":["none","raw"],"fixture_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","isolated_tenant_verified":true,"mode":"staging","owner":"owner","period":{"ended_at":"2026-07-27T12:10:00Z","started_at":"2026-07-27T12:00:00Z"},"queue_canaries_verified":true,"release":"release\/\u00e9","representative":true,"run_id":"run","runner":"node","runner_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","traffic":"synthetic","validator":"validator"}`;

    assert.equal(calculateBaselineFingerprint(context), sha256(phpCanonicalJson));
});

test('valid configuration is bound to the runner, fixture, baseline, origin, and profile', () => {
    const documents = documentsFor({ method: 'POST' });
    const configuration = configurationFor(documents);

    assert.equal(configuration.runnerHash, TEST_HASH);
    assert.equal(configuration.fixtureHash, documents.fixtureHash);
    assert.equal(configuration.baselineFingerprint, documents.plan.baseline_fingerprint);
    assert.equal(configuration.targetOriginHash, sha256(TEST_ORIGIN));
    assert.equal(configuration.requestTimeoutMs, 500);
    assert.equal(configuration.requestUrl.href, `${TEST_ORIGIN}/success`);
});

test('manifest, baseline, and top-level identity tampering fail closed', () => {
    const manifestDocuments = documentsFor();
    manifestDocuments.plan.scenarios[0].profile.virtual_users = 2;
    expectFailure('MANIFEST_HASH_MISMATCH', () => configurationFor(manifestDocuments));

    const baselineDocuments = documentsFor();
    baselineDocuments.plan.baseline_context.approved = false;
    expectFailure('BASELINE_FINGERPRINT_MISMATCH', () => configurationFor(baselineDocuments));

    const identityDocuments = documentsFor();
    identityDocuments.plan.run_id = 'tampered-top-level-run';
    expectFailure('PLAN_BASELINE_IDENTITY_MISMATCH', () => configurationFor(identityDocuments));
});

test('stale preflight, active blockers, and fixture-byte tampering are refused', () => {
    const preflightDocuments = documentsFor();
    preflightDocuments.plan.preflight.ready = false;
    preflightDocuments.plan.preflight.issues = ['queue unavailable'];
    expectFailure('PLAN_PREFLIGHT_NOT_READY', () => configurationFor(preflightDocuments));

    const blockedDocuments = documentsFor();
    blockedDocuments.plan.scenarios[0].blocker = {
        reason: 'business route unavailable',
        owner: 'platform',
        review_at: '2099-01-01T00:00:00Z',
    };
    refreshScenarioBinding(blockedDocuments);
    expectFailure('SCENARIO_BLOCKED', () => configurationFor(blockedDocuments));

    const tamperedFixtureDocuments = documentsFor();
    tamperedFixtureDocuments.fixtures.scenarios.node_runner_test.query.signed = 'changed-after-approval';
    tamperedFixtureDocuments.fixtureHash = sha256(JSON.stringify(tamperedFixtureDocuments.fixtures));
    expectFailure('FIXTURE_HASH_MISMATCH', () => configurationFor(tamperedFixtureDocuments));
});

test('only baseline-approved HTTPS origins can be targeted', () => {
    const httpDocuments = documentsFor();
    httpDocuments.fixtures.base_url = 'http://127.0.0.1:43123';
    Object.assign(httpDocuments, bindFixture(httpDocuments.plan, httpDocuments.fixtures));
    expectFailure('BASE_URL_HTTPS_REQUIRED', () => configurationFor(httpDocuments));

    const unauthorizedDocuments = documentsFor({
        origin: 'https://fixture-controlled.example.test',
        approvedOrigins: ['https://operator-approved.example.test'],
    });
    expectFailure('BASE_URL_NOT_ALLOWLISTED', () => configurationFor(unauthorizedDocuments));

    const selfAuthorizationDocuments = documentsFor();
    selfAuthorizationDocuments.fixtures.allowed_origins = [TEST_ORIGIN];
    Object.assign(selfAuthorizationDocuments, bindFixture(
        selfAuthorizationDocuments.plan,
        selfAuthorizationDocuments.fixtures
    ));
    expectFailure('FIXTURE_SCHEMA_INVALID', () => configurationFor(selfAuthorizationDocuments));
});

test('route traversal, method overrides, and unsafe headers are rejected', () => {
    for (const routeUri of ['/safe/../admin', '/safe/%252e%252e/admin']) {
        const documents = documentsFor({ routeUri });
        expectFailure('FIXTURE_ROUTE_URI_INVALID', () => configurationFor(documents));
    }

    const pathParameterDocuments = documentsFor({ routeUri: '/customers/{customer}' });
    pathParameterDocuments.fixtures.scenarios.node_runner_test.path_parameters.customer = '../admin';
    Object.assign(pathParameterDocuments, bindFixture(
        pathParameterDocuments.plan,
        pathParameterDocuments.fixtures
    ));
    expectFailure('FIXTURE_ROUTE_URI_INVALID', () => configurationFor(pathParameterDocuments));

    const queryOverrideDocuments = documentsFor();
    queryOverrideDocuments.fixtures.scenarios.node_runner_test.query._method = 'DELETE';
    Object.assign(queryOverrideDocuments, bindFixture(
        queryOverrideDocuments.plan,
        queryOverrideDocuments.fixtures
    ));
    expectFailure('FIXTURE_QUERY_INVALID', () => configurationFor(queryOverrideDocuments));

    for (const header of ['Host', 'X-HTTP-Method-Override', 'X-Forwarded-Host']) {
        const documents = documentsFor();
        documents.fixtures.scenarios.node_runner_test.headers[header] = 'forbidden';
        Object.assign(documents, bindFixture(documents.plan, documents.fixtures));
        expectFailure('FIXTURE_HEADERS_INVALID', () => configurationFor(documents));
    }

    const bodyOverrideDocuments = documentsFor({ method: 'POST' });
    bodyOverrideDocuments.fixtures.scenarios.node_runner_test.body._method = 'DELETE';
    Object.assign(bodyOverrideDocuments, bindFixture(
        bodyOverrideDocuments.plan,
        bodyOverrideDocuments.fixtures
    ));
    expectFailure('FIXTURE_METHOD_OVERRIDE_FORBIDDEN', () => configurationFor(bodyOverrideDocuments));
});

test('authentication, CSRF, safety mode, and timeout policy are enforced', () => {
    const authenticatedDocuments = documentsFor({ method: 'POST' });
    authenticatedDocuments.plan.scenarios[0].protocol.authentication = 'authenticated_session';
    authenticatedDocuments.plan.scenarios[0].protocol.csrf = true;
    refreshScenarioBinding(authenticatedDocuments);
    expectFailure('FIXTURE_SESSION_COOKIE_REQUIRED', () => configurationFor(authenticatedDocuments));

    authenticatedDocuments.fixtures.scenarios.node_runner_test.headers.Cookie = 'session=synthetic';
    Object.assign(authenticatedDocuments, bindFixture(
        authenticatedDocuments.plan,
        authenticatedDocuments.fixtures
    ));
    expectFailure('FIXTURE_CSRF_REQUIRED', () => configurationFor(authenticatedDocuments));

    authenticatedDocuments.fixtures.scenarios.node_runner_test.headers['X-CSRF-TOKEN'] = 'synthetic-token';
    Object.assign(authenticatedDocuments, bindFixture(
        authenticatedDocuments.plan,
        authenticatedDocuments.fixtures
    ));
    assert.doesNotThrow(() => configurationFor(authenticatedDocuments));

    const productionWriteDocuments = documentsFor({ method: 'POST' });
    productionWriteDocuments.plan.scenarios[0].safety = {
        mode: 'controlled_write',
        requires_isolated_tenant: true,
    };
    productionWriteDocuments.plan.baseline_context.mode = 'production_read_only';
    productionWriteDocuments.plan.baseline_context.isolated_tenant_verified = true;
    refreshScenarioBinding(productionWriteDocuments);
    expectFailure('PRODUCTION_WRITE_SCENARIO_FORBIDDEN', () => configurationFor(productionWriteDocuments));

    const shortTimeoutDocuments = documentsFor();
    shortTimeoutDocuments.plan.scenarios[0].profile.request_timeout_ms = 499;
    refreshScenarioBinding(shortTimeoutDocuments);
    expectFailure('PLAN_REQUEST_TIMEOUT_INVALID', () => configurationFor(shortTimeoutDocuments));

    const fixtureTimeoutDocuments = documentsFor();
    fixtureTimeoutDocuments.fixtures.request_timeout_ms = 1;
    Object.assign(fixtureTimeoutDocuments, bindFixture(
        fixtureTimeoutDocuments.plan,
        fixtureTimeoutDocuments.fixtures
    ));
    expectFailure('FIXTURE_SCHEMA_INVALID', () => configurationFor(fixtureTimeoutDocuments));
});

test('business responses, redirects, oversized bodies, and transport failures fail closed', async () => {
    const statusConfiguration = configurationFor(documentsFor());
    const unexpectedStatus = await executeBusinessRequest(statusConfiguration, {
        fetchImpl: async () => jsonResponse({ unavailable: true }, 503),
    });
    assert.equal(unexpectedStatus.assertionFailure, true);

    const outcomeConfiguration = configurationFor(documentsFor({
        outcome: { strategy: 'json_field_equals', field: 'outcome.ok', value: true },
    }));
    const wrongOutcome = await executeBusinessRequest(outcomeConfiguration, {
        fetchImpl: async () => jsonResponse({ outcome: { ok: false } }),
    });
    assert.equal(wrongOutcome.assertionFailure, true);

    let redirectPolicy;
    const redirect = await executeBusinessRequest(statusConfiguration, {
        fetchImpl: async (url, options) => {
            redirectPolicy = options.redirect;
            return new Response(null, { status: 302, headers: { Location: '/sensitive' } });
        },
    });
    assert.equal(redirectPolicy, 'manual');
    assert.equal(redirect.assertionFailure, true);

    const oversized = await executeBusinessRequest(statusConfiguration, {
        fetchImpl: async () => new Response(new Uint8Array((1024 * 1024) + 1), { status: 200 }),
    });
    assert.equal(oversized.completed, true);
    assert.equal(oversized.assertionFailure, true);

    const streamFailure = await executeBusinessRequest(statusConfiguration, {
        fetchImpl: async () => new Response(new ReadableStream({
            start(controller) {
                controller.enqueue(new Uint8Array([123]));
                queueMicrotask(() => controller.error(new Error('body-stream-failed')));
            },
        }), { status: 200 }),
    });
    assert.equal(streamFailure.completed, false);
    assert.equal(streamFailure.transportError, true);

    const transportFailure = await executeBusinessRequest(statusConfiguration, {
        fetchImpl: async () => { throw new Error('secret target must not escape'); },
    });
    assert.deepEqual(transportFailure, {
        completed: false,
        transportError: true,
        assertionFailure: false,
        latencyMs: null,
    });
});

test('the runner emits schema v3 and uses fixed-delay cadence without catch-up bursts', async () => {
    const documents = documentsFor({
        method: 'POST',
        outcome: { strategy: 'json_field_equals', field: 'outcome.ok', value: true },
    });
    documents.plan.scenarios[0].profile.request_interval_ms = 100;
    refreshScenarioBinding(documents);
    const configuration = configurationFor(documents);
    const startedAt = new Date();
    let monotonicMilliseconds = 0;
    const sleepDurations = [];
    const requests = [];
    const result = await executeScenario(configuration, {
        fetchImpl: async (url, options) => {
            requests.push({ url: url.href, method: options.method });
            monotonicMilliseconds += 250;
            return jsonResponse({ outcome: { ok: true } });
        },
        now: () => new Date(startedAt.getTime() + monotonicMilliseconds),
        monotonicNow: () => monotonicMilliseconds,
        sleepImpl: async (milliseconds) => {
            sleepDurations.push(milliseconds);
            monotonicMilliseconds += milliseconds;
        },
    });

    assert.deepEqual(Object.keys(result), RESULT_FIELDS);
    assert.equal(result.schema_version, 3);
    assert.equal(result.fixture_hash, documents.fixtureHash);
    assert.equal(result.target_origin_hash, sha256(TEST_ORIGIN));
    assert.equal(result.request_timeout_ms, 500);
    assert.equal(result.attempted_requests, 3);
    assert.equal(result.completed_requests, 3);
    assert.equal(result.transport_errors, 0);
    assert.equal(result.assertion_failures, 0);
    assert.ok(sleepDurations.filter((duration) => duration === 100).length >= 2);
    assert.ok(requests.every(({ url, method }) => url === `${TEST_ORIGIN}/success` && method === 'POST'));
});

async function cliFixture(temporaryDirectory, fixtureMutator = null) {
    const runnerPath = resolve('scripts/capacity/p0-006-runner.mjs');
    const actualRunnerHash = sha256(await readFile(runnerPath));
    const documents = documentsFor({ runnerHash: actualRunnerHash });
    if (fixtureMutator !== null) {
        fixtureMutator(documents.fixtures);
    }
    const fixtureText = JSON.stringify(documents.fixtures);
    Object.assign(documents, bindFixture(documents.plan, documents.fixtures, fixtureText));
    const planPath = join(temporaryDirectory, 'plan.json');
    const fixturesPath = join(temporaryDirectory, 'fixtures.json');
    const outputPath = join(temporaryDirectory, 'result.json');
    await writeFile(planPath, JSON.stringify(documents.plan));
    await writeFile(fixturesPath, fixtureText);

    return { ...documents, planPath, fixturesPath, outputPath };
}

test('runCli writes a complete v3 artifact without exposing the approved origin', async () => {
    const temporaryDirectory = await mkdtemp(join(tmpdir(), 'p0-006-node-runner-'));
    const originalFetch = globalThis.fetch;
    try {
        const files = await cliFixture(temporaryDirectory);
        globalThis.fetch = async () => jsonResponse({ ok: true });
        let stdout = '';
        let stderr = '';
        const exitCode = await runCli({
            argv: [
                '--plan', files.planPath,
                '--fixtures', files.fixturesPath,
                '--scenario', 'node_runner_test',
                '--output', files.outputPath,
            ],
            stdout: { write: (value) => { stdout += value; } },
            stderr: { write: (value) => { stderr += value; } },
        });
        const resultText = await readFile(files.outputPath, 'utf8');
        const result = JSON.parse(resultText);

        assert.equal(exitCode, 0);
        assert.equal(stderr, '');
        assert.equal(JSON.parse(stdout).status, 'completed');
        assert.deepEqual(Object.keys(result), RESULT_FIELDS);
        assert.equal(result.schema_version, 3);
        assert.equal(result.fixture_hash, files.fixtureHash);
        assert.equal(result.target_origin_hash, sha256(TEST_ORIGIN));
        assert.equal(resultText.includes(TEST_ORIGIN), false);
    } finally {
        globalThis.fetch = originalFetch;
        await rm(temporaryDirectory, { recursive: true, force: true });
    }
});

test('runCli preserves failed results, existing files, and secret-free diagnostics', async () => {
    const temporaryDirectory = await mkdtemp(join(tmpdir(), 'p0-006-node-runner-'));
    const originalFetch = globalThis.fetch;
    try {
        const failedFiles = await cliFixture(temporaryDirectory);
        globalThis.fetch = async () => jsonResponse({ unavailable: true }, 503);
        let stdout = '';
        const failedExitCode = await runCli({
            argv: [
                '--plan', failedFiles.planPath,
                '--fixtures', failedFiles.fixturesPath,
                '--scenario', 'node_runner_test',
                '--output', failedFiles.outputPath,
            ],
            stdout: { write: (value) => { stdout += value; } },
            stderr: { write: () => {} },
        });
        const failedResult = JSON.parse(await readFile(failedFiles.outputPath, 'utf8'));
        assert.equal(failedExitCode, 1);
        assert.equal(JSON.parse(stdout).status, 'failed_closed');
        assert.ok(failedResult.assertion_failures >= 1);

        const sentinel = '{"existing":"must-remain"}\n';
        await writeFile(failedFiles.outputPath, sentinel);
        const overwriteExitCode = await runCli({
            argv: [
                '--plan', failedFiles.planPath,
                '--fixtures', failedFiles.fixturesPath,
                '--scenario', 'node_runner_test',
                '--output', failedFiles.outputPath,
            ],
            stdout: { write: () => {} },
            stderr: { write: () => {} },
        });
        assert.equal(overwriteExitCode, 2);
        assert.equal(await readFile(failedFiles.outputPath, 'utf8'), sentinel);

        let flagError = '';
        const publicFlagExitCode = await runCli({
            argv: ['--test-allow-http-localhost'],
            stdout: { write: () => {} },
            stderr: { write: (value) => { flagError += value; } },
        });
        assert.equal(publicFlagExitCode, 2);
        assert.match(flagError, /ARGUMENT_UNKNOWN/);

        const secretDirectory = await mkdtemp(join(tmpdir(), 'p0-006-secret-runner-'));
        try {
            const secretFiles = await cliFixture(secretDirectory, (fixtures) => {
                fixtures.scenarios.node_runner_test.route_uri = '/tampered-route';
                fixtures.scenarios.node_runner_test.headers.Cookie = 'sensitive-cookie-sentinel';
                fixtures.scenarios.node_runner_test.query.signature = 'sensitive-signature-sentinel';
            });
            let diagnostic = '';
            const secretExitCode = await runCli({
                argv: [
                    '--plan', secretFiles.planPath,
                    '--fixtures', secretFiles.fixturesPath,
                    '--scenario', 'node_runner_test',
                    '--output', secretFiles.outputPath,
                ],
                stdout: { write: (value) => { diagnostic += value; } },
                stderr: { write: (value) => { diagnostic += value; } },
            });
            assert.equal(secretExitCode, 2);
            assert.match(diagnostic, /FIXTURE_ROUTE_URI_MISMATCH/);
            assert.equal(diagnostic.includes('sensitive-cookie-sentinel'), false);
            assert.equal(diagnostic.includes('sensitive-signature-sentinel'), false);
        } finally {
            await rm(secretDirectory, { recursive: true, force: true });
        }
    } finally {
        globalThis.fetch = originalFetch;
        await rm(temporaryDirectory, { recursive: true, force: true });
    }
});
