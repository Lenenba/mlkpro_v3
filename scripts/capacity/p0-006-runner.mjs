#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { readFile, stat, writeFile } from 'node:fs/promises';
import { dirname, extname, resolve } from 'node:path';
import { performance } from 'node:perf_hooks';
import { fileURLToPath } from 'node:url';

const RESULT_FIELDS = Object.freeze([
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
]);

const RESULT_SCHEMA_VERSION = 3;
const MAX_PLAN_BYTES = 2 * 1024 * 1024;
const MAX_FIXTURE_BYTES = 256 * 1024;
const MAX_JSON_RESPONSE_BYTES = 1024 * 1024;
const SHA256_PATTERN = /^[a-f0-9]{64}$/;
const SAFE_REQUEST_HEADERS = new Set([
    'accept',
    'authorization',
    'content-type',
    'cookie',
    'idempotency-key',
    'x-csrf-token',
    'x-xsrf-token',
]);

export class RunnerFailure extends Error {
    constructor(code) {
        super(code);
        this.name = 'RunnerFailure';
        this.code = code;
    }
}

function fail(code) {
    throw new RunnerFailure(code);
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function requiredString(value, code) {
    if (typeof value !== 'string' || value.trim() === '') {
        fail(code);
    }

    return value.trim();
}

function requiredInteger(value, minimum, code) {
    if (!Number.isInteger(value) || value < minimum) {
        fail(code);
    }

    return value;
}

function containsPlaceholder(value) {
    if (typeof value === 'string') {
        return /^(?:REPLACE|COPY_FROM|INSERT)_/i.test(value.trim()) || /^<[^>]+>$/.test(value.trim());
    }
    if (Array.isArray(value)) {
        return value.some((item) => containsPlaceholder(item));
    }
    if (isObject(value)) {
        return Object.values(value).some((item) => containsPlaceholder(item));
    }

    return false;
}

function canonicalizeManifestValue(value) {
    if (Array.isArray(value)) {
        return value.map((item) => canonicalizeManifestValue(item));
    }
    if (!isObject(value)) {
        return value;
    }

    return Object.fromEntries(
        Object.keys(value)
            .sort()
            .map((key) => [key, canonicalizeManifestValue(value[key])])
    );
}

function phpJsonString(value) {
    return JSON.stringify(value)
        .replaceAll('/', '\\/')
        .replace(/[\u0080-\uFFFF]/g, (character) => `\\u${character.charCodeAt(0).toString(16).padStart(4, '0')}`);
}

function phpJsonEncode(value) {
    if (value === null) {
        return 'null';
    }
    if (typeof value === 'string') {
        return phpJsonString(value);
    }
    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
        return JSON.stringify(value);
    }
    if (Array.isArray(value)) {
        return `[${value.map((item) => phpJsonEncode(item)).join(',')}]`;
    }
    if (isObject(value)) {
        return `{${Object.keys(value)
            .sort()
            .map((key) => `${phpJsonString(key)}:${phpJsonEncode(value[key])}`)
            .join(',')}}`;
    }

    fail('MANIFEST_VALUE_INVALID');
}

export function calculateManifestHash(scenario) {
    if (!isObject(scenario)) {
        fail('PLAN_SCENARIO_INVALID');
    }

    const manifest = {};
    for (const field of [
        'key',
        'method',
        'route_names',
        'route_uris',
        'accepted_status_codes',
        'protocol',
        'profile',
        'safety',
        'targets',
        'blocker',
    ]) {
        if (Object.hasOwn(scenario, field)) {
            manifest[field] = scenario[field];
        }
    }

    return createHash('sha256')
        .update(phpJsonEncode(canonicalizeManifestValue(manifest)))
        .digest('hex');
}

function normalizedFingerprintString(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function phpBoolean(value) {
    if (value === true || value === 1) {
        return true;
    }
    if (typeof value !== 'string') {
        return false;
    }

    return ['1', 'true', 'on', 'yes'].includes(value.trim().toLowerCase());
}

function normalizedExclusions(value) {
    const exclusions = Array.isArray(value)
        ? value
        : typeof value === 'string'
            ? value.split(',')
            : null;
    if (exclusions === null) {
        return null;
    }

    return exclusions
        .map((exclusion) => typeof exclusion === 'string' ? exclusion.trim() : '')
        .filter((exclusion) => exclusion !== '' && exclusion !== '0');
}

export function calculateBaselineFingerprint(baselineContext) {
    if (!isObject(baselineContext)) {
        fail('BASELINE_CONTEXT_INVALID');
    }

    const period = isObject(baselineContext.period) ? baselineContext.period : {};
    const identity = {
        release: normalizedFingerprintString(baselineContext.release),
        run_id: normalizedFingerprintString(baselineContext.run_id),
        environment: normalizedFingerprintString(baselineContext.environment),
        commit: normalizedFingerprintString(baselineContext.commit),
        period: {
            started_at: normalizedFingerprintString(period.started_at),
            ended_at: normalizedFingerprintString(period.ended_at),
        },
        traffic: normalizedFingerprintString(baselineContext.traffic),
        runner: normalizedFingerprintString(baselineContext.runner),
        runner_hash: typeof baselineContext.runner_hash === 'string'
            ? baselineContext.runner_hash.trim().toLowerCase()
            : null,
        fixture_hash: typeof baselineContext.fixture_hash === 'string'
            ? baselineContext.fixture_hash.trim().toLowerCase()
            : null,
        allowed_origins: Array.isArray(baselineContext.allowed_origins)
            ? [...new Set(baselineContext.allowed_origins.map((origin) => normalizedOrigin(origin)))].sort()
            : [],
        exclusions: normalizedExclusions(baselineContext.exclusions),
        mode: normalizedFingerprintString(baselineContext.mode),
        representative: phpBoolean(baselineContext.representative),
        approved: phpBoolean(baselineContext.approved),
        approval_reference: normalizedFingerprintString(baselineContext.approval_reference),
        queue_canaries_verified: phpBoolean(baselineContext.queue_canaries_verified),
        isolated_tenant_verified: phpBoolean(baselineContext.isolated_tenant_verified),
        owner: normalizedFingerprintString(baselineContext.owner),
        validator: normalizedFingerprintString(baselineContext.validator),
    };

    return createHash('sha256')
        .update(phpJsonEncode(canonicalizeManifestValue(identity)))
        .digest('hex');
}

function durationSeconds(value, allowZero, code) {
    if (typeof value !== 'string') {
        fail(code);
    }

    const match = value.trim().toLowerCase().match(/^(\d+)(ms|s|m|h)$/);
    if (match === null) {
        fail(code);
    }

    const amount = Number.parseInt(match[1], 10);
    const seconds = match[2] === 'ms'
        ? (amount % 1000 === 0 ? amount / 1000 : Number.NaN)
        : match[2] === 's'
            ? amount
            : match[2] === 'm'
                ? amount * 60
                : amount * 3600;

    if (!Number.isInteger(seconds) || seconds < (allowZero ? 0 : 1)) {
        fail(code);
    }

    return seconds;
}

function normalizedOrigin(value) {
    let url;
    try {
        url = new URL(requiredString(value, 'ORIGIN_REQUIRED'));
    } catch (error) {
        if (error instanceof RunnerFailure) {
            throw error;
        }
        fail('ORIGIN_INVALID');
    }

    if (url.username !== '' || url.password !== '' || url.pathname !== '/' || url.search !== '' || url.hash !== '') {
        fail('ORIGIN_INVALID');
    }

    if (url.protocol !== 'https:') {
        fail('BASE_URL_HTTPS_REQUIRED');
    }

    return url.origin;
}

function validateHeaders(headers, code) {
    if (!isObject(headers)) {
        fail(code);
    }

    const normalized = new Map();
    for (const [name, value] of Object.entries(headers)) {
        const normalizedName = typeof name === 'string' ? name.trim().toLowerCase() : '';
        const forbiddenHeader = [
            'host',
            'forwarded',
            'x-http-method-override',
            'x-method-override',
            'x-rewrite-url',
            'connection',
            'transfer-encoding',
        ].includes(normalizedName)
            || normalizedName.startsWith('x-forwarded-')
            || normalizedName.startsWith('x-original-');
        if (typeof name !== 'string'
            || name.trim() === ''
            || name.trim().toLowerCase() === '_method'
            || typeof value !== 'string'
            || /[\r\n]/.test(name)
            || /[\r\n]/.test(value)
            || containsPlaceholder(value)
            || forbiddenHeader
            || !SAFE_REQUEST_HEADERS.has(normalizedName)) {
            fail(code);
        }

        normalized.set(normalizedName, [name.trim(), value]);
    }

    return normalized;
}

function assertSafePathname(pathname, code) {
    if (typeof pathname !== 'string'
        || !pathname.startsWith('/')
        || pathname.includes('?')
        || pathname.includes('#')) {
        fail(code);
    }

    for (const segment of pathname.split('/')) {
        let decoded = segment;
        for (let pass = 0; pass < 5; pass += 1) {
            if (decoded === '.' || decoded === '..') {
                fail(code);
            }

            let next;
            try {
                next = decodeURIComponent(decoded);
            } catch {
                fail(code);
            }
            if (next.includes('/') || next.includes('\\') || next.includes('\0')) {
                fail(code);
            }
            if (next === decoded) {
                break;
            }
            decoded = next;
        }
        if (decoded === '.'
            || decoded === '..'
            || /%(?:2e|2f|5c)/i.test(decoded)) {
            fail(code);
        }
    }
}

function resolveRoute(routeUri, pathParameters) {
    assertSafePathname(routeUri, 'FIXTURE_ROUTE_URI_INVALID');
    if (!isObject(pathParameters)) {
        fail('FIXTURE_PATH_PARAMETERS_INVALID');
    }

    const placeholders = [...routeUri.matchAll(/\{([^}?]+)\??\}/g)].map((match) => match[1]);
    if (Object.keys(pathParameters).some((key) => !placeholders.includes(key))) {
        fail('FIXTURE_PATH_PARAMETERS_INVALID');
    }

    let resolvedRoute = routeUri;
    for (const placeholder of placeholders) {
        const value = pathParameters[placeholder];
        if ((typeof value !== 'string' && typeof value !== 'number')
            || String(value).trim() === ''
            || containsPlaceholder(String(value))) {
            fail('FIXTURE_PATH_PARAMETERS_INVALID');
        }
        resolvedRoute = resolvedRoute.replace(
            new RegExp(`\\{${placeholder}\\??\\}`, 'g'),
            encodeURIComponent(String(value))
        );
    }

    if (/[{}]/.test(resolvedRoute) || !resolvedRoute.startsWith('/')) {
        fail('FIXTURE_ROUTE_URI_INVALID');
    }
    assertSafePathname(resolvedRoute, 'FIXTURE_ROUTE_URI_INVALID');

    return resolvedRoute;
}

function appendQuery(url, query) {
    if (!isObject(query)) {
        fail('FIXTURE_QUERY_INVALID');
    }

    for (const [name, value] of Object.entries(query)) {
        if (typeof name !== 'string'
            || name.trim() === ''
            || name.trim().toLowerCase() === '_method'
            || !['string', 'number', 'boolean'].includes(typeof value)
            || String(value).trim() === ''
            || containsPlaceholder(String(value))) {
            fail('FIXTURE_QUERY_INVALID');
        }
        url.searchParams.append(name, String(value));
    }
}

function validateOutcome(outcome) {
    if (!isObject(outcome)
        || !['status_code', 'json_key_present', 'json_field_equals'].includes(outcome.strategy)) {
        fail('PLAN_OUTCOME_INVALID');
    }

    if (outcome.strategy !== 'status_code') {
        requiredString(outcome.field, 'PLAN_OUTCOME_INVALID');
    }
    if (outcome.strategy === 'json_field_equals' && !Object.hasOwn(outcome, 'value')) {
        fail('PLAN_OUTCOME_INVALID');
    }

    return outcome;
}

function valueAtPath(payload, field) {
    let current = payload;
    for (const segment of field.split('.')) {
        if (!isObject(current) && !Array.isArray(current)) {
            return { exists: false, value: undefined };
        }
        if (!Object.hasOwn(current, segment)) {
            return { exists: false, value: undefined };
        }
        current = current[segment];
    }

    return { exists: true, value: current };
}

function sameJsonValue(left, right) {
    return JSON.stringify(left) === JSON.stringify(right);
}

export function validateRunConfiguration({
    plan,
    fixtures,
    scenarioKey,
    scriptHash,
    fixtureHash,
}) {
    if (!isObject(plan) || plan.status !== 'ready_for_approved_harness') {
        fail('PLAN_NOT_READY');
    }
    if (!isObject(plan.preflight)
        || plan.preflight.ready !== true
        || !Array.isArray(plan.preflight.issues)
        || plan.preflight.issues.length !== 0
        || (plan.preflight.errors !== undefined
            && (!Array.isArray(plan.preflight.errors) || plan.preflight.errors.length !== 0))
        || !Array.isArray(plan.configuration_issues)
        || plan.configuration_issues.length !== 0
        || !Array.isArray(plan.issues)
        || plan.issues.length !== 0) {
        fail('PLAN_PREFLIGHT_NOT_READY');
    }

    const normalizedScriptHash = requiredString(scriptHash, 'RUNNER_HASH_REQUIRED').toLowerCase();
    if (!SHA256_PATTERN.test(normalizedScriptHash)) {
        fail('RUNNER_HASH_INVALID');
    }
    const planRunnerHash = requiredString(plan.runner_hash, 'PLAN_RUNNER_HASH_REQUIRED').toLowerCase();
    if (!SHA256_PATTERN.test(planRunnerHash) || planRunnerHash !== normalizedScriptHash) {
        fail('RUNNER_HASH_MISMATCH');
    }
    const normalizedFixtureHash = requiredString(fixtureHash, 'FIXTURE_HASH_REQUIRED').toLowerCase();
    const planFixtureHash = requiredString(plan.fixture_hash, 'PLAN_FIXTURE_HASH_REQUIRED').toLowerCase();
    if (!SHA256_PATTERN.test(normalizedFixtureHash)
        || !SHA256_PATTERN.test(planFixtureHash)
        || normalizedFixtureHash !== planFixtureHash) {
        fail('FIXTURE_HASH_MISMATCH');
    }
    if (!isObject(plan.runner_result_contract)
        || plan.runner_result_contract.schema_version !== RESULT_SCHEMA_VERSION) {
        fail('PLAN_RESULT_SCHEMA_INVALID');
    }

    const baselineFingerprint = requiredString(
        plan.baseline_fingerprint,
        'BASELINE_FINGERPRINT_REQUIRED'
    ).toLowerCase();
    if (!SHA256_PATTERN.test(baselineFingerprint)) {
        fail('BASELINE_FINGERPRINT_INVALID');
    }

    const selectedScenarioKey = requiredString(scenarioKey, 'SCENARIO_REQUIRED');
    const scenarios = Array.isArray(plan.scenarios) ? plan.scenarios : [];
    const matchingScenarios = scenarios.filter((candidate) => isObject(candidate) && candidate.key === selectedScenarioKey);
    if (matchingScenarios.length !== 1) {
        fail('SCENARIO_NOT_UNIQUE');
    }
    const scenario = matchingScenarios[0];
    const manifestHash = requiredString(scenario.manifest_hash, 'MANIFEST_HASH_REQUIRED').toLowerCase();
    if (!SHA256_PATTERN.test(manifestHash)) {
        fail('MANIFEST_HASH_INVALID');
    }
    if (calculateManifestHash(scenario) !== manifestHash) {
        fail('MANIFEST_HASH_MISMATCH');
    }

    const baselineContext = plan.baseline_context;
    if (!isObject(baselineContext)
        || baselineContext.status !== 'complete'
        || !['staging', 'production_read_only'].includes(baselineContext.mode)) {
        fail('BASELINE_CONTEXT_INVALID');
    }
    if (calculateBaselineFingerprint(baselineContext) !== baselineFingerprint) {
        fail('BASELINE_FINGERPRINT_MISMATCH');
    }
    if (baselineContext.representative !== true
        || baselineContext.approved !== true
        || baselineContext.queue_canaries_verified !== true
        || typeof baselineContext.isolated_tenant_verified !== 'boolean'
        || !Array.isArray(baselineContext.exclusions)) {
        fail('BASELINE_CONTEXT_INVALID');
    }
    for (const value of [
        baselineContext.release,
        baselineContext.run_id,
        baselineContext.environment,
        baselineContext.commit,
        baselineContext.traffic,
        baselineContext.runner,
        baselineContext.runner_hash,
        baselineContext.fixture_hash,
        baselineContext.approval_reference,
        baselineContext.owner,
        baselineContext.validator,
        baselineContext.period?.started_at,
        baselineContext.period?.ended_at,
    ]) {
        requiredString(value, 'BASELINE_CONTEXT_INVALID');
    }
    if (!SHA256_PATTERN.test(baselineContext.runner_hash.toLowerCase())
        || !SHA256_PATTERN.test(baselineContext.fixture_hash.toLowerCase())
        || baselineContext.fixture_hash.toLowerCase() !== normalizedFixtureHash
        || !Array.isArray(baselineContext.allowed_origins)
        || baselineContext.allowed_origins.length === 0) {
        fail('BASELINE_CONTEXT_INVALID');
    }
    const approvedOrigins = [...new Set(baselineContext.allowed_origins.map((origin) => normalizedOrigin(origin)))].sort();
    const planApprovedOrigins = Array.isArray(plan.allowed_origins)
        ? [...new Set(plan.allowed_origins.map((origin) => normalizedOrigin(origin)))].sort()
        : [];
    if (!isObject(plan.period)
        || plan.run_id !== baselineContext.run_id
        || plan.environment !== baselineContext.environment
        || plan.commit !== baselineContext.commit
        || plan.runner !== baselineContext.runner
        || plan.runner_hash.toLowerCase() !== baselineContext.runner_hash.toLowerCase()
        || plan.fixture_hash.toLowerCase() !== baselineContext.fixture_hash.toLowerCase()
        || JSON.stringify(planApprovedOrigins) !== JSON.stringify(approvedOrigins)
        || plan.period.started_at !== baselineContext.period.started_at
        || plan.period.ended_at !== baselineContext.period.ended_at) {
        fail('PLAN_BASELINE_IDENTITY_MISMATCH');
    }
    if (!isObject(scenario.safety)
        || !['read_only', 'controlled_write'].includes(scenario.safety.mode)) {
        fail('SCENARIO_SAFETY_INVALID');
    }
    if (baselineContext.mode === 'production_read_only' && scenario.safety.mode !== 'read_only') {
        fail('PRODUCTION_WRITE_SCENARIO_FORBIDDEN');
    }
    if (scenario.safety.mode === 'controlled_write' && baselineContext.isolated_tenant_verified !== true) {
        fail('ISOLATED_TENANT_REQUIRED');
    }
    if (isObject(scenario.blocker)
        && typeof scenario.blocker.reason === 'string'
        && scenario.blocker.reason.trim() !== '') {
        fail('SCENARIO_BLOCKED');
    }

    if (!isObject(fixtures)
        || fixtures.schema_version !== 2
        || !isObject(fixtures.scenarios)
        || Object.keys(fixtures).some((field) => !['schema_version', 'base_url', 'scenarios'].includes(field))) {
        fail('FIXTURE_SCHEMA_INVALID');
    }
    const fixture = fixtures.scenarios[selectedScenarioKey];
    if (!isObject(fixture)) {
        fail('FIXTURE_SCENARIO_MISSING');
    }
    if (Object.keys(fixture).some((field) => ![
        'method',
        'route_name',
        'route_uri',
        'path_parameters',
        'query',
        'headers',
        'body',
    ].includes(field))) {
        fail('FIXTURE_FIELDS_INVALID');
    }

    const method = requiredString(scenario.method, 'PLAN_METHOD_INVALID').toUpperCase();
    if (!['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
        fail('PLAN_METHOD_INVALID');
    }
    if (requiredString(fixture.method, 'FIXTURE_METHOD_REQUIRED').toUpperCase() !== method) {
        fail('FIXTURE_METHOD_MISMATCH');
    }
    const routeName = requiredString(fixture.route_name, 'FIXTURE_ROUTE_NAME_REQUIRED');
    if (!Array.isArray(scenario.route_names) || !scenario.route_names.includes(routeName)) {
        fail('FIXTURE_ROUTE_NAME_MISMATCH');
    }
    if (!isObject(scenario.route_uris)) {
        fail('PLAN_ROUTE_URIS_INVALID');
    }
    const routeUri = requiredString(scenario.route_uris[routeName], 'PLAN_ROUTE_URI_MISSING');
    if (requiredString(fixture.route_uri, 'FIXTURE_ROUTE_URI_REQUIRED') !== routeUri) {
        fail('FIXTURE_ROUTE_URI_MISMATCH');
    }

    if (!isObject(scenario.protocol)
        || scenario.protocol.transport !== 'http'
        || scenario.protocol.request_format !== 'json'
        || scenario.protocol.follow_redirects !== false
        || scenario.protocol.runner_policy !== 'external_approved_harness') {
        fail('PLAN_PROTOCOL_INVALID');
    }
    const outcome = validateOutcome(scenario.protocol.outcome);
    const acceptedStatusCodes = Array.isArray(scenario.accepted_status_codes)
        ? scenario.accepted_status_codes
        : [];
    if (acceptedStatusCodes.length === 0
        || acceptedStatusCodes.some((status) => !Number.isInteger(status) || status < 100 || status > 599)) {
        fail('PLAN_STATUS_CODES_INVALID');
    }

    const baseOrigin = normalizedOrigin(fixtures.base_url);
    if (!approvedOrigins.includes(baseOrigin)) {
        fail('BASE_URL_NOT_ALLOWLISTED');
    }

    const resolvedRoute = resolveRoute(routeUri, fixture.path_parameters ?? {});
    const requestUrl = new URL(resolvedRoute, `${baseOrigin}/`);
    const expectedPathname = requestUrl.pathname;
    appendQuery(requestUrl, fixture.query ?? {});
    if (requestUrl.origin !== baseOrigin || requestUrl.pathname !== expectedPathname) {
        fail('REQUEST_ORIGIN_CHANGED');
    }

    const planHeaders = validateHeaders(scenario.protocol.headers, 'PLAN_HEADERS_INVALID');
    const fixtureHeaders = validateHeaders(fixture.headers ?? {}, 'FIXTURE_HEADERS_INVALID');
    for (const protectedHeader of ['accept', 'content-type']) {
        if (fixtureHeaders.has(protectedHeader)) {
            fail('FIXTURE_PROTECTED_HEADER_OVERRIDE');
        }
    }
    const headers = new Headers();
    for (const [, [name, value]] of [...planHeaders, ...fixtureHeaders]) {
        headers.set(name, value);
    }

    const authentication = requiredString(scenario.protocol.authentication, 'PLAN_AUTHENTICATION_INVALID');
    if (authentication !== 'public' && !headers.has('cookie')) {
        fail('FIXTURE_SESSION_COOKIE_REQUIRED');
    }
    if (scenario.protocol.csrf === true
        && !headers.has('x-csrf-token')
        && !headers.has('x-xsrf-token')) {
        fail('FIXTURE_CSRF_REQUIRED');
    }

    let body;
    if (['GET', 'HEAD'].includes(method)) {
        if (fixture.body !== undefined && fixture.body !== null) {
            fail('FIXTURE_BODY_NOT_ALLOWED');
        }
    } else {
        if ((!isObject(fixture.body) && !Array.isArray(fixture.body)) || containsPlaceholder(fixture.body)) {
            fail('FIXTURE_BODY_REQUIRED');
        }
        if (isObject(fixture.body)
            && Object.keys(fixture.body).some((key) => key.toLowerCase() === '_method')) {
            fail('FIXTURE_METHOD_OVERRIDE_FORBIDDEN');
        }
        body = JSON.stringify(fixture.body);
    }

    if (!isObject(scenario.profile)) {
        fail('PLAN_PROFILE_INVALID');
    }
    const virtualUsers = requiredInteger(scenario.profile.virtual_users, 1, 'PLAN_VIRTUAL_USERS_INVALID');
    const plannedDurationSeconds = durationSeconds(scenario.profile.duration, false, 'PLAN_DURATION_INVALID');
    const rampUpSeconds = durationSeconds(scenario.profile.ramp_up, true, 'PLAN_RAMP_UP_INVALID');
    if (rampUpSeconds >= plannedDurationSeconds) {
        fail('PLAN_RAMP_UP_INVALID');
    }
    const minimumCompletedRequests = requiredInteger(
        scenario.profile.minimum_completed_requests,
        1,
        'PLAN_MINIMUM_REQUESTS_INVALID'
    );
    const requestIntervalMs = requiredInteger(
        scenario.profile.request_interval_ms,
        1,
        'PLAN_REQUEST_INTERVAL_INVALID'
    );
    const requestTimeoutMs = requiredInteger(
        scenario.profile.request_timeout_ms,
        500,
        'PLAN_REQUEST_TIMEOUT_INVALID'
    );
    if (requestTimeoutMs > 60_000) {
        fail('PLAN_REQUEST_TIMEOUT_INVALID');
    }

    const period = isObject(plan.period) ? plan.period : {};
    const periodStartedAt = new Date(requiredString(period.started_at, 'PLAN_PERIOD_INVALID'));
    const periodEndedAt = new Date(requiredString(period.ended_at, 'PLAN_PERIOD_INVALID'));
    if (!Number.isFinite(periodStartedAt.getTime())
        || !Number.isFinite(periodEndedAt.getTime())
        || periodStartedAt >= periodEndedAt) {
        fail('PLAN_PERIOD_INVALID');
    }

    return Object.freeze({
        runId: requiredString(plan.run_id, 'PLAN_RUN_ID_REQUIRED'),
        environment: requiredString(plan.environment, 'PLAN_ENVIRONMENT_REQUIRED'),
        commit: requiredString(plan.commit, 'PLAN_COMMIT_REQUIRED'),
        runner: requiredString(plan.runner, 'PLAN_RUNNER_REQUIRED'),
        runnerHash: normalizedScriptHash,
        scenarioKey: selectedScenarioKey,
        manifestHash,
        fixtureHash: normalizedFixtureHash,
        baselineFingerprint,
        targetOriginHash: createHash('sha256').update(baseOrigin).digest('hex'),
        method,
        requestUrl,
        headers,
        body,
        outcome,
        acceptedStatusCodes,
        virtualUsers,
        durationSeconds: plannedDurationSeconds,
        rampUpSeconds,
        minimumCompletedRequests,
        requestIntervalMs,
        requestTimeoutMs,
        periodStartedAt,
        periodEndedAt,
    });
}

async function consumeBoundedBody(response) {
    if (response.body === null) {
        return { withinLimit: true, bytes: Buffer.alloc(0) };
    }

    const reader = response.body.getReader();
    const chunks = [];
    let totalBytes = 0;
    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) {
                break;
            }
            totalBytes += value.byteLength;
            if (totalBytes > MAX_JSON_RESPONSE_BYTES) {
                await reader.cancel();
                return { withinLimit: false, bytes: null };
            }
            chunks.push(Buffer.from(value));
        }
    } finally {
        reader.releaseLock();
    }

    return { withinLimit: true, bytes: Buffer.concat(chunks, totalBytes) };
}

function outcomeMatches(body, outcome) {
    if (outcome.strategy === 'status_code') {
        return true;
    }

    let decoded;
    try {
        decoded = JSON.parse(body.toString('utf8'));
    } catch {
        return false;
    }
    const selected = valueAtPath(decoded, outcome.field);
    if (outcome.strategy === 'json_key_present') {
        return selected.exists;
    }

    return selected.exists && sameJsonValue(selected.value, outcome.value);
}

export async function executeBusinessRequest(configuration, {
    fetchImpl = globalThis.fetch,
    timeoutMs = configuration.requestTimeoutMs,
} = {}) {
    const startedAt = performance.now();
    const abortController = new AbortController();
    const timeout = setTimeout(() => abortController.abort(), Math.max(1, timeoutMs));

    try {
        const response = await fetchImpl(configuration.requestUrl, {
            method: configuration.method,
            headers: configuration.headers,
            body: configuration.body,
            redirect: 'manual',
            signal: abortController.signal,
        });
        const body = await consumeBoundedBody(response);
        const latencyMs = Math.max(0, performance.now() - startedAt);
        const acceptedStatus = configuration.acceptedStatusCodes.includes(response.status);
        const acceptedOutcome = acceptedStatus && body.withinLimit
            ? outcomeMatches(body.bytes, configuration.outcome)
            : false;

        return {
            completed: true,
            transportError: false,
            assertionFailure: !body.withinLimit || !acceptedStatus || !acceptedOutcome,
            latencyMs,
        };
    } catch {
        return {
            completed: false,
            transportError: true,
            assertionFailure: false,
            latencyMs: null,
        };
    } finally {
        clearTimeout(timeout);
    }
}

function sleep(milliseconds) {
    return new Promise((resolveSleep) => setTimeout(resolveSleep, Math.max(0, milliseconds)));
}

function percentile(values, percentileValue) {
    if (values.length === 0) {
        return 0;
    }
    const sorted = [...values].sort((left, right) => left - right);
    const index = (sorted.length - 1) * percentileValue;
    const lower = Math.floor(index);
    const upper = Math.ceil(index);
    const interpolated = lower === upper
        ? sorted[lower]
        : sorted[lower] + ((sorted[upper] - sorted[lower]) * (index - lower));

    return Math.round(interpolated * 10) / 10;
}

function latencyDistribution(values) {
    return {
        p50: percentile(values, 0.50),
        p95: percentile(values, 0.95),
        p99: percentile(values, 0.99),
        max: values.length === 0 ? 0 : Math.round(Math.max(...values) * 10) / 10,
    };
}

export async function executeScenario(configuration, {
    fetchImpl = globalThis.fetch,
    now = () => new Date(),
    monotonicNow = () => performance.now(),
    sleepImpl = sleep,
} = {}) {
    const wallStartedAt = now();
    if (!(wallStartedAt instanceof Date)
        || !Number.isFinite(wallStartedAt.getTime())
        || wallStartedAt < configuration.periodStartedAt
        || wallStartedAt.getTime() + (configuration.durationSeconds * 1000) > configuration.periodEndedAt.getTime()) {
        fail('RUN_OUTSIDE_APPROVED_PERIOD');
    }

    const monotonicStartedAt = monotonicNow();
    const deadline = monotonicStartedAt + (configuration.durationSeconds * 1000);
    const counters = {
        attempted: 0,
        completed: 0,
        transportErrors: 0,
        assertionFailures: 0,
        latencies: [],
    };

    const worker = async (workerIndex) => {
        const startDelay = configuration.virtualUsers === 1
            ? 0
            : (configuration.rampUpSeconds * 1000 * workerIndex) / (configuration.virtualUsers - 1);
        await sleepImpl(Math.max(0, (monotonicStartedAt + startDelay) - monotonicNow()));
        let nextRequestAt = monotonicStartedAt + startDelay;

        while (monotonicNow() < deadline) {
            const wait = nextRequestAt - monotonicNow();
            if (wait > 0) {
                await sleepImpl(wait);
            }
            const remainingMs = deadline - monotonicNow();
            if (remainingMs <= 0) {
                break;
            }

            counters.attempted += 1;
            const observation = await executeBusinessRequest(configuration, {
                fetchImpl,
                timeoutMs: Math.min(configuration.requestTimeoutMs, Math.max(1, remainingMs)),
            });
            if (observation.completed) {
                counters.completed += 1;
                counters.latencies.push(observation.latencyMs);
            }
            if (observation.transportError) {
                counters.transportErrors += 1;
            }
            if (observation.assertionFailure) {
                counters.assertionFailures += 1;
            }
            // Fixed-delay cadence: a slow response never creates a catch-up burst.
            nextRequestAt = monotonicNow() + configuration.requestIntervalMs;
        }
    };

    await Promise.all(Array.from(
        { length: configuration.virtualUsers },
        (_, workerIndex) => worker(workerIndex)
    ));
    const wallEndedAt = now();

    const result = {
        schema_version: RESULT_SCHEMA_VERSION,
        run_id: configuration.runId,
        environment: configuration.environment,
        commit: configuration.commit,
        scenario_key: configuration.scenarioKey,
        manifest_hash: configuration.manifestHash,
        fixture_hash: configuration.fixtureHash,
        baseline_fingerprint: configuration.baselineFingerprint,
        target_origin_hash: configuration.targetOriginHash,
        runner: configuration.runner,
        runner_hash: configuration.runnerHash,
        started_at: wallStartedAt.toISOString(),
        ended_at: wallEndedAt.toISOString(),
        virtual_users: configuration.virtualUsers,
        duration_seconds: configuration.durationSeconds,
        ramp_up_seconds: configuration.rampUpSeconds,
        request_interval_ms: configuration.requestIntervalMs,
        request_timeout_ms: configuration.requestTimeoutMs,
        attempted_requests: counters.attempted,
        completed_requests: counters.completed,
        transport_errors: counters.transportErrors,
        assertion_failures: counters.assertionFailures,
        client_latency_ms: latencyDistribution(counters.latencies),
    };

    if (Object.keys(result).join('|') !== RESULT_FIELDS.join('|')) {
        fail('RESULT_SCHEMA_INTERNAL_ERROR');
    }

    return result;
}

async function readJsonDocument(path, maximumBytes, code) {
    try {
        const metadata = await stat(path);
        if (!metadata.isFile() || metadata.size > maximumBytes) {
            fail(code);
        }
        const contents = await readFile(path);
        const decoded = JSON.parse(contents.toString('utf8'));
        if (!isObject(decoded)) {
            fail(code);
        }
        return {
            value: decoded,
            sha256: createHash('sha256').update(contents).digest('hex'),
        };
    } catch (error) {
        if (error instanceof RunnerFailure) {
            throw error;
        }
        fail(code);
    }
}

function parseArguments(argv) {
    const values = {};
    const allowedValueArguments = new Set(['plan', 'fixtures', 'scenario', 'output']);
    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];
        if (!argument.startsWith('--') || !allowedValueArguments.has(argument.slice(2))) {
            fail('ARGUMENT_UNKNOWN');
        }
        const key = argument.slice(2);
        if (Object.hasOwn(values, key) || index + 1 >= argv.length || argv[index + 1].startsWith('--')) {
            fail('ARGUMENT_INVALID');
        }
        values[key] = argv[index + 1];
        index += 1;
    }

    for (const key of allowedValueArguments) {
        requiredString(values[key], 'ARGUMENT_REQUIRED');
    }
    if (extname(values.output).toLowerCase() !== '.json') {
        fail('OUTPUT_EXTENSION_INVALID');
    }

    return values;
}

async function runnerHash() {
    const scriptPath = fileURLToPath(import.meta.url);
    const contents = await readFile(scriptPath);
    return createHash('sha256').update(contents).digest('hex');
}

async function writeResult(path, result) {
    const resolvedPath = resolve(path);
    try {
        await stat(dirname(resolvedPath));
        await writeFile(resolvedPath, `${JSON.stringify(result, null, 2)}\n`, {
            encoding: 'utf8',
            flag: 'wx',
            mode: 0o600,
        });
    } catch {
        fail('OUTPUT_WRITE_FAILED');
    }
}

export async function runCli({
    argv = process.argv.slice(2),
    stdout = process.stdout,
    stderr = process.stderr,
} = {}) {
    try {
        const nodeMajor = Number.parseInt(process.versions.node.split('.')[0], 10);
        if (!Number.isInteger(nodeMajor) || nodeMajor < 20) {
            fail('NODE_20_REQUIRED');
        }
        const argumentsMap = parseArguments(argv);
        const planPath = resolve(argumentsMap.plan);
        const fixturePath = resolve(argumentsMap.fixtures);
        const outputPath = resolve(argumentsMap.output);
        if (new Set([planPath, fixturePath, outputPath]).size !== 3) {
            fail('INPUT_OUTPUT_PATH_COLLISION');
        }

        const [planDocument, fixtureDocument, scriptHash] = await Promise.all([
            readJsonDocument(planPath, MAX_PLAN_BYTES, 'PLAN_FILE_INVALID'),
            readJsonDocument(fixturePath, MAX_FIXTURE_BYTES, 'FIXTURE_FILE_INVALID'),
            runnerHash(),
        ]);
        const configuration = validateRunConfiguration({
            plan: planDocument.value,
            fixtures: fixtureDocument.value,
            scenarioKey: argumentsMap.scenario,
            scriptHash,
            fixtureHash: fixtureDocument.sha256,
        });
        const result = await executeScenario(configuration);
        await writeResult(outputPath, result);

        const accepted = result.transport_errors === 0
            && result.assertion_failures === 0
            && result.completed_requests >= configuration.minimumCompletedRequests;
        stdout.write(`${JSON.stringify({
            status: accepted ? 'completed' : 'failed_closed',
            scenario_key: result.scenario_key,
            attempted_requests: result.attempted_requests,
            completed_requests: result.completed_requests,
            transport_errors: result.transport_errors,
            assertion_failures: result.assertion_failures,
        })}\n`);

        return accepted ? 0 : 1;
    } catch (error) {
        const code = error instanceof RunnerFailure ? error.code : 'UNEXPECTED_FAILURE';
        stderr.write(`P0-006 runner failed: ${code}\n`);
        return 2;
    }
}

const isMainModule = process.argv[1] !== undefined
    && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url));
if (isMainModule) {
    process.exitCode = await runCli();
}
