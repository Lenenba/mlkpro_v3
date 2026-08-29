import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    needsSocialDeliveryVerification,
    socialStatusAxes,
    socialStatusToneClass,
} from '../../resources/js/utils/socialStatusAxes.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const dropzone = read('resources/js/Components/DropzoneInput.vue');
const composer = read('resources/js/Pages/Social/Components/SocialPostComposer.vue');
const history = read('resources/js/Pages/Social/Components/SocialPostHistory.vue');
const calendar = read('resources/js/Pages/Social/Components/SocialEditorialCalendar.vue');
const accountManager = read('resources/js/Pages/Social/Components/SocialAccountManager.vue');
const statusAxesSource = read('resources/js/utils/socialStatusAxes.js');

test('the shared dropzone seals every media mutation while disabled', () => {
    assert.match(dropzone, /disabled:\s*Boolean,/u);
    assert.match(dropzone, /const processSelectedFile = async[\s\S]*?if \(props\.disabled \|\| !selectedFile\)/u);
    assert.match(dropzone, /await resizeImageFile\([\s\S]*?if \(props\.disabled\)/u);

    for (const handler of ['triggerFileInput', 'removeFile', 'handleDragOver']) {
        assert.match(
            dropzone,
            new RegExp(`const ${handler} =[\\s\\S]*?if \\(props\\.disabled\\)`),
            handler,
        );
    }

    assert.match(
        dropzone,
        /const handleFileChange =[\s\S]*?processSelectedFile\(event\.target\.files\[0\], event\.target\)/u,
    );
    assert.match(
        dropzone,
        /const handleDrop =[\s\S]*?processSelectedFile\(droppedFile\)/u,
    );

    const fileInput = dropzone.slice(dropzone.lastIndexOf('<input'), dropzone.lastIndexOf('/>') + 2);

    assert.match(fileInput, /:disabled="disabled"/u);
    assert.match(fileInput, /:aria-disabled="disabled"/u);
    assert.ok((dropzone.match(/:disabled="disabled"/gu) || []).length >= 3);
    assert.ok((dropzone.match(/:aria-disabled="disabled"/gu) || []).length >= 2);
    assert.match(dropzone, /disabled \? 'cursor-not-allowed opacity-60' : 'cursor-pointer'/u);
});

test('the pulse composer keeps queued publication content and media locked', () => {
    assert.match(
        composer,
        /typeof draftSnapshot\.value\?\.is_queued_publication === 'boolean'[\s\S]*?metadata\?\.publish_requested_at/u,
    );
    assert.match(
        composer,
        /const isEditDisabled = computed\(\(\) => \([\s\S]*?isApprovalLocked\.value[\s\S]*?isQueuedPublication\.value/u,
    );
    assert.match(composer, /draftSnapshot\.value\.is_editable === false/u);
    assert.match(composer, /const imageInputModel = computed\(\{[\s\S]*?if \(isEditDisabled\.value\)/u);
    assert.match(
        composer,
        /<DropzoneInput[\s\S]*?v-model="imageInputModel"[\s\S]*?:disabled="isEditDisabled"/u,
    );

    for (const handler of [
        'toggleTarget',
        'applyCaptionSuggestion',
        'appendHashtagsToText',
        'applyCtaSuggestion',
        'addCustomHashtag',
        'removeHashtag',
    ]) {
        assert.match(
            composer,
            new RegExp(`const ${handler} = \\(.*?\\) => \\{\\s*if \\(isEditDisabled\\.value\\)`),
            handler,
        );
    }

    assert.match(
        composer,
        /const saveDraft = async \(\{ quiet = false \} = \{\}\) => \{\s*if \(isEditDisabled\.value\)/u,
    );
    assert.match(
        composer,
        /const submitApprovalRequest = async \(\) => \{\s*if \(!canSubmitForApproval\.value \|\| isEditDisabled\.value\)/u,
    );
    assert.match(
        composer,
        /const clearSourceReference = \(\) => \{\s*if \(isEditDisabled\.value\)/u,
    );
    assert.match(
        composer,
        /const saveAsTemplate = async \(\) => \{\s*if \(!canManage\.value \|\| isEditDisabled\.value\)/u,
    );
    assert.match(composer, /:disabled="isEditDisabled" @click="submit"/u);
    assert.match(composer, /:disabled="isLoading \|\| isEditDisabled"\s+@click="submitApprovalRequest"/u);
    assert.match(composer, /:disabled="isEditDisabled"\s+@click="applyCaptionSuggestion\(caption\)"/u);
    assert.match(composer, /:disabled="isEditDisabled \|\| !suggestions\.hashtags\.length"\s+@click="appendHashtagsToText"/u);
    assert.match(composer, /:disabled="isEditDisabled"\s+@click="applyCtaSuggestion\(cta\)"/u);
    assert.match(composer, /:disabled="isEditDisabled"\s+@click="toggleTarget\(account\.id\)"/u);
    assert.match(composer, /:disabled="isEditDisabled" @click="clearSourceReference"/u);
    assert.match(composer, /:disabled="isEditDisabled" @click="saveAsTemplate"/u);
});

test('the pulse composer exposes Buffer media controls and serializes media assets', () => {
    for (const field of [
        'buffer_media',
        'media_help',
        'media_type',
        'media_url',
        'media_alt_text',
        'media_title',
        'media_thumbnail_url',
        'media_thumbnail_offset',
    ]) {
        assert.match(
            composer,
            new RegExp(`social\\.composer_manager\\.fields\\.${field}`),
            field,
        );
    }

    for (const action of ['add_media', 'remove_media']) {
        assert.match(
            composer,
            new RegExp(`social\\.composer_manager\\.actions\\.${action}`),
            action,
        );
    }

    for (const type of ['image', 'video', 'document']) {
        assert.match(composer, new RegExp(`['"]${type}['"]`), type);
    }

    assert.match(
        composer,
        /const appendMediaAssets = \(formData, assets\) => \{[\s\S]*?formData\.append\('media_assets'/u,
    );
    assert.match(composer, /appendMediaAssets\(formData, form(?:\.value)?\.media_assets\)/u);
    assert.match(
        composer,
        /const usesPrimaryImageField = firstAsset\?\.type === 'image'[\s\S]*?media_assets: assets\.slice\(1\)/u,
    );
    assert.match(composer, /image_url: assets\.length > 0 \? '' : primaryUrl/u);
    assert.match(
        composer,
        /const bufferMediaLimitReached = computed[\s\S]*?form\.value\.media_assets\.length[\s\S]*?>= 20/u,
    );
    assert.match(composer, /:disabled="isEditDisabled \|\| bufferMediaLimitReached"/u);
});

test('Buffer media copy exists in every locale and states the public document requirements', () => {
    const requiredFields = [
        'buffer_media',
        'media_help',
        'media_type',
        'media_url',
        'media_alt_text',
        'media_title',
        'media_thumbnail_url',
        'media_thumbnail_offset',
    ];
    const helpPatterns = {
        fr: /^(?=.*https)(?=.*publique)(?=.*document)(?=.*titre)(?=.*miniature)/iu,
        en: /^(?=.*https)(?=.*public)(?=.*document)(?=.*title)(?=.*thumbnail)/iu,
        es: /^(?=.*https)(?=.*pública)(?=.*documento)(?=.*título)(?=.*miniatura)/iu,
    };

    for (const locale of ['fr', 'en', 'es']) {
        const composerCopy = readJson(`resources/js/i18n/modules/${locale}/social.json`)
            .social.composer_manager;

        for (const field of requiredFields) {
            assert.equal(typeof composerCopy.fields[field], 'string', `${locale}:fields:${field}`);
            assert.notEqual(composerCopy.fields[field].trim(), '', `${locale}:fields:${field}`);
        }

        for (const action of ['add_media', 'remove_media']) {
            assert.equal(typeof composerCopy.actions[action], 'string', `${locale}:actions:${action}`);
            assert.notEqual(composerCopy.actions[action].trim(), '', `${locale}:actions:${action}`);
        }

        for (const type of ['image', 'video', 'document']) {
            assert.equal(typeof composerCopy.media_types[type], 'string', `${locale}:media_types:${type}`);
            assert.notEqual(composerCopy.media_types[type].trim(), '', `${locale}:media_types:${type}`);
        }

        assert.match(composerCopy.fields.media_help, helpPatterns[locale], locale);
    }
});

test('the pulse account manager releases oauth recovery only after the callback claim expires', () => {
    const claimGuards = accountManager.match(
        /:disabled="busy \|\| isLoading \|\| selectedConnection\.oauth_callback_active"/gu,
    ) || [];

    assert.equal(claimGuards.length, 2);
    assert.doesNotMatch(
        accountManager,
        /:disabled="busy \|\| isLoading \|\| selectedConnection\.status === 'authorizing'"/u,
    );
});

test('the pulse history exposes each target status and failure without reopening queued posts', () => {
    assert.match(
        history,
        /const canEditPost = \(post\) => \{[\s\S]*?typeof post\?\.is_editable === 'boolean'[\s\S]*?return post\.is_editable/u,
    );
    assert.match(history, /\['draft', 'scheduled'\][\s\S]*?!post\?\.metadata\?\.publish_requested_at/u);
    assert.match(history, /v-if="canManage && canEditPost\(post\)"/u);
    assert.match(history, /targetStatusLabel\(target\.status\)/u);
    assert.match(history, /v-if="target\.failure_reason"/u);
    assert.match(history, /status === 'failed' \|\| status === 'canceled'/u);
    assert.match(history, /target_failure_reason[\s\S]*?reason: target\.failure_reason/u);
});

test('pulse target statuses and failure copy exist in every locale', () => {
    const targetStatuses = ['pending', 'scheduled', 'publishing', 'published', 'failed', 'canceled'];

    for (const locale of ['fr', 'en', 'es']) {
        const social = readJson(`resources/js/i18n/modules/${locale}/social.json`);

        for (const status of targetStatuses) {
            const label = social.social.history_manager.target_statuses[status];

            assert.equal(typeof label, 'string', `${locale}:${status}`);
            assert.notEqual(label.trim(), '', `${locale}:${status}`);
        }

        assert.equal(
            typeof social.social.accounts_manager.statuses.authorizing,
            'string',
            `${locale}:authorizing`,
        );
        assert.notEqual(
            social.social.accounts_manager.statuses.authorizing.trim(),
            '',
            `${locale}:authorizing`,
        );

        assert.match(social.social.history_manager.target_failure_reason, /\{reason\}/u, locale);
    }
});

test('provider-neutral delivery axes only expose fields supplied by the server', () => {
    assert.deepEqual(socialStatusAxes({ status: 'published' }), []);
    assert.deepEqual(
        socialStatusAxes({
            editorial_status: 'approved',
            delivery_status: 'published',
            sync_status: 'synced',
        }),
        [
            { key: 'editorial', value: 'approved' },
            { key: 'delivery', value: 'published' },
            { key: 'sync', value: 'synced' },
        ],
    );
    assert.deepEqual(
        socialStatusAxes({ editorial_status: 'approved', delivery_status: 'queued' }, { includeEditorial: false }),
        [{ key: 'delivery', value: 'queued' }],
    );
    assert.doesNotMatch(statusAxesSource, /buffer/iu);
});

test('unknown delivery requires verification without inferring a retry action', () => {
    assert.equal(needsSocialDeliveryVerification({ delivery_status: 'unknown' }), true);
    assert.equal(needsSocialDeliveryVerification({
        delivery_status: 'published',
        targets: [{ delivery_status: 'unknown' }],
    }), true);
    assert.equal(needsSocialDeliveryVerification({
        delivery_status: 'published',
        targets: [{ delivery_status: 'published' }],
    }), false);
    assert.match(socialStatusToneClass('unknown'), /amber/u);
});

test('history composer and calendar consume delivery axes conditionally and announce state changes', () => {
    for (const [name, component] of Object.entries({ history, composer, calendar })) {
        assert.match(component, /from '@\/utils\/socialStatusAxes'/u, name);
        assert.match(component, /social\.delivery_axes\.verification\.description/u, name);
        assert.match(component, /aria-live="assertive"/u, name);
        assert.match(component, /aria-live="polite"/u, name);
        assert.match(component, /:aria-busy=/u, name);
    }

    assert.match(history, /v-if="statusAxesFor\(post\)\.length"/u);
    assert.match(history, /v-if="statusAxesFor\(target, false\)\.length"/u);
    assert.match(composer, /v-if="activeStatusAxes\.length"/u);
    assert.match(composer, /:aria-pressed="form\.target_connection_ids\.includes/u);
    assert.match(calendar, /v-if="activeStatusAxes\.length"/u);
    assert.match(calendar, /:aria-pressed="viewMode === 'week'"/u);
    assert.match(calendar, /:aria-pressed="day\.key === selectedDayKey"/u);
});

test('delivery axis labels and the do-not-republish warning exist in every locale', () => {
    const expectedStatuses = {
        editorial: ['draft', 'pending_approval', 'approved', 'rejected', 'archived'],
        delivery: [
            'not_submitted',
            'queued',
            'submitted',
            'scheduled',
            'remote_approval_required',
            'publishing',
            'sending',
            'published',
            'partial_failed',
            'failed',
            'unknown',
            'canceled',
        ],
        sync: ['pending', 'synced', 'error', 'reconnect_required'],
    };

    for (const locale of ['fr', 'en', 'es']) {
        const axes = readJson(`resources/js/i18n/modules/${locale}/social.json`).social.delivery_axes;

        for (const axis of Object.keys(expectedStatuses)) {
            assert.equal(typeof axes.labels[axis], 'string', `${locale}:${axis}:label`);

            for (const status of expectedStatuses[axis]) {
                assert.equal(typeof axes.statuses[axis][status], 'string', `${locale}:${axis}:${status}`);
                assert.notEqual(axes.statuses[axis][status].trim(), '', `${locale}:${axis}:${status}`);
            }
        }

        assert.equal(typeof axes.verification.title, 'string', `${locale}:verification:title`);
        assert.equal(typeof axes.verification.description, 'string', `${locale}:verification:description`);
        assert.notEqual(axes.verification.description.trim(), '', `${locale}:verification:description`);
    }

    const frenchWarning = readJson('resources/js/i18n/modules/fr/social.json')
        .social.delivery_axes.verification;
    const englishWarning = readJson('resources/js/i18n/modules/en/social.json')
        .social.delivery_axes.verification;

    assert.match(`${frenchWarning.title} ${frenchWarning.description}`, /verification requise.*ne republiez pas/iu);
    assert.match(`${englishWarning.title} ${englishWarning.description}`, /verification required.*do not publish again/iu);
});
