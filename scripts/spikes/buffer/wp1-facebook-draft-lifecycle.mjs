#!/usr/bin/env node

import { createHash, randomBytes } from 'node:crypto';
import { constants as fileSystemConstants } from 'node:fs';
import { mkdir, open, rename, unlink } from 'node:fs/promises';
import { performance } from 'node:perf_hooks';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    BUFFER_WP1_API_URL,
    BufferWp1ProbeFailure,
    executeBufferWp1Probe,
    splitRepeatedRateLimitHeader,
} from './wp1-read-only-probe.mjs';

export const BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION = 'DELETE_TEMPORARY_FACEBOOK_DRAFT_AFTER_TEST';

export const BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION = `mutation PulseBufferCreateFacebookDraft($input: CreatePostInput!) {
  createPost(input: $input) {
    __typename
    ... on PostActionSuccess {
      post {
        id
        channelId
        channelService
        dueAt
        externalLink
        schedulingType
        sentAt
        sharedNow
        shareMode
        status
        text
      }
    }
    ... on MutationError {
      message
    }
  }
}`;

export const BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION = `mutation PulseBufferEditFacebookDraft($input: EditPostInput!) {
  editPost(input: $input) {
    __typename
    ... on PostActionSuccess {
      post {
        id
        channelId
        channelService
        dueAt
        externalLink
        schedulingType
        sentAt
        sharedNow
        shareMode
        status
        text
      }
    }
    ... on MutationError {
      message
    }
  }
}`;

export const BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION = `mutation PulseBufferMoveFacebookDraft($input: MovePostInQueueInput!) {
  movePostInQueue(input: $input) {
    __typename
    ... on PostActionSuccess {
      post {
        id
        channelId
        channelService
        dueAt
        externalLink
        schedulingType
        sentAt
        sharedNow
        shareMode
        status
        text
      }
    }
    ... on MutationError {
      message
    }
  }
}`;

export const BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION = `mutation PulseBufferDeleteFacebookDraft($input: DeletePostInput!) {
  deletePost(input: $input) {
    __typename
    ... on DeletePostSuccess {
      id
    }
    ... on MutationError {
      message
    }
  }
}`;

export const BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY = `query PulseBufferVerifyFacebookDraftDeleted($input: PostInput!) {
  post(input: $input) {
    id
  }
}`;

export const BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY = `query PulseBufferInspectFacebookDraft($input: PostInput!) {
  post(input: $input) {
    id
    channelId
    channelService
    dueAt
    externalLink
    schedulingType
    sentAt
    sharedNow
    shareMode
    status
    text
  }
}`;

const MAX_RESPONSE_BYTES = 1024 * 1024;
const ALLOWED_ENVIRONMENTS = new Set(['development', 'local', 'test', 'testing']);
const GRAPHQL_NAME_PATTERN = /^[_A-Za-z][_0-9A-Za-z]*$/u;
const REMOTE_ID_PATTERN = /^[A-Za-z0-9_-]{1,128}$/u;
const SHA256_PATTERN = /^[a-f0-9]{64}$/u;
const SAFE_GRAPHQL_ERROR_CODES = new Set([
    'FORBIDDEN',
    'NOT_FOUND',
    'RATE_LIMIT_EXCEEDED',
    'UNAUTHORIZED',
    'UNEXPECTED',
]);
const SAFE_MUTATION_RESPONSE_TYPES = new Set([
    'DeletePostSuccess',
    'InvalidInputError',
    'LimitReachedError',
    'NotFoundError',
    'PostActionSuccess',
    'RestProxyError',
    'UnauthorizedError',
    'UnexpectedError',
    'VoidMutationError',
]);
const DEFINITIVE_CREATE_REJECTION_TYPES = new Set([
    'InvalidInputError',
    'LimitReachedError',
    'NotFoundError',
    'UnauthorizedError',
]);
const RUN_ID_PATTERN = /^[A-Za-z0-9_-]{1,80}$/u;
const REQUIRED_QUOTA_WINDOWS = new Map([
    ['15min', 900],
    ['1day', 86400],
    ['30days', 2592000],
]);
const REQUIRED_MUTATION_CAPACITY = 8;
const REQUIRED_CLEANUP_CAPACITY = 3;
const FORBIDDEN_SCHEDULING_MODES = new Set(['customScheduled', 'shareNext', 'shareNow']);
const MAX_RECOVERY_JOURNAL_BYTES = 4096;
const RECOVERY_STATES = new Set([
    'cleanup_required',
    'create_pending',
    'creation_outcome_unknown',
]);
const EXPECTED_FACEBOOK_CREATE_METADATA_CAPABILITY = {
    facebook_field: 'facebook:FacebookPostMetadataInput',
    metadata_input: 'PostInputMetaData',
    post_type_field: 'type:PostTypeFacebook!',
    post_types: ['post', 'reel', 'story'],
};
const EXPECTED_POST_DELETE_CLEANUP_CAPABILITY = {
    delete_input: 'DeletePostInput!',
    delete_payload: 'DeletePostPayload!',
    delete_root_field: 'deletePost',
    inspect_input: 'PostInput!',
    inspect_output: 'Post!',
    inspect_root_field: 'post',
};

export class BufferWp1FacebookDraftFailure extends Error {
    constructor(code) {
        super(code);
        this.name = 'BufferWp1FacebookDraftFailure';
        this.code = code;
    }
}

function fail(code) {
    throw new BufferWp1FacebookDraftFailure(code);
}

function recoveryJournalRecord({ draftMarker, postId, runId, state, targetFingerprint: fingerprint }) {
    return {
        schema_version: 1,
        draft_marker: draftMarker,
        post_id: postId,
        run_id: runId,
        state,
        target_fingerprint: fingerprint,
    };
}

function processIsAlive(processId) {
    try {
        process.kill(processId, 0);

        return true;
    } catch (error) {
        return error?.code !== 'ESRCH';
    }
}

export function createBufferWp1FacebookDraftRecoveryJournal({
    directory = resolve('storage/app/private/buffer-wp1-c'),
    isProcessAlive = processIsAlive,
    processId = process.pid,
} = {}) {
    const journalDirectory = resolve(directory);
    const journalPath = resolve(journalDirectory, 'active.json');
    const cleanupLockPath = resolve(journalDirectory, 'cleanup.lock');
    const lockOwner = randomBytes(16).toString('hex');
    let lockHeld = false;

    async function writeExclusive(path, record) {
        const handle = await open(path, 'wx', 0o600);

        try {
            await handle.writeFile(`${JSON.stringify(record)}\n`, { encoding: 'utf8' });
            await handle.sync();
        } finally {
            await handle.close();
        }
    }

    async function readPrivateJson(path, { invalidCode, notFoundCode, readCode }) {
        let handle;

        try {
            handle = await open(
                path,
                fileSystemConstants.O_RDONLY | fileSystemConstants.O_NOFOLLOW,
            );
        } catch (error) {
            if (error?.code === 'ENOENT') {
                fail(notFoundCode);
            }

            fail(readCode);
        }

        try {
            const metadata = await handle.stat();
            if (!metadata.isFile()
                || metadata.size < 2
                || metadata.size > MAX_RECOVERY_JOURNAL_BYTES
                || (metadata.mode & 0o077) !== 0) {
                fail(invalidCode);
            }

            const value = await handle.readFile({ encoding: 'utf8' });

            try {
                return JSON.parse(value);
            } catch {
                fail(invalidCode);
            }
        } finally {
            await handle.close();
        }
    }

    function validLockRecord(record) {
        return hasExactKeys(record, ['owner', 'process_id', 'schema_version'])
            && record.schema_version === 1
            && Number.isSafeInteger(record.process_id)
            && record.process_id > 0
            && typeof record.owner === 'string'
            && /^[a-f0-9]{32}$/u.test(record.owner);
    }

    return {
        async acquire() {
            if (!Number.isSafeInteger(processId)
                || processId < 1
                || typeof isProcessAlive !== 'function') {
                fail('RECOVERY_CLEANUP_LOCK_FAILED');
            }
            if (lockHeld) {
                fail('RECOVERY_CLEANUP_IN_PROGRESS');
            }

            await mkdir(journalDirectory, { mode: 0o700, recursive: true });
            const lockRecord = {
                schema_version: 1,
                owner: lockOwner,
                process_id: processId,
            };

            try {
                await writeExclusive(cleanupLockPath, lockRecord);
            } catch (error) {
                if (error?.code !== 'EEXIST') {
                    fail('RECOVERY_CLEANUP_LOCK_FAILED');
                }

                const existingLock = await readPrivateJson(cleanupLockPath, {
                    invalidCode: 'RECOVERY_CLEANUP_LOCK_INVALID',
                    notFoundCode: 'RECOVERY_CLEANUP_IN_PROGRESS',
                    readCode: 'RECOVERY_CLEANUP_LOCK_INVALID',
                });
                if (!validLockRecord(existingLock)) {
                    fail('RECOVERY_CLEANUP_LOCK_INVALID');
                }
                if (isProcessAlive(existingLock.process_id)) {
                    fail('RECOVERY_CLEANUP_IN_PROGRESS');
                }

                try {
                    await unlink(cleanupLockPath);
                    await writeExclusive(cleanupLockPath, lockRecord);
                } catch (retryError) {
                    if (retryError?.code === 'EEXIST' || retryError?.code === 'ENOENT') {
                        fail('RECOVERY_CLEANUP_IN_PROGRESS');
                    }

                    fail('RECOVERY_CLEANUP_LOCK_FAILED');
                }
            }

            lockHeld = true;
        },
        async begin(record) {
            await mkdir(journalDirectory, { mode: 0o700, recursive: true });

            try {
                await writeExclusive(journalPath, record);
            } catch (error) {
                if (error?.code === 'EEXIST') {
                    fail('RECOVERY_REQUIRED');
                }

                fail('RECOVERY_JOURNAL_WRITE_FAILED');
            }
        },
        async complete() {
            try {
                await unlink(journalPath);
            } catch (error) {
                if (error?.code !== 'ENOENT') {
                    fail('RECOVERY_JOURNAL_CLEAR_FAILED');
                }
            }
        },
        async read() {
            return readPrivateJson(journalPath, {
                invalidCode: 'RECOVERY_JOURNAL_INVALID',
                notFoundCode: 'RECOVERY_JOURNAL_NOT_FOUND',
                readCode: 'RECOVERY_JOURNAL_READ_FAILED',
            });
        },
        async release() {
            if (!lockHeld) {
                fail('RECOVERY_CLEANUP_UNLOCK_FAILED');
            }

            const existingLock = await readPrivateJson(cleanupLockPath, {
                invalidCode: 'RECOVERY_CLEANUP_LOCK_INVALID',
                notFoundCode: 'RECOVERY_CLEANUP_UNLOCK_FAILED',
                readCode: 'RECOVERY_CLEANUP_LOCK_INVALID',
            });
            if (!validLockRecord(existingLock) || existingLock.owner !== lockOwner) {
                fail('RECOVERY_CLEANUP_UNLOCK_FAILED');
            }

            try {
                await unlink(cleanupLockPath);
                lockHeld = false;
            } catch (error) {
                fail('RECOVERY_CLEANUP_UNLOCK_FAILED');
            }
        },
        async update(record) {
            const temporaryPath = resolve(
                journalDirectory,
                `active-${randomBytes(8).toString('hex')}.tmp`,
            );

            try {
                await writeExclusive(temporaryPath, record);
                await rename(temporaryPath, journalPath);
            } catch {
                await unlink(temporaryPath).catch(() => {});
                fail('RECOVERY_JOURNAL_WRITE_FAILED');
            }
        },
    };
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function nullableString(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function requiredAccessToken(value) {
    const token = nullableString(value);

    if (token === null) {
        fail('ACCESS_TOKEN_REQUIRED');
    }

    return token;
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

function validatedEnvironments(values) {
    const environments = values
        .map((environment) => nullableString(environment)?.toLowerCase() ?? null)
        .filter(Boolean);

    if (environments.length === 0) {
        fail('ENVIRONMENT_REQUIRED');
    }
    if (environments.includes('production')) {
        fail('PRODUCTION_FORBIDDEN');
    }
    if (environments.some((environment) => !ALLOWED_ENVIRONMENTS.has(environment))) {
        fail('NON_LOCAL_ENVIRONMENT_FORBIDDEN');
    }

    return environments;
}

function enabled(value) {
    return ['1', 'true', 'on', 'yes'].includes(String(value ?? '').trim().toLowerCase());
}

function validatedRecoveryJournal(value) {
    if (!isObject(value)
        || typeof value.acquire !== 'function'
        || typeof value.begin !== 'function'
        || typeof value.complete !== 'function'
        || typeof value.release !== 'function'
        || typeof value.update !== 'function') {
        fail('RECOVERY_JOURNAL_REQUIRED');
    }

    return value;
}

function validatedCleanupRecoveryJournal(value) {
    const journal = validatedRecoveryJournal(value);

    if (typeof journal.read !== 'function') {
        fail('RECOVERY_JOURNAL_REQUIRED');
    }

    return journal;
}

function validatedRecoveryRecord(value, secret) {
    if (!hasExactKeys(value, [
        'draft_marker',
        'post_id',
        'run_id',
        'schema_version',
        'state',
        'target_fingerprint',
    ]) || value.schema_version !== 1) {
        fail('RECOVERY_JOURNAL_INVALID');
    }

    const runId = nullableString(value.run_id);
    const draftMarker = nullableString(value.draft_marker);
    const state = nullableString(value.state);
    const fingerprint = nullableString(value.target_fingerprint);
    const postId = value.post_id === null ? null : safeRemoteId(value.post_id, secret);

    if (runId === null
        || !RUN_ID_PATTERN.test(runId)
        || draftMarker !== `MALIKIA-WP1C-${runId}`
        || state === null
        || !RECOVERY_STATES.has(state)
        || fingerprint === null
        || !SHA256_PATTERN.test(fingerprint)
        || draftMarker.includes(secret)
        || fingerprint.includes(secret)
        || (value.post_id !== null && postId === null)
        || (state === 'cleanup_required' && postId === null)
        || (state !== 'cleanup_required' && postId !== null)) {
        fail('RECOVERY_JOURNAL_INVALID');
    }

    return {
        draftMarker,
        postId,
        runId,
        state,
        targetFingerprint: fingerprint,
    };
}

function roundedDuration(startedAt) {
    return Math.round((performance.now() - startedAt) * 100) / 100;
}

function secretRedactionMarker(secret) {
    return ['[REDACTED]', '[FILTERED]', '*', '']
        .find((candidate) => !candidate.includes(secret)) ?? '';
}

function redactSecretFromString(value, secret) {
    if (!value.includes(secret)) {
        return value;
    }

    const marker = secretRedactionMarker(secret);
    const redacted = value.split(secret).join(marker);

    return redacted.includes(secret) ? marker : redacted;
}

function hashRemoteMessage(value, secret) {
    const message = nullableString(value);

    return message === null ? null : createHash('sha256')
        .update(redactSecretFromString(message, secret))
        .digest('hex');
}

function redactSecret(value, secret) {
    if (typeof value === 'string') {
        return redactSecretFromString(value, secret);
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

function safeLifecycleResult(result, secret) {
    return redactSecret(result, secret);
}

function safeMutationResponseType(value, secret) {
    const responseType = nullableString(value);

    return responseType !== null
        && responseType !== secret
        && GRAPHQL_NAME_PATTERN.test(responseType)
        && SAFE_MUTATION_RESPONSE_TYPES.has(responseType)
        ? responseType
        : null;
}

function safeRemoteId(value, secret) {
    const id = nullableString(value);

    return id !== null && REMOTE_ID_PATTERN.test(id) && !id.includes(secret) ? id : null;
}

function targetFingerprint(organizationId, channelId) {
    return createHash('sha256').update(`${organizationId}\0${channelId}`).digest('hex');
}

function responseHeader(response, name) {
    if (!response?.headers || typeof response.headers.get !== 'function') {
        return null;
    }

    return nullableString(response.headers.get(name));
}

function parseHeaderParameters(segments) {
    const parameters = new Map();

    for (const segment of segments) {
        const match = /^(?<name>[a-z][a-z0-9_-]*)=(?<value>.+)$/u.exec(segment.trim());

        if (match === null || parameters.has(match.groups.name)) {
            return null;
        }

        parameters.set(match.groups.name, match.groups.value.trim());
    }

    return parameters;
}

function unsignedInteger(parameters, name) {
    const value = parameters?.get(name);

    if (value === undefined || !/^\d+$/u.test(value)) {
        return null;
    }

    const number = Number(value);

    return Number.isSafeInteger(number) ? number : null;
}

function parseQuotaEntry(value, parameterNames) {
    const segments = value.split(';');
    const label = /^"(?<limit>\d+)-in-(?<period>15min|1day|30days)"$/u.exec(
        segments.shift()?.trim() ?? '',
    );
    const parameters = parseHeaderParameters(segments);

    if (label === null || parameters === null) {
        return null;
    }

    const declaredLimit = Number(label.groups.limit);
    const normalizedParameters = Object.fromEntries(
        parameterNames.map((name) => [name, unsignedInteger(parameters, name)]),
    );

    if (!Number.isSafeInteger(declaredLimit)
        || declaredLimit <= 0
        || Object.values(normalizedParameters).some((value_) => value_ === null)) {
        return null;
    }

    return {
        declared_limit: declaredLimit,
        label: `${label.groups.limit}-in-${label.groups.period}`,
        parameters: normalizedParameters,
        period: label.groups.period,
    };
}

function normalizedQuota(response) {
    const limits = splitRepeatedRateLimitHeader(responseHeader(response, 'ratelimit'))
        .map((entry) => parseQuotaEntry(entry, ['r', 't']));
    const policies = splitRepeatedRateLimitHeader(responseHeader(response, 'ratelimit-policy'))
        .map((entry) => parseQuotaEntry(entry, ['q', 'w']));
    const limitsByLabel = new Map(limits.filter(Boolean).map((entry) => [entry.label, entry]));
    const policiesByLabel = new Map(policies.filter(Boolean).map((entry) => [entry.label, entry]));
    const windows = [];

    for (const [period, expectedSeconds] of REQUIRED_QUOTA_WINDOWS) {
        const policy = policies.find((entry) => entry?.period === period);
        const limit = policy === undefined || policy === null
            ? null
            : limitsByLabel.get(policy.label) ?? null;

        if (policy === undefined
            || policy === null
            || limit === null
            || policy.parameters.q !== policy.declared_limit
            || policy.parameters.w !== expectedSeconds
            || limit.parameters.r > limit.declared_limit
            || limit.parameters.t > expectedSeconds) {
            continue;
        }

        windows.push({
            limit: policy.declared_limit,
            period,
            remaining: limit.parameters.r,
            reset_seconds: limit.parameters.t,
            window_seconds: policy.parameters.w,
        });
    }

    return {
        complete: limits.length === 3
            && policies.length === 3
            && limits.every(Boolean)
            && policies.every(Boolean)
            && limitsByLabel.size === 3
            && policiesByLabel.size === 3
            && windows.length === 3,
        windows,
    };
}

function quotaHasCapacity(quota, requiredRequests) {
    return quota.complete === true
        && quota.windows.length === 3
        && quota.windows.every((window) => window.remaining >= requiredRequests);
}

function probeHasMutationCapacity(result, requiredRequests) {
    const limits = result?.quota?.rate_limits;

    if (!Array.isArray(limits) || limits.length !== 3) {
        return false;
    }

    const parsedLimits = limits.map((entry) => parseQuotaEntry(entry, ['r', 't']));

    return parsedLimits.every((entry) => (
        entry !== null
        && REQUIRED_QUOTA_WINDOWS.has(entry.period)
        && entry.parameters.r >= requiredRequests
    )) && new Set(parsedLimits.map((entry) => entry.period)).size === 3;
}

async function readBoundedResponseText(response, controller) {
    const contentLength = Number.parseInt(responseHeader(response, 'content-length') ?? '', 10);

    if (Number.isFinite(contentLength) && contentLength > MAX_RESPONSE_BYTES) {
        controller.abort();
        fail('RESPONSE_TOO_LARGE');
    }
    if (response.body === null || typeof response.body?.getReader !== 'function') {
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

function normalizeGraphqlErrors(value, isPresent, secret) {
    if (!isPresent) {
        return [];
    }
    if (!Array.isArray(value) || value.length === 0) {
        return null;
    }

    const errors = value.map((error) => {
        if (!isObject(error)) {
            return null;
        }

        const remoteCode = error.extensions === undefined || error.extensions === null
            ? null
            : isObject(error.extensions) ? nullableString(error.extensions.code) : undefined;
        const messageHash = hashRemoteMessage(error.message, secret);

        if (remoteCode === undefined || messageHash === null) {
            return null;
        }

        const code = remoteCode !== null
            && remoteCode !== secret
            && SAFE_GRAPHQL_ERROR_CODES.has(remoteCode)
            ? remoteCode
            : null;

        return { code, message_sha256: messageHash };
    });

    return errors.some((error) => error === null) ? null : errors;
}

function httpClassification(status) {
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

    return status >= 200 && status < 300 ? 'success' : 'http_error';
}

function hasExactKeys(value, expectedKeys) {
    return isObject(value)
        && Object.keys(value).sort().join('\0') === [...expectedKeys].sort().join('\0');
}

function validateFixedMutationRequest(query, variables) {
    const input = variables?.input;
    const values = isObject(input) ? Object.values(input) : [];

    if (values.some((value) => typeof value === 'string' && FORBIDDEN_SCHEDULING_MODES.has(value))) {
        fail('MUTATION_VARIABLE_SAFETY_VIOLATION');
    }

    const valid = query === BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY
        || query === BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY
        ? hasExactKeys(input, ['id']) && nullableString(input.id) !== null
        : query === BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION
        ? hasExactKeys(input, [
            'assets',
            'channelId',
            'mode',
            'needsApproval',
            'saveToDraft',
            'schedulingType',
            'text',
        ])
            && Array.isArray(input.assets)
            && input.assets.length === 0
            && nullableString(input.channelId) !== null
            && input.mode === 'addToQueue'
            && input.needsApproval === false
            && input.saveToDraft === true
            && input.schedulingType === 'automatic'
            && nullableString(input.text)?.startsWith('[MALIKIA WP1-C TEMP DRAFT - DO NOT PUBLISH] ')
        : query === BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION
            ? hasExactKeys(input, ['id', 'saveToDraft', 'text'])
                && nullableString(input.id) !== null
                && input.saveToDraft === true
                && nullableString(input.text)?.endsWith(' - EDITED')
            : query === BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION
                ? hasExactKeys(input, ['id', 'position'])
                    && nullableString(input.id) !== null
                    && input.position === 'bottom'
                : query === BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION
                    ? hasExactKeys(input, ['id']) && nullableString(input.id) !== null
                    : false;

    if (!valid) {
        fail('MUTATION_VARIABLE_SAFETY_VIOLATION');
    }
}

async function executeMutationRequest({ accessToken, fetchImpl, query, timeoutMs, variables }) {
    validateFixedMutationRequest(query, variables);

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    const startedAt = performance.now();
    let response;

    try {
        response = await fetchImpl(BUFFER_WP1_API_URL, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${accessToken}`,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query, variables }),
            redirect: 'error',
            signal: controller.signal,
        });
    } catch (error) {
        clearTimeout(timeout);

        return {
            classification: error?.name === 'AbortError' ? 'timeout' : 'transport_error',
            duration_ms: roundedDuration(startedAt),
            graphql_errors: [],
            http_status: null,
            payload: null,
            quota: { complete: false, windows: [] },
        };
    }

    let responseText;
    try {
        responseText = await readBoundedResponseText(response, controller);
    } catch (error) {
        clearTimeout(timeout);

        return {
            classification: error instanceof BufferWp1FacebookDraftFailure
                && error.code === 'RESPONSE_TOO_LARGE'
                ? 'response_too_large'
                : error instanceof BufferWp1FacebookDraftFailure
                    && error.code === 'RESPONSE_STREAM_REQUIRED'
                    ? 'response_stream_unavailable'
                    : error?.name === 'AbortError' ? 'timeout' : 'transport_error',
            duration_ms: roundedDuration(startedAt),
            graphql_errors: [],
            http_status: response.status,
            payload: null,
            quota: normalizedQuota(response),
        };
    }

    clearTimeout(timeout);

    let payload;
    try {
        payload = JSON.parse(responseText);
    } catch {
        return {
            classification: httpClassification(response.status) === 'success'
                ? 'invalid_json'
                : httpClassification(response.status),
            duration_ms: roundedDuration(startedAt),
            graphql_errors: [],
            http_status: response.status,
            payload: null,
            quota: normalizedQuota(response),
        };
    }

    const graphqlErrors = normalizeGraphqlErrors(
        payload?.errors,
        isObject(payload) && Object.hasOwn(payload, 'errors'),
        accessToken,
    );
    let classification = httpClassification(response.status);

    if (classification === 'success' && graphqlErrors === null) {
        classification = 'invalid_payload';
    } else if (classification === 'success' && graphqlErrors.length > 0) {
        const errorCodes = graphqlErrors.map((error) => error.code).filter(Boolean);
        classification = errorCodes.includes('UNAUTHORIZED')
            ? 'unauthorized'
            : errorCodes.includes('FORBIDDEN')
                ? 'forbidden'
                : errorCodes.includes('NOT_FOUND')
                    ? 'not_found'
                    : errorCodes.includes('RATE_LIMIT_EXCEEDED')
                        ? 'rate_limited'
                        : 'graphql_error';
    }

    return {
        classification,
        duration_ms: roundedDuration(startedAt),
        graphql_errors: graphqlErrors ?? [],
        http_status: response.status,
        payload,
        quota: normalizedQuota(response),
    };
}

function notAttemptedStep() {
    return {
        attempted: false,
        duration_ms: null,
        graphql_errors: [],
        http_status: null,
        message_sha256: null,
        outcome: 'not_attempted',
        quota: { complete: false, windows: [] },
        response_type: null,
    };
}

function mutationStep(request, values = {}) {
    return {
        attempted: true,
        duration_ms: request.duration_ms,
        graphql_errors: request.graphql_errors,
        http_status: request.http_status,
        message_sha256: values.messageHash ?? null,
        outcome: values.outcome ?? request.classification,
        quota: request.quota,
        response_type: values.responseType ?? null,
    };
}

function normalizePostAction(request, field, secret) {
    const payload = request.payload?.data?.[field];
    const salvagedId = isObject(payload?.post)
        ? safeRemoteId(payload.post.id, secret)
        : null;
    const responseType = safeMutationResponseType(payload?.__typename, secret);

    if (!isObject(payload) || responseType === null) {
        return {
            id: salvagedId,
            kind: 'invalid_payload',
            step: mutationStep(request, { outcome: 'invalid_payload' }),
        };
    }
    if (responseType !== 'PostActionSuccess') {
        if (request.classification !== 'success') {
            return { id: salvagedId, kind: 'request_failure', step: mutationStep(request) };
        }

        const messageHash = hashRemoteMessage(payload.message, secret);

        return messageHash === null ? {
            id: salvagedId,
            kind: 'invalid_payload',
            step: mutationStep(request, { outcome: 'invalid_payload' }),
        } : {
            id: salvagedId,
            kind: 'typed_error',
            step: mutationStep(request, {
                messageHash,
                outcome: 'typed_error',
                responseType,
            }),
        };
    }

    const post = payload.post;
    const id = safeRemoteId(post?.id, secret);
    const channelId = post?.channelId === undefined ? null : nullableString(post.channelId);
    const channelService = post?.channelService === undefined
        ? null
        : nullableString(post.channelService);
    const dueAt = post?.dueAt === undefined
        ? undefined
        : post.dueAt === null ? null : nullableString(post.dueAt);
    const externalLink = post?.externalLink === undefined
        ? undefined
        : post.externalLink === null ? null : nullableString(post.externalLink);
    const schedulingType = post?.schedulingType === undefined
        ? null
        : nullableString(post.schedulingType);
    const sentAt = post?.sentAt === undefined
        ? undefined
        : post.sentAt === null ? null : nullableString(post.sentAt);
    const sharedNow = typeof post?.sharedNow === 'boolean' ? post.sharedNow : null;
    const shareMode = post?.shareMode === undefined ? null : nullableString(post.shareMode);
    const status = nullableString(post?.status);
    const text = post?.text === undefined ? null : nullableString(post.text);

    if (!isObject(post) || id === null || status === null) {
        return {
            id,
            kind: 'invalid_payload',
            step: mutationStep(request, { outcome: 'invalid_payload', responseType }),
        };
    }

    return {
        channelId,
        channelService,
        dueAt,
        externalLink,
        id,
        kind: request.classification === 'success' ? 'success' : 'partial_success',
        schedulingType,
        sentAt,
        sharedNow,
        shareMode,
        status,
        step: mutationStep(request, {
            outcome: request.classification === 'success' ? 'success' : 'partial_success',
            responseType,
        }),
        text,
    };
}

function normalizeDeleteAction(request, expectedPostId, secret) {
    if (request.classification !== 'success') {
        return { confirmed: false, step: mutationStep(request) };
    }

    const payload = request.payload?.data?.deletePost;
    const responseType = safeMutationResponseType(payload?.__typename, secret);

    if (!isObject(payload) || responseType === null) {
        return {
            confirmed: false,
            step: mutationStep(request, { outcome: 'invalid_payload' }),
        };
    }
    if (responseType === 'DeletePostSuccess') {
        const id = safeRemoteId(payload.id, secret);
        const confirmed = id !== null && id === expectedPostId;

        return {
            confirmed,
            step: mutationStep(request, {
                outcome: confirmed ? 'deleted' : 'invalid_payload',
                responseType,
            }),
        };
    }

    const messageHash = hashRemoteMessage(payload.message, secret);

    return {
        confirmed: false,
        step: mutationStep(request, {
            messageHash,
            outcome: messageHash === null ? 'invalid_payload' : 'typed_error',
            responseType: messageHash === null ? null : responseType,
        }),
    };
}

function normalizeDeleteVerification(request) {
    const notFound = request.http_status === 200
        && request.classification === 'not_found'
        && request.graphql_errors.length === 1
        && request.graphql_errors[0].code === 'NOT_FOUND'
        && Array.isArray(request.payload?.errors)
        && request.payload.errors.length === 1
        && request.payload?.data === null;

    return {
        confirmed: notFound,
        step: mutationStep(request, {
            outcome: notFound ? 'not_found_confirmed' : request.classification,
        }),
    };
}

function normalizeInspectedDraft(
    request,
    { channelId, editedText, initialText, postId, secret },
) {
    const deletionVerification = normalizeDeleteVerification(request);

    if (deletionVerification.confirmed) {
        return {
            id: null,
            kind: 'not_found',
            step: mutationStep(request, { outcome: 'not_found_confirmed' }),
        };
    }

    const post = request.payload?.data?.post;
    const id = safeRemoteId(post?.id, secret);
    const exactDraft = request.http_status === 200
        && request.classification === 'success'
        && request.graphql_errors.length === 0
        && isObject(post)
        && id === postId
        && post.channelId === channelId
        && post.channelService === 'facebook'
        && post.dueAt === null
        && post.externalLink === null
        && post.schedulingType === 'automatic'
        && post.sentAt === null
        && post.sharedNow === false
        && post.shareMode === 'addToQueue'
        && post.status === 'draft'
        && (post.text === initialText || post.text === editedText);

    return {
        id: exactDraft ? id : null,
        kind: exactDraft ? 'draft' : 'invalid',
        step: mutationStep(request, {
            outcome: exactDraft ? 'draft_confirmed' : 'inspection_unconfirmed',
        }),
    };
}

function probeClassification(result, expectedOperation) {
    const classification = nullableString(result?.classification);

    if (classification === 'success') {
        return isObject(result)
            && result.operation === expectedOperation
            && result.ok === true
            ? 'success'
            : 'invalid_payload';
    }

    return classification ?? 'probe_failure';
}

function hasExactStringCapability(value, expected) {
    return hasExactKeys(value, Object.keys(expected))
        && Object.entries(expected).every(([key, expectedValue]) => (
            Array.isArray(expectedValue)
                ? Array.isArray(value[key])
                    && value[key].length === expectedValue.length
                    && value[key].every((item, index) => item === expectedValue[index])
                : value[key] === expectedValue
        ));
}

function schemaProbeClassification(result, profile, capabilityName) {
    const classification = probeClassification(result, 'schema');
    if (classification !== 'success') {
        return classification;
    }

    const contract = result.data?.schema_contract;
    const capability = contract?.capabilities?.[capabilityName];
    const expectedCapability = capabilityName === 'facebook_create_metadata'
        ? EXPECTED_FACEBOOK_CREATE_METADATA_CAPABILITY
        : EXPECTED_POST_DELETE_CLEANUP_CAPABILITY;

    return isObject(contract)
        && contract.profile === profile
        && hasExactStringCapability(capability, expectedCapability)
        ? 'success'
        : 'invalid_payload';
}

function baseResult(draftMarker) {
    return {
        schema_version: 1,
        operation: 'facebook_draft_lifecycle',
        safety: {
            automatic_retries: 0,
            cleanup: 'single_delete_attempt_in_finally',
            draft_only: true,
            evidence_storage: 'ephemeral_do_not_commit',
            identifiers_in_output: false,
            production_allowed: false,
            publication_modes_allowed: false,
        },
        ok: false,
        classification: 'preflight_pending',
        draft_marker: draftMarker,
        channel: null,
        preflight: {
            account: 'not_attempted',
            channels: 'not_attempted',
            eligible_facebook_channel_count: null,
            mutation_capacity_available: null,
            organization_count: null,
            schema: 'not_attempted',
            target_fingerprint: null,
            target_fingerprint_matched: null,
        },
        steps: {
            create: notAttemptedStep(),
            delete: notAttemptedStep(),
            edit: notAttemptedStep(),
            move: notAttemptedStep(),
            verify_delete: notAttemptedStep(),
        },
        cleanup: {
            attempted: false,
            confirmed: false,
            manual_reconciliation_required: false,
            recovery_journal_armed: false,
            recovery_journal_cleared: false,
            state: 'not_required',
        },
    };
}

function baseCleanupResult(draftMarker) {
    return {
        schema_version: 1,
        operation: 'facebook_draft_cleanup',
        safety: {
            automatic_retries: 0,
            cleanup_only: true,
            identifiers_in_output: false,
            production_allowed: false,
            publication_modes_allowed: false,
        },
        ok: false,
        classification: 'cleanup_preflight_pending',
        draft_marker: draftMarker,
        channel: null,
        preflight: {
            account: 'not_attempted',
            channels: 'not_attempted',
            eligible_facebook_channel_count: null,
            mutation_capacity_available: null,
            organization_count: null,
            schema: 'not_attempted',
            target_fingerprint: null,
            target_fingerprint_matched: null,
        },
        steps: {
            inspect: notAttemptedStep(),
            delete: notAttemptedStep(),
            verify_delete: notAttemptedStep(),
        },
        cleanup: {
            attempted: false,
            confirmed: false,
            manual_reconciliation_required: true,
            recovery_journal_armed: true,
            recovery_journal_cleared: false,
            state: 'recovery_required',
        },
    };
}

function selectedFacebookChannel(channels, organizationId) {
    const eligibleChannels = channels.filter((channel) => (
        channel.organization_id === organizationId
        && channel.service.toLowerCase() === 'facebook'
        && channel.type.toLowerCase() === 'page'
        && channel.is_disconnected === false
        && channel.is_locked === false
        && channel.is_queue_paused === false
        && channel.allowed_actions.includes('managePostingSchedule')
        && channel.allowed_actions.includes('manageUpdates')
        && channel.allowed_actions.includes('readUpdates')
        && channel.allowed_actions.includes('viewPublish')
    ));

    return { channel: eligibleChannels.length === 1 ? eligibleChannels[0] : null, count: eligibleChannels.length };
}

function validateMutationDocuments() {
    const documents = [
        BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
    ];

    if (documents.some((document) => !/^\s*mutation\b/u.test(document))
        || documents.some((document) => [...FORBIDDEN_SCHEDULING_MODES].some((mode) => (
            document.includes(mode)
        )))) {
        fail('MUTATION_DOCUMENT_SAFETY_VIOLATION');
    }
}

export async function executeBufferWp1FacebookDraftLifecycle({
    accessToken,
    authorization,
    environment,
    fetchImpl = globalThis.fetch,
    mutationProbeEnabled = false,
    probeImpl = executeBufferWp1Probe,
    probeEnabled = false,
    recoveryJournal,
    runId = randomBytes(16).toString('hex'),
    shouldStop = () => false,
    targetFingerprint: expectedTargetFingerprint = null,
    timeoutMs = 10000,
} = {}) {
    validatedEnvironments([environment]);
    const token = requiredAccessToken(accessToken);
    const requestTimeout = normalizedTimeout(timeoutMs);

    if (probeEnabled !== true) {
        fail('PROBE_DISABLED');
    }
    if (mutationProbeEnabled !== true) {
        fail('MUTATION_PROBE_DISABLED');
    }
    if (authorization !== BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION) {
        fail('MUTATION_CONFIRMATION_REQUIRED');
    }
    const journal = validatedRecoveryJournal(recoveryJournal);
    if (typeof fetchImpl !== 'function'
        || typeof probeImpl !== 'function'
        || typeof shouldStop !== 'function') {
        fail('FETCH_IMPLEMENTATION_REQUIRED');
    }
    if (nullableString(runId) === null || !RUN_ID_PATTERN.test(runId)) {
        fail('RUN_ID_INVALID');
    }
    if (expectedTargetFingerprint !== null
        && (typeof expectedTargetFingerprint !== 'string'
            || !SHA256_PATTERN.test(expectedTargetFingerprint))) {
        fail('TARGET_FINGERPRINT_INVALID');
    }

    validateMutationDocuments();

    const draftMarker = `MALIKIA-WP1C-${runId}`;
    const initialText = `[MALIKIA WP1-C TEMP DRAFT - DO NOT PUBLISH] ${draftMarker}`;
    const editedText = `${initialText} - EDITED`;

    if (draftMarker.includes(token)
        || initialText.includes(token)
        || editedText.includes(token)
        || expectedTargetFingerprint?.includes(token)) {
        fail('LOCAL_MARKER_SECRET_COLLISION');
    }

    const result = baseResult(draftMarker);
    if (shouldStop()) {
        result.classification = 'interrupted_before_create';

        return safeLifecycleResult(result, token);
    }
    const probeOptions = {
        accessToken: token,
        environment,
        fetchImpl,
        timeoutMs: requestTimeout,
    };
    let schemaResult;

    try {
        schemaResult = await probeImpl({ ...probeOptions, schema: true, schemaProfile: 'full' });
    } catch {
        result.classification = 'schema_probe_failure';
        result.preflight.schema = 'probe_failure';

        return safeLifecycleResult(result, token);
    }

    result.preflight.schema = schemaProbeClassification(
        schemaResult,
        'full',
        'facebook_create_metadata',
    );
    if (result.preflight.schema !== 'success') {
        result.classification = 'schema_contract_unavailable';

        return safeLifecycleResult(result, token);
    }

    let accountResult;
    try {
        accountResult = await probeImpl(probeOptions);
    } catch {
        result.classification = 'account_probe_failure';
        result.preflight.account = 'probe_failure';

        return safeLifecycleResult(result, token);
    }

    result.preflight.account = probeClassification(accountResult, 'account');
    if (result.preflight.account !== 'success') {
        result.classification = 'account_contract_unavailable';

        return safeLifecycleResult(result, token);
    }

    const organizations = accountResult.data?.account?.organizations;
    result.preflight.organization_count = Array.isArray(organizations) ? organizations.length : null;
    if (!Array.isArray(organizations) || organizations.length !== 1) {
        result.classification = 'organization_selection_ambiguous';

        return safeLifecycleResult(result, token);
    }

    const organizationId = nullableString(organizations[0]?.id);
    if (organizationId === null) {
        result.classification = 'organization_selection_invalid';

        return safeLifecycleResult(result, token);
    }

    let channelsResult;
    try {
        channelsResult = await probeImpl({ ...probeOptions, organizationId });
    } catch {
        result.classification = 'channels_probe_failure';
        result.preflight.channels = 'probe_failure';

        return safeLifecycleResult(result, token);
    }

    result.preflight.channels = probeClassification(channelsResult, 'channels');
    if (result.preflight.channels !== 'success') {
        result.classification = 'channels_contract_unavailable';

        return safeLifecycleResult(result, token);
    }

    result.preflight.mutation_capacity_available = probeHasMutationCapacity(
        channelsResult,
        REQUIRED_MUTATION_CAPACITY,
    );
    if (!result.preflight.mutation_capacity_available) {
        result.classification = 'insufficient_mutation_quota';

        return safeLifecycleResult(result, token);
    }

    const channels = channelsResult.data?.channels;
    if (!Array.isArray(channels)) {
        result.classification = 'channels_contract_invalid';

        return safeLifecycleResult(result, token);
    }

    const selection = selectedFacebookChannel(channels, organizationId);
    result.preflight.eligible_facebook_channel_count = selection.count;
    if (selection.channel === null) {
        result.classification = 'facebook_channel_selection_ambiguous';

        return safeLifecycleResult(result, token);
    }

    const channelId = selection.channel.id;
    const observedTargetFingerprint = targetFingerprint(organizationId, channelId);
    result.preflight.target_fingerprint = observedTargetFingerprint;
    result.preflight.target_fingerprint_matched = expectedTargetFingerprint
        === observedTargetFingerprint;
    if (expectedTargetFingerprint === null) {
        result.classification = 'target_confirmation_required';

        return safeLifecycleResult(result, token);
    }
    if (!result.preflight.target_fingerprint_matched) {
        result.classification = 'target_confirmation_mismatch';

        return safeLifecycleResult(result, token);
    }

    result.channel = { service: 'facebook', type: 'page' };
    if (shouldStop()) {
        result.classification = 'interrupted_before_create';

        return safeLifecycleResult(result, token);
    }

    try {
        await journal.acquire();
    } catch (error) {
        result.classification = error instanceof BufferWp1FacebookDraftFailure
            && error.code === 'RECOVERY_CLEANUP_IN_PROGRESS'
            ? 'operation_in_progress'
            : 'recovery_lock_failed';

        return safeLifecycleResult(result, token);
    }

    try {
        const initialJournalRecord = recoveryJournalRecord({
        draftMarker,
        postId: null,
        runId,
        state: 'create_pending',
        targetFingerprint: observedTargetFingerprint,
        });

    try {
        await journal.begin(initialJournalRecord);
        result.cleanup.recovery_journal_armed = true;
    } catch (error) {
        result.classification = error instanceof BufferWp1FacebookDraftFailure
            && error.code === 'RECOVERY_REQUIRED'
            ? 'recovery_required'
            : 'recovery_journal_write_failed';
        result.cleanup.manual_reconciliation_required = result.classification === 'recovery_required';

        return safeLifecycleResult(result, token);
    }

    if (shouldStop()) {
        result.classification = 'interrupted_before_create';

        try {
            await journal.complete();
            result.cleanup.recovery_journal_cleared = true;
        } catch {
            result.classification = 'recovery_journal_clear_failed';
            result.cleanup.manual_reconciliation_required = true;
        }

        return safeLifecycleResult(result, token);
    }

    let createdPostId = null;
    let lifecycleSucceeded = false;
    let lifecycleSuccessClassification = null;
    let quotaEvidenceComplete = true;

    lifecycle: {
        try {
            const createRequest = await executeMutationRequest({
                accessToken: token,
                fetchImpl,
                query: BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION,
                timeoutMs: requestTimeout,
                variables: {
                    input: {
                        assets: [],
                        channelId,
                        mode: 'addToQueue',
                        needsApproval: false,
                        saveToDraft: true,
                        schedulingType: 'automatic',
                        text: initialText,
                    },
                },
            });
            const createAction = normalizePostAction(createRequest, 'createPost', token);
            result.steps.create = createAction.step;
            quotaEvidenceComplete &&= createRequest.quota.complete;

            if (createAction.id !== null) {
                createdPostId = createAction.id;
                result.cleanup.state = 'required';

                try {
                    await journal.update(recoveryJournalRecord({
                        draftMarker,
                        postId: createdPostId,
                        runId,
                        state: 'cleanup_required',
                        targetFingerprint: observedTargetFingerprint,
                    }));
                } catch {
                    result.classification = 'recovery_journal_write_failed';
                    result.cleanup.manual_reconciliation_required = true;

                    break lifecycle;
                }
            }
            if (shouldStop() && createdPostId !== null) {
                result.classification = 'interrupted_after_create';

                break lifecycle;
            }
            if (createAction.kind !== 'success') {
                if (createdPostId === null
                    && createAction.kind === 'typed_error'
                    && DEFINITIVE_CREATE_REJECTION_TYPES.has(
                        createAction.step.response_type,
                    )) {
                    result.classification = 'create_rejected';
                } else if (createdPostId === null) {
                    result.classification = 'create_outcome_unknown';
                    result.cleanup.manual_reconciliation_required = true;
                    result.cleanup.state = 'creation_outcome_unknown';

                    await journal.update(recoveryJournalRecord({
                        draftMarker,
                        postId: null,
                        runId,
                        state: 'creation_outcome_unknown',
                        targetFingerprint: observedTargetFingerprint,
                    })).catch(() => {});
                } else {
                    result.classification = 'create_partial_response';
                }

                break lifecycle;
            }
            if (createAction.status !== 'draft'
                || createAction.channelId !== channelId
                || createAction.channelService !== 'facebook'
                || createAction.dueAt !== null
                || createAction.externalLink !== null
                || createAction.schedulingType !== 'automatic'
                || createAction.sentAt !== null
                || createAction.sharedNow !== false
                || createAction.shareMode !== 'addToQueue'
                || createAction.text !== initialText) {
                result.steps.create.outcome = 'draft_invariant_failed';
                result.classification = 'create_draft_invariant_failed';

                break lifecycle;
            }
            if (!createRequest.quota.complete) {
                result.classification = 'incomplete_quota_evidence';

                break lifecycle;
            }
            if (!quotaHasCapacity(createRequest.quota, 4)) {
                result.classification = 'insufficient_remaining_quota_after_create';

                break lifecycle;
            }
            if (shouldStop()) {
                result.classification = 'interrupted_after_create';

                break lifecycle;
            }

            const editRequest = await executeMutationRequest({
                accessToken: token,
                fetchImpl,
                query: BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION,
                timeoutMs: requestTimeout,
                variables: {
                    input: {
                        id: createdPostId,
                        saveToDraft: true,
                        text: editedText,
                    },
                },
            });
            const editAction = normalizePostAction(editRequest, 'editPost', token);
            result.steps.edit = editAction.step;
            quotaEvidenceComplete &&= editRequest.quota.complete;

            if (editAction.kind !== 'success'
                || editAction.id !== createdPostId
                || editAction.status !== 'draft'
                || editAction.channelId !== channelId
                || editAction.channelService !== 'facebook'
                || editAction.dueAt !== null
                || editAction.externalLink !== null
                || editAction.schedulingType !== 'automatic'
                || editAction.sentAt !== null
                || editAction.sharedNow !== false
                || editAction.shareMode !== 'addToQueue'
                || editAction.text !== editedText) {
                result.steps.edit.outcome = editAction.kind === 'success'
                    ? 'draft_invariant_failed'
                    : result.steps.edit.outcome;
                result.classification = editRequest.classification === 'timeout'
                    ? 'edit_outcome_unknown'
                    : 'edit_failed';

                break lifecycle;
            }
            if (!editRequest.quota.complete) {
                result.classification = 'incomplete_quota_evidence';

                break lifecycle;
            }
            if (!quotaHasCapacity(editRequest.quota, 3)) {
                result.classification = 'insufficient_remaining_quota_after_edit';

                break lifecycle;
            }
            if (shouldStop()) {
                result.classification = 'interrupted_after_edit';

                break lifecycle;
            }

            const moveRequest = await executeMutationRequest({
                accessToken: token,
                fetchImpl,
                query: BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION,
                timeoutMs: requestTimeout,
                variables: { input: { id: createdPostId, position: 'bottom' } },
            });
            const moveAction = normalizePostAction(moveRequest, 'movePostInQueue', token);
            result.steps.move = moveAction.step;
            quotaEvidenceComplete &&= moveRequest.quota.complete;

            if (moveAction.kind === 'typed_error'
                && moveAction.step.response_type === 'VoidMutationError') {
                result.steps.move.outcome = 'draft_move_rejected';
                result.classification = 'draft_move_rejected';
            } else if (moveAction.kind === 'success'
                && moveAction.id === createdPostId
                && moveAction.channelId === channelId
                && moveAction.status === 'draft'
                && moveAction.channelService === 'facebook'
                && moveAction.dueAt === null
                && moveAction.externalLink === null
                && moveAction.schedulingType === 'automatic'
                && moveAction.sentAt === null
                && moveAction.sharedNow === false
                && moveAction.shareMode === 'addToQueue'
                && moveAction.text === editedText) {
                result.steps.move.outcome = 'draft_preserved';
                lifecycleSucceeded = true;
                lifecycleSuccessClassification = 'draft_move_preserved_cleanup_confirmed';
            } else {
                result.steps.move.outcome = moveAction.kind === 'success'
                    ? 'draft_invariant_failed'
                    : result.steps.move.outcome;
                result.classification = moveRequest.classification === 'timeout'
                    ? 'move_outcome_unknown'
                    : 'move_failed';
            }
        } finally {
            if (createdPostId !== null) {
                result.cleanup.attempted = true;
                const deleteRequest = await executeMutationRequest({
                    accessToken: token,
                    fetchImpl,
                    query: BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
                    timeoutMs: requestTimeout,
                    variables: { input: { id: createdPostId } },
                });
                const deleteAction = normalizeDeleteAction(deleteRequest, createdPostId, token);
                result.steps.delete = deleteAction.step;
                quotaEvidenceComplete &&= deleteRequest.quota.complete;

                if (deleteAction.confirmed) {
                    const verifyRequest = await executeMutationRequest({
                        accessToken: token,
                        fetchImpl,
                        query: BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
                        timeoutMs: requestTimeout,
                        variables: { input: { id: createdPostId } },
                    });
                    const verification = normalizeDeleteVerification(verifyRequest);
                    result.steps.verify_delete = verification.step;
                    result.cleanup.confirmed = verification.confirmed;
                    quotaEvidenceComplete &&= verifyRequest.quota.complete;
                }

                result.cleanup.manual_reconciliation_required = !result.cleanup.confirmed;
                result.cleanup.state = result.cleanup.confirmed
                    ? 'confirmed_deleted'
                    : deleteAction.confirmed
                        ? 'delete_verification_unconfirmed'
                        : 'delete_unconfirmed';
            }
        }
    }

    if (result.cleanup.attempted && !result.cleanup.confirmed) {
        result.classification = 'cleanup_failed';

        return safeLifecycleResult(result, token);
    }
    if (createdPostId === null) {
        if (result.classification === 'create_rejected') {
            try {
                await journal.complete();
                result.cleanup.recovery_journal_cleared = true;
            } catch {
                result.classification = 'recovery_journal_clear_failed';
                result.cleanup.manual_reconciliation_required = true;
            }
        }

        return safeLifecycleResult(result, token);
    }
    if (result.cleanup.confirmed) {
        try {
            await journal.complete();
            result.cleanup.recovery_journal_cleared = true;
        } catch {
            result.classification = 'recovery_journal_clear_failed';
            result.cleanup.manual_reconciliation_required = true;

            return safeLifecycleResult(result, token);
        }
    }
    if (!lifecycleSucceeded) {
        return safeLifecycleResult(result, token);
    }
    if (!quotaEvidenceComplete) {
        result.classification = 'incomplete_quota_evidence';

        return safeLifecycleResult(result, token);
    }

    result.ok = true;
    result.classification = lifecycleSuccessClassification;

        return safeLifecycleResult(result, token);
    } finally {
        await journal.release();
    }
}

export async function executeBufferWp1FacebookDraftCleanup({
    accessToken,
    authorization,
    environment,
    fetchImpl = globalThis.fetch,
    mutationProbeEnabled = false,
    probeImpl = executeBufferWp1Probe,
    probeEnabled = false,
    recoveryJournal,
    targetFingerprint: expectedTargetFingerprint = null,
    timeoutMs = 10000,
} = {}) {
    validatedEnvironments([environment]);
    const token = requiredAccessToken(accessToken);
    const requestTimeout = normalizedTimeout(timeoutMs);

    if (probeEnabled !== true) {
        fail('PROBE_DISABLED');
    }
    if (mutationProbeEnabled !== true) {
        fail('MUTATION_PROBE_DISABLED');
    }
    if (authorization !== BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION) {
        fail('MUTATION_CONFIRMATION_REQUIRED');
    }
    if (typeof fetchImpl !== 'function' || typeof probeImpl !== 'function') {
        fail('FETCH_IMPLEMENTATION_REQUIRED');
    }
    if (typeof expectedTargetFingerprint !== 'string'
        || !SHA256_PATTERN.test(expectedTargetFingerprint)) {
        fail('TARGET_FINGERPRINT_REQUIRED');
    }
    if (expectedTargetFingerprint.includes(token)) {
        fail('LOCAL_MARKER_SECRET_COLLISION');
    }

    validateMutationDocuments();
    const journal = validatedCleanupRecoveryJournal(recoveryJournal);
    let lockAcquired = false;

    try {
        await journal.acquire();
        lockAcquired = true;

        const record = validatedRecoveryRecord(await journal.read(), token);
        const result = baseCleanupResult(record.draftMarker);
        const initialText = `[MALIKIA WP1-C TEMP DRAFT - DO NOT PUBLISH] ${record.draftMarker}`;
        const editedText = `${initialText} - EDITED`;

        if (record.postId === null) {
            result.classification = 'cleanup_identifier_unavailable';
            result.cleanup.state = record.state;

            return safeLifecycleResult(result, token);
        }
        if (record.targetFingerprint !== expectedTargetFingerprint) {
            result.classification = 'target_confirmation_mismatch';
            result.preflight.target_fingerprint = record.targetFingerprint;
            result.preflight.target_fingerprint_matched = false;

            return safeLifecycleResult(result, token);
        }

        const probeOptions = {
            accessToken: token,
            environment,
            fetchImpl,
            timeoutMs: requestTimeout,
        };
        let schemaResult;

        try {
            schemaResult = await probeImpl({
                ...probeOptions,
                schema: true,
                schemaProfile: 'cleanup',
            });
        } catch {
            result.classification = 'schema_probe_failure';
            result.preflight.schema = 'probe_failure';

            return safeLifecycleResult(result, token);
        }

        result.preflight.schema = schemaProbeClassification(
            schemaResult,
            'cleanup',
            'post_delete_cleanup',
        );
        if (result.preflight.schema !== 'success') {
            result.classification = 'schema_contract_unavailable';

            return safeLifecycleResult(result, token);
        }

        let accountResult;
        try {
            accountResult = await probeImpl(probeOptions);
        } catch {
            result.classification = 'account_probe_failure';
            result.preflight.account = 'probe_failure';

            return safeLifecycleResult(result, token);
        }

        result.preflight.account = probeClassification(accountResult, 'account');
        if (result.preflight.account !== 'success') {
            result.classification = 'account_contract_unavailable';

            return safeLifecycleResult(result, token);
        }

        const organizations = accountResult.data?.account?.organizations;
        result.preflight.organization_count = Array.isArray(organizations)
            ? organizations.length
            : null;
        if (!Array.isArray(organizations) || organizations.length !== 1) {
            result.classification = 'organization_selection_ambiguous';

            return safeLifecycleResult(result, token);
        }

        const organizationId = nullableString(organizations[0]?.id);
        if (organizationId === null) {
            result.classification = 'organization_selection_invalid';

            return safeLifecycleResult(result, token);
        }

        let channelsResult;
        try {
            channelsResult = await probeImpl({ ...probeOptions, organizationId });
        } catch {
            result.classification = 'channels_probe_failure';
            result.preflight.channels = 'probe_failure';

            return safeLifecycleResult(result, token);
        }

        result.preflight.channels = probeClassification(channelsResult, 'channels');
        if (result.preflight.channels !== 'success') {
            result.classification = 'channels_contract_unavailable';

            return safeLifecycleResult(result, token);
        }

        result.preflight.mutation_capacity_available = probeHasMutationCapacity(
            channelsResult,
            REQUIRED_CLEANUP_CAPACITY,
        );
        if (!result.preflight.mutation_capacity_available) {
            result.classification = 'insufficient_cleanup_quota';

            return safeLifecycleResult(result, token);
        }

        const channels = channelsResult.data?.channels;
        if (!Array.isArray(channels)) {
            result.classification = 'channels_contract_invalid';

            return safeLifecycleResult(result, token);
        }

        const selection = selectedFacebookChannel(channels, organizationId);
        result.preflight.eligible_facebook_channel_count = selection.count;
        if (selection.channel === null) {
            result.classification = 'facebook_channel_selection_ambiguous';

            return safeLifecycleResult(result, token);
        }

        const channelId = selection.channel.id;
        const observedTargetFingerprint = targetFingerprint(organizationId, channelId);
        result.preflight.target_fingerprint = observedTargetFingerprint;
        result.preflight.target_fingerprint_matched = observedTargetFingerprint
            === expectedTargetFingerprint;
        if (!result.preflight.target_fingerprint_matched) {
            result.classification = 'target_confirmation_mismatch';

            return safeLifecycleResult(result, token);
        }

        result.channel = { service: 'facebook', type: 'page' };
        const inspectRequest = await executeMutationRequest({
            accessToken: token,
            fetchImpl,
            query: BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY,
            timeoutMs: requestTimeout,
            variables: { input: { id: record.postId } },
        });
        const inspectedDraft = normalizeInspectedDraft(inspectRequest, {
            channelId,
            editedText,
            initialText,
            postId: record.postId,
            secret: token,
        });
        result.steps.inspect = inspectedDraft.step;

        if (inspectedDraft.kind === 'not_found') {
            result.cleanup.attempted = true;
            result.cleanup.confirmed = true;
            result.cleanup.manual_reconciliation_required = false;
            result.cleanup.state = 'confirmed_deleted';

            try {
                await journal.complete();
                result.cleanup.recovery_journal_cleared = true;
                result.ok = true;
                result.classification = 'cleanup_already_confirmed';
            } catch {
                result.classification = 'recovery_journal_clear_failed';
                result.cleanup.manual_reconciliation_required = true;
            }

            return safeLifecycleResult(result, token);
        }
        if (inspectedDraft.kind !== 'draft') {
            result.classification = 'cleanup_inspection_unconfirmed';

            return safeLifecycleResult(result, token);
        }
        if (!inspectRequest.quota.complete || !quotaHasCapacity(inspectRequest.quota, 2)) {
            result.classification = 'insufficient_remaining_cleanup_quota';

            return safeLifecycleResult(result, token);
        }

        result.cleanup.attempted = true;
        const deleteRequest = await executeMutationRequest({
            accessToken: token,
            fetchImpl,
            query: BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
            timeoutMs: requestTimeout,
            variables: { input: { id: record.postId } },
        });
        const deleteAction = normalizeDeleteAction(deleteRequest, record.postId, token);
        result.steps.delete = deleteAction.step;

        if (deleteAction.confirmed) {
            const verifyRequest = await executeMutationRequest({
                accessToken: token,
                fetchImpl,
                query: BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
                timeoutMs: requestTimeout,
                variables: { input: { id: record.postId } },
            });
            const verification = normalizeDeleteVerification(verifyRequest);
            result.steps.verify_delete = verification.step;
            result.cleanup.confirmed = verification.confirmed;
        }

        result.cleanup.manual_reconciliation_required = !result.cleanup.confirmed;
        result.cleanup.state = result.cleanup.confirmed
            ? 'confirmed_deleted'
            : deleteAction.confirmed
                ? 'delete_verification_unconfirmed'
                : 'delete_unconfirmed';

        if (!result.cleanup.confirmed) {
            result.classification = 'cleanup_failed';

            return safeLifecycleResult(result, token);
        }

        try {
            await journal.complete();
            result.cleanup.recovery_journal_cleared = true;
        } catch {
            result.classification = 'recovery_journal_clear_failed';
            result.cleanup.manual_reconciliation_required = true;

            return safeLifecycleResult(result, token);
        }

        result.ok = true;
        result.classification = 'cleanup_confirmed';

        return safeLifecycleResult(result, token);
    } finally {
        if (lockAcquired) {
            await journal.release();
        }
    }
}

function parseArguments(argv) {
    let execute = false;
    let cleanupOnly = false;
    let confirmation = null;
    let confirmationSeen = false;

    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];

        if (argument === '--help') {
            return { confirmation: null, execute: false, help: true };
        }
        if (argument === '--execute-facebook-draft-lifecycle') {
            if (execute) {
                fail('ARGUMENT_DUPLICATE');
            }

            execute = true;
            continue;
        }
        if (argument === '--cleanup-only') {
            if (cleanupOnly) {
                fail('ARGUMENT_DUPLICATE');
            }

            cleanupOnly = true;
            continue;
        }
        if (argument === '--confirm-delete-temporary-draft') {
            if (confirmationSeen) {
                fail('ARGUMENT_DUPLICATE');
            }

            confirmationSeen = true;
            confirmation = argv[index + 1];
            if (confirmation === undefined || confirmation.startsWith('--')) {
                fail('MUTATION_CONFIRMATION_REQUIRED');
            }

            index += 1;
            continue;
        }
        if (argument.startsWith('--confirm-delete-temporary-draft=')) {
            if (confirmationSeen) {
                fail('ARGUMENT_DUPLICATE');
            }

            confirmationSeen = true;
            confirmation = argument.slice('--confirm-delete-temporary-draft='.length);
            continue;
        }

        fail('ARGUMENT_INVALID');
    }

    if (execute && cleanupOnly) {
        fail('ARGUMENT_CONFLICT');
    }
    if (!execute && !cleanupOnly) {
        fail('MUTATION_EXECUTION_FLAG_REQUIRED');
    }
    if (confirmation !== BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION) {
        fail('MUTATION_CONFIRMATION_REQUIRED');
    }

    return { confirmation, help: false, mode: cleanupOnly ? 'cleanup' : 'lifecycle' };
}

function safeConfigurationFailure(code) {
    return {
        schema_version: 1,
        operation: 'facebook_draft_lifecycle',
        safety: {
            automatic_retries: 0,
            draft_only: true,
            identifiers_in_output: false,
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

function interruptGuard(signalSource) {
    if (typeof signalSource?.on !== 'function' || typeof signalSource?.off !== 'function') {
        fail('SIGNAL_SOURCE_INVALID');
    }

    let receivedSignal = null;
    const handlers = new Map([
        ['SIGINT', () => {
            receivedSignal ??= 'SIGINT';
        }],
        ['SIGTERM', () => {
            receivedSignal ??= 'SIGTERM';
        }],
    ]);

    for (const [signal, handler] of handlers) {
        signalSource.on(signal, handler);
    }

    return {
        dispose() {
            for (const [signal, handler] of handlers) {
                signalSource.off(signal, handler);
            }
        },
        exitCode() {
            return receivedSignal === 'SIGINT' ? 130 : receivedSignal === 'SIGTERM' ? 143 : null;
        },
        shouldStop() {
            return receivedSignal !== null;
        },
    };
}

export async function runBufferWp1FacebookDraftLifecycleCli({
    argv = process.argv.slice(2),
    env = process.env,
    fetchImpl = globalThis.fetch,
    probeImpl = executeBufferWp1Probe,
    recoveryJournal = createBufferWp1FacebookDraftRecoveryJournal(),
    runId,
    signalSource = process,
    stdout = (value) => process.stdout.write(value),
    stderr = (value) => process.stderr.write(value),
} = {}) {
    let signals = null;

    try {
        const arguments_ = parseArguments(argv);

        if (arguments_.help) {
            stdout('Usage: npm run pulse:buffer:facebook-draft-lifecycle -- (--execute-facebook-draft-lifecycle | --cleanup-only) --confirm-delete-temporary-draft=DELETE_TEMPORARY_FACEBOOK_DRAFT_AFTER_TEST\n');
            return 0;
        }

        const environments = validatedEnvironments([env.APP_ENV, env.NODE_ENV]);
        if (!enabled(env.BUFFER_WP1_PROBE_ENABLED)) {
            fail('PROBE_DISABLED');
        }
        if (!enabled(env.BUFFER_WP1_MUTATION_PROBE_ENABLED)) {
            fail('MUTATION_PROBE_DISABLED');
        }

        signals = interruptGuard(signalSource);
        const options = {
            accessToken: env.BUFFER_WP1_PROBE_ACCESS_TOKEN,
            authorization: arguments_.confirmation,
            environment: environments[0],
            fetchImpl,
            mutationProbeEnabled: true,
            probeImpl,
            probeEnabled: true,
            recoveryJournal,
            targetFingerprint: env.BUFFER_WP1_FACEBOOK_TARGET_FINGERPRINT || null,
            timeoutMs: env.BUFFER_WP1_PROBE_TIMEOUT_MS ?? 10000,
        };
        const result = arguments_.mode === 'cleanup'
            ? await executeBufferWp1FacebookDraftCleanup(options)
            : await executeBufferWp1FacebookDraftLifecycle({
                ...options,
                runId,
                shouldStop: signals.shouldStop,
            });

        writeJson(stdout, result);

        return signals.exitCode() ?? (result.ok ? 0 : 1);
    } catch (error) {
        const code = error instanceof BufferWp1FacebookDraftFailure
            || error instanceof BufferWp1ProbeFailure
            ? error.code
            : 'MUTATION_PROBE_CONFIGURATION_FAILED';
        writeJson(stderr, safeConfigurationFailure(code));

        return 1;
    } finally {
        signals?.dispose();
    }
}

const isMainModule = process.argv[1]
    && fileURLToPath(import.meta.url) === resolve(process.argv[1]);

if (isMainModule) {
    process.exitCode = await runBufferWp1FacebookDraftLifecycleCli();
}
