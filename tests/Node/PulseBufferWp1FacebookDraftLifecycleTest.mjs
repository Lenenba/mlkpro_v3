import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { EventEmitter } from 'node:events';
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import {
    BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION,
    BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
    BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION,
    BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION,
    BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY,
    BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION,
    BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
    BufferWp1FacebookDraftFailure,
    createBufferWp1FacebookDraftRecoveryJournal,
    executeBufferWp1FacebookDraftCleanup,
    executeBufferWp1FacebookDraftLifecycle,
    runBufferWp1FacebookDraftLifecycleCli,
} from '../../scripts/spikes/buffer/wp1-facebook-draft-lifecycle.mjs';

const ACCESS_TOKEN = 'buffer-wp1c-secret-token';
const ORGANIZATION_ID = 'organization-1';
const CHANNEL_ID = 'facebook-channel-1';
const POST_ID = 'facebook-draft-post-1';
const RUN_ID = '20260827T120000Z';
const TARGET_FINGERPRINT = createHash('sha256')
    .update(`${ORGANIZATION_ID}\0${CHANNEL_ID}`)
    .digest('hex');
const DRAFT_MARKER = `MALIKIA-WP1C-${RUN_ID}`;
const INITIAL_TEXT = `[MALIKIA WP1-C TEMP DRAFT - DO NOT PUBLISH] ${DRAFT_MARKER}`;
const EDITED_TEXT = `${INITIAL_TEXT} - EDITED`;
function quotaHeaders(remaining = 90) {
    return {
        RateLimit: [
            `"100-in-15min";r=${remaining};t=800`,
            `"250-in-1day";r=${remaining};t=86000`,
            `"3000-in-30days";r=${remaining};t=2591000`,
        ].join(', '),
        'RateLimit-Policy': [
            '"100-in-15min";q=100;w=900;pk=:private-partition:',
            '"250-in-1day";q=250;w=86400;pk=:private-partition:',
            '"3000-in-30days";q=3000;w=2592000;pk=:private-partition:',
        ].join(', '),
    };
}

const QUOTA_HEADERS = quotaHeaders();

function jsonResponse(payload, status = 200, headers = QUOTA_HEADERS) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: {
            'Content-Type': 'application/json',
            ...headers,
        },
    });
}

function invalidJsonResponse(status = 200) {
    return new Response('{invalid-json', {
        status,
        headers: {
            'Content-Type': 'application/json',
            ...QUOTA_HEADERS,
        },
    });
}

function responseWithoutStream(status = 200) {
    return new Response(null, {
        status,
        headers: {
            'Content-Type': 'application/json',
            ...QUOTA_HEADERS,
        },
    });
}

function oversizedResponse(status = 200) {
    return new Response('x'.repeat((1024 * 1024) + 1), {
        status,
        headers: {
            'Content-Type': 'application/json',
            ...QUOTA_HEADERS,
        },
    });
}

function rejectedRequest(name = 'Error') {
    return async () => {
        const error = new Error('sanitized simulated request failure');
        error.name = name;

        throw error;
    };
}

function healthyChannel(overrides = {}) {
    return {
        id: CHANNEL_ID,
        organization_id: ORGANIZATION_ID,
        name: 'private-channel-name',
        display_name: 'Private Facebook Page',
        service: 'facebook',
        type: 'page',
        is_disconnected: false,
        is_locked: false,
        is_queue_paused: false,
        timezone: 'America/Toronto',
        scopes: ['pages_manage_posts'],
        allowed_actions: [
            'managePostingSchedule',
            'manageUpdates',
            'readUpdates',
            'viewPublish',
        ],
        ...overrides,
    };
}

function probeQuota(remaining = 90) {
    return {
        rate_limits: [
            `"100-in-15min";r=${remaining};t=800`,
            `"250-in-1day";r=${remaining};t=86000`,
            `"3000-in-30days";r=${remaining};t=2591000`,
        ],
        rate_limit_policies: [],
        retry_after_seconds: null,
    };
}

function schemaContract(profile) {
    return {
        capabilities: profile === 'cleanup'
            ? {
                post_delete_cleanup: {
                    delete_input: 'DeletePostInput!',
                    delete_payload: 'DeletePostPayload!',
                    delete_root_field: 'deletePost',
                    inspect_input: 'PostInput!',
                    inspect_metadata_field: 'metadata:PostMetadata',
                    inspect_metadata_member: 'FacebookPostMetadata',
                    inspect_metadata_type_field: 'type:PostType!',
                    inspect_metadata_type_value: 'post',
                    inspect_output: 'Post!',
                    inspect_root_field: 'post',
                },
            }
            : {
                facebook_create_metadata: {
                    facebook_field: 'facebook:FacebookPostMetadataInput',
                    metadata_input: 'PostInputMetaData',
                    post_type_field: 'type:PostTypeFacebook!',
                    post_types: ['post', 'reel', 'story'],
                },
                post_delete_cleanup: {
                    delete_input: 'DeletePostInput!',
                    delete_payload: 'DeletePostPayload!',
                    delete_root_field: 'deletePost',
                    inspect_input: 'PostInput!',
                    inspect_metadata_field: 'metadata:PostMetadata',
                    inspect_metadata_member: 'FacebookPostMetadata',
                    inspect_metadata_type_field: 'type:PostType!',
                    inspect_metadata_type_value: 'post',
                    inspect_output: 'Post!',
                    inspect_root_field: 'post',
                },
            },
        profile,
    };
}

function createProbe({
    channels = [healthyChannel()],
    organizations = [{ id: ORGANIZATION_ID, name: 'Private organization' }],
    remaining = 90,
    schemaClassification = 'success',
    schemaClassifications = {},
} = {}) {
    const calls = [];
    const probeImpl = async (options) => {
        calls.push(options);

        if (options.schema === true) {
            const profile = options.schemaProfile ?? 'full';
            const classification = schemaClassifications[profile] ?? schemaClassification;

            return {
                operation: 'schema',
                ok: classification === 'success',
                classification,
                data: {
                    schema_contract: classification === 'success'
                        ? schemaContract(profile)
                        : null,
                },
            };
        }
        if (options.organizationId !== undefined) {
            return {
                operation: 'channels',
                ok: true,
                classification: 'success',
                data: { channels },
                quota: probeQuota(remaining),
            };
        }

        return {
            operation: 'account',
            ok: true,
            classification: 'success',
            data: {
                account: {
                    id: 'private-account-id',
                    name: null,
                    organizations,
                    connected_apps: [],
                },
            },
            quota: probeQuota(remaining),
        };
    };

    return { calls, probeImpl };
}

function safePost({ id = POST_ID, text = INITIAL_TEXT, ...overrides } = {}) {
    return {
        id,
        channelId: CHANNEL_ID,
        channelService: 'facebook',
        dueAt: null,
        externalLink: null,
        metadata: {
            __typename: 'FacebookPostMetadata',
            type: 'post',
        },
        schedulingType: 'automatic',
        sentAt: null,
        sharedNow: false,
        shareMode: 'addToQueue',
        status: 'draft',
        text,
        ...overrides,
    };
}

function postAction(field, post) {
    return jsonResponse({
        data: {
            [field]: {
                __typename: 'PostActionSuccess',
                post,
            },
        },
    });
}

function typedAction(
    field,
    message = 'This draft is not in the queue',
    responseType = field === 'movePostInQueue' || field === 'deletePost'
        ? 'VoidMutationError'
        : 'InvalidInputError',
) {
    return jsonResponse({
        data: {
            [field]: {
                __typename: responseType,
                message,
            },
        },
    });
}

function deleteSuccess(id = POST_ID) {
    return jsonResponse({
        data: {
            deletePost: {
                __typename: 'DeletePostSuccess',
                id,
            },
        },
    });
}

function deleteNotFoundVerification() {
    return jsonResponse({
        data: null,
        errors: [{
            message: 'Post not found',
            extensions: { code: 'NOT_FOUND' },
        }],
    });
}

function createScriptedFetch(responses) {
    const requests = [];
    const fetchImpl = async (url, options) => {
        requests.push({ options, url });
        const response = responses.shift();

        if (response === undefined) {
            assert.fail('an unexpected Buffer mutation request was attempted');
        }
        if (typeof response === 'function') {
            return response(url, options);
        }

        return response;
    };

    return { fetchImpl, requests };
}

function createMemoryRecoveryJournal({ activeRecord = null } = {}) {
    const events = [];
    let record = activeRecord;
    let locked = false;

    return {
        events,
        get record() {
            return record;
        },
        async acquire() {
            events.push('acquire');
            if (locked) {
                throw new BufferWp1FacebookDraftFailure('RECOVERY_CLEANUP_IN_PROGRESS');
            }
            locked = true;
        },
        async begin(value) {
            events.push('begin');
            if (record !== null) {
                throw new BufferWp1FacebookDraftFailure('RECOVERY_REQUIRED');
            }
            record = structuredClone(value);
        },
        async complete() {
            events.push('complete');
            record = null;
        },
        async read() {
            events.push('read');
            if (record === null) {
                throw new BufferWp1FacebookDraftFailure('RECOVERY_JOURNAL_NOT_FOUND');
            }

            return structuredClone(record);
        },
        async release() {
            events.push('release');
            locked = false;
        },
        async update(value) {
            events.push('update');
            record = structuredClone(value);
        },
    };
}

function recoveryRecord({ postId = POST_ID, state = 'cleanup_required', ...overrides } = {}) {
    return {
        schema_version: 1,
        draft_marker: DRAFT_MARKER,
        post_id: postId,
        run_id: RUN_ID,
        state,
        target_fingerprint: TARGET_FINGERPRINT,
        ...overrides,
    };
}

function executeLifecycle({ fetchImpl, probeImpl, ...overrides } = {}) {
    return executeBufferWp1FacebookDraftLifecycle({
        accessToken: ACCESS_TOKEN,
        authorization: BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION,
        environment: 'testing',
        fetchImpl,
        mutationProbeEnabled: true,
        probeEnabled: true,
        probeImpl,
        recoveryJournal: createMemoryRecoveryJournal(),
        runId: RUN_ID,
        targetFingerprint: TARGET_FINGERPRINT,
        timeoutMs: 1000,
        ...overrides,
    });
}

function executeCleanup({ fetchImpl, probeImpl, recoveryJournal, ...overrides } = {}) {
    return executeBufferWp1FacebookDraftCleanup({
        accessToken: ACCESS_TOKEN,
        authorization: BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION,
        environment: 'testing',
        fetchImpl,
        mutationProbeEnabled: true,
        probeEnabled: true,
        probeImpl,
        recoveryJournal: recoveryJournal ?? createMemoryRecoveryJournal({
            activeRecord: recoveryRecord(),
        }),
        targetFingerprint: TARGET_FINGERPRINT,
        timeoutMs: 1000,
        ...overrides,
    });
}

function successfulResponses({
    move = postAction('movePostInQueue', safePost({ text: EDITED_TEXT })),
} = {}) {
    return [
        postAction('createPost', safePost()),
        postAction('editPost', safePost({ text: EDITED_TEXT })),
        move,
        deleteSuccess(),
        deleteNotFoundVerification(),
    ];
}

test('the WP1-C cycle sends only fixed draft mutations and deletes after a typed move rejection', async () => {
    const probe = createProbe();
    const scriptedFetch = createScriptedFetch(successfulResponses({
        move: typedAction('movePostInQueue'),
    }));
    const recoveryJournal = createMemoryRecoveryJournal();

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.ok, false);
    assert.equal(result.classification, 'draft_move_rejected');
    assert.equal(result.steps.move.outcome, 'draft_move_rejected');
    assert.deepEqual(result.cleanup, {
        attempted: true,
        confirmed: true,
        manual_reconciliation_required: false,
        recovery_journal_armed: true,
        recovery_journal_cleared: true,
        state: 'confirmed_deleted',
    });
    assert.equal(probe.calls.length, 3);
    assert.deepEqual(
        recoveryJournal.events,
        ['acquire', 'begin', 'update', 'complete', 'release'],
    );
    assert.equal(recoveryJournal.record, null);
    assert.equal(probe.calls[0].schema, true);
    assert.equal(probe.calls[0].schemaProfile, 'full');
    assert.equal(probe.calls[1].schema, undefined);
    assert.equal(probe.calls[2].organizationId, ORGANIZATION_ID);
    assert.equal(scriptedFetch.requests.length, 5);

    const bodies = scriptedFetch.requests.map((request) => JSON.parse(request.options.body));
    assert.deepEqual(bodies.map((body) => body.query), [
        BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
    ]);
    assert.deepEqual(bodies[0].variables, {
        input: {
            assets: [],
            channelId: CHANNEL_ID,
            metadata: { facebook: { type: 'post' } },
            mode: 'addToQueue',
            needsApproval: false,
            saveToDraft: true,
            schedulingType: 'automatic',
            text: INITIAL_TEXT,
        },
    });
    assert.deepEqual(bodies[1].variables, {
        input: {
            id: POST_ID,
            metadata: { facebook: { type: 'post' } },
            saveToDraft: true,
            text: EDITED_TEXT,
        },
    });
    assert.deepEqual(bodies[2].variables, { input: { id: POST_ID, position: 'bottom' } });
    assert.deepEqual(bodies[3].variables, { input: { id: POST_ID } });
    assert.deepEqual(bodies[4].variables, { input: { id: POST_ID } });
    for (const request of scriptedFetch.requests) {
        assert.equal(request.options.redirect, 'error');
        assert.equal(request.options.method, 'POST');
        assert.equal(request.options.headers.Authorization, `Bearer ${ACCESS_TOKEN}`);
    }
    for (const document of bodies.map((body) => body.query)) {
        assert.doesNotMatch(document, /shareNow|shareNext|customScheduled/u);
        assert.equal(document.includes(CHANNEL_ID), false);
        assert.equal(document.includes(POST_ID), false);
    }
    for (const document of [
        BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY,
    ]) {
        assert.match(
            document,
            /metadata\s*\{\s*__typename\s*\.\.\. on FacebookPostMetadata\s*\{\s*type\s*\}\s*\}/u,
        );
    }

    const serialized = JSON.stringify(result);
    assert.equal(serialized.includes(ACCESS_TOKEN), false);
    assert.equal(serialized.includes(CHANNEL_ID), false);
    assert.equal(serialized.includes(POST_ID), false);
    assert.equal(serialized.includes('private-partition'), false);
    assert.equal(serialized.includes('private-channel-name'), false);
});

test('the WP1-C cycle accepts a move only when every draft invariant remains true', async () => {
    const probe = createProbe();
    const scriptedFetch = createScriptedFetch(successfulResponses());

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.ok, true);
    assert.equal(result.classification, 'draft_move_preserved_cleanup_confirmed');
    assert.equal(result.steps.move.outcome, 'draft_preserved');
    assert.equal(result.cleanup.confirmed, true);
});

test('the WP1-C direct executor refuses every mutation gate before preflight or HTTP', async () => {
    const cases = [
        { overrides: { probeEnabled: false }, code: 'PROBE_DISABLED' },
        { overrides: { mutationProbeEnabled: false }, code: 'MUTATION_PROBE_DISABLED' },
        { overrides: { authorization: 'wrong' }, code: 'MUTATION_CONFIRMATION_REQUIRED' },
        { overrides: { environment: 'production' }, code: 'PRODUCTION_FORBIDDEN' },
        { overrides: { environment: 'staging' }, code: 'NON_LOCAL_ENVIRONMENT_FORBIDDEN' },
        { overrides: { accessToken: '' }, code: 'ACCESS_TOKEN_REQUIRED' },
        { overrides: { runId: '../unsafe' }, code: 'RUN_ID_INVALID' },
        {
            overrides: { accessToken: RUN_ID, runId: RUN_ID },
            code: 'LOCAL_MARKER_SECRET_COLLISION',
        },
        {
            overrides: { accessToken: TARGET_FINGERPRINT.slice(0, 12) },
            code: 'LOCAL_MARKER_SECRET_COLLISION',
        },
        { overrides: { recoveryJournal: null }, code: 'RECOVERY_JOURNAL_REQUIRED' },
        { overrides: { targetFingerprint: 'invalid' }, code: 'TARGET_FINGERPRINT_INVALID' },
        { overrides: { timeoutMs: 999 }, code: 'TIMEOUT_MS_INVALID' },
    ];

    for (const testCase of cases) {
        let probeCount = 0;
        let requestCount = 0;

        await assert.rejects(
            executeLifecycle({
                fetchImpl: async () => {
                    requestCount += 1;
                    return jsonResponse({});
                },
                probeImpl: async () => {
                    probeCount += 1;
                    return {};
                },
                ...testCase.overrides,
            }),
            (error) => error instanceof BufferWp1FacebookDraftFailure
                && error.code === testCase.code,
        );
        assert.equal(probeCount, 0);
        assert.equal(requestCount, 0);
    }
});

test('the WP1-C CLI refuses unsafe, disabled, duplicate, or unconfirmed execution before HTTP', async () => {
    const validArguments = [
        '--execute-facebook-draft-lifecycle',
        `--confirm-delete-temporary-draft=${BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION}`,
    ];
    const validEnvironment = {
        APP_ENV: 'local',
        BUFFER_WP1_MUTATION_PROBE_ENABLED: 'true',
        BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
        BUFFER_WP1_PROBE_ENABLED: 'true',
    };
    const cases = [
        { argv: [], env: validEnvironment, code: 'MUTATION_EXECUTION_FLAG_REQUIRED' },
        {
            argv: ['--execute-facebook-draft-lifecycle'],
            env: validEnvironment,
            code: 'MUTATION_CONFIRMATION_REQUIRED',
        },
        {
            argv: [...validArguments, '--execute-facebook-draft-lifecycle'],
            env: validEnvironment,
            code: 'ARGUMENT_DUPLICATE',
        },
        {
            argv: [...validArguments, '--cleanup-only'],
            env: validEnvironment,
            code: 'ARGUMENT_CONFLICT',
        },
        {
            argv: validArguments,
            env: { ...validEnvironment, APP_ENV: 'production' },
            code: 'PRODUCTION_FORBIDDEN',
        },
        {
            argv: validArguments,
            env: { ...validEnvironment, BUFFER_WP1_PROBE_ENABLED: 'false' },
            code: 'PROBE_DISABLED',
        },
        {
            argv: validArguments,
            env: { ...validEnvironment, BUFFER_WP1_MUTATION_PROBE_ENABLED: 'false' },
            code: 'MUTATION_PROBE_DISABLED',
        },
    ];

    for (const testCase of cases) {
        let output = '';
        let probeCount = 0;
        let requestCount = 0;
        const exitCode = await runBufferWp1FacebookDraftLifecycleCli({
            argv: testCase.argv,
            env: testCase.env,
            fetchImpl: async () => {
                requestCount += 1;
                return jsonResponse({});
            },
            probeImpl: async () => {
                probeCount += 1;
                return {};
            },
            stderr: (value) => {
                output += value;
            },
            stdout: (value) => {
                output += value;
            },
        });

        assert.equal(exitCode, 1);
        assert.equal(JSON.parse(output).code, testCase.code);
        assert.equal(output.includes(ACCESS_TOKEN), false);
        assert.equal(probeCount, 0);
        assert.equal(requestCount, 0);
    }
});

test('the WP1-C preflight fails closed for an unavailable contract or ambiguous organization', async () => {
    const cases = [
        {
            probe: createProbe({ schemaClassification: 'invalid_payload' }),
            classification: 'schema_contract_unavailable',
            expectedProbeCount: 1,
        },
        {
            probe: createProbe({ organizations: [] }),
            classification: 'organization_selection_ambiguous',
            expectedProbeCount: 2,
        },
        {
            probe: createProbe({
                organizations: [
                    { id: ORGANIZATION_ID, name: 'One' },
                    { id: 'organization-2', name: 'Two' },
                ],
            }),
            classification: 'organization_selection_ambiguous',
            expectedProbeCount: 2,
        },
    ];

    for (const testCase of cases) {
        const result = await executeLifecycle({
            fetchImpl: async () => assert.fail('mutations must not run after failed preflight'),
            probeImpl: testCase.probe.probeImpl,
        });

        assert.equal(result.ok, false);
        assert.equal(result.classification, testCase.classification);
        assert.equal(testCase.probe.calls.length, testCase.expectedProbeCount);
        assert.equal(result.steps.create.attempted, false);
    }
});

test('the WP1-C preflight rejects empty, wrong-operation, or wrong-profile schema success', async (t) => {
    const incompleteOutputContract = schemaContract('full');
    delete incompleteOutputContract.capabilities.post_delete_cleanup
        .inspect_metadata_type_value;
    const cases = [
        {
            name: 'empty schema contract',
            result: {
                operation: 'schema',
                ok: true,
                classification: 'success',
                data: { schema_contract: {} },
            },
        },
        {
            name: 'wrong operation',
            result: {
                operation: 'account',
                ok: true,
                classification: 'success',
                data: { schema_contract: schemaContract('full') },
            },
        },
        {
            name: 'wrong schema profile',
            result: {
                operation: 'schema',
                ok: true,
                classification: 'success',
                data: { schema_contract: schemaContract('cleanup') },
            },
        },
        {
            name: 'incomplete metadata output capability',
            result: {
                operation: 'schema',
                ok: true,
                classification: 'success',
                data: { schema_contract: incompleteOutputContract },
            },
        },
    ];

    for (const testCase of cases) {
        await t.test(testCase.name, async () => {
            let probeCount = 0;
            const recoveryJournal = createMemoryRecoveryJournal();
            const result = await executeLifecycle({
                fetchImpl: async () => assert.fail('schema drift reached HTTP'),
                probeImpl: async () => {
                    probeCount += 1;

                    return testCase.result;
                },
                recoveryJournal,
            });

            assert.equal(result.classification, 'schema_contract_unavailable');
            assert.equal(result.preflight.schema, 'invalid_payload');
            assert.equal(probeCount, 1);
            assert.deepEqual(recoveryJournal.events, []);
        });
    }
});

test('the WP1-C preflight permits only one healthy and authorized Facebook page', async () => {
    const channelCases = [
        [],
        [healthyChannel(), healthyChannel({ id: 'facebook-channel-2' })],
        [healthyChannel({ organization_id: 'organization-2' })],
        [healthyChannel({ service: 'instagram' })],
        [healthyChannel({ type: 'group' })],
        [healthyChannel({ is_disconnected: true })],
        [healthyChannel({ is_locked: true })],
        [healthyChannel({ is_queue_paused: true })],
        [healthyChannel({ allowed_actions: ['manageUpdates'] })],
        [healthyChannel({ allowed_actions: ['viewPublish'] })],
        [healthyChannel({
            allowed_actions: ['managePostingSchedule', 'manageUpdates', 'viewPublish'],
        })],
    ];

    for (const channels of channelCases) {
        const probe = createProbe({ channels });
        let requestCount = 0;
        const result = await executeLifecycle({
            fetchImpl: async () => {
                requestCount += 1;
                return jsonResponse({});
            },
            probeImpl: probe.probeImpl,
        });

        assert.equal(result.classification, 'facebook_channel_selection_ambiguous');
        assert.equal(result.steps.create.attempted, false);
        assert.equal(requestCount, 0);
    }
});

test('the WP1-C preflight binds the real mutation target to an explicit opaque fingerprint', async () => {
    for (const [targetFingerprint, classification] of [
        [null, 'target_confirmation_required'],
        ['0'.repeat(64), 'target_confirmation_mismatch'],
    ]) {
        const probe = createProbe();
        let requestCount = 0;
        const result = await executeLifecycle({
            fetchImpl: async () => {
                requestCount += 1;
                return jsonResponse({});
            },
            probeImpl: probe.probeImpl,
            targetFingerprint,
        });

        assert.equal(result.classification, classification);
        assert.equal(result.preflight.target_fingerprint, TARGET_FINGERPRINT);
        assert.equal(result.preflight.target_fingerprint_matched, false);
        assert.equal(result.steps.create.attempted, false);
        assert.equal(requestCount, 0);
    }
});

test('the WP1-C cycle blocks every new create while a recovery journal remains active', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal({
        activeRecord: { state: 'cleanup_required' },
    });
    let requestCount = 0;

    const result = await executeLifecycle({
        fetchImpl: async () => {
            requestCount += 1;
            return jsonResponse({});
        },
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, 'recovery_required');
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(result.cleanup.recovery_journal_armed, false);
    assert.equal(result.steps.create.attempted, false);
    assert.equal(requestCount, 0);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'begin', 'release']);
});

test('the WP1-C file journal is private, exclusive, durable, and removable after confirmed cleanup', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'mlkpro-wp1c-journal-'));
    const journal = createBufferWp1FacebookDraftRecoveryJournal({ directory });
    const initialRecord = {
        schema_version: 1,
        draft_marker: DRAFT_MARKER,
        post_id: null,
        run_id: RUN_ID,
        state: 'create_pending',
        target_fingerprint: TARGET_FINGERPRINT,
    };

    try {
        await journal.begin(initialRecord);

        const journalPath = join(directory, 'active.json');
        assert.equal((await stat(journalPath)).mode & 0o777, 0o600);
        assert.deepEqual(JSON.parse(await readFile(journalPath, 'utf8')), initialRecord);
        assert.deepEqual(await journal.read(), initialRecord);
        await assert.rejects(
            journal.begin(initialRecord),
            (error) => error instanceof BufferWp1FacebookDraftFailure
                && error.code === 'RECOVERY_REQUIRED',
        );

        const cleanupRecord = { ...initialRecord, post_id: POST_ID, state: 'cleanup_required' };
        await journal.update(cleanupRecord);
        assert.deepEqual(JSON.parse(await readFile(journalPath, 'utf8')), cleanupRecord);
        assert.deepEqual(await journal.read(), cleanupRecord);

        await journal.acquire();
        await assert.rejects(
            journal.acquire(),
            (error) => error instanceof BufferWp1FacebookDraftFailure
                && error.code === 'RECOVERY_CLEANUP_IN_PROGRESS',
        );
        await journal.release();

        await journal.complete();
        await assert.rejects(stat(journalPath), (error) => error.code === 'ENOENT');
    } finally {
        await rm(directory, { force: true, recursive: true });
    }
});

test('the WP1-C operation lock blocks live owners and can replace only a dead owner', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'mlkpro-wp1c-lock-'));
    const firstJournal = createBufferWp1FacebookDraftRecoveryJournal({
        directory,
        isProcessAlive: () => true,
        processId: 101,
    });

    try {
        await firstJournal.acquire();

        const liveCompetitor = createBufferWp1FacebookDraftRecoveryJournal({
            directory,
            isProcessAlive: () => true,
            processId: 202,
        });
        await assert.rejects(
            liveCompetitor.acquire(),
            (error) => error instanceof BufferWp1FacebookDraftFailure
                && error.code === 'RECOVERY_CLEANUP_IN_PROGRESS',
        );

        const deadOwnerRecovery = createBufferWp1FacebookDraftRecoveryJournal({
            directory,
            isProcessAlive: (processId) => processId !== 101,
            processId: 303,
        });
        await deadOwnerRecovery.acquire();
        await deadOwnerRecovery.release();
        await assert.rejects(
            firstJournal.release(),
            (error) => error instanceof BufferWp1FacebookDraftFailure
                && error.code === 'RECOVERY_CLEANUP_UNLOCK_FAILED',
        );
    } finally {
        await rm(directory, { force: true, recursive: true });
    }
});

test('the WP1-C operation lock serializes the lifecycle against cleanup-only', async () => {
    const lifecycleProbe = createProbe();
    const cleanupProbe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    let competingCleanupCode = null;
    const scriptedFetch = createScriptedFetch([
        async () => {
            try {
                await executeCleanup({
                    fetchImpl: async () => assert.fail('cleanup-only reached HTTP'),
                    probeImpl: cleanupProbe.probeImpl,
                    recoveryJournal,
                });
            } catch (error) {
                competingCleanupCode = error.code;
            }

            return postAction('createPost', safePost());
        },
        postAction('editPost', safePost({ text: EDITED_TEXT })),
        postAction('movePostInQueue', safePost({ text: EDITED_TEXT })),
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: lifecycleProbe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.ok, true);
    assert.equal(competingCleanupCode, 'RECOVERY_CLEANUP_IN_PROGRESS');
    assert.equal(cleanupProbe.calls.length, 0);
    assert.equal(scriptedFetch.requests.length, 5);
    assert.equal(scriptedFetch.requests.filter((request) => (
        JSON.parse(request.options.body).query === BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION
    )).length, 1);
    assert.deepEqual(
        recoveryJournal.events,
        ['acquire', 'begin', 'acquire', 'update', 'complete', 'release'],
    );
});

test('the WP1-C preflight keeps an eight-request safety reserve in every quota window', async () => {
    for (const remaining of [0, 1, 2, 3, 4, 7]) {
        const probe = createProbe({ remaining });
        let requestCount = 0;
        const result = await executeLifecycle({
            fetchImpl: async () => {
                requestCount += 1;
                return jsonResponse({});
            },
            probeImpl: probe.probeImpl,
        });

        assert.equal(result.classification, 'insufficient_mutation_quota');
        assert.equal(result.preflight.mutation_capacity_available, false);
        assert.equal(requestCount, 0);
    }
});

test('the WP1-C cycle treats a typed create rejection as definitive and never retries or deletes', async () => {
    const probe = createProbe();
    const scriptedFetch = createScriptedFetch([
        typedAction('createPost', `${ACCESS_TOKEN}: invalid draft`),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'create_rejected');
    assert.equal(result.steps.create.outcome, 'typed_error');
    assert.equal(result.steps.create.message_sha256.length, 64);
    assert.equal(result.cleanup.attempted, false);
    assert.equal(scriptedFetch.requests.length, 1);
    assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
});

test('the WP1-C cycle keeps unexpected typed create outcomes in manual reconciliation', async () => {
    for (const responseType of ['RestProxyError', 'UnexpectedError']) {
        const probe = createProbe();
        const recoveryJournal = createMemoryRecoveryJournal();
        const scriptedFetch = createScriptedFetch([
            typedAction('createPost', 'ambiguous provider response', responseType),
        ]);

        const result = await executeLifecycle({
            fetchImpl: scriptedFetch.fetchImpl,
            probeImpl: probe.probeImpl,
            recoveryJournal,
        });

        assert.equal(result.classification, 'create_outcome_unknown');
        assert.equal(result.cleanup.manual_reconciliation_required, true);
        assert.equal(result.cleanup.recovery_journal_cleared, false);
        assert.equal(recoveryJournal.record.state, 'creation_outcome_unknown');
        assert.deepEqual(recoveryJournal.events, ['acquire', 'begin', 'update', 'release']);
        assert.equal(scriptedFetch.requests.length, 1);
    }
});

test('the WP1-C evidence never exposes a token colliding with a GraphQL error code', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const scriptedFetch = createScriptedFetch([
        jsonResponse({
            data: null,
            errors: [{ message: 'remote failure', extensions: { code: 'UNEXPECTED' } }],
        }),
    ]);

    const result = await executeLifecycle({
        accessToken: 'UNEXPECTED',
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, 'create_outcome_unknown');
    assert.equal(result.steps.create.graphql_errors[0].code, null);
    assert.equal(JSON.stringify(result).includes('UNEXPECTED'), false);
});

test('the WP1-C evidence redaction cannot recombine secret fragments', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const scriptedFetch = createScriptedFetch([
        typedAction('createPost', 'RREE'),
    ]);

    const result = await executeLifecycle({
        accessToken: 'RE',
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, 'create_rejected');
    assert.equal(
        result.steps.create.message_sha256,
        createHash('sha256').update('R*E').digest('hex'),
    );
    assert.equal(JSON.stringify(result).includes('RE'), false);
});

test('the WP1-C evidence never exposes a token colliding with a mutation response type', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeLifecycle({
        accessToken: 'PostActionSuccess',
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, 'create_partial_response');
    assert.equal(result.steps.create.response_type, null);
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(JSON.stringify(result).includes('PostActionSuccess'), false);
});

test('the WP1-C recovery journal never persists a post identifier colliding with the token', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
    ]);

    const result = await executeLifecycle({
        accessToken: POST_ID,
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, 'create_outcome_unknown');
    assert.equal(result.cleanup.attempted, false);
    assert.equal(recoveryJournal.record.post_id, null);
    assert.equal(JSON.stringify(recoveryJournal.record).includes(POST_ID), false);
    assert.equal(JSON.stringify(result).includes(POST_ID), false);
});

test('the WP1-C final lifecycle result redacts a token colliding with an internal classification', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
        postAction('editPost', safePost({ text: EDITED_TEXT })),
        typedAction('movePostInQueue'),
        typedAction('deletePost', 'delete rejected'),
    ]);

    const result = await executeLifecycle({
        accessToken: 'cleanup_failed',
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, '[REDACTED]');
    assert.equal(result.cleanup.confirmed, false);
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(JSON.stringify(result).includes('cleanup_failed'), false);
});

test('the WP1-F cycle aborts a timed-out create request and never retries it', { timeout: 2500 }, async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    let observedSignal = null;
    const scriptedFetch = createScriptedFetch([
        async (_url, options) => new Promise((_resolve, reject) => {
            observedSignal = options.signal;
            options.signal.addEventListener('abort', () => {
                const error = new Error('sanitized simulated timeout');
                error.name = 'AbortError';
                reject(error);
            }, { once: true });
        }),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
        timeoutMs: 1000,
    });

    assert.equal(result.classification, 'create_outcome_unknown');
    assert.equal(result.steps.create.outcome, 'timeout');
    assert.equal(result.cleanup.state, 'creation_outcome_unknown');
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(result.draft_marker, DRAFT_MARKER);
    assert.equal(observedSignal?.aborted, true);
    assert.equal(scriptedFetch.requests.length, 1);
    assert.equal(recoveryJournal.record.state, 'creation_outcome_unknown');
    assert.equal(recoveryJournal.record.post_id, null);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'begin', 'update', 'release']);
});

test('the WP1-F cycle treats every ambiguous create response as unknown', async (t) => {
    const cases = [
        {
            expectedOutcome: 'transport_error',
            name: 'transport failure',
            response: rejectedRequest(),
        },
        {
            expectedOutcome: 'http_error',
            name: 'HTTP 500',
            response: jsonResponse({ data: null }, 500),
        },
        {
            expectedOutcome: 'invalid_json',
            name: 'invalid JSON',
            response: invalidJsonResponse(),
        },
        {
            expectedOutcome: 'response_stream_unavailable',
            name: 'response without stream',
            response: responseWithoutStream(),
        },
        {
            expectedOutcome: 'response_too_large',
            name: 'oversized response',
            response: oversizedResponse(),
        },
    ];

    for (const testCase of cases) {
        await t.test(testCase.name, async () => {
            const probe = createProbe();
            const recoveryJournal = createMemoryRecoveryJournal();
            const scriptedFetch = createScriptedFetch([testCase.response]);

            const result = await executeLifecycle({
                fetchImpl: scriptedFetch.fetchImpl,
                probeImpl: probe.probeImpl,
                recoveryJournal,
            });

            assert.equal(result.classification, 'create_outcome_unknown');
            assert.equal(result.steps.create.outcome, testCase.expectedOutcome);
            assert.equal(result.steps.edit.attempted, false);
            assert.equal(result.steps.move.attempted, false);
            assert.equal(result.steps.delete.attempted, false);
            assert.equal(result.cleanup.attempted, false);
            assert.equal(result.cleanup.manual_reconciliation_required, true);
            assert.equal(recoveryJournal.record.state, 'creation_outcome_unknown');
            assert.equal(recoveryJournal.record.post_id, null);
            assert.equal(scriptedFetch.requests.length, 1);
            assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
        });
    }
});

test('the WP1-C cycle salvages a partial create ID only for its single cleanup attempt', async () => {
    const probe = createProbe();
    const partialCreate = jsonResponse({
        data: {
            createPost: {
                __typename: 'PostActionSuccess',
                post: safePost(),
            },
        },
        errors: [{ message: 'partial warning', extensions: { code: 'UNEXPECTED' } }],
    });
    const scriptedFetch = createScriptedFetch([
        partialCreate,
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'create_partial_response');
    assert.equal(result.steps.create.outcome, 'partial_success');
    assert.equal(result.steps.edit.attempted, false);
    assert.equal(result.steps.move.attempted, false);
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(scriptedFetch.requests.length, 3);
    assert.deepEqual(JSON.parse(scriptedFetch.requests[1].options.body).variables, {
        input: { id: POST_ID },
    });
});

test('the WP1-F cycle uses an ID from a non-success create response only for cleanup', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const scriptedFetch = createScriptedFetch([
        jsonResponse({
            data: {
                createPost: {
                    __typename: 'PostActionSuccess',
                    post: safePost(),
                },
            },
        }, 500),
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, 'create_partial_response');
    assert.equal(result.steps.create.outcome, 'partial_success');
    assert.equal(result.steps.create.http_status, 500);
    assert.equal(result.steps.edit.attempted, false);
    assert.equal(result.steps.move.attempted, false);
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(result.cleanup.recovery_journal_cleared, true);
    assert.equal(recoveryJournal.record, null);
    assert.equal(scriptedFetch.requests.length, 3);
    assert.deepEqual(
        scriptedFetch.requests.map((request) => JSON.parse(request.options.body).query),
        [
            BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION,
            BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
            BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
        ],
    );
    assert.equal(JSON.stringify(result).includes(POST_ID), false);
});

test('the WP1-C cycle salvages a created ID even when the response typename is missing or invalid', async () => {
    for (const typename of [undefined, 'UnexpectedShape']) {
        const probe = createProbe();
        const createPayload = {
            data: {
                createPost: {
                    post: safePost(),
                },
            },
        };
        if (typename !== undefined) {
            createPayload.data.createPost.__typename = typename;
        }
        const scriptedFetch = createScriptedFetch([
            jsonResponse(createPayload),
            deleteSuccess(),
            deleteNotFoundVerification(),
        ]);

        const result = await executeLifecycle({
            fetchImpl: scriptedFetch.fetchImpl,
            probeImpl: probe.probeImpl,
        });

        assert.equal(result.classification, 'create_partial_response');
        assert.equal(result.cleanup.confirmed, true);
        assert.equal(result.cleanup.recovery_journal_cleared, true);
        assert.equal(scriptedFetch.requests.length, 3);
        assert.deepEqual(JSON.parse(scriptedFetch.requests[1].options.body).variables, {
            input: { id: POST_ID },
        });
    }
});

test('the WP1-C cycle deletes immediately when create violates a draft safety invariant', async () => {
    const unsafePosts = [
        safePost({ channelId: 'wrong-channel' }),
        safePost({ channelService: 'linkedin' }),
        safePost({ dueAt: '2026-08-28T12:00:00.000Z' }),
        safePost({ externalLink: 'https://facebook.example/post' }),
        safePost({ schedulingType: 'notification' }),
        safePost({ sentAt: '2026-08-27T12:00:00.000Z', status: 'sent' }),
        safePost({ sharedNow: true }),
        safePost({ shareMode: 'shareNow' }),
        safePost({ status: 'scheduled' }),
        safePost({ text: 'unexpected text' }),
    ];

    for (const unsafePost of unsafePosts) {
        const probe = createProbe();
        const scriptedFetch = createScriptedFetch([
            postAction('createPost', unsafePost),
            deleteSuccess(),
            deleteNotFoundVerification(),
        ]);
        const result = await executeLifecycle({
            fetchImpl: scriptedFetch.fetchImpl,
            probeImpl: probe.probeImpl,
        });

        assert.equal(
            result.classification,
            'create_draft_invariant_failed',
            JSON.stringify(unsafePost),
        );
        assert.equal(result.steps.edit.attempted, false);
        assert.equal(result.cleanup.confirmed, true);
        assert.equal(scriptedFetch.requests.length, 3);
    }
});

test('the WP1-E cycle rejects missing, widened, cross-service, or non-post metadata at every stage', async () => {
    const cases = [
        {
            classification: 'create_draft_invariant_failed',
            responses: [
                postAction('createPost', safePost({ metadata: undefined })),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            step: 'create',
        },
        {
            classification: 'create_draft_invariant_failed',
            responses: [
                postAction('createPost', safePost({ metadata: null })),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            step: 'create',
        },
        {
            classification: 'create_draft_invariant_failed',
            responses: [
                postAction('createPost', safePost({ metadata: { type: 'post' } })),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            step: 'create',
        },
        {
            classification: 'create_draft_invariant_failed',
            responses: [
                postAction('createPost', safePost({
                    metadata: { __typename: 'FacebookPostMetadata' },
                })),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            step: 'create',
        },
        {
            classification: 'create_draft_invariant_failed',
            responses: [
                postAction('createPost', safePost({
                    metadata: {
                        __typename: 'FacebookPostMetadata',
                        firstComment: null,
                        type: 'post',
                    },
                })),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            step: 'create',
        },
        {
            classification: 'edit_failed',
            responses: [
                postAction('createPost', safePost()),
                postAction('editPost', safePost({
                    metadata: {
                        __typename: 'InstagramPostMetadata',
                        type: 'post',
                    },
                    text: EDITED_TEXT,
                })),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            step: 'edit',
        },
        {
            classification: 'move_failed',
            responses: [
                postAction('createPost', safePost()),
                postAction('editPost', safePost({ text: EDITED_TEXT })),
                postAction('movePostInQueue', safePost({
                    metadata: {
                        __typename: 'FacebookPostMetadata',
                        type: 'reel',
                    },
                    text: EDITED_TEXT,
                })),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            step: 'move',
        },
    ];

    for (const testCase of cases) {
        const probe = createProbe();
        const scriptedFetch = createScriptedFetch([...testCase.responses]);
        const recoveryJournal = createMemoryRecoveryJournal();
        const result = await executeLifecycle({
            fetchImpl: scriptedFetch.fetchImpl,
            probeImpl: probe.probeImpl,
            recoveryJournal,
        });

        assert.equal(result.classification, testCase.classification);
        assert.equal(result.steps[testCase.step].outcome, 'draft_invariant_failed');
        assert.equal(result.cleanup.confirmed, true);
        assert.equal(result.cleanup.recovery_journal_cleared, true);
        assert.equal(recoveryJournal.record, null);
        assert.equal(scriptedFetch.requests.filter((request) => (
            JSON.parse(request.options.body).query === BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION
        )).length, 1);
        assert.equal(scriptedFetch.requests.filter((request) => (
            JSON.parse(request.options.body).query === BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY
        )).length, 1);
    }
});

test('the WP1-C cycle stops after incomplete create quota evidence but still deletes', async () => {
    const probe = createProbe();
    const createWithoutQuota = postAction('createPost', safePost());
    createWithoutQuota.headers.delete('RateLimit-Policy');
    const scriptedFetch = createScriptedFetch([
        createWithoutQuota,
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'incomplete_quota_evidence');
    assert.equal(result.steps.edit.attempted, false);
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(scriptedFetch.requests.length, 3);
});

test('the WP1-C cycle preserves delete and verification quota when create capacity drops', async () => {
    const probe = createProbe();
    const createWithLowQuota = postAction('createPost', safePost());
    for (const [name, value] of Object.entries(quotaHeaders(3))) {
        createWithLowQuota.headers.set(name, value);
    }
    const scriptedFetch = createScriptedFetch([
        createWithLowQuota,
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'insufficient_remaining_quota_after_create');
    assert.equal(result.steps.edit.attempted, false);
    assert.equal(result.steps.move.attempted, false);
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(scriptedFetch.requests.length, 3);
});

test('the WP1-C cycle skips move when edit leaves only cleanup quota', async () => {
    const probe = createProbe();
    const editWithCleanupOnlyQuota = postAction(
        'editPost',
        safePost({ text: EDITED_TEXT }),
    );
    for (const [name, value] of Object.entries(quotaHeaders(2))) {
        editWithCleanupOnlyQuota.headers.set(name, value);
    }
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
        editWithCleanupOnlyQuota,
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'insufficient_remaining_quota_after_edit');
    assert.equal(result.steps.edit.outcome, 'success');
    assert.equal(result.steps.move.attempted, false);
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(scriptedFetch.requests.length, 4);
});

test('the WP1-C cycle rejects a moved draft with a different channel or edited text', async () => {
    for (const movedPost of [
        safePost({ channelId: 'different-channel', text: EDITED_TEXT }),
        safePost({ text: 'different edited text' }),
    ]) {
        const probe = createProbe();
        const scriptedFetch = createScriptedFetch([
            postAction('createPost', safePost()),
            postAction('editPost', safePost({ text: EDITED_TEXT })),
            postAction('movePostInQueue', movedPost),
            deleteSuccess(),
            deleteNotFoundVerification(),
        ]);

        const result = await executeLifecycle({
            fetchImpl: scriptedFetch.fetchImpl,
            probeImpl: probe.probeImpl,
        });

        assert.equal(result.classification, 'move_failed');
        assert.equal(result.steps.move.outcome, 'draft_invariant_failed');
        assert.equal(result.cleanup.confirmed, true);
        assert.equal(scriptedFetch.requests.length, 5);
    }
});

test('the WP1-C cycle deletes exactly once after edit or move failures and never retries', async () => {
    const cases = [
        {
            responses: [
                postAction('createPost', safePost()),
                typedAction('editPost', 'edit rejected'),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            classification: 'edit_failed',
            expectedRequests: 4,
        },
        {
            responses: [
                postAction('createPost', safePost()),
                postAction('editPost', safePost({ text: EDITED_TEXT })),
                jsonResponse({ errors: [{ message: 'system failure', extensions: { code: 'UNEXPECTED' } }] }),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            classification: 'move_failed',
            expectedRequests: 5,
        },
        {
            responses: [
                postAction('createPost', safePost()),
                postAction('editPost', safePost({ text: EDITED_TEXT })),
                typedAction('movePostInQueue', 'unknown typed response', 'InvalidInputError'),
                deleteSuccess(),
                deleteNotFoundVerification(),
            ],
            classification: 'move_failed',
            expectedRequests: 5,
        },
    ];

    for (const testCase of cases) {
        const probe = createProbe();
        const scriptedFetch = createScriptedFetch([...testCase.responses]);
        const result = await executeLifecycle({
            fetchImpl: scriptedFetch.fetchImpl,
            probeImpl: probe.probeImpl,
        });

        assert.equal(result.classification, testCase.classification);
        assert.equal(result.cleanup.confirmed, true);
        assert.equal(scriptedFetch.requests.length, testCase.expectedRequests);
        assert.equal(scriptedFetch.requests.filter((request) => (
            JSON.parse(request.options.body).query === BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION
        )).length, 1);
    }
});

test('the WP1-F cycle treats ambiguous edit and move results as unknown and cleans once', async (t) => {
    const failures = [
        {
            expectedOutcome: 'timeout',
            name: 'timeout',
            response: () => rejectedRequest('AbortError'),
        },
        {
            expectedOutcome: 'transport_error',
            name: 'transport failure',
            response: () => rejectedRequest(),
        },
        {
            expectedOutcome: 'http_error',
            name: 'HTTP 500',
            response: () => jsonResponse({ data: null }, 500),
        },
        {
            expectedOutcome: 'invalid_json',
            name: 'invalid JSON',
            response: () => invalidJsonResponse(),
        },
        {
            expectedOutcome: 'response_stream_unavailable',
            name: 'response without stream',
            response: () => responseWithoutStream(),
        },
        {
            expectedOutcome: 'response_too_large',
            name: 'oversized response',
            response: () => oversizedResponse(),
        },
    ];

    for (const stage of ['edit', 'move']) {
        for (const failure of failures) {
            await t.test(`${stage}: ${failure.name}`, async () => {
                const probe = createProbe();
                const recoveryJournal = createMemoryRecoveryJournal();
                const responses = [postAction('createPost', safePost())];
                if (stage === 'move') {
                    responses.push(postAction('editPost', safePost({ text: EDITED_TEXT })));
                }
                responses.push(
                    failure.response(),
                    deleteSuccess(),
                    deleteNotFoundVerification(),
                );
                const scriptedFetch = createScriptedFetch(responses);

                const result = await executeLifecycle({
                    fetchImpl: scriptedFetch.fetchImpl,
                    probeImpl: probe.probeImpl,
                    recoveryJournal,
                });

                assert.equal(result.classification, `${stage}_outcome_unknown`);
                assert.equal(result.steps[stage].outcome, failure.expectedOutcome);
                assert.equal(result.cleanup.confirmed, true);
                assert.equal(result.cleanup.recovery_journal_cleared, true);
                assert.equal(recoveryJournal.record, null);
                assert.equal(result.steps.delete.outcome, 'deleted');
                assert.equal(result.steps.verify_delete.outcome, 'not_found_confirmed');

                const queries = scriptedFetch.requests.map((request) => (
                    JSON.parse(request.options.body).query
                ));
                assert.equal(queries.filter((query) => (
                    query === BUFFER_WP1_CREATE_FACEBOOK_DRAFT_MUTATION
                )).length, 1);
                assert.equal(queries.filter((query) => (
                    query === BUFFER_WP1_EDIT_FACEBOOK_DRAFT_MUTATION
                )).length, 1);
                assert.equal(queries.filter((query) => (
                    query === BUFFER_WP1_MOVE_FACEBOOK_DRAFT_MUTATION
                )).length, stage === 'move' ? 1 : 0);
                assert.equal(queries.filter((query) => (
                    query === BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION
                )).length, 1);
                assert.equal(queries.filter((query) => (
                    query === BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY
                )).length, 1);
                assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
                assert.equal(JSON.stringify(result).includes(POST_ID), false);
            });
        }
    }
});

test('the WP1-C cleanup uses a fresh signal and requires reconciliation for every unconfirmed delete', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const signals = [];
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
        postAction('editPost', safePost({ text: EDITED_TEXT })),
        typedAction('movePostInQueue'),
        (_url, options) => {
            signals.push(options.signal);
            return typedAction('deletePost', 'delete rejected');
        },
    ]);
    const wrappedFetch = async (url, options) => {
        signals.push(options.signal);
        return scriptedFetch.fetchImpl(url, options);
    };

    const result = await executeLifecycle({
        fetchImpl: wrappedFetch,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.classification, 'cleanup_failed');
    assert.equal(result.cleanup.confirmed, false);
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(result.cleanup.state, 'delete_unconfirmed');
    assert.equal(scriptedFetch.requests.length, 4);
    assert.equal(new Set(signals).size >= 4, true);
    assert.equal(signals.at(-1).aborted, false);
    assert.equal(recoveryJournal.record.state, 'cleanup_required');
    assert.equal(recoveryJournal.record.post_id, POST_ID);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'begin', 'update', 'release']);
});

test('the WP1-C cleanup refuses a mismatched delete identifier', async () => {
    const probe = createProbe();
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
        postAction('editPost', safePost({ text: EDITED_TEXT })),
        typedAction('movePostInQueue'),
        deleteSuccess('different-post'),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'cleanup_failed');
    assert.equal(result.steps.delete.outcome, 'invalid_payload');
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(scriptedFetch.requests.length, 4);
});

test('the WP1-C cleanup rejects a contradictory NOT_FOUND response that still returns the draft', async () => {
    const probe = createProbe();
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
        postAction('editPost', safePost({ text: EDITED_TEXT })),
        typedAction('movePostInQueue'),
        deleteSuccess(),
        jsonResponse({
            data: { post: { id: POST_ID } },
            errors: [{ message: 'contradictory', extensions: { code: 'NOT_FOUND' } }],
        }),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'cleanup_failed');
    assert.equal(result.steps.delete.outcome, 'deleted');
    assert.equal(result.steps.verify_delete.outcome, 'not_found');
    assert.equal(result.cleanup.state, 'delete_verification_unconfirmed');
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(scriptedFetch.requests.length, 5);
});

test('the WP1-C cleanup rejects mixed GraphQL errors beside NOT_FOUND', async () => {
    const probe = createProbe();
    const scriptedFetch = createScriptedFetch([
        postAction('createPost', safePost()),
        postAction('editPost', safePost({ text: EDITED_TEXT })),
        typedAction('movePostInQueue'),
        deleteSuccess(),
        jsonResponse({
            data: null,
            errors: [
                { message: 'Post not found', extensions: { code: 'NOT_FOUND' } },
                { message: 'Unexpected provider error', extensions: { code: 'UNEXPECTED' } },
            ],
        }),
    ]);

    const result = await executeLifecycle({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
    });

    assert.equal(result.classification, 'cleanup_failed');
    assert.equal(result.steps.verify_delete.outcome, 'not_found');
    assert.equal(result.cleanup.confirmed, false);
    assert.equal(result.cleanup.state, 'delete_verification_unconfirmed');
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(scriptedFetch.requests.length, 5);
});

test('the WP1-E cleanup preserves its journal when the metadata output capability is incomplete', async () => {
    const incompleteContract = schemaContract('cleanup');
    delete incompleteContract.capabilities.post_delete_cleanup.inspect_metadata_member;
    const recoveryJournal = createMemoryRecoveryJournal({
        activeRecord: recoveryRecord(),
    });
    let probeCount = 0;
    let requestCount = 0;

    const result = await executeCleanup({
        fetchImpl: async () => {
            requestCount += 1;
            return jsonResponse({});
        },
        probeImpl: async () => {
            probeCount += 1;

            return {
                operation: 'schema',
                ok: true,
                classification: 'success',
                data: { schema_contract: incompleteContract },
            };
        },
        recoveryJournal,
    });

    assert.equal(result.classification, 'schema_contract_unavailable');
    assert.equal(result.preflight.schema, 'invalid_payload');
    assert.equal(result.cleanup.recovery_journal_cleared, false);
    assert.equal(recoveryJournal.record.post_id, POST_ID);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'release']);
    assert.equal(probeCount, 1);
    assert.equal(requestCount, 0);
});

test('the WP1-C cleanup-only path inspects the exact draft before one delete and verification', async () => {
    const probe = createProbe({
        schemaClassifications: {
            cleanup: 'success',
            full: 'invalid_payload',
        },
    });
    const recoveryJournal = createMemoryRecoveryJournal({
        activeRecord: recoveryRecord(),
    });
    const scriptedFetch = createScriptedFetch([
        jsonResponse({ data: { post: safePost({ text: EDITED_TEXT }) } }),
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeCleanup({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.ok, true);
    assert.equal(result.classification, 'cleanup_confirmed');
    assert.equal(result.steps.inspect.outcome, 'draft_confirmed');
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(result.cleanup.recovery_journal_cleared, true);
    assert.equal(probe.calls[0].schemaProfile, 'cleanup');
    assert.equal(recoveryJournal.record, null);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'complete', 'release']);

    const bodies = scriptedFetch.requests.map((request) => JSON.parse(request.options.body));
    assert.deepEqual(bodies.map((body) => body.query), [
        BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY,
        BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
        BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
    ]);
    assert.equal(bodies.some((body) => body.query.includes('createPost')), false);
    assert.equal(bodies.some((body) => body.query.includes('editPost')), false);
    assert.equal(bodies.some((body) => body.query.includes('movePostInQueue')), false);
    assert.equal(JSON.stringify(result).includes(POST_ID), false);
});

test('the WP1-F cleanup-only path recovers a draft before its first edit', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal({
        activeRecord: recoveryRecord(),
    });
    const scriptedFetch = createScriptedFetch([
        jsonResponse({ data: { post: safePost({ text: INITIAL_TEXT }) } }),
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);

    const result = await executeCleanup({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.ok, true);
    assert.equal(result.classification, 'cleanup_confirmed');
    assert.equal(result.steps.inspect.outcome, 'draft_confirmed');
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(result.cleanup.recovery_journal_cleared, true);
    assert.equal(recoveryJournal.record, null);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'complete', 'release']);
    assert.deepEqual(
        scriptedFetch.requests.map((request) => JSON.parse(request.options.body).query),
        [
            BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY,
            BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
            BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
        ],
    );
});

test('the WP1-C cleanup-only path clears an already absent draft without deleting again', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal({
        activeRecord: recoveryRecord(),
    });
    const scriptedFetch = createScriptedFetch([deleteNotFoundVerification()]);

    const result = await executeCleanup({
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
    });

    assert.equal(result.ok, true);
    assert.equal(result.classification, 'cleanup_already_confirmed');
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(scriptedFetch.requests.length, 1);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'complete', 'release']);
});

test('the WP1-C cleanup-only path never guesses a missing identifier', async () => {
    const recoveryJournal = createMemoryRecoveryJournal({
        activeRecord: recoveryRecord({
            postId: null,
            state: 'creation_outcome_unknown',
        }),
    });
    let probeCount = 0;
    let requestCount = 0;

    const result = await executeCleanup({
        fetchImpl: async () => {
            requestCount += 1;
            return jsonResponse({});
        },
        probeImpl: async () => {
            probeCount += 1;
            return {};
        },
        recoveryJournal,
    });

    assert.equal(result.classification, 'cleanup_identifier_unavailable');
    assert.equal(result.cleanup.manual_reconciliation_required, true);
    assert.equal(result.cleanup.recovery_journal_cleared, false);
    assert.equal(recoveryJournal.record.state, 'creation_outcome_unknown');
    assert.equal(probeCount, 0);
    assert.equal(requestCount, 0);
    assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'release']);
});

test('the WP1-C cleanup-only path refuses malformed recovery records before HTTP', async () => {
    const records = [
        recoveryRecord({ postId: null }),
        recoveryRecord({ state: 'create_pending' }),
        recoveryRecord({ unexpected: true }),
        recoveryRecord({ draft_marker: 'wrong-marker' }),
    ];

    for (const activeRecord of records) {
        const recoveryJournal = createMemoryRecoveryJournal({ activeRecord });
        let requestCount = 0;

        await assert.rejects(
            executeCleanup({
                fetchImpl: async () => {
                    requestCount += 1;
                    return jsonResponse({});
                },
                probeImpl: async () => ({}),
                recoveryJournal,
            }),
            (error) => error instanceof BufferWp1FacebookDraftFailure
                && error.code === 'RECOVERY_JOURNAL_INVALID',
        );
        assert.equal(requestCount, 0);
        assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'release']);
    }
});

test('the WP1-C cleanup-only path preserves the journal when inspection is not the exact draft', async () => {
    for (const inspectedPost of [
        safePost({ channelId: 'different-channel', text: EDITED_TEXT }),
        safePost({ status: 'scheduled', text: EDITED_TEXT }),
        safePost({ text: 'different text' }),
        safePost({ metadata: undefined, text: EDITED_TEXT }),
        safePost({ metadata: null, text: EDITED_TEXT }),
        safePost({ metadata: { type: 'post' }, text: EDITED_TEXT }),
        safePost({
            metadata: { __typename: 'FacebookPostMetadata' },
            text: EDITED_TEXT,
        }),
        safePost({
            metadata: { __typename: 'InstagramPostMetadata', type: 'post' },
            text: EDITED_TEXT,
        }),
        safePost({
            metadata: { __typename: 'FacebookPostMetadata', type: 'reel' },
            text: EDITED_TEXT,
        }),
        safePost({
            metadata: {
                __typename: 'FacebookPostMetadata',
                firstComment: null,
                type: 'post',
            },
            text: EDITED_TEXT,
        }),
    ]) {
        const probe = createProbe();
        const recoveryJournal = createMemoryRecoveryJournal({
            activeRecord: recoveryRecord(),
        });
        const scriptedFetch = createScriptedFetch([
            jsonResponse({ data: { post: inspectedPost } }),
        ]);

        const result = await executeCleanup({
            fetchImpl: scriptedFetch.fetchImpl,
            probeImpl: probe.probeImpl,
            recoveryJournal,
        });

        assert.equal(result.classification, 'cleanup_inspection_unconfirmed');
        assert.equal(result.cleanup.confirmed, false);
        assert.equal(result.cleanup.manual_reconciliation_required, true);
        assert.equal(recoveryJournal.record.post_id, POST_ID);
        assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'release']);
        assert.equal(scriptedFetch.requests.length, 1);
    }
});

test('the WP1-F cleanup-only path fails closed on ambiguous inspect, delete, or verification', async (t) => {
    const cases = [
        {
            expectedClassification: 'cleanup_inspection_unconfirmed',
            expectedOutcomes: {
                delete: 'not_attempted',
                inspect: 'transport_error',
                verify_delete: 'not_attempted',
            },
            expectedQueries: [BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY],
            expectedState: 'recovery_required',
            name: 'inspect transport failure',
            responses: () => [rejectedRequest()],
        },
        {
            expectedClassification: 'cleanup_failed',
            expectedOutcomes: {
                delete: 'http_error',
                inspect: 'draft_confirmed',
                verify_delete: 'not_attempted',
            },
            expectedQueries: [
                BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY,
                BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
            ],
            expectedState: 'delete_unconfirmed',
            name: 'delete HTTP 500',
            responses: () => [
                jsonResponse({ data: { post: safePost({ text: EDITED_TEXT }) } }),
                jsonResponse({ data: null }, 500),
            ],
        },
        {
            expectedClassification: 'cleanup_failed',
            expectedOutcomes: {
                delete: 'deleted',
                inspect: 'draft_confirmed',
                verify_delete: 'invalid_json',
            },
            expectedQueries: [
                BUFFER_WP1_INSPECT_FACEBOOK_DRAFT_QUERY,
                BUFFER_WP1_DELETE_FACEBOOK_DRAFT_MUTATION,
                BUFFER_WP1_VERIFY_FACEBOOK_DRAFT_DELETED_QUERY,
            ],
            expectedState: 'delete_verification_unconfirmed',
            name: 'verification invalid JSON',
            responses: () => [
                jsonResponse({ data: { post: safePost({ text: EDITED_TEXT }) } }),
                deleteSuccess(),
                invalidJsonResponse(),
            ],
        },
    ];

    for (const testCase of cases) {
        await t.test(testCase.name, async () => {
            const probe = createProbe();
            const activeRecord = recoveryRecord();
            const recoveryJournal = createMemoryRecoveryJournal({ activeRecord });
            const scriptedFetch = createScriptedFetch(testCase.responses());

            const result = await executeCleanup({
                fetchImpl: scriptedFetch.fetchImpl,
                probeImpl: probe.probeImpl,
                recoveryJournal,
            });

            assert.equal(result.classification, testCase.expectedClassification);
            assert.equal(result.cleanup.confirmed, false);
            assert.equal(result.cleanup.manual_reconciliation_required, true);
            assert.equal(result.cleanup.recovery_journal_cleared, false);
            assert.equal(result.cleanup.state, testCase.expectedState);
            assert.deepEqual(recoveryJournal.record, activeRecord);
            assert.deepEqual(recoveryJournal.events, ['acquire', 'read', 'release']);
            for (const [step, outcome] of Object.entries(testCase.expectedOutcomes)) {
                assert.equal(result.steps[step].outcome, outcome);
            }
            assert.deepEqual(
                scriptedFetch.requests.map((request) => JSON.parse(request.options.body).query),
                testCase.expectedQueries,
            );
            assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
            assert.equal(JSON.stringify(result).includes(POST_ID), false);
        });
    }
});

test('the WP1-C CLI defers SIGINT until a created draft is deleted and removes its handlers', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal();
    const signalSource = new EventEmitter();
    const scriptedFetch = createScriptedFetch([
        () => {
            signalSource.emit('SIGINT');
            return postAction('createPost', safePost());
        },
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);
    let output = '';

    const exitCode = await runBufferWp1FacebookDraftLifecycleCli({
        argv: [
            '--execute-facebook-draft-lifecycle',
            `--confirm-delete-temporary-draft=${BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION}`,
        ],
        env: {
            APP_ENV: 'local',
            BUFFER_WP1_MUTATION_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            BUFFER_WP1_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_TIMEOUT_MS: '1000',
            BUFFER_WP1_FACEBOOK_TARGET_FINGERPRINT: TARGET_FINGERPRINT,
        },
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
        runId: RUN_ID,
        signalSource,
        stderr: (value) => {
            output += value;
        },
        stdout: (value) => {
            output += value;
        },
    });

    const result = JSON.parse(output);
    assert.equal(exitCode, 130);
    assert.equal(result.classification, 'interrupted_after_create');
    assert.equal(result.cleanup.confirmed, true);
    assert.equal(result.cleanup.recovery_journal_cleared, true);
    assert.equal(scriptedFetch.requests.length, 3);
    assert.deepEqual(
        recoveryJournal.events,
        ['acquire', 'begin', 'update', 'complete', 'release'],
    );
    assert.equal(signalSource.listenerCount('SIGINT'), 0);
    assert.equal(signalSource.listenerCount('SIGTERM'), 0);
});

test('the WP1-C cleanup-only CLI uses only the recovery path', async () => {
    const probe = createProbe();
    const recoveryJournal = createMemoryRecoveryJournal({
        activeRecord: recoveryRecord(),
    });
    const scriptedFetch = createScriptedFetch([
        jsonResponse({ data: { post: safePost({ text: EDITED_TEXT }) } }),
        deleteSuccess(),
        deleteNotFoundVerification(),
    ]);
    let output = '';

    const exitCode = await runBufferWp1FacebookDraftLifecycleCli({
        argv: [
            '--cleanup-only',
            `--confirm-delete-temporary-draft=${BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION}`,
        ],
        env: {
            APP_ENV: 'local',
            BUFFER_WP1_MUTATION_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            BUFFER_WP1_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_TIMEOUT_MS: '1000',
            BUFFER_WP1_FACEBOOK_TARGET_FINGERPRINT: TARGET_FINGERPRINT,
        },
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal,
        stderr: (value) => {
            output += value;
        },
        stdout: (value) => {
            output += value;
        },
    });

    assert.equal(exitCode, 0);
    assert.equal(JSON.parse(output).classification, 'cleanup_confirmed');
    assert.equal(output.includes(ACCESS_TOKEN), false);
    assert.equal(output.includes(POST_ID), false);
    assert.equal(scriptedFetch.requests.length, 3);
});

test('the WP1-C CLI exits zero only for a fully cleaned lifecycle and prints no remote identifier', async () => {
    const probe = createProbe();
    const scriptedFetch = createScriptedFetch(successfulResponses());
    let output = '';

    const exitCode = await runBufferWp1FacebookDraftLifecycleCli({
        argv: [
            '--execute-facebook-draft-lifecycle',
            `--confirm-delete-temporary-draft=${BUFFER_WP1_FACEBOOK_DRAFT_CONFIRMATION}`,
        ],
        env: {
            APP_ENV: 'local',
            BUFFER_WP1_MUTATION_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            BUFFER_WP1_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_TIMEOUT_MS: '1000',
            BUFFER_WP1_FACEBOOK_TARGET_FINGERPRINT: TARGET_FINGERPRINT,
        },
        fetchImpl: scriptedFetch.fetchImpl,
        probeImpl: probe.probeImpl,
        recoveryJournal: createMemoryRecoveryJournal(),
        runId: RUN_ID,
        stderr: (value) => {
            output += value;
        },
        stdout: (value) => {
            output += value;
        },
    });

    assert.equal(exitCode, 0);
    assert.equal(
        JSON.parse(output).classification,
        'draft_move_preserved_cleanup_confirmed',
    );
    assert.equal(output.includes(ACCESS_TOKEN), false);
    assert.equal(output.includes(ORGANIZATION_ID), false);
    assert.equal(output.includes(CHANNEL_ID), false);
    assert.equal(output.includes(POST_ID), false);
});
