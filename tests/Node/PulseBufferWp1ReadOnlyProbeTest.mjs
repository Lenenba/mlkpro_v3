import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';

import {
    BUFFER_WP1_ACCOUNT_QUERY,
    BUFFER_WP1_API_URL,
    BUFFER_WP1_CHANNELS_QUERY,
    BUFFER_WP1_SCHEMA_QUERY,
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

const INTROSPECTION_TYPE_KINDS = new Map([
    ['AnnotationInputFacebook', 'INPUT_OBJECT'],
    ['AssetInput', 'INPUT_OBJECT'],
    ['BlueskyPostMetadataInput', 'INPUT_OBJECT'],
    ['CreatePostInput', 'INPUT_OBJECT'],
    ['DeletePostInput', 'INPUT_OBJECT'],
    ['EditPostInput', 'INPUT_OBJECT'],
    ['FacebookPostMetadata', 'OBJECT'],
    ['FacebookPostMetadataInput', 'INPUT_OBJECT'],
    ['GoogleBusinessPostMetadataInput', 'INPUT_OBJECT'],
    ['InstagramPostMetadataInput', 'INPUT_OBJECT'],
    ['LinkedInPostMetadataInput', 'INPUT_OBJECT'],
    ['LinkAttachmentInput', 'INPUT_OBJECT'],
    ['MastodonPostMetadataInput', 'INPUT_OBJECT'],
    ['MovePostInQueueInput', 'INPUT_OBJECT'],
    ['PinterestPostMetadataInput', 'INPUT_OBJECT'],
    ['PostInput', 'INPUT_OBJECT'],
    ['PostInputMetaData', 'INPUT_OBJECT'],
    ['PostMetadata', 'UNION'],
    ['PostType', 'ENUM'],
    ['PostsInput', 'INPUT_OBJECT'],
    ['PostApprovalChange', 'ENUM'],
    ['PostTypeFacebook', 'ENUM'],
    ['QueuePosition', 'ENUM'],
    ['SchedulingType', 'ENUM'],
    ['ShareMode', 'ENUM'],
    ['ThreadsPostMetadataInput', 'INPUT_OBJECT'],
    ['TikTokPostMetadataInput', 'INPUT_OBJECT'],
    ['TwitterPostMetadataInput', 'INPUT_OBJECT'],
    ['YoutubePostMetadataInput', 'INPUT_OBJECT'],
    ['DeletePostPayload', 'UNION'],
    ['MovePostInQueuePayload', 'UNION'],
    ['PostActionPayload', 'UNION'],
    ['Post', 'OBJECT'],
    ['PostsResults', 'OBJECT'],
]);

function introspectionType(type) {
    if (type.endsWith('!')) {
        return { kind: 'NON_NULL', name: null, ofType: introspectionType(type.slice(0, -1)) };
    }
    if (type.startsWith('[') && type.endsWith(']')) {
        return { kind: 'LIST', name: null, ofType: introspectionType(type.slice(1, -1)) };
    }

    return {
        kind: INTROSPECTION_TYPE_KINDS.get(type) ?? 'SCALAR',
        name: type,
        ofType: null,
    };
}

function introspectionInput(name, type = 'String', defaultValue = null) {
    return { defaultValue, name, type: introspectionType(type) };
}

function introspectionField(name, argumentTypes, outputType) {
    return {
        args: Object.entries(argumentTypes).map(([argumentName, argumentType]) => (
            introspectionInput(argumentName, argumentType)
        )),
        deprecationReason: null,
        isDeprecated: false,
        name,
        type: introspectionType(outputType),
    };
}

function inputContract(name, fieldTypes) {
    return {
        enumValues: null,
        fields: null,
        inputFields: fieldTypes.map(([fieldName, fieldType, defaultValue = null]) => (
            introspectionInput(fieldName, fieldType, defaultValue)
        )),
        kind: 'INPUT_OBJECT',
        name,
        possibleTypes: null,
    };
}

function unionContract(name, possibleTypeNames) {
    return {
        enumValues: null,
        fields: null,
        inputFields: null,
        kind: 'UNION',
        name,
        possibleTypes: possibleTypeNames.map((possibleTypeName) => ({
            kind: 'OBJECT',
            name: possibleTypeName,
        })),
    };
}

function enumContract(name, enumValueNames) {
    return {
        enumValues: enumValueNames.map((enumValueName) => ({
            deprecationReason: null,
            isDeprecated: false,
            name: enumValueName,
        })),
        fields: null,
        inputFields: null,
        kind: 'ENUM',
        name,
        possibleTypes: null,
    };
}

function objectContract(name, fieldTypes) {
    return {
        enumValues: null,
        fields: fieldTypes.map(([fieldName, fieldType]) => (
            introspectionField(fieldName, {}, fieldType)
        )),
        inputFields: null,
        kind: 'OBJECT',
        name,
        possibleTypes: null,
    };
}

function schemaPayload() {
    return {
        data: {
            createPostInput: inputContract('CreatePostInput', [
                ['aiAssisted', 'Boolean'],
                ['assets', '[AssetInput!]!', '[]'],
                ['channelId', 'ChannelId!'],
                ['draftId', 'DraftId'],
                ['dueAt', 'DateTime'],
                ['ideaId', 'IdeaId'],
                ['metadata', 'PostInputMetaData'],
                ['mode', 'ShareMode!'],
                ['needsApproval', 'Boolean!', 'false'],
                ['saveToDraft', 'Boolean'],
                ['schedulingType', 'SchedulingType!'],
                ['source', 'String'],
                ['tagIds', '[TagId!]'],
                ['text', 'String'],
            ]),
            deletePostInput: inputContract('DeletePostInput', [['id', 'PostId!']]),
            deletePostPayload: unionContract('DeletePostPayload', [
                'DeletePostSuccess',
                'VoidMutationError',
            ]),
            editPostInput: inputContract('EditPostInput', [
                ['aiAssisted', 'Boolean'],
                ['approvalChange', 'PostApprovalChange'],
                ['assets', '[AssetInput!]'],
                ['draftId', 'DraftId'],
                ['dueAt', 'DateTime'],
                ['id', 'PostId!'],
                ['ideaId', 'IdeaId'],
                ['metadata', 'PostInputMetaData'],
                ['mode', 'ShareMode'],
                ['saveToDraft', 'Boolean'],
                ['schedulingType', 'SchedulingType'],
                ['source', 'String'],
                ['tagIds', '[TagId!]'],
                ['text', 'String'],
            ]),
            facebookPostMetadataInput: inputContract('FacebookPostMetadataInput', [
                ['annotations', '[AnnotationInputFacebook!]'],
                ['firstComment', 'String'],
                ['linkAttachment', 'LinkAttachmentInput'],
                ['type', 'PostTypeFacebook!'],
            ]),
            facebookPostMetadata: objectContract('FacebookPostMetadata', [
                ['type', 'PostType!'],
            ]),
            movePostInQueueInput: inputContract('MovePostInQueueInput', [
                ['id', 'PostId!'],
                ['position', 'QueuePosition!'],
            ]),
            movePostInQueuePayload: unionContract('MovePostInQueuePayload', [
                'PostActionSuccess',
                'VoidMutationError',
            ]),
            mutationRoot: {
                fields: [
                    introspectionField(
                        'createPost',
                        { input: 'CreatePostInput!' },
                        'PostActionPayload!',
                    ),
                    introspectionField(
                        'deletePost',
                        { input: 'DeletePostInput!' },
                        'DeletePostPayload!',
                    ),
                    introspectionField(
                        'editPost',
                        { input: 'EditPostInput!' },
                        'PostActionPayload!',
                    ),
                    introspectionField(
                        'movePostInQueue',
                        { input: 'MovePostInQueueInput!' },
                        'MovePostInQueuePayload!',
                    ),
                ],
                kind: 'OBJECT',
                name: 'Mutation',
            },
            postActionPayload: unionContract('PostActionPayload', [
                'PostActionSuccess',
                'NotFoundError',
                'UnauthorizedError',
                'UnexpectedError',
                'RestProxyError',
                'LimitReachedError',
                'InvalidInputError',
            ]),
            postApprovalChange: enumContract('PostApprovalChange', ['request', 'revert']),
            post: objectContract('Post', [['metadata', 'PostMetadata']]),
            postInputMetaData: inputContract('PostInputMetaData', [
                ['bluesky', 'BlueskyPostMetadataInput'],
                ['facebook', 'FacebookPostMetadataInput'],
                ['google', 'GoogleBusinessPostMetadataInput'],
                ['instagram', 'InstagramPostMetadataInput'],
                ['linkedin', 'LinkedInPostMetadataInput'],
                ['mastodon', 'MastodonPostMetadataInput'],
                ['pinterest', 'PinterestPostMetadataInput'],
                ['threads', 'ThreadsPostMetadataInput'],
                ['tiktok', 'TikTokPostMetadataInput'],
                ['twitter', 'TwitterPostMetadataInput'],
                ['youtube', 'YoutubePostMetadataInput'],
            ]),
            postMetadata: unionContract('PostMetadata', ['FacebookPostMetadata']),
            postType: enumContract('PostType', [
                'carousel',
                'event',
                'ghost_post',
                'offer',
                'post',
                'reel',
                'short',
                'story',
                'thread',
                'whats_new',
            ]),
            postTypeFacebook: enumContract('PostTypeFacebook', ['post', 'reel', 'story']),
            queuePosition: enumContract('QueuePosition', ['bottom', 'top']),
            postStatus: enumContract('PostStatus', [
                'draft',
                'error',
                'needs_approval',
                'scheduled',
                'sending',
                'sent',
            ]),
            queryRoot: {
                fields: [
                    introspectionField('post', { input: 'PostInput!' }, 'Post!'),
                    introspectionField(
                        'posts',
                        { after: 'String', first: 'Int', input: 'PostsInput!' },
                        'PostsResults!',
                    ),
                ],
                kind: 'OBJECT',
                name: 'Query',
            },
            schedulingType: enumContract('SchedulingType', ['automatic', 'notification']),
            shareMode: enumContract('ShareMode', [
                'addToQueue',
                'customScheduled',
                'shareNext',
                'shareNow',
            ]),
        },
    };
}

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

test('the schema probe sends one fixed introspection query and normalizes the mutation contract', async () => {
    const requests = [];
    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        schema: true,
        fetchImpl: async (url, options) => {
            requests.push({ url, options });

            return jsonResponse(schemaPayload());
        },
    });

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, BUFFER_WP1_API_URL);
    const requestBody = JSON.parse(requests[0].options.body);
    assert.equal(requestBody.query, BUFFER_WP1_SCHEMA_QUERY);
    assert.deepEqual(requestBody.variables, {});
    assert.match(requestBody.query.trim(), /^query\s/u);
    assert.doesNotMatch(requestBody.query, /^\s*mutation\b/u);
    const operationDefinition = requestBody.query.split('\n\nfragment ')[0];
    const rootSelectionLines = operationDefinition.split('\n')
        .filter((line) => /^  \S/u.test(line) && line.trim() !== '}');
    assert.ok(rootSelectionLines.length > 0);
    for (const rootSelectionLine of rootSelectionLines) {
        assert.match(
            rootSelectionLine,
            /^  [_A-Za-z][_0-9A-Za-z]*:\s*__type\(name:\s*"[_A-Za-z][_0-9A-Za-z]*"\)\s*\{$/u,
        );
    }
    assert.equal(result.ok, true);
    assert.equal(result.operation, 'schema');
    assert.equal(result.classification, 'success');
    assert.equal(result.data.schema_contract.profile, 'full');
    assert.deepEqual(
        result.data.schema_contract.capabilities.facebook_create_metadata,
        {
            facebook_field: 'facebook:FacebookPostMetadataInput',
            metadata_input: 'PostInputMetaData',
            post_type_field: 'type:PostTypeFacebook!',
            post_types: ['post', 'reel', 'story'],
        },
    );
    assert.deepEqual(result.quota.rate_limits, []);
    assert.deepEqual(result.quota.rate_limit_policies, []);
    assert.deepEqual(
        result.data.schema_contract.mutation_fields.map((field) => field.name),
        ['createPost', 'deletePost', 'editPost', 'movePostInQueue'],
    );
    assert.deepEqual(
        result.data.schema_contract.types.shareMode.enum_values.map((enumValue) => enumValue.name),
        ['addToQueue', 'customScheduled', 'shareNext', 'shareNow'],
    );
    assert.deepEqual(
        Object.fromEntries(result.data.schema_contract.types.createPostInput.input_fields.map((field) => (
            [field.name, field.type]
        ))),
        {
            aiAssisted: 'Boolean',
            assets: '[AssetInput!]!',
            channelId: 'ChannelId!',
            draftId: 'DraftId',
            dueAt: 'DateTime',
            ideaId: 'IdeaId',
            metadata: 'PostInputMetaData',
            mode: 'ShareMode!',
            needsApproval: 'Boolean!',
            saveToDraft: 'Boolean',
            schedulingType: 'SchedulingType!',
            source: 'String',
            tagIds: '[TagId!]',
            text: 'String',
        },
    );
    assert.deepEqual(
        Object.fromEntries(result.data.schema_contract.types.createPostInput.input_fields.map((field) => (
            [field.name, field.default_value]
        ))),
        {
            aiAssisted: null,
            assets: '[]',
            channelId: null,
            draftId: null,
            dueAt: null,
            ideaId: null,
            metadata: null,
            mode: null,
            needsApproval: 'false',
            saveToDraft: null,
            schedulingType: null,
            source: null,
            tagIds: null,
            text: null,
        },
    );
    assert.deepEqual(
        Object.fromEntries(
            result.data.schema_contract.types.facebookPostMetadataInput.input_fields.map((field) => (
                [field.name, { default: field.default_value, type: field.type }]
            )),
        ),
        {
            annotations: { default: null, type: '[AnnotationInputFacebook!]' },
            firstComment: { default: null, type: 'String' },
            linkAttachment: { default: null, type: 'LinkAttachmentInput' },
            type: { default: null, type: 'PostTypeFacebook!' },
        },
    );
    assert.equal(
        result.data.schema_contract.types.postInputMetaData.input_fields
            .find((field) => field.name === 'facebook')?.type,
        'FacebookPostMetadataInput',
    );
    assert.deepEqual(
        Object.fromEntries(
            result.data.schema_contract.types.postInputMetaData.input_fields.map((field) => (
                [field.name, { default: field.default_value, type: field.type }]
            )),
        ),
        {
            bluesky: { default: null, type: 'BlueskyPostMetadataInput' },
            facebook: { default: null, type: 'FacebookPostMetadataInput' },
            google: { default: null, type: 'GoogleBusinessPostMetadataInput' },
            instagram: { default: null, type: 'InstagramPostMetadataInput' },
            linkedin: { default: null, type: 'LinkedInPostMetadataInput' },
            mastodon: { default: null, type: 'MastodonPostMetadataInput' },
            pinterest: { default: null, type: 'PinterestPostMetadataInput' },
            threads: { default: null, type: 'ThreadsPostMetadataInput' },
            tiktok: { default: null, type: 'TikTokPostMetadataInput' },
            twitter: { default: null, type: 'TwitterPostMetadataInput' },
            youtube: { default: null, type: 'YoutubePostMetadataInput' },
        },
    );
    assert.deepEqual(
        result.data.schema_contract.types.postTypeFacebook.enum_values.map(
            (enumValue) => enumValue.name,
        ),
        ['post', 'reel', 'story'],
    );
    assert.equal(
        result.data.schema_contract.types.post.output_fields
            .find((field) => field.name === 'metadata')?.type,
        'PostMetadata',
    );
    assert.deepEqual(
        result.data.schema_contract.types.postMetadata.possible_types,
        ['FacebookPostMetadata'],
    );
    assert.equal(
        result.data.schema_contract.types.facebookPostMetadata.output_fields
            .find((field) => field.name === 'type')?.type,
        'PostType!',
    );
    assert.equal(
        result.data.schema_contract.types.postType.enum_values
            .find((enumValue) => enumValue.name === 'post')?.is_deprecated,
        false,
    );
    assert.deepEqual(
        result.data.schema_contract.types.queuePosition.enum_values.map((enumValue) => enumValue.name),
        ['bottom', 'top'],
    );
    assert.equal(JSON.stringify(result).includes(ACCESS_TOKEN), false);
});

test('the cleanup schema profile ignores create metadata drift but keeps post and delete exact', async () => {
    const payload = schemaPayload();
    payload.data.postInputMetaData = null;
    payload.data.facebookPostMetadataInput = null;
    payload.data.postTypeFacebook = null;
    payload.data.mutationRoot.fields = payload.data.mutationRoot.fields
        .filter((field) => field.name !== 'createPost');

    const result = await executeProbe({
        accessToken: ACCESS_TOKEN,
        schema: true,
        schemaProfile: 'cleanup',
        fetchImpl: async () => jsonResponse(payload),
    });

    assert.equal(result.ok, true);
    assert.equal(result.operation, 'schema');
    assert.equal(result.classification, 'success');
    assert.equal(result.data.schema_contract.profile, 'cleanup');
    assert.deepEqual(Object.keys(result.data.schema_contract.types), [
        'deletePostInput',
        'deletePostPayload',
        'facebookPostMetadata',
        'post',
        'postMetadata',
        'postType',
    ]);
    assert.deepEqual(
        result.data.schema_contract.capabilities.post_delete_cleanup,
        {
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
    );
});

test('the cleanup schema profile fails closed when Facebook metadata output drifts', async (t) => {
    const cases = [
        {
            name: 'Post metadata field is removed',
            mutate(payload) {
                payload.data.post.fields = [];
            },
        },
        {
            name: 'Post metadata field type changes',
            mutate(payload) {
                payload.data.post.fields[0].type = introspectionType('String');
            },
        },
        {
            name: 'Post metadata field is deprecated',
            mutate(payload) {
                payload.data.post.fields[0].isDeprecated = true;
                payload.data.post.fields[0].deprecationReason = 'retired';
            },
        },
        {
            name: 'Facebook metadata leaves the union',
            mutate(payload) {
                payload.data.postMetadata.possibleTypes = [];
            },
        },
        {
            name: 'Facebook metadata type field is removed',
            mutate(payload) {
                payload.data.facebookPostMetadata.fields = [];
            },
        },
        {
            name: 'Facebook metadata type loses its non-null wrapper',
            mutate(payload) {
                payload.data.facebookPostMetadata.fields[0].type = introspectionType('PostType');
            },
        },
        {
            name: 'Facebook metadata type gains a required argument',
            mutate(payload) {
                payload.data.facebookPostMetadata.fields[0].args.push(
                    introspectionInput('futureRequired', 'String!'),
                );
            },
        },
        {
            name: 'Post enum removes post',
            mutate(payload) {
                payload.data.postType.enumValues = payload.data.postType.enumValues
                    .filter((enumValue) => enumValue.name !== 'post');
            },
        },
        {
            name: 'Post enum deprecates post',
            mutate(payload) {
                const post = payload.data.postType.enumValues
                    .find((enumValue) => enumValue.name === 'post');
                post.isDeprecated = true;
                post.deprecationReason = 'retired';
            },
        },
    ];

    for (const testCase of cases) {
        await t.test(testCase.name, async () => {
            const payload = schemaPayload();
            testCase.mutate(payload);
            const result = await executeProbe({
                accessToken: ACCESS_TOKEN,
                schema: true,
                schemaProfile: 'cleanup',
                fetchImpl: async () => jsonResponse(payload),
            });

            assert.equal(result.ok, false);
            assert.equal(result.classification, 'invalid_payload');
            assert.equal(result.data.schema_contract, null);
        });
    }
});

test('the schema probe fails closed on incompatible removals and deprecations', async (t) => {
    const cases = [
        ...['postInputMetaData', 'facebookPostMetadataInput', 'postTypeFacebook'].map((alias) => ({
            name: `${alias} introspection type is absent`,
            mutate(payload) {
                payload.data[alias] = null;
            },
        })),
        ...[
            'bluesky',
            'facebook',
            'google',
            'instagram',
            'linkedin',
            'mastodon',
            'pinterest',
            'threads',
            'tiktok',
            'twitter',
            'youtube',
        ].map((metadataField) => ({
            name: `${metadataField} metadata entry removed`,
            mutate(payload) {
                payload.data.postInputMetaData.inputFields = payload.data.postInputMetaData.inputFields
                    .filter((field) => field.name !== metadataField);
            },
        })),
        {
            name: 'required mutation removed',
            mutate(payload) {
                payload.data.mutationRoot.fields = payload.data.mutationRoot.fields
                    .filter((field) => field.name !== 'deletePost');
            },
        },
        {
            name: 'required union member removed',
            mutate(payload) {
                payload.data.postActionPayload.possibleTypes = payload.data.postActionPayload.possibleTypes
                    .filter((possibleType) => possibleType.name !== 'InvalidInputError');
            },
        },
        {
            name: 'required enum value removed',
            mutate(payload) {
                payload.data.shareMode.enumValues = payload.data.shareMode.enumValues
                    .filter((enumValue) => enumValue.name !== 'shareNow');
            },
        },
        {
            name: 'required enum value deprecated',
            mutate(payload) {
                const shareNow = payload.data.shareMode.enumValues
                    .find((enumValue) => enumValue.name === 'shareNow');
                shareNow.isDeprecated = true;
                shareNow.deprecationReason = 'retired';
            },
        },
        {
            name: 'required Facebook post type removed',
            mutate(payload) {
                payload.data.facebookPostMetadataInput.inputFields = payload.data
                    .facebookPostMetadataInput.inputFields
                    .filter((field) => field.name !== 'type');
            },
        },
        {
            name: 'required Facebook enum value removed',
            mutate(payload) {
                payload.data.postTypeFacebook.enumValues = payload.data.postTypeFacebook.enumValues
                    .filter((enumValue) => enumValue.name !== 'post');
            },
        },
        {
            name: 'required Facebook enum value deprecated',
            mutate(payload) {
                const post = payload.data.postTypeFacebook.enumValues
                    .find((enumValue) => enumValue.name === 'post');
                post.isDeprecated = true;
                post.deprecationReason = 'retired';
            },
        },
        {
            name: 'required query deprecated',
            mutate(payload) {
                const posts = payload.data.queryRoot.fields.find((field) => field.name === 'posts');
                posts.isDeprecated = true;
                posts.deprecationReason = 'retired';
            },
        },
        {
            name: 'required mutation deprecated',
            mutate(payload) {
                const createPost = payload.data.mutationRoot.fields
                    .find((field) => field.name === 'createPost');
                createPost.isDeprecated = true;
                createPost.deprecationReason = 'retired';
            },
        },
    ];

    for (const testCase of cases) {
        await t.test(testCase.name, async () => {
            const payload = schemaPayload();
            testCase.mutate(payload);
            const result = await executeProbe({
                accessToken: ACCESS_TOKEN,
                schema: true,
                fetchImpl: async () => jsonResponse(payload),
            });

            assert.equal(result.ok, false);
            assert.equal(result.classification, 'invalid_payload');
            assert.equal(result.data.schema_contract, null);
        });
    }
});

test('the schema probe distinguishes compatible additions from breaking required inputs', async (t) => {
    await t.test('accepts optional or defaulted additions', async () => {
        const payload = schemaPayload();
        payload.data.createPostInput.inputFields.push(
            introspectionInput('futureOptional', 'String'),
            introspectionInput('futureDefaulted', 'Boolean!', 'false'),
        );
        payload.data.facebookPostMetadataInput.inputFields.push(
            introspectionInput('futureFacebookOptional', 'String'),
            introspectionInput('futureFacebookDefaulted', 'Boolean!', 'false'),
        );
        payload.data.postInputMetaData.inputFields.push(
            {
                defaultValue: null,
                name: 'futureNetwork',
                type: {
                    kind: 'INPUT_OBJECT',
                    name: 'FutureNetworkPostMetadataInput',
                    ofType: null,
                },
            },
        );
        const createPost = payload.data.mutationRoot.fields
            .find((field) => field.name === 'createPost');
        createPost.args.push(
            introspectionInput('futureOptional', 'String'),
            introspectionInput('futureDefaulted', 'Boolean!', 'false'),
        );
        payload.data.shareMode.enumValues.push({
            deprecationReason: null,
            isDeprecated: false,
            name: 'futureMode',
        });
        payload.data.postTypeFacebook.enumValues.push({
            deprecationReason: null,
            isDeprecated: false,
            name: 'futureFacebookType',
        });
        payload.data.post.fields.push(
            introspectionField('futureMetadataSummary', {}, 'String'),
        );
        payload.data.facebookPostMetadata.fields.push(
            introspectionField('futureFacebookOutput', {}, 'String'),
        );
        payload.data.postMetadata.possibleTypes.push({
            kind: 'OBJECT',
            name: 'FuturePostMetadata',
        });
        payload.data.postType.enumValues.push({
            deprecationReason: null,
            isDeprecated: false,
            name: 'futurePostType',
        });
        payload.data.postActionPayload.possibleTypes.push({ kind: 'OBJECT', name: 'FutureError' });

        const result = await executeProbe({
            accessToken: ACCESS_TOKEN,
            schema: true,
            fetchImpl: async () => jsonResponse(payload),
        });

        assert.equal(result.ok, true);
        assert.equal(result.classification, 'success');
        assert.ok(
            result.data.schema_contract.types.postTypeFacebook.enum_values
                .some((enumValue) => enumValue.name === 'futureFacebookType'),
        );
        assert.ok(
            result.data.schema_contract.types.post.output_fields
                .some((field) => field.name === 'futureMetadataSummary'),
        );
        assert.ok(
            result.data.schema_contract.types.postMetadata.possible_types
                .includes('FuturePostMetadata'),
        );
        assert.ok(
            result.data.schema_contract.types.postType.enum_values
                .some((enumValue) => enumValue.name === 'futurePostType'),
        );
    });

    const breakingCases = [
        {
            name: 'known input type changed',
            mutate(payload) {
                const channelId = payload.data.createPostInput.inputFields
                    .find((field) => field.name === 'channelId');
                channelId.type = introspectionType('Boolean!');
            },
        },
        {
            name: 'Facebook post type loses non-null wrapper',
            mutate(payload) {
                const type = payload.data.facebookPostMetadataInput.inputFields
                    .find((field) => field.name === 'type');
                type.type = introspectionType('PostTypeFacebook');
            },
        },
        {
            name: 'Facebook metadata type changed',
            mutate(payload) {
                const facebook = payload.data.postInputMetaData.inputFields
                    .find((field) => field.name === 'facebook');
                facebook.type = introspectionType('String');
            },
        },
        {
            name: 'new required input has no default',
            mutate(payload) {
                payload.data.createPostInput.inputFields.push(
                    introspectionInput('futureRequired', 'String!'),
                );
            },
        },
        {
            name: 'new required Facebook metadata has no default',
            mutate(payload) {
                payload.data.facebookPostMetadataInput.inputFields.push(
                    introspectionInput('futureRequired', 'String!'),
                );
            },
        },
        {
            name: 'new required root argument has no default',
            mutate(payload) {
                const createPost = payload.data.mutationRoot.fields
                    .find((field) => field.name === 'createPost');
                createPost.args.push(introspectionInput('futureRequired', 'String!'));
            },
        },
        {
            name: 'mutation return type changed',
            mutate(payload) {
                const createPost = payload.data.mutationRoot.fields
                    .find((field) => field.name === 'createPost');
                createPost.type = introspectionType('DeletePostPayload!');
            },
        },
        {
            name: 'required mutation argument removed',
            mutate(payload) {
                const createPost = payload.data.mutationRoot.fields
                    .find((field) => field.name === 'createPost');
                createPost.args = [];
            },
        },
        {
            name: 'required query argument type changed',
            mutate(payload) {
                const post = payload.data.queryRoot.fields.find((field) => field.name === 'post');
                post.args[0].type = introspectionType('PostsInput!');
            },
        },
        {
            name: 'known input default changed',
            mutate(payload) {
                const saveToDraft = payload.data.createPostInput.inputFields
                    .find((field) => field.name === 'saveToDraft');
                saveToDraft.defaultValue = 'true';
            },
        },
        {
            name: 'known query argument default changed',
            mutate(payload) {
                const posts = payload.data.queryRoot.fields.find((field) => field.name === 'posts');
                const first = posts.args.find((argument) => argument.name === 'first');
                first.defaultValue = '1000';
            },
        },
    ];

    for (const testCase of breakingCases) {
        await t.test(testCase.name, async () => {
            const payload = schemaPayload();
            testCase.mutate(payload);
            const result = await executeProbe({
                accessToken: ACCESS_TOKEN,
                schema: true,
                fetchImpl: async () => jsonResponse(payload),
            });

            assert.equal(result.ok, false);
            assert.equal(result.classification, 'invalid_payload');
        });
    }
});

test('the schema probe rejects duplicate, malformed, or incoherent introspection structures', async (t) => {
    const cases = [
        {
            name: 'duplicate root field',
            mutate(payload) {
                payload.data.mutationRoot.fields.push(payload.data.mutationRoot.fields[0]);
            },
        },
        {
            name: 'duplicate enum value',
            mutate(payload) {
                payload.data.shareMode.enumValues.push(payload.data.shareMode.enumValues[0]);
            },
        },
        {
            name: 'input object exposes enum values',
            mutate(payload) {
                payload.data.createPostInput.enumValues = [];
            },
        },
        {
            name: 'named type kind is invalid',
            mutate(payload) {
                const channelId = payload.data.createPostInput.inputFields
                    .find((field) => field.name === 'channelId');
                channelId.type.ofType.kind = 'INVALID_KIND';
            },
        },
        {
            name: 'known scalar is disguised as an input object',
            mutate(payload) {
                const channelId = payload.data.createPostInput.inputFields
                    .find((field) => field.name === 'channelId');
                channelId.type.ofType.kind = 'INPUT_OBJECT';
            },
        },
        {
            name: 'known input object is disguised as a scalar',
            mutate(payload) {
                const post = payload.data.queryRoot.fields.find((field) => field.name === 'post');
                post.args[0].type.ofType.kind = 'SCALAR';
            },
        },
        {
            name: 'Facebook metadata input is disguised as a scalar',
            mutate(payload) {
                const facebook = payload.data.postInputMetaData.inputFields
                    .find((field) => field.name === 'facebook');
                facebook.type.kind = 'SCALAR';
            },
        },
        {
            name: 'Facebook post type enum is disguised as a scalar',
            mutate(payload) {
                const type = payload.data.facebookPostMetadataInput.inputFields
                    .find((field) => field.name === 'type');
                type.type.ofType.kind = 'SCALAR';
            },
        },
        {
            name: 'Facebook annotation input is disguised as a scalar',
            mutate(payload) {
                const annotations = payload.data.facebookPostMetadataInput.inputFields
                    .find((field) => field.name === 'annotations');
                annotations.type.ofType.ofType.kind = 'SCALAR';
            },
        },
        {
            name: 'Facebook annotation item loses its non-null wrapper',
            mutate(payload) {
                const annotations = payload.data.facebookPostMetadataInput.inputFields
                    .find((field) => field.name === 'annotations');
                annotations.type.ofType = introspectionType('AnnotationInputFacebook');
            },
        },
        {
            name: 'wrapper exposes an impossible name',
            mutate(payload) {
                const channelId = payload.data.createPostInput.inputFields
                    .find((field) => field.name === 'channelId');
                channelId.type.name = 'ImpossibleWrapperName';
            },
        },
        {
            name: 'named type exposes an impossible nested type',
            mutate(payload) {
                const channelId = payload.data.createPostInput.inputFields
                    .find((field) => field.name === 'channelId');
                channelId.type.ofType.ofType = introspectionType('String');
            },
        },
        {
            name: 'non-null wraps another non-null',
            mutate(payload) {
                payload.data.createPostInput.inputFields.push({
                    defaultValue: '"safe"',
                    name: 'futureDefaulted',
                    type: {
                        kind: 'NON_NULL',
                        name: null,
                        ofType: {
                            kind: 'NON_NULL',
                            name: null,
                            ofType: introspectionType('String'),
                        },
                    },
                });
            },
        },
        {
            name: 'output-only union is used as an input',
            mutate(payload) {
                payload.data.createPostInput.inputFields.push({
                    defaultValue: null,
                    name: 'futureUnion',
                    type: { kind: 'UNION', name: 'FutureUnion', ofType: null },
                });
            },
        },
        {
            name: 'input object is used as a query output',
            mutate(payload) {
                payload.data.queryRoot.fields.push({
                    args: [],
                    deprecationReason: null,
                    isDeprecated: false,
                    name: 'futureQuery',
                    type: { kind: 'INPUT_OBJECT', name: 'FutureInput', ofType: null },
                });
            },
        },
        {
            name: 'input field has an invalid GraphQL name',
            mutate(payload) {
                payload.data.createPostInput.inputFields.push(
                    introspectionInput('bad-name', 'String'),
                );
            },
        },
        {
            name: 'enum value has an invalid GraphQL name',
            mutate(payload) {
                payload.data.shareMode.enumValues.push({
                    deprecationReason: null,
                    isDeprecated: false,
                    name: 'bad-value',
                });
            },
        },
        {
            name: 'union member has an invalid GraphQL name',
            mutate(payload) {
                payload.data.postActionPayload.possibleTypes.push({
                    kind: 'OBJECT',
                    name: 'bad-type',
                });
            },
        },
    ];

    for (const testCase of cases) {
        await t.test(testCase.name, async () => {
            const payload = schemaPayload();
            testCase.mutate(payload);
            const result = await executeProbe({
                accessToken: ACCESS_TOKEN,
                schema: true,
                fetchImpl: async () => jsonResponse(payload),
            });

            assert.equal(result.ok, false);
            assert.equal(result.classification, 'invalid_payload');
        });
    }
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

test('the WP1 probe redaction marker never reintroduces a colliding token', async (t) => {
    for (const collidingToken of ['REDACTED', '[REDACTED]']) {
        await t.test(collidingToken, async () => {
            const result = await executeProbe({
                accessToken: collidingToken,
                fetchImpl: async () => jsonResponse({
                    data: {
                        account: {
                            id: collidingToken,
                            name: collidingToken,
                            organizations: [{ id: 'organization-1', name: collidingToken }],
                            connectedApps: [],
                        },
                    },
                }, 200, {
                    ...BUFFER_QUOTA_HEADERS,
                    'X-Request-Id': collidingToken,
                }),
            });

            assert.equal(result.ok, true);
            assert.equal(JSON.stringify(result).includes(collidingToken), false);
        });
    }
});

test('the WP1 probe redaction cannot recombine secret fragments around a replacement', async () => {
    const collidingToken = 'RE';
    const result = await executeProbe({
        accessToken: collidingToken,
        fetchImpl: async () => jsonResponse({
            data: {
                account: {
                    id: 'account-1',
                    name: 'RREE',
                    organizations: [{ id: 'organization-1', name: 'Workspace' }],
                    connectedApps: [],
                },
            },
        }, 200, BUFFER_QUOTA_HEADERS),
    });

    assert.equal(result.ok, true);
    assert.equal(result.data.account.name, 'R*E');
    assert.equal(JSON.stringify(result).includes(collidingToken), false);
});

test('the schema probe redacts recombining fragments from defaults and deprecation reasons', async () => {
    const collidingToken = 'RE';
    const payload = schemaPayload();
    payload.data.facebookPostMetadataInput.inputFields.push(
        introspectionInput('futureOptional', 'String', '"RREE"'),
    );
    const futureMutation = introspectionField('futureMutation', {}, 'String');
    futureMutation.isDeprecated = true;
    futureMutation.deprecationReason = 'RREE';
    payload.data.mutationRoot.fields.push(futureMutation);

    const result = await executeProbe({
        accessToken: collidingToken,
        schema: true,
        fetchImpl: async () => jsonResponse(payload),
    });

    assert.equal(result.ok, true);
    assert.equal(
        result.data.schema_contract.types.facebookPostMetadataInput.input_fields
            .find((field) => field.name === 'futureOptional')?.default_value,
        '"R*E"',
    );
    assert.equal(
        result.data.schema_contract.mutation_fields
            .find((field) => field.name === 'futureMutation')?.deprecation_reason,
        'R*E',
    );
    assert.equal(JSON.stringify(result).includes(collidingToken), false);
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
        { environment: 'testing', timeoutMs: 5000, schema: 'yes', code: 'SCHEMA_FLAG_INVALID' },
        {
            environment: 'testing',
            timeoutMs: 5000,
            schema: true,
            schemaProfile: 'unknown',
            code: 'SCHEMA_PROFILE_INVALID',
        },
        {
            environment: 'testing',
            timeoutMs: 5000,
            schemaProfile: 'cleanup',
            code: 'ARGUMENT_COMBINATION_INVALID',
        },
        {
            environment: 'testing',
            organizationId: 'organization-1',
            timeoutMs: 5000,
            schema: true,
            code: 'ARGUMENT_COMBINATION_INVALID',
        },
    ];

    for (const testCase of cases) {
        let requestCount = 0;

        await assert.rejects(
            executeBufferWp1Probe({
                accessToken: ACCESS_TOKEN,
                environment: testCase.environment,
                organizationId: testCase.organizationId,
                schema: testCase.schema,
                schemaProfile: testCase.schemaProfile,
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

test('the WP1 CLI refuses conflicting or duplicate selector arguments before HTTP', async () => {
    const cases = [
        { argv: ['--schema', '--organization=organization-1'], code: 'ARGUMENT_COMBINATION_INVALID' },
        { argv: ['--schema', '--schema'], code: 'ARGUMENT_DUPLICATE' },
        {
            argv: ['--organization=organization-1', '--organization', 'organization-2'],
            code: 'ARGUMENT_DUPLICATE',
        },
    ];

    for (const testCase of cases) {
        let output = '';
        let requestCount = 0;
        const exitCode = await runBufferWp1ProbeCli({
            argv: testCase.argv,
            env: {
                APP_ENV: 'local',
                BUFFER_WP1_PROBE_ENABLED: 'true',
                BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
            },
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
    }
});

test('the WP1 CLI wires the schema flag to the fixed introspection query', async () => {
    let output = '';
    let requestBody = null;
    const exitCode = await runBufferWp1ProbeCli({
        argv: ['--schema'],
        env: {
            APP_ENV: 'local',
            BUFFER_WP1_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
        },
        fetchImpl: async (_url, options) => {
            requestBody = JSON.parse(options.body);

            return jsonResponse(schemaPayload());
        },
        stdout: (value) => {
            output += value;
        },
        stderr: (value) => {
            output += value;
        },
    });

    assert.equal(exitCode, 0);
    assert.equal(requestBody.query, BUFFER_WP1_SCHEMA_QUERY);
    assert.equal(JSON.parse(output).operation, 'schema');
    assert.equal(output.includes(ACCESS_TOKEN), false);
});

test('the WP1 CLI exits non-zero when the authenticated schema contract drifts', async () => {
    const payload = schemaPayload();
    payload.data.queuePosition.enumValues = payload.data.queuePosition.enumValues
        .filter((enumValue) => enumValue.name !== 'top');
    let output = '';
    const exitCode = await runBufferWp1ProbeCli({
        argv: ['--schema'],
        env: {
            APP_ENV: 'local',
            BUFFER_WP1_PROBE_ENABLED: 'true',
            BUFFER_WP1_PROBE_ACCESS_TOKEN: ACCESS_TOKEN,
        },
        fetchImpl: async () => jsonResponse(payload),
        stdout: (value) => {
            output += value;
        },
        stderr: (value) => {
            output += value;
        },
    });

    assert.equal(exitCode, 1);
    assert.equal(JSON.parse(output).classification, 'invalid_payload');
    assert.equal(output.includes(ACCESS_TOKEN), false);
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
