#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { performance } from 'node:perf_hooks';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const BUFFER_WP1_API_URL = 'https://api.buffer.com';

export const BUFFER_WP1_ACCOUNT_QUERY = `query PulseBufferAccountProbe {
  account {
    id
    name
    organizations {
      id
      name
    }
    connectedApps {
      clientId
      scopes
    }
  }
}`;

export const BUFFER_WP1_CHANNELS_QUERY = `query PulseBufferChannelsProbe($input: ChannelsInput!) {
  channels(input: $input) {
    id
    organizationId
    name
    displayName
    service
    type
    isDisconnected
    isLocked
    isQueuePaused
    timezone
    scopes
    allowedActions
  }
}`;

const MAX_RESPONSE_BYTES = 1024 * 1024;
const ORGANIZATION_ID_PATTERN = /^[A-Za-z0-9_-]{1,128}$/u;
const ALLOWED_PROBE_ENVIRONMENTS = new Set(['development', 'local', 'test', 'testing']);
const BUFFER_QUOTA_PERIODS = new Map([
    ['15min', 900],
    ['1day', 86400],
    ['30days', 2592000],
]);

export class BufferWp1ProbeFailure extends Error {
    constructor(code) {
        super(code);
        this.name = 'BufferWp1ProbeFailure';
        this.code = code;
    }
}

function fail(code) {
    throw new BufferWp1ProbeFailure(code);
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function nullableString(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function nullableBoolean(value) {
    return typeof value === 'boolean' ? value : null;
}

function requiredAccessToken(value) {
    const token = nullableString(value);

    if (token === null) {
        fail('ACCESS_TOKEN_REQUIRED');
    }

    return token;
}

function normalizedOrganizationId(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const organizationId = nullableString(value);

    if (organizationId === null || !ORGANIZATION_ID_PATTERN.test(organizationId)) {
        fail('ORGANIZATION_ID_INVALID');
    }

    return organizationId;
}

function normalizedTimeout(value) {
    const timeoutValue = String(value ?? '10000').trim();

    if (!/^\d+$/u.test(timeoutValue)) {
        fail('TIMEOUT_MS_INVALID');
    }

    const timeoutMs = Number(timeoutValue);

    if (!Number.isInteger(timeoutMs) || timeoutMs < 1000 || timeoutMs > 30000) {
        fail('TIMEOUT_MS_INVALID');
    }

    return timeoutMs;
}

function validatedProbeEnvironments(values) {
    const environments = values
        .map((environment) => nullableString(environment)?.toLowerCase() ?? null)
        .filter(Boolean);

    if (environments.length === 0) {
        fail('ENVIRONMENT_REQUIRED');
    }
    if (environments.includes('production')) {
        fail('PRODUCTION_FORBIDDEN');
    }
    if (environments.some((environment) => !ALLOWED_PROBE_ENVIRONMENTS.has(environment))) {
        fail('NON_LOCAL_ENVIRONMENT_FORBIDDEN');
    }

    return environments;
}

function roundedDuration(startedAt) {
    return Math.round((performance.now() - startedAt) * 100) / 100;
}

function responseHeader(response, name) {
    if (!response?.headers || typeof response.headers.get !== 'function') {
        return null;
    }

    return nullableString(response.headers.get(name));
}

export function splitRepeatedRateLimitHeader(value) {
    const header = nullableString(value);

    return header === null
        ? []
        : header.split(/,\s*(?=")/u).map((entry) => entry.trim()).filter(Boolean);
}

function quotaEvidence(response) {
    return {
        rate_limits: splitRepeatedRateLimitHeader(responseHeader(response, 'ratelimit')),
        rate_limit_policies: splitRepeatedRateLimitHeader(responseHeader(response, 'ratelimit-policy')),
        retry_after_seconds: responseHeader(response, 'retry-after'),
    };
}

function parseQuotaHeaderEntry(value) {
    const segments = value.split(';').map((segment) => segment.trim());
    const label = /^"(?<quota>\d+)-in-(?<period>15min|1day|30days)"$/u.exec(segments.shift());
    if (label === null) {
        return null;
    }

    const parameters = new Map();
    for (const segment of segments) {
        const parameter = /^(?<name>[a-z][a-z0-9_-]*)=(?<value>.+)$/u.exec(segment);
        if (parameter === null || parameters.has(parameter.groups.name)) {
            return null;
        }

        parameters.set(parameter.groups.name, parameter.groups.value.trim());
    }

    const declaredQuota = Number(label.groups.quota);
    if (!Number.isSafeInteger(declaredQuota) || declaredQuota <= 0) {
        return null;
    }

    return {
        declaredQuota,
        label: `${label.groups.quota}-in-${label.groups.period}`,
        parameters,
        period: label.groups.period,
    };
}

function unsignedIntegerParameter(parameters, name) {
    const value = parameters.get(name);
    if (value === undefined || !/^\d+$/u.test(value)) {
        return null;
    }

    const number = Number(value);

    return Number.isSafeInteger(number) ? number : null;
}

function hasCompleteQuotaEvidence(quota) {
    if (quota.rate_limits.length !== 3 || quota.rate_limit_policies.length !== 3) {
        return false;
    }

    const limits = quota.rate_limits.map(parseQuotaHeaderEntry);
    const policies = quota.rate_limit_policies.map(parseQuotaHeaderEntry);
    if (limits.some((limit) => limit === null) || policies.some((policy) => policy === null)) {
        return false;
    }

    const limitsByLabel = new Map(limits.map((limit) => [limit.label, limit]));
    const policiesByLabel = new Map(policies.map((policy) => [policy.label, policy]));
    const periods = new Set(policies.map((policy) => policy.period));
    if (limitsByLabel.size !== 3 || policiesByLabel.size !== 3 || periods.size !== 3) {
        return false;
    }

    return policies.every((policy) => {
        const limit = limitsByLabel.get(policy.label);
        const remaining = limit === undefined
            ? null
            : unsignedIntegerParameter(limit.parameters, 'r');
        const resetSeconds = limit === undefined
            ? null
            : unsignedIntegerParameter(limit.parameters, 't');
        const policyQuota = unsignedIntegerParameter(policy.parameters, 'q');
        const policyWindow = unsignedIntegerParameter(policy.parameters, 'w');

        return limit !== undefined
            && remaining !== null
            && remaining <= policy.declaredQuota
            && resetSeconds !== null
            && resetSeconds <= policyWindow
            && policyQuota === policy.declaredQuota
            && policyWindow === BUFFER_QUOTA_PERIODS.get(policy.period)
            && nullableString(policy.parameters.get('pk')) !== null
            && policiesByLabel.has(limit.label);
    });
}

function requestId(response) {
    return responseHeader(response, 'x-request-id')
        ?? responseHeader(response, 'request-id')
        ?? responseHeader(response, 'cf-ray');
}

function normalizeGraphqlErrors(value, isPresent, secret) {
    if (!isPresent) {
        return [];
    }
    if (!Array.isArray(value) || value.length === 0) {
        return null;
    }

    const normalizedErrors = value.map((error) => {
        if (!isObject(error)) {
            return null;
        }

        const message = nullableString(error.message);
        const extensions = error.extensions;
        const code = nullableString(extensions?.code);
        const window = nullableString(extensions?.window);
        if (message === null || (
            extensions !== null
            && extensions !== undefined
            && !isObject(extensions)
        ) || (
            extensions?.code !== null
            && extensions?.code !== undefined
            && code === null
        ) || (
            extensions?.window !== null
            && extensions?.window !== undefined
            && window === null
        )) {
            return null;
        }

        return {
            code,
            window,
            message_sha256: createHash('sha256')
                .update(message.split(secret).join('[REDACTED]'))
                .digest('hex'),
        };
    });

    return normalizedErrors.some((error) => error === null) ? null : normalizedErrors;
}

function normalizeRequiredStringArray(value) {
    if (!Array.isArray(value)) {
        return null;
    }

    const normalizedValues = value.map(nullableString);

    return normalizedValues.some((normalizedValue) => normalizedValue === null)
        ? null
        : normalizedValues;
}

function normalizeNullableStringArray(value) {
    if (!Array.isArray(value)) {
        return null;
    }

    const normalizedValues = value.map((item) => item === null ? null : nullableString(item));

    return normalizedValues.some((normalizedValue, index) => (
        value[index] !== null && normalizedValue === null
    )) ? null : normalizedValues;
}

function normalizeAccount(value) {
    if (!isObject(value) || !Array.isArray(value.organizations)) {
        return null;
    }

    const id = nullableString(value.id);
    const organizations = value.organizations
        .filter(isObject)
        .map((organization) => ({
            id: nullableString(organization.id),
            name: nullableString(organization.name),
        }));
    const connectedApps = value.connectedApps === null
        ? null
        : Array.isArray(value.connectedApps)
            ? value.connectedApps.filter(isObject).map((connectedApp) => ({
                client_id: nullableString(connectedApp.clientId),
                scopes: normalizeRequiredStringArray(connectedApp.scopes),
            }))
            : undefined;

    if (id === null
        || organizations.length !== value.organizations.length
        || organizations.some((organization) => organization.id === null || organization.name === null)
        || connectedApps === undefined
        || (Array.isArray(connectedApps) && (
            connectedApps.length !== value.connectedApps.length
            || connectedApps.some((connectedApp) => (
                connectedApp.client_id === null || connectedApp.scopes === null
            ))
        ))
    ) {
        return null;
    }

    return {
        id,
        name: nullableString(value.name),
        organizations,
        connected_apps: connectedApps,
    };
}

async function readBoundedResponseText(response, controller) {
    const contentLength = Number.parseInt(responseHeader(response, 'content-length') ?? '', 10);

    if (Number.isFinite(contentLength) && contentLength > MAX_RESPONSE_BYTES) {
        controller.abort();
        fail('RESPONSE_TOO_LARGE');
    }

    if (response.body === null) {
        return '';
    }
    if (typeof response.body?.getReader !== 'function') {
        controller.abort();
        fail('RESPONSE_STREAM_REQUIRED');
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let byteLength = 0;
    let responseText = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) {
            break;
        }

        byteLength += value.byteLength;
        if (byteLength > MAX_RESPONSE_BYTES) {
            await reader.cancel().catch(() => {});
            controller.abort();
            fail('RESPONSE_TOO_LARGE');
        }

        responseText += decoder.decode(value, { stream: true });
    }

    return responseText + decoder.decode();
}

function invalidJsonClassification(status) {
    if (status === 401) {
        return 'unauthorized';
    }
    if (status === 403) {
        return 'forbidden';
    }
    if (status === 404) {
        return 'not_found';
    }
    if (status === 429) {
        return 'rate_limited';
    }
    if (status < 200 || status >= 300) {
        return 'http_error';
    }

    return 'invalid_json';
}

function normalizeChannels(value) {
    if (!Array.isArray(value)) {
        return null;
    }

    const channels = value.filter(isObject).map((channel) => ({
        id: nullableString(channel.id),
        organization_id: nullableString(channel.organizationId),
        name: nullableString(channel.name),
        display_name: nullableString(channel.displayName),
        service: nullableString(channel.service),
        type: nullableString(channel.type),
        is_disconnected: nullableBoolean(channel.isDisconnected),
        is_locked: nullableBoolean(channel.isLocked),
        is_queue_paused: nullableBoolean(channel.isQueuePaused),
        timezone: nullableString(channel.timezone),
        scopes: normalizeNullableStringArray(channel.scopes),
        allowed_actions: normalizeRequiredStringArray(channel.allowedActions),
    }));

    if (channels.length !== value.length || channels.some((channel) => (
        channel.id === null
        || channel.organization_id === null
        || channel.name === null
        || channel.service === null
        || channel.type === null
        || channel.is_disconnected === null
        || channel.is_locked === null
        || channel.is_queue_paused === null
        || channel.timezone === null
        || channel.scopes === null
        || channel.allowed_actions === null
    ))) {
        return null;
    }

    return channels;
}

function classificationFor(status, graphqlErrors, validPayload, quota) {
    const errorCodes = graphqlErrors.map((error) => error.code).filter(Boolean);

    if (status === 401 || errorCodes.includes('UNAUTHORIZED')) {
        return 'unauthorized';
    }
    if (status === 403 || errorCodes.includes('FORBIDDEN')) {
        return 'forbidden';
    }
    if (status === 404 || errorCodes.includes('NOT_FOUND')) {
        return 'not_found';
    }
    if (status === 429 || errorCodes.includes('RATE_LIMIT_EXCEEDED')) {
        return 'rate_limited';
    }
    if (status < 200 || status >= 300) {
        return 'http_error';
    }
    if (graphqlErrors.length > 0) {
        return errorCodes.includes('UNEXPECTED') ? 'unexpected' : 'graphql_error';
    }
    if (!validPayload) {
        return 'invalid_payload';
    }
    if (!hasCompleteQuotaEvidence(quota)) {
        return 'incomplete_quota_evidence';
    }

    return 'success';
}

function resultEnvelope({
    operation,
    ok,
    classification,
    httpStatus = null,
    durationMs,
    response = null,
    graphqlErrors = [],
    data = null,
}) {
    return {
        schema_version: 1,
        safety: {
            read_only: true,
            mutation_documents_allowed: false,
            automatic_retries: 0,
            production_allowed: false,
            evidence_storage: 'ephemeral_do_not_commit',
        },
        operation,
        ok,
        classification,
        http_status: httpStatus,
        duration_ms: durationMs,
        request_id: response === null ? null : requestId(response),
        quota: response === null ? {
            rate_limits: [],
            rate_limit_policies: [],
            retry_after_seconds: null,
        } : quotaEvidence(response),
        graphql_errors: graphqlErrors,
        data,
    };
}

function redactSecret(value, secret) {
    if (typeof value === 'string') {
        return value.includes(secret) ? value.split(secret).join('[REDACTED]') : value;
    }
    if (Array.isArray(value)) {
        return value.map((item) => redactSecret(item, secret));
    }
    if (isObject(value)) {
        return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [key, redactSecret(item, secret)]),
        );
    }

    return value;
}

function safeResultEnvelope(secret, values) {
    return redactSecret(resultEnvelope(values), secret);
}

export async function executeBufferWp1Probe({
    accessToken,
    environment,
    organizationId = null,
    timeoutMs = 10000,
    fetchImpl = globalThis.fetch,
} = {}) {
    validatedProbeEnvironments([environment]);
    const token = requiredAccessToken(accessToken);
    const normalizedOrganization = normalizedOrganizationId(organizationId);
    const normalizedRequestTimeout = normalizedTimeout(timeoutMs);

    if (typeof fetchImpl !== 'function') {
        fail('FETCH_IMPLEMENTATION_REQUIRED');
    }

    const operation = normalizedOrganization === null ? 'account' : 'channels';
    const query = operation === 'account' ? BUFFER_WP1_ACCOUNT_QUERY : BUFFER_WP1_CHANNELS_QUERY;
    const variables = operation === 'account'
        ? {}
        : { input: { organizationId: normalizedOrganization } };

    if (!/^\s*query\b/u.test(query) || /\bmutation\b/u.test(query)) {
        fail('READ_ONLY_QUERY_REQUIRED');
    }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), normalizedRequestTimeout);
    const startedAt = performance.now();
    let response;

    try {
        response = await fetchImpl(BUFFER_WP1_API_URL, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query, variables }),
            redirect: 'error',
            signal: controller.signal,
        });
    } catch (error) {
        clearTimeout(timeout);

        return safeResultEnvelope(token, {
            operation,
            ok: false,
            classification: error?.name === 'AbortError' ? 'timeout' : 'transport_error',
            durationMs: roundedDuration(startedAt),
        });
    }

    let responseText;
    try {
        responseText = await readBoundedResponseText(response, controller);
    } catch (error) {
        clearTimeout(timeout);

        return safeResultEnvelope(token, {
            operation,
            ok: false,
            classification: error instanceof BufferWp1ProbeFailure && error.code === 'RESPONSE_TOO_LARGE'
                ? 'response_too_large'
                : error instanceof BufferWp1ProbeFailure && error.code === 'RESPONSE_STREAM_REQUIRED'
                    ? 'response_stream_unavailable'
                : error?.name === 'AbortError' ? 'timeout' : 'transport_error',
            httpStatus: response.status,
            durationMs: roundedDuration(startedAt),
            response,
        });
    }

    clearTimeout(timeout);

    let payload;
    try {
        payload = JSON.parse(responseText);
    } catch {
        return safeResultEnvelope(token, {
            operation,
            ok: false,
            classification: invalidJsonClassification(response.status),
            httpStatus: response.status,
            durationMs: roundedDuration(startedAt),
            response,
        });
    }

    const normalizedGraphqlErrors = normalizeGraphqlErrors(
        payload?.errors,
        isObject(payload) && Object.hasOwn(payload, 'errors'),
        token,
    );
    const graphqlErrors = normalizedGraphqlErrors ?? [];
    const data = operation === 'account'
        ? { account: normalizeAccount(payload?.data?.account) }
        : { channels: normalizeChannels(payload?.data?.channels) };
    const validData = operation === 'account' ? data.account !== null : data.channels !== null;
    const validPayload = normalizedGraphqlErrors !== null && validData;
    const classification = classificationFor(
        response.status,
        graphqlErrors,
        validPayload,
        quotaEvidence(response),
    );

    return safeResultEnvelope(token, {
        operation,
        ok: classification === 'success',
        classification,
        httpStatus: response.status,
        durationMs: roundedDuration(startedAt),
        response,
        graphqlErrors,
        data,
    });
}

function enabled(value) {
    return ['1', 'true', 'on', 'yes'].includes(String(value ?? '').trim().toLowerCase());
}

function parseArguments(argv) {
    let organizationId = null;

    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];

        if (argument === '--help') {
            return { help: true, organizationId: null };
        }
        if (argument === '--organization') {
            const value = argv[index + 1];
            if (value === undefined || value.startsWith('--')) {
                fail('ORGANIZATION_ID_REQUIRED');
            }

            organizationId = normalizedOrganizationId(value);
            index += 1;
            continue;
        }
        if (argument.startsWith('--organization=')) {
            const value = argument.slice('--organization='.length);
            if (value === '') {
                fail('ORGANIZATION_ID_REQUIRED');
            }

            organizationId = normalizedOrganizationId(value);
            continue;
        }

        fail('ARGUMENT_INVALID');
    }

    return {
        help: false,
        organizationId,
    };
}

function safeConfigurationFailure(code) {
    return {
        schema_version: 1,
        safety: {
            read_only: true,
            mutation_documents_allowed: false,
            automatic_retries: 0,
            production_allowed: false,
        },
        ok: false,
        classification: 'configuration_error',
        code,
    };
}

function writeJson(writer, value) {
    writer(`${JSON.stringify(value, null, 2)}\n`);
}

export async function runBufferWp1ProbeCli({
    argv = process.argv.slice(2),
    env = process.env,
    fetchImpl = globalThis.fetch,
    stdout = (value) => process.stdout.write(value),
    stderr = (value) => process.stderr.write(value),
} = {}) {
    try {
        const arguments_ = parseArguments(argv);

        if (arguments_.help) {
            stdout('Usage: npm run pulse:buffer:probe -- [--organization=<Buffer organization ID>]\n');
            return 0;
        }

        const environments = validatedProbeEnvironments([env.APP_ENV, env.NODE_ENV]);
        if (!enabled(env.BUFFER_WP1_PROBE_ENABLED)) {
            fail('PROBE_DISABLED');
        }

        const result = await executeBufferWp1Probe({
            accessToken: env.BUFFER_WP1_PROBE_ACCESS_TOKEN,
            environment: environments[0],
            organizationId: arguments_.organizationId,
            timeoutMs: env.BUFFER_WP1_PROBE_TIMEOUT_MS ?? 10000,
            fetchImpl,
        });

        writeJson(stdout, result);

        return result.ok ? 0 : 1;
    } catch (error) {
        const code = error instanceof BufferWp1ProbeFailure ? error.code : 'PROBE_CONFIGURATION_FAILED';
        writeJson(stderr, safeConfigurationFailure(code));

        return 1;
    }
}

const isMainModule = process.argv[1]
    && fileURLToPath(import.meta.url) === resolve(process.argv[1]);

if (isMainModule) {
    process.exitCode = await runBufferWp1ProbeCli();
}
