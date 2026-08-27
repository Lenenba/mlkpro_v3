import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';

import {
    BUFFER_WP1_ACCOUNT_QUERY,
    BUFFER_WP1_API_URL,
    BUFFER_WP1_CHANNELS_QUERY,
    executeBufferWp1Probe,
    runBufferWp1ProbeCli,
    splitRepeatedRateLimitHeader,
} from '../../scripts/spikes/buffer/wp1-read-only-probe.mjs';

const ACCESS_TOKEN = 'buffer-wp1-secret-token';
const BUFFER_QUOTA_HEADERS = {
    RateLimit: [
        '"100-in-15min";r=99;t=897',
        '"250-in-1day";r=249;t=86397',
        '"3000-in-30days";r=2999;t=2591997',
    ].join(', '),
    'RateLimit-Policy': [
        '"100-in-15min";q=100;w=900;pk=:bucket:',
        '"250-in-1day";q=250;w=86400;pk=:bucket:',
        '"3000-in-30days";q=3000;w=2592000;pk=:bucket:',
    ].join(', '),
};

function jsonResponse(payload, status = 200, headers = {}) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: {
            'Content-Type': 'application/json',
            ...headers,
        },
    });
}

function executeProbe(options) {
    return executeBufferWp1Probe({
        environment: 'testing',
        ...options,
    });
}

test('the WP1 probe parses every repeated Buffer quota window', () => {
    const header = [
        '"100-in-15min";r=99;t=897',
        '"250-in-1day";r=249;t=86397',
        '"3000-in-30days";r=2999;t=2591997',
    ].join(', ');

    assert.deepEqual(splitRepeatedRateLimitHeader(header), [
        '"100-in-15min";r=99;t=897',
        '"250-in-1day";r=249;t=86397',
        '"3000-in-30days";r=2999;t=2591997',
    ]);
    assert.deepEqual(splitRepeatedRateLimitHeader(null), []);
});

test('the account probe sends one fixed read-only query and returns sanitized evidence', async () => {
    const requests = [];
    const fetchImpl = async (url, options) => {
        requests.push({ url, options });

        return jsonResponse({
            data: {
                account: {
                    id: 'account-1',
                    name: 'Pulse Test Account',
                    organizations: [
                        { id: 'organization-1', name: 'First workspace' },
                        { id: 'organization-2', name: 'Second workspace' },
                    ],
                    connectedApps: [{
                        clientId: 'client-1',
                        scopes: ['account:read', 'posts:read'],
                    }],
                },
            },
        }, 200, {
            ...BUFFER_QUOTA_HEADERS,
            'X-Request-Id': 'request-account-1',
        });
    };

    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        fetchImpl,
    });

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, BUFFER_WP1_API_URL);
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.redirect, 'error');
    assert.equal(requests[0].options.headers.Authorization, `Bearer ${ACCESS_TOKEN}`);

    const requestBody = JSON.parse(requests[0].options.body);
    assert.equal(requestBody.query, BUFFER_WP1_ACCOUNT_QUERY);
    assert.deepEqual(requestBody.variables, {});
    assert.match(requestBody.query.trim(), /^query\s/u);
    assert.doesNotMatch(requestBody.query, /\bmutation\b/u);

    assert.equal(result.ok, true);
    assert.equal(result.operation, 'account');
    assert.equal(result.classification, 'success');
    assert.equal(result.request_id, 'request-account-1');
    assert.equal(result.safety.automatic_retries, 0);
    assert.equal(result.safety.mutation_documents_allowed, false);
    assert.deepEqual(result.data.account.organizations, [
        { id: 'organization-1', name: 'First workspace' },
        { id: 'organization-2', name: 'Second workspace' },
    ]);
    assert.deepEqual(result.data.account.connected_apps, [{
        client_id: 'client-1',
        scopes: ['account:read', 'posts:read'],
    }]);
    assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
    assert.equal(result.quota.rate_limits.length, 3);
    assert.equal(result.quota.rate_limit_policies.length, 3);
});

test('the channel probe binds the organization as a GraphQL variable without enabling mutations', async () => {
    const requests = [];
    const fetchImpl = async (url, options) => {
        requests.push({ url, options });

        return jsonResponse({
            data: {
                channels: [{
                    id: 'channel-1',
                    organizationId: 'organization-1',
                    name: '@malikia',
                    displayName: 'Malikia',
                    service: 'instagram',
                    type: 'business',
                    isDisconnected: false,
                    isLocked: false,
                    isQueuePaused: true,
                    timezone: 'America/Toronto',
                    scopes: ['publish'],
                    allowedActions: ['viewPublish'],
                }],
            },
        }, 200, BUFFER_QUOTA_HEADERS);
    };

    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        organizationId: 'organization-1',
        fetchImpl,
    });

    assert.equal(requests.length, 1);
    const requestBody = JSON.parse(requests[0].options.body);
    assert.equal(requestBody.query, BUFFER_WP1_CHANNELS_QUERY);
    assert.deepEqual(requestBody.variables, {
        input: { organizationId: 'organization-1' },
    });
    assert.doesNotMatch(requestBody.query, /\bmutation\b/u);
    assert.equal(result.ok, true);
    assert.equal(result.operation, 'channels');
    assert.deepEqual(result.data.channels, [{
        id: 'channel-1',
        organization_id: 'organization-1',
        name: '@malikia',
        display_name: 'Malikia',
        service: 'instagram',
        type: 'business',
        is_disconnected: false,
        is_locked: false,
        is_queue_paused: true,
        timezone: 'America/Toronto',
        scopes: ['publish'],
        allowed_actions: ['viewPublish'],
    }]);
});

test('the WP1 probe classifies GraphQL errors even when Buffer returns HTTP 200', async () => {
    let requestCount = 0;
    const reflectedMessage = `Not authorized: ${ACCESS_TOKEN}`;
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        fetchImpl: async () => {
            requestCount += 1;

            return jsonResponse({
                data: null,
                errors: [{
                    message: reflectedMessage,
                    extensions: { code: 'UNAUTHORIZED' },
                }],
            });
        },
    });

    assert.equal(requestCount, 1);
    assert.equal(result.ok, false);
    assert.equal(result.http_status, 200);
    assert.equal(result.classification, 'unauthorized');
    assert.deepEqual(result.graphql_errors, [{
        code: 'UNAUTHORIZED',
        window: null,
        message_sha256: createHash('sha256')
            .update('Not authorized: [REDACTED]')
            .digest('hex'),
    }]);
    assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
});

test('the WP1 probe fails closed when a present GraphQL errors contract is empty or malformed', async () => {
    for (const errors of [null, [], ['malformed']]) {
        const result = await executeProbe({
            accessToken: ACCESS_TOKEN,
            fetchImpl: async () => jsonResponse({
                data: {
                    account: {
                        id: 'account-1',
                        name: null,
                        organizations: [],
                        connectedApps: [],
                    },
                },
                errors,
            }, 200, BUFFER_QUOTA_HEADERS),
        });

        assert.equal(result.ok, false);
        assert.equal(result.classification, 'invalid_payload');
    }
});

test('the WP1 probe redacts the exact token from every remote evidence field', async () => {
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        fetchImpl: async () => jsonResponse({
            data: {
                account: {
                    id: 'account-1',
                    name: `Account ${ACCESS_TOKEN}`,
                    organizations: [{ id: 'organization-1', name: ACCESS_TOKEN }],
                    connectedApps: [],
                },
            },
            errors: [{
                message: `Reflected ${ACCESS_TOKEN}`,
                extensions: { code: ACCESS_TOKEN, window: ACCESS_TOKEN },
            }],
        }, 200, {
            ...BUFFER_QUOTA_HEADERS,
            'Retry-After': ACCESS_TOKEN,
            'X-Request-Id': ACCESS_TOKEN,
        }),
    });

    assert.equal(result.classification, 'graphql_error');
    assert.equal(result.request_id, '[REDACTED]');
    assert.equal(result.quota.retry_after_seconds, '[REDACTED]');
    assert.equal(result.graphql_errors[0].code, '[REDACTED]');
    assert.equal(result.graphql_errors[0].window, '[REDACTED]');
    assert.equal(result.data.account.name, 'Account [REDACTED]');
    assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
});

test('the WP1 probe rejects incomplete success payloads instead of inventing channel state', async () => {
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        organizationId: 'organization-1',
        fetchImpl: async () => jsonResponse({
            data: {
                channels: [{
                    id: 'channel-1',
                    organizationId: 'organization-1',
                    name: '@malikia',
                    service: 'instagram',
                    type: 'business',
                    timezone: 'America/Toronto',
                    scopes: [],
                    allowedActions: [],
                }],
            },
        }),
    });

    assert.equal(result.ok, false);
    assert.equal(result.classification, 'invalid_payload');
    assert.equal(result.data.channels, null);
});

test('the account probe preserves a nullable connected-app list and rejects malformed contract fields', async () => {
    const validAccount = {
        id: 'account-1',
        name: null,
        organizations: [{ id: 'organization-1', name: 'Workspace' }],
        connectedApps: null,
    };
    const nullableResult = await executeProbe({
        accessToken: ACCESS_TOKEN,
        fetchImpl: async () => jsonResponse({
            data: { account: validAccount },
        }, 200, BUFFER_QUOTA_HEADERS),
    });

    assert.equal(nullableResult.ok, true);
    assert.equal(nullableResult.data.account.connected_apps, null);

    const malformedAccounts = [
        { ...validAccount, connectedApps: undefined },
        {
            ...validAccount,
            organizations: [{ id: 'organization-1', name: null }],
        },
        {
            ...validAccount,
            connectedApps: [{ clientId: 'client-1', scopes: [null, 'account:read'] }],
        },
    ];

    for (const account of malformedAccounts) {
        const result = await executeProbe({
            accessToken: ACCESS_TOKEN,
            fetchImpl: async () => jsonResponse({
                data: { account },
            }, 200, BUFFER_QUOTA_HEADERS),
        });

        assert.equal(result.ok, false);
        assert.equal(result.classification, 'invalid_payload');
        assert.equal(result.data.account, null);
    }
});

test('the channel probe preserves nullable scopes and rejects invalid scope or action entries', async () => {
    const validChannel = {
        id: 'channel-1',
        organizationId: 'organization-1',
        name: '@malikia',
        displayName: null,
        service: 'instagram',
        type: 'business',
        isDisconnected: false,
        isLocked: false,
        isQueuePaused: false,
        timezone: 'America/Toronto',
        scopes: ['publish'],
        allowedActions: ['viewPublish'],
    };
    const nullableScopeResult = await executeProbe({
        accessToken: ACCESS_TOKEN,
        organizationId: 'organization-1',
        fetchImpl: async () => jsonResponse({
            data: { channels: [{ ...validChannel, scopes: [null, 'publish'] }] },
        }, 200, BUFFER_QUOTA_HEADERS),
    });

    assert.equal(nullableScopeResult.ok, true);
    assert.deepEqual(nullableScopeResult.data.channels[0].scopes, [null, 'publish']);

    const malformedChannels = [
        { ...validChannel, scopes: [42, 'publish'] },
        { ...validChannel, allowedActions: [42, 'viewPublish'] },
    ];

    for (const channel of malformedChannels) {
        const result = await executeProbe({
            accessToken: ACCESS_TOKEN,
            organizationId: 'organization-1',
            fetchImpl: async () => jsonResponse({
                data: { channels: [channel] },
            }, 200, BUFFER_QUOTA_HEADERS),
        });

        assert.equal(result.ok, false);
        assert.equal(result.classification, 'invalid_payload');
        assert.equal(result.data.channels, null);
    }
});

test('the WP1 probe refuses to call a valid payload complete without all quota windows', async () => {
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        fetchImpl: async () => jsonResponse({
            data: {
                account: {
                    id: 'account-1',
                    name: null,
                    organizations: [],
                    connectedApps: [],
                },
            },
        }),
    });

    assert.equal(result.ok, false);
    assert.equal(result.classification, 'incomplete_quota_evidence');
    assert.deepEqual(result.quota.rate_limits, []);
    assert.deepEqual(result.quota.rate_limit_policies, []);
});

test('the WP1 quota gate rejects duplicate, mismatched, or malformed windows', async () => {
    const duplicateHeaders = {
        RateLimit: Array(3).fill('"100-in-15min";r=99;t=897').join(', '),
        'RateLimit-Policy': Array(3).fill('"100-in-15min";q=100;w=900;pk=:bucket:').join(', '),
    };
    const mismatchedHeaders = {
        ...BUFFER_QUOTA_HEADERS,
        'RateLimit-Policy': BUFFER_QUOTA_HEADERS['RateLimit-Policy']
            .replace('"3000-in-30days";q=3000', '"999-in-30days";q=999'),
    };
    const wrongWindowHeaders = {
        ...BUFFER_QUOTA_HEADERS,
        'RateLimit-Policy': BUFFER_QUOTA_HEADERS['RateLimit-Policy']
            .replace('w=2592000', 'w=86400'),
    };
    const wrongResetHeaders = {
        ...BUFFER_QUOTA_HEADERS,
        RateLimit: BUFFER_QUOTA_HEADERS.RateLimit.replace('t=897', 't=999999'),
    };
    const malformedHeaders = {
        RateLimit: '"x";r=1;t=1, "y";r=1;t=1, "z";r=1;t=1',
        'RateLimit-Policy': '"x";q=1;w=900;pk=:bucket:, "y";q=1;w=86400;pk=:bucket:, "z";q=1;w=2592000;pk=:bucket:',
    };

    for (const headers of [
        duplicateHeaders,
        mismatchedHeaders,
        wrongWindowHeaders,
        wrongResetHeaders,
        malformedHeaders,
    ]) {
        const result = await executeProbe({
            accessToken: ACCESS_TOKEN,
            fetchImpl: async () => jsonResponse({
                data: {
                    account: {
                        id: 'account-1',
                        name: null,
                        organizations: [],
                        connectedApps: [],
                    },
                },
            }, 200, headers),
        });

        assert.equal(result.ok, false);
        assert.equal(result.classification, 'incomplete_quota_evidence');
    }
});

test('the WP1 quota gate accepts plan-dependent limits when all periods remain coherent', async () => {
    const headers = {
        RateLimit: [
            '"200-in-15min";r=199;t=897',
            '"500-in-1day";r=499;t=86397',
            '"6000-in-30days";r=5999;t=2591997',
        ].join(', '),
        'RateLimit-Policy': [
            '"200-in-15min";q=200;w=900;pk=:bucket:',
            '"500-in-1day";q=500;w=86400;pk=:bucket:',
            '"6000-in-30days";q=6000;w=2592000;pk=:bucket:',
        ].join(', '),
    };
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        fetchImpl: async () => jsonResponse({
            data: {
                account: {
                    id: 'account-1',
                    name: null,
                    organizations: [],
                    connectedApps: [],
                },
            },
        }, 200, headers),
    });

    assert.equal(result.ok, true);
    assert.equal(result.classification, 'success');
});

test('the WP1 probe preserves 401 and 429 transport evidence without retrying', async () => {
    const cases = [
        {
            status: 401,
            payload: { errors: [{ extensions: { code: 'UNAUTHORIZED' }, message: 'Invalid token' }] },
            headers: {},
            classification: 'unauthorized',
            retryAfter: null,
        },
        {
            status: 429,
            payload: { errors: [{ extensions: { code: 'RATE_LIMIT_EXCEEDED', window: '15m' }, message: 'Wait' }] },
            headers: {
                RateLimit: '"100-in-15min";r=0;t=591',
                'RateLimit-Policy': '"100-in-15min";q=100;w=900;pk=:bucket:',
                'Retry-After': '591',
            },
            classification: 'rate_limited',
            retryAfter: '591',
        },
    ];

    for (const testCase of cases) {
        let requestCount = 0;
        const result = await executeProbe({
            accessToken: ACCESS_TOKEN,
            fetchImpl: async () => {
                requestCount += 1;

                return jsonResponse(testCase.payload, testCase.status, testCase.headers);
            },
        });

        assert.equal(requestCount, 1);
        assert.equal(result.ok, false);
        assert.equal(result.http_status, testCase.status);
        assert.equal(result.classification, testCase.classification);
        assert.equal(result.quota.retry_after_seconds, testCase.retryAfter);
    }
});

test('the WP1 probe classifies timeout, transport, and non-JSON failures without retrying', async () => {
    const timeoutError = new Error('simulated timeout');
    timeoutError.name = 'AbortError';
    const cases = [
        {
            fetchImpl: async () => {
                throw timeoutError;
            },
            classification: 'timeout',
        },
        {
            fetchImpl: async () => {
                throw new Error('simulated connection failure');
            },
            classification: 'transport_error',
        },
        {
            fetchImpl: async () => new Response('<html>not json</html>', { status: 502 }),
            classification: 'http_error',
        },
        {
            fetchImpl: async () => new Response('<html>not json</html>', { status: 200 }),
            classification: 'invalid_json',
        },
        {
            fetchImpl: async () => new Response('<html>not json</html>', { status: 401 }),
            classification: 'unauthorized',
        },
        {
            fetchImpl: async () => new Response('<html>not json</html>', { status: 429 }),
            classification: 'rate_limited',
        },
    ];

    for (const testCase of cases) {
        let requestCount = 0;
        const result = await executeProbe({
            accessToken: ACCESS_TOKEN,
            fetchImpl: async (...arguments_) => {
                requestCount += 1;

                return testCase.fetchImpl(...arguments_);
            },
        });

        assert.equal(requestCount, 1);
        assert.equal(result.ok, false);
        assert.equal(result.classification, testCase.classification);
    }
});

test('the WP1 probe applies its actual abort signal to a timed-out request', { timeout: 2500 }, async () => {
    let abortObserved = false;
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        timeoutMs: 1000,
        fetchImpl: async (_url, options) => new Promise((_resolve, reject) => {
            options.signal.addEventListener('abort', () => {
                abortObserved = true;
                const error = new Error('request aborted');
                error.name = 'AbortError';
                reject(error);
            }, { once: true });
        }),
    });

    assert.equal(abortObserved, true);
    assert.equal(result.ok, false);
    assert.equal(result.classification, 'timeout');
});

test('the WP1 probe aborts a streamed response once its evidence exceeds one MiB', async () => {
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        fetchImpl: async () => new Response(new Uint8Array((1024 * 1024) + 1), {
            status: 200,
            headers: BUFFER_QUOTA_HEADERS,
        }),
    });

    assert.equal(result.ok, false);
    assert.equal(result.classification, 'response_too_large');
});

test('the WP1 probe validates direct execution environment and timeout before HTTP', async () => {
    const cases = [
        { environment: undefined, timeoutMs: 5000, code: 'ENVIRONMENT_REQUIRED' },
        { environment: 'production', timeoutMs: 5000, code: 'PRODUCTION_FORBIDDEN' },
        { environment: 'staging', timeoutMs: 5000, code: 'NON_LOCAL_ENVIRONMENT_FORBIDDEN' },
        { environment: 'testing', timeoutMs: '5000junk', code: 'TIMEOUT_MS_INVALID' },
        { environment: 'testing', timeoutMs: '1000.9', code: 'TIMEOUT_MS_INVALID' },
    ];

    for (const testCase of cases) {
        let requestCount = 0;

        await assert.rejects(
            executeBufferWp1Probe({
                accessToken: ACCESS_TOKEN,
                environment: testCase.environment,
                timeoutMs: testCase.timeoutMs,
                fetchImpl: async () => {
                    requestCount += 1;
                    return jsonResponse({ data: {} });
                },
            }),
            (error) => error.code === testCase.code,
        );
        assert.equal(requestCount, 0);
    }
});

test('the WP1 CLI refuses disabled and production execution before any HTTP request', async () => {
    const cases = [
        {
            env: { BUFFER_WP1_PROBE_ENABLED: 'true', BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN },
            code: 'ENVIRONMENT_REQUIRED',
        },
        {
            env: { APP_ENV: 'local', BUFFER_WP1_PROBE_ENABLED: 'false' },
            code: 'PROBE_DISABLED',
        },
        {
            env: {
                APP_ENV: 'production',
                BUFFER_WP1_PROBE_ENABLED: 'true',
                BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            },
            code: 'PRODUCTION_FORBIDDEN',
        },
        {
            env: {
                APP_ENV: 'staging',
                BUFFER_WP1_PROBE_ENABLED: 'true',
                BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            },
            code: 'NON_LOCAL_ENVIRONMENT_FORBIDDEN',
        },
        {
            env: {
                APP_ENV: 'local',
                NODE_ENV: 'prod',
                BUFFER_WP1_PROBE_ENABLED: 'true',
                BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            },
            code: 'NON_LOCAL_ENVIRONMENT_FORBIDDEN',
        },
        {
            env: {
                APP_ENV: 'local',
                NODE_ENV: 'production',
                BUFFER_WP1_PROBE_ENABLED: 'true',
                BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            },
            code: 'PRODUCTION_FORBIDDEN',
        },
        {
            env: { APP_ENV: 'local', BUFFER_WP1_PROBE_ENABLED: 'true' },
            code: 'ACCESS_TOKEN_REQUIRED',
        },
    ];

    for (const testCase of cases) {
        let requestCount = 0;
        let output = '';
        const exitCode = await runBufferWp1ProbeCli({
            env: testCase.env,
            fetchImpl: async () => {
                requestCount += 1;
                return jsonResponse({ data: {} });
            },
            stdout: (value) => {
                output += value;
            },
            stderr: (value) => {
                output += value;
            },
        });

        assert.equal(exitCode, 1);
        assert.equal(requestCount, 0);
        assert.equal(JSON.parse(output).code, testCase.code);
        assert.equal(output.includes(ACCESS_TOKEN), false);
    }
});

test('the WP1 CLI refuses an organization flag without an exact identifier', async () => {
    let output = '';
    const exitCode = await runBufferWp1ProbeCli({
        argv: ['--organization='],
        env: {
            APP_ENV: 'local',
            BUFFER_WP1_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
        },
        fetchImpl: async () => {
            assert.fail('HTTP must not run with an invalid organization argument');
        },
        stdout: (value) => {
            output += value;
        },
        stderr: (value) => {
            output += value;
        },
    });

    assert.equal(exitCode, 1);
    assert.equal(JSON.parse(output).code, 'ORGANIZATION_ID_REQUIRED');
});

test('the WP1 CLI prints successful evidence without exposing its access token', async () => {
    let output = '';
    const exitCode = await runBufferWp1ProbeCli({
        env: {
            APP_ENV: 'local',
            BUFFER_WP1_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            BUFFER_WP1_PROBE_TIMEOUT_MS: '5000',
        },
        fetchImpl: async () => jsonResponse({
            data: {
                account: {
                    id: 'account-1',
                    name: null,
                    organizations: [],
                    connectedApps: [],
                },
            },
        }, 200, BUFFER_QUOTA_HEADERS),
        stdout: (value) => {
            output += value;
        },
        stderr: (value) => {
            output += value;
        },
    });

    assert.equal(exitCode, 0);
    assert.equal(JSON.parse(output).classification, 'success');
    assert.equal(output.includes(ACCESS_TOKEN), false);
});
