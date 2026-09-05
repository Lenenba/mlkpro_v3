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

export const BUFFER_WP1_SCHEMA_QUERY = `query PulseBufferSchemaProbe {
  queryRoot: __type(name: "Query") {
    name
    kind
    fields(includeDeprecated: true) {
      ...PulseBufferRootField
    }
  }
  mutationRoot: __type(name: "Mutation") {
    name
    kind
    fields(includeDeprecated: true) {
      ...PulseBufferRootField
    }
  }
  createPostInput: __type(name: "CreatePostInput") {
    ...PulseBufferContractType
  }
  editPostInput: __type(name: "EditPostInput") {
    ...PulseBufferContractType
  }
  deletePostInput: __type(name: "DeletePostInput") {
    ...PulseBufferContractType
  }
  movePostInQueueInput: __type(name: "MovePostInQueueInput") {
    ...PulseBufferContractType
  }
  postInputMetaData: __type(name: "PostInputMetaData") {
    ...PulseBufferContractType
  }
  facebookPostMetadataInput: __type(name: "FacebookPostMetadataInput") {
    ...PulseBufferContractType
  }
  post: __type(name: "Post") {
    ...PulseBufferContractType
  }
  postMetadata: __type(name: "PostMetadata") {
    ...PulseBufferContractType
  }
  facebookPostMetadata: __type(name: "FacebookPostMetadata") {
    ...PulseBufferContractType
  }
  postType: __type(name: "PostType") {
    ...PulseBufferContractType
  }
  postTypeFacebook: __type(name: "PostTypeFacebook") {
    ...PulseBufferContractType
  }
  postActionPayload: __type(name: "PostActionPayload") {
    ...PulseBufferContractType
  }
  deletePostPayload: __type(name: "DeletePostPayload") {
    ...PulseBufferContractType
  }
  movePostInQueuePayload: __type(name: "MovePostInQueuePayload") {
    ...PulseBufferContractType
  }
  shareMode: __type(name: "ShareMode") {
    ...PulseBufferContractType
  }
  schedulingType: __type(name: "SchedulingType") {
    ...PulseBufferContractType
  }
  postStatus: __type(name: "PostStatus") {
    ...PulseBufferContractType
  }
  postApprovalChange: __type(name: "PostApprovalChange") {
    ...PulseBufferContractType
  }
  queuePosition: __type(name: "QueuePosition") {
    ...PulseBufferContractType
  }
}

fragment PulseBufferRootField on __Field {
  name
  isDeprecated
  deprecationReason
  args {
    name
    defaultValue
    type {
      ...PulseBufferTypeRef
    }
  }
  type {
    ...PulseBufferTypeRef
  }
}

fragment PulseBufferContractType on __Type {
  name
  kind
  fields(includeDeprecated: true) {
    ...PulseBufferRootField
  }
  inputFields {
    name
    defaultValue
    type {
      ...PulseBufferTypeRef
    }
  }
  possibleTypes {
    kind
    name
  }
  enumValues(includeDeprecated: true) {
    name
    isDeprecated
    deprecationReason
  }
}

fragment PulseBufferTypeRef on __Type {
  kind
  name
  ofType {
    kind
    name
    ofType {
      kind
      name
      ofType {
        kind
        name
        ofType {
          kind
          name
        }
      }
    }
  }
}`;

const MAX_RESPONSE_BYTES = 1024 * 1024;
const ORGANIZATION_ID_PATTERN = /^[A-Za-z0-9_-]{1,128}$/u;
const GRAPHQL_NAME_PATTERN = /^[_A-Za-z][_0-9A-Za-z]*$/u;
const ALLOWED_PROBE_ENVIRONMENTS = new Set(['development', 'local', 'test', 'testing']);
const BUFFER_QUOTA_PERIODS = new Map([
    ['15min', 900],
    ['1day', 86400],
    ['30days', 2592000],
]);
const BUFFER_NAMED_TYPE_KINDS = new Set([
    'ENUM',
    'INPUT_OBJECT',
    'INTERFACE',
    'OBJECT',
    'SCALAR',
    'UNION',
]);
const BUFFER_INPUT_NAMED_TYPE_KINDS = new Set(['ENUM', 'INPUT_OBJECT', 'SCALAR']);
const BUFFER_OUTPUT_NAMED_TYPE_KINDS = new Set([
    'ENUM',
    'INTERFACE',
    'OBJECT',
    'SCALAR',
    'UNION',
]);
const BUFFER_NAMED_TYPE_KIND_REQUIREMENTS = new Map([
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
    ['PostMetadata', 'UNION'],
    ['PostType', 'ENUM'],
    ['PostsResults', 'OBJECT'],
    ['Boolean', 'SCALAR'],
    ['ChannelId', 'SCALAR'],
    ['DateTime', 'SCALAR'],
    ['DraftId', 'SCALAR'],
    ['IdeaId', 'SCALAR'],
    ['Int', 'SCALAR'],
    ['PostId', 'SCALAR'],
    ['String', 'SCALAR'],
    ['TagId', 'SCALAR'],
]);
const BUFFER_SCHEMA_REQUIREMENTS = {
    createPostInput: {
        inputDefaults: {
            assets: '[]',
            needsApproval: 'false',
        },
        kind: 'INPUT_OBJECT',
        name: 'CreatePostInput',
        inputFields: {
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
    },
    deletePostInput: {
        kind: 'INPUT_OBJECT',
        name: 'DeletePostInput',
        inputFields: { id: 'PostId!' },
    },
    deletePostPayload: {
        kind: 'UNION',
        name: 'DeletePostPayload',
        possibleTypes: ['DeletePostSuccess', 'VoidMutationError'],
    },
    editPostInput: {
        kind: 'INPUT_OBJECT',
        name: 'EditPostInput',
        inputFields: {
            aiAssisted: 'Boolean',
            approvalChange: 'PostApprovalChange',
            assets: '[AssetInput!]',
            draftId: 'DraftId',
            dueAt: 'DateTime',
            id: 'PostId!',
            ideaId: 'IdeaId',
            metadata: 'PostInputMetaData',
            mode: 'ShareMode',
            saveToDraft: 'Boolean',
            schedulingType: 'SchedulingType',
            source: 'String',
            tagIds: '[TagId!]',
            text: 'String',
        },
    },
    facebookPostMetadataInput: {
        kind: 'INPUT_OBJECT',
        name: 'FacebookPostMetadataInput',
        inputFields: {
            annotations: '[AnnotationInputFacebook!]',
            firstComment: 'String',
            linkAttachment: 'LinkAttachmentInput',
            type: 'PostTypeFacebook!',
        },
    },
    facebookPostMetadata: {
        kind: 'OBJECT',
        name: 'FacebookPostMetadata',
        outputFields: { type: 'PostType!' },
    },
    movePostInQueueInput: {
        kind: 'INPUT_OBJECT',
        name: 'MovePostInQueueInput',
        inputFields: { id: 'PostId!', position: 'QueuePosition!' },
    },
    movePostInQueuePayload: {
        kind: 'UNION',
        name: 'MovePostInQueuePayload',
        possibleTypes: ['PostActionSuccess', 'VoidMutationError'],
    },
    postActionPayload: {
        kind: 'UNION',
        name: 'PostActionPayload',
        possibleTypes: [
            'InvalidInputError',
            'LimitReachedError',
            'NotFoundError',
            'PostActionSuccess',
            'RestProxyError',
            'UnauthorizedError',
            'UnexpectedError',
        ],
    },
    postApprovalChange: {
        enumValues: ['request', 'revert'],
        kind: 'ENUM',
        name: 'PostApprovalChange',
    },
    postInputMetaData: {
        kind: 'INPUT_OBJECT',
        name: 'PostInputMetaData',
        inputFields: {
            bluesky: 'BlueskyPostMetadataInput',
            facebook: 'FacebookPostMetadataInput',
            google: 'GoogleBusinessPostMetadataInput',
            instagram: 'InstagramPostMetadataInput',
            linkedin: 'LinkedInPostMetadataInput',
            mastodon: 'MastodonPostMetadataInput',
            pinterest: 'PinterestPostMetadataInput',
            threads: 'ThreadsPostMetadataInput',
            tiktok: 'TikTokPostMetadataInput',
            twitter: 'TwitterPostMetadataInput',
            youtube: 'YoutubePostMetadataInput',
        },
    },
    post: {
        kind: 'OBJECT',
        name: 'Post',
        outputFields: { metadata: 'PostMetadata' },
    },
    postMetadata: {
        kind: 'UNION',
        name: 'PostMetadata',
        possibleTypes: ['FacebookPostMetadata'],
    },
    postType: {
        enumValues: ['post'],
        kind: 'ENUM',
        name: 'PostType',
    },
    postTypeFacebook: {
        enumValues: ['post', 'reel', 'story'],
        kind: 'ENUM',
        name: 'PostTypeFacebook',
    },
    queuePosition: {
        enumValues: ['bottom', 'top'],
        kind: 'ENUM',
        name: 'QueuePosition',
    },
    postStatus: {
        enumValues: ['draft', 'error', 'needs_approval', 'scheduled', 'sending', 'sent'],
        kind: 'ENUM',
        name: 'PostStatus',
    },
    schedulingType: {
        enumValues: ['automatic', 'notification'],
        kind: 'ENUM',
        name: 'SchedulingType',
    },
    shareMode: {
        enumValues: ['addToQueue', 'customScheduled', 'shareNext', 'shareNow'],
        kind: 'ENUM',
        name: 'ShareMode',
    },
};
const BUFFER_MUTATION_FIELD_REQUIREMENTS = [
    { arguments: { input: 'CreatePostInput!' }, name: 'createPost', output: 'PostActionPayload!' },
    { arguments: { input: 'DeletePostInput!' }, name: 'deletePost', output: 'DeletePostPayload!' },
    { arguments: { input: 'EditPostInput!' }, name: 'editPost', output: 'PostActionPayload!' },
    { arguments: { input: 'MovePostInQueueInput!' }, name: 'movePostInQueue', output: 'MovePostInQueuePayload!' },
];
const BUFFER_QUERY_FIELD_REQUIREMENTS = [
    { arguments: { input: 'PostInput!' }, name: 'post', output: 'Post!' },
    {
        arguments: { after: 'String', first: 'Int', input: 'PostsInput!' },
        name: 'posts',
        output: 'PostsResults!',
    },
];
const BUFFER_SCHEMA_PROFILES = {
    cleanup: {
        capabilities: {
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
        mutationFields: BUFFER_MUTATION_FIELD_REQUIREMENTS
            .filter((requirement) => requirement.name === 'deletePost'),
        queryFields: BUFFER_QUERY_FIELD_REQUIREMENTS
            .filter((requirement) => requirement.name === 'post'),
        requirements: {
            deletePostInput: BUFFER_SCHEMA_REQUIREMENTS.deletePostInput,
            deletePostPayload: BUFFER_SCHEMA_REQUIREMENTS.deletePostPayload,
            facebookPostMetadata: BUFFER_SCHEMA_REQUIREMENTS.facebookPostMetadata,
            post: BUFFER_SCHEMA_REQUIREMENTS.post,
            postMetadata: BUFFER_SCHEMA_REQUIREMENTS.postMetadata,
            postType: BUFFER_SCHEMA_REQUIREMENTS.postType,
        },
    },
    full: {
        capabilities: {
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
        mutationFields: BUFFER_MUTATION_FIELD_REQUIREMENTS,
        queryFields: BUFFER_QUERY_FIELD_REQUIREMENTS,
        requirements: BUFFER_SCHEMA_REQUIREMENTS,
    },
};
const BUFFER_SCHEMA_PROFILE_NAMES = new Set(Object.keys(BUFFER_SCHEMA_PROFILES));

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

function graphqlName(value) {
    const name = nullableString(value);

    return name !== null && GRAPHQL_NAME_PATTERN.test(name) ? name : null;
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

function normalizedSchemaProfile(value) {
    const profile = nullableString(value);

    if (profile === null || !BUFFER_SCHEMA_PROFILE_NAMES.has(profile)) {
        fail('SCHEMA_PROFILE_INVALID');
    }

    return profile;
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
                .update(redactSecretFromString(message, secret))
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

function optionalIntrospectionString(value) {
    if (value === null) {
        return null;
    }

    return typeof value === 'string' ? value : undefined;
}

function normalizeTypeReference(value, allowedNamedKinds, parentKind = null) {
    if (!isObject(value)) {
        return null;
    }

    const kind = nullableString(value.kind);
    if (kind === 'LIST' || kind === 'NON_NULL') {
        if (value.name !== null || (kind === 'NON_NULL' && parentKind === 'NON_NULL')) {
            return null;
        }

        const nestedType = normalizeTypeReference(value.ofType, allowedNamedKinds, kind);
        if (nestedType === null) {
            return null;
        }

        return kind === 'LIST' ? `[${nestedType}]` : `${nestedType}!`;
    }

    const name = graphqlName(value.name);
    if (!BUFFER_NAMED_TYPE_KINDS.has(kind)
        || !allowedNamedKinds.has(kind)
        || name === null
        || value.ofType !== null
        || (BUFFER_NAMED_TYPE_KIND_REQUIREMENTS.has(name)
            && BUFFER_NAMED_TYPE_KIND_REQUIREMENTS.get(name) !== kind)) {
        return null;
    }

    return name;
}

function normalizeInputValues(value) {
    if (!Array.isArray(value)) {
        return null;
    }

    const inputValues = value.filter(isObject).map((inputValue) => ({
        default_value: optionalIntrospectionString(inputValue.defaultValue),
        name: graphqlName(inputValue.name),
        type: normalizeTypeReference(inputValue.type, BUFFER_INPUT_NAMED_TYPE_KINDS),
    }));

    if (inputValues.length !== value.length
        || new Set(inputValues.map((inputValue) => inputValue.name)).size !== inputValues.length
        || inputValues.some((inputValue) => (
        inputValue.default_value === undefined
        || inputValue.name === null
        || inputValue.type === null
    ))) {
        return null;
    }

    return inputValues.sort((left, right) => left.name.localeCompare(right.name));
}

function normalizeOutputFields(value) {
    if (!Array.isArray(value)) {
        return null;
    }

    const fields = value.filter(isObject).map((field) => ({
        arguments: normalizeInputValues(field.args),
        deprecation_reason: optionalIntrospectionString(field.deprecationReason),
        is_deprecated: nullableBoolean(field.isDeprecated),
        name: graphqlName(field.name),
        type: normalizeTypeReference(field.type, BUFFER_OUTPUT_NAMED_TYPE_KINDS),
    }));

    if (fields.length !== value.length
        || new Set(fields.map((field) => field.name)).size !== fields.length
        || fields.some((field) => (
            field.arguments === null
            || field.deprecation_reason === undefined
            || field.is_deprecated === null
            || field.name === null
            || field.type === null
        ))) {
        return null;
    }

    return fields.sort((left, right) => left.name.localeCompare(right.name));
}

function normalizeRootType(value, expectedName) {
    if (!isObject(value)) {
        return null;
    }

    const fields = normalizeOutputFields(value.fields);

    if (graphqlName(value.name) !== expectedName
        || nullableString(value.kind) !== 'OBJECT'
        || fields === null) {
        return null;
    }

    return fields;
}

function normalizePossibleTypes(value) {
    if (!Array.isArray(value)) {
        return null;
    }

    const names = value.filter(isObject).map((possibleType) => (
        nullableString(possibleType.kind) === 'OBJECT'
            ? graphqlName(possibleType.name)
            : null
    ));

    return names.length === value.length
        && names.every((name) => name !== null)
        && new Set(names).size === names.length
        ? names.sort((left, right) => left.localeCompare(right))
        : null;
}

function normalizeEnumValues(value) {
    if (!Array.isArray(value)) {
        return null;
    }

    const enumValues = value.filter(isObject).map((enumValue) => ({
        deprecation_reason: optionalIntrospectionString(enumValue.deprecationReason),
        is_deprecated: nullableBoolean(enumValue.isDeprecated),
        name: graphqlName(enumValue.name),
    }));

    if (enumValues.length !== value.length
        || new Set(enumValues.map((enumValue) => enumValue.name)).size !== enumValues.length
        || enumValues.some((enumValue) => (
        enumValue.deprecation_reason === undefined
        || enumValue.is_deprecated === null
        || enumValue.name === null
    ))) {
        return null;
    }

    return enumValues.sort((left, right) => left.name.localeCompare(right.name));
}

function normalizeContractType(value) {
    if (!isObject(value)) {
        return null;
    }

    const normalized = {
        enum_values: null,
        input_fields: null,
        kind: nullableString(value.kind),
        name: graphqlName(value.name),
        output_fields: null,
        possible_types: null,
    };

    if (value.fields !== null) {
        normalized.output_fields = normalizeOutputFields(value.fields);
        if (normalized.output_fields === null) {
            return null;
        }
    }
    if (value.inputFields !== null) {
        normalized.input_fields = normalizeInputValues(value.inputFields);
        if (normalized.input_fields === null) {
            return null;
        }
    }
    if (value.possibleTypes !== null) {
        normalized.possible_types = normalizePossibleTypes(value.possibleTypes);
        if (normalized.possible_types === null) {
            return null;
        }
    }
    if (value.enumValues !== null) {
        normalized.enum_values = normalizeEnumValues(value.enumValues);
        if (normalized.enum_values === null) {
            return null;
        }
    }

    if (normalized.kind === null || normalized.name === null) {
        return null;
    }

    const hasCoherentShape = (
        normalized.kind === 'INPUT_OBJECT'
        && normalized.input_fields !== null
        && normalized.output_fields === null
        && normalized.possible_types === null
        && normalized.enum_values === null
    ) || (
        normalized.kind === 'UNION'
        && normalized.input_fields === null
        && normalized.output_fields === null
        && normalized.possible_types !== null
        && normalized.enum_values === null
    ) || (
        normalized.kind === 'ENUM'
        && normalized.input_fields === null
        && normalized.output_fields === null
        && normalized.possible_types === null
        && normalized.enum_values !== null
    ) || (
        normalized.kind === 'OBJECT'
        && normalized.input_fields === null
        && normalized.output_fields !== null
        && normalized.possible_types === null
        && normalized.enum_values === null
    );

    return hasCoherentShape ? normalized : null;
}

function includesEvery(values, requiredValues) {
    const valueSet = new Set(values);

    return requiredValues.every((requiredValue) => valueSet.has(requiredValue));
}

function isOmissibleInputValue(inputValue) {
    return !inputValue.type.endsWith('!') || inputValue.default_value !== null;
}

function hasCompatibleRootField(fields, requirement) {
    const field = fields.find((candidate) => candidate.name === requirement.name);
    if (field === undefined || field.type !== requirement.output || field.is_deprecated) {
        return false;
    }

    const argumentsByName = new Map(field.arguments.map((argument) => [argument.name, argument]));
    const hasExpectedArguments = Object.entries(requirement.arguments).every(([name, type]) => (
        argumentsByName.get(name)?.type === type
        && argumentsByName.get(name)?.default_value === null
    ));

    return hasExpectedArguments && field.arguments.every((argument) => (
        Object.hasOwn(requirement.arguments, argument.name) || isOmissibleInputValue(argument)
    ));
}

function hasCompatibleInputFields(inputFields, requiredFields, requiredDefaults = {}) {
    if (!Array.isArray(inputFields)) {
        return false;
    }

    const fieldsByName = new Map(inputFields.map((inputField) => [inputField.name, inputField]));
    const hasRequiredFields = Object.entries(requiredFields).every(([name, type]) => (
        fieldsByName.get(name)?.type === type
        && fieldsByName.get(name)?.default_value === (
            Object.hasOwn(requiredDefaults, name) ? requiredDefaults[name] : null
        )
    ));

    return hasRequiredFields && inputFields.every((inputField) => (
        Object.hasOwn(requiredFields, inputField.name) || isOmissibleInputValue(inputField)
    ));
}

function hasCompatibleOutputFields(outputFields, requiredFields) {
    if (!Array.isArray(outputFields)) {
        return false;
    }

    const fieldsByName = new Map(outputFields.map((outputField) => (
        [outputField.name, outputField]
    )));

    return Object.entries(requiredFields).every(([name, type]) => {
        const field = fieldsByName.get(name);

        return field?.type === type
            && field.is_deprecated === false
            && field.arguments.every(isOmissibleInputValue);
    });
}

function hasActiveEnumValues(enumValues, requiredValues) {
    if (!Array.isArray(enumValues)) {
        return false;
    }

    const valuesByName = new Map(enumValues.map((enumValue) => [enumValue.name, enumValue]));

    return requiredValues.every((requiredValue) => (
        valuesByName.get(requiredValue)?.is_deprecated === false
    ));
}

function normalizeSchemaContract(value, profileName) {
    if (!isObject(value)) {
        return null;
    }

    const profile = BUFFER_SCHEMA_PROFILES[profileName];
    const queryFields = normalizeRootType(value.queryRoot, 'Query');
    const mutationFields = normalizeRootType(value.mutationRoot, 'Mutation');
    if (queryFields === null
        || mutationFields === null
        || !profile.queryFields.every((requirement) => (
            hasCompatibleRootField(queryFields, requirement)
        ))
        || !profile.mutationFields.every((requirement) => (
            hasCompatibleRootField(mutationFields, requirement)
        ))) {
        return null;
    }

    const types = {};
    for (const [alias, requirement] of Object.entries(profile.requirements)) {
        const type = normalizeContractType(value[alias]);
        if (type === null || type.name !== requirement.name || type.kind !== requirement.kind) {
            return null;
        }
        if (requirement.inputFields !== undefined
            && !hasCompatibleInputFields(
                type.input_fields,
                requirement.inputFields,
                requirement.inputDefaults,
            )) {
            return null;
        }
        if (requirement.outputFields !== undefined
            && !hasCompatibleOutputFields(type.output_fields, requirement.outputFields)) {
            return null;
        }
        if (requirement.possibleTypes !== undefined && !includesEvery(
            type.possible_types ?? [],
            requirement.possibleTypes,
        )) {
            return null;
        }
        if (requirement.enumValues !== undefined
            && !hasActiveEnumValues(type.enum_values, requirement.enumValues)) {
            return null;
        }

        types[alias] = type;
    }

    return {
        capabilities: profile.capabilities,
        mutation_fields: mutationFields,
        profile: profileName,
        query_fields: queryFields,
        types,
    };
}

function classificationFor(status, graphqlErrors, validPayload, quota, requiresQuotaEvidence) {
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
    if (requiresQuotaEvidence && !hasCompleteQuotaEvidence(quota)) {
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

function safeResultEnvelope(secret, values) {
    return redactSecret(resultEnvelope(values), secret);
}

export async function executeBufferWp1Probe({
    accessToken,
    environment,
    organizationId = null,
    schema = false,
    schemaProfile = 'full',
    timeoutMs = 10000,
    fetchImpl = globalThis.fetch,
} = {}) {
    validatedProbeEnvironments([environment]);
    const token = requiredAccessToken(accessToken);
    const normalizedOrganization = normalizedOrganizationId(organizationId);
    const normalizedProfile = normalizedSchemaProfile(schemaProfile);
    const normalizedRequestTimeout = normalizedTimeout(timeoutMs);

    if (typeof schema !== 'boolean') {
        fail('SCHEMA_FLAG_INVALID');
    }
    if (schema && normalizedOrganization !== null) {
        fail('ARGUMENT_COMBINATION_INVALID');
    }
    if (!schema && normalizedProfile !== 'full') {
        fail('ARGUMENT_COMBINATION_INVALID');
    }

    if (typeof fetchImpl !== 'function') {
        fail('FETCH_IMPLEMENTATION_REQUIRED');
    }

    const operation = schema ? 'schema' : normalizedOrganization === null ? 'account' : 'channels';
    const query = operation === 'schema'
        ? BUFFER_WP1_SCHEMA_QUERY
        : operation === 'account' ? BUFFER_WP1_ACCOUNT_QUERY : BUFFER_WP1_CHANNELS_QUERY;
    const variables = operation === 'channels'
        ? { input: { organizationId: normalizedOrganization } }
        : {};

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
        : operation === 'channels'
            ? { channels: normalizeChannels(payload?.data?.channels) }
            : { schema_contract: normalizeSchemaContract(payload?.data, normalizedProfile) };
    const validData = operation === 'account'
        ? data.account !== null
        : operation === 'channels' ? data.channels !== null : data.schema_contract !== null;
    const validPayload = normalizedGraphqlErrors !== null && validData;
    const classification = classificationFor(
        response.status,
        graphqlErrors,
        validPayload,
        quotaEvidence(response),
        operation !== 'schema',
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
    let organizationOptionSeen = false;
    let schema = false;

    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];

        if (argument === '--help') {
            return { help: true, organizationId: null, schema: false };
        }
        if (argument === '--schema') {
            if (schema) {
                fail('ARGUMENT_DUPLICATE');
            }

            schema = true;
            continue;
        }
        if (argument === '--organization') {
            if (organizationOptionSeen) {
                fail('ARGUMENT_DUPLICATE');
            }

            organizationOptionSeen = true;
            const value = argv[index + 1];
            if (value === undefined || value.startsWith('--')) {
                fail('ORGANIZATION_ID_REQUIRED');
            }

            organizationId = normalizedOrganizationId(value);
            index += 1;
            continue;
        }
        if (argument.startsWith('--organization=')) {
            if (organizationOptionSeen) {
                fail('ARGUMENT_DUPLICATE');
            }

            organizationOptionSeen = true;
            const value = argument.slice('--organization='.length);
            if (value === '') {
                fail('ORGANIZATION_ID_REQUIRED');
            }

            organizationId = normalizedOrganizationId(value);
            continue;
        }

        fail('ARGUMENT_INVALID');
    }

    if (schema && organizationId !== null) {
        fail('ARGUMENT_COMBINATION_INVALID');
    }

    return {
        help: false,
        organizationId,
        schema,
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
            stdout('Usage: npm run pulse:buffer:probe -- [--schema | --organization=<Buffer organization ID>]\n');
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
            schema: arguments_.schema,
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
