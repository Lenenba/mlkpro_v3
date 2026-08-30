import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    needsSocialDeliveryVerification,
    socialStatusAxes,
    socialStatusToneClass,
} from '../../resources/js/utils/socialStatusAxes.js';
import {
    MEDIA_LIMITS,
    prepareMediaFile,
    resolveMediaType,
    takeFilesWithinTotalBytes,
} from '../../resources/js/utils/media.js';
import {
    normalizeSocialMediaAsset,
    normalizeSocialMediaState,
    serializeSocialMediaAsset,
    SOCIAL_MEDIA_EXTENSIONS,
    SOCIAL_MEDIA_MAX_ITEMS,
} from '../../resources/js/utils/socialMediaAssets.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const dropzone = read('resources/js/Components/DropzoneInput.vue');
const composer = read('resources/js/Pages/Social/Components/SocialPostComposer.vue');
const templateManager = read('resources/js/Pages/Social/Components/SocialTemplateManager.vue');
const history = read('resources/js/Pages/Social/Components/SocialPostHistory.vue');
const calendar = read('resources/js/Pages/Social/Components/SocialEditorialCalendar.vue');
const accountManager = read('resources/js/Pages/Social/Components/SocialAccountManager.vue');
const statusAxesSource = read('resources/js/utils/socialStatusAxes.js');
const mediaUtilsSource = read('resources/js/utils/media.js');
const socialMediaAssetsSource = read('resources/js/utils/socialMediaAssets.js');
const socialPostController = read('app/Http/Controllers/SocialPostController.php');
const socialMediaAssetService = read('app/Services/Social/SocialMediaAssetService.php');

test('the shared dropzone seals every media mutation while disabled', () => {
    assert.match(dropzone, /disabled:\s*Boolean,/u);
    assert.match(dropzone, /const processSelectedFiles = async[\s\S]*?if \(props\.disabled \|\| candidates\.length === 0\)/u);
    assert.match(dropzone, /await prepareMediaFile\([\s\S]*?if \(props\.disabled\)/u);

    for (const handler of [
        'triggerFileInput',
        'removeFile',
        'removeSelectedFile',
        'removeExistingItem',
        'handleDragOver',
    ]) {
        assert.match(
            dropzone,
            new RegExp(`const ${handler} =[\\s\\S]*?if \\(props\\.disabled\\)`),
            handler,
        );
    }

    assert.match(
        dropzone,
        /const handleFileChange =[\s\S]*?processSelectedFiles\(event\.target\.files, event\.target\)/u,
    );
    assert.match(
        dropzone,
        /const handleDrop =[\s\S]*?processSelectedFiles\(event\.dataTransfer\?\.files\)/u,
    );
    assert.match(
        dropzone,
        /const latestSelectedFiles = selectedFiles\.value;[\s\S]*?const remainingSlots = Math\.max\([\s\S]*?const filesWithinSlotLimit = uniqueFiles\.slice\(0, remainingSlots\);[\s\S]*?takeFilesWithinTotalBytes\([\s\S]*?file\.value = \[\.\.\.latestSelectedFiles, \.\.\.acceptedFiles\]/u,
        'the final append must re-check capacity after asynchronous processing',
    );

    const fileInput = dropzone.slice(dropzone.lastIndexOf('<input'), dropzone.lastIndexOf('/>') + 2);

    assert.match(fileInput, /:disabled="disabled \|\| \(multiple && !canAddFiles\)"/u);
    assert.match(fileInput, /:aria-disabled="disabled \|\| \(multiple && !canAddFiles\)"/u);
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
        /<DropzoneInput[\s\S]*?v-model="mediaFiles"[\s\S]*?:disabled="isEditDisabled"/u,
    );
    assert.match(
        composer,
        /<SocialMediaAssetPicker[\s\S]*?v-model="imageInputModel"[\s\S]*?:disabled="isEditDisabled"/u,
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

test('the pulse composer exposes one generic multi-media uploader and honors its upload contract', async () => {
    assert.match(composer, /const mediaFiles = ref\(\[\]\)/u);
    assert.match(composer, /const mediaExtensions = SOCIAL_MEDIA_EXTENSIONS/u);
    assert.equal(SOCIAL_MEDIA_MAX_ITEMS, 20);
    assert.deepEqual([...SOCIAL_MEDIA_EXTENSIONS], [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm', 'pdf',
    ]);
    assert.match(
        composer,
        /<DropzoneInput[\s\S]*?v-model="mediaFiles"[\s\S]*?mode="media"[\s\S]*?multiple[\s\S]*?:max-files="SOCIAL_MEDIA_MAX_ITEMS"[\s\S]*?:existing-items="existingMediaItems"[\s\S]*?@remove-existing="removeExistingMedia"/u,
    );
    assert.match(composer, /const existingMediaItems = computed/u);
    assert.match(composer, /const clearMediaFiles = \(\) => \{[\s\S]*?mediaFiles\.value = \[\]/u);
    assert.doesNotMatch(composer, /social\.composer_manager\.fields\.buffer_media/u);
    assert.doesNotMatch(composer, /v-model="asset\.(?:type|url|alt_text|title|thumbnail_url|thumbnail_offset)"/u);

    assert.match(
        composer,
        /const appendMediaAssets = \(formData, assets\) => \{[\s\S]*?serializeSocialMediaAssets\(assets\)[\s\S]*?formData\.append\('media_assets'/u,
    );
    assert.match(composer, /appendMediaAssets\(formData, form(?:\.value)?\.media_assets\)/u);
    assert.equal(
        (composer.match(/formData\.append\('media_files\[\]', mediaFile\)/gu) || []).length,
        2,
        'draft and template payloads both attach every selected file',
    );
    assert.match(socialMediaAssetsSource, /const usesPrimaryImageField = firstAsset\?\.type === 'image'[\s\S]*?firstAsset\.source !== 'upload'[\s\S]*?media_assets: assets\.slice\(1\)/u);
    assert.match(socialMediaAssetsSource, /image_url: assets\.length > 0 \? '' : primaryUrl/u);
    assert.match(composer, /const mediaLimitReached = computed[\s\S]*?SOCIAL_MEDIA_MAX_ITEMS/u);
    assert.match(
        composer,
        /<SocialMediaAssetPicker[\s\S]*?:disabled="isEditDisabled \|\| mediaLimitReached"/u,
    );

    assert.match(dropzone, /maxVideoBytes: MEDIA_LIMITS\.maxVideoBytes/u);
    assert.match(dropzone, /maxDocumentBytes: MEDIA_LIMITS\.maxDocumentBytes/u);
    assert.doesNotMatch(dropzone, /Number\.POSITIVE_INFINITY/u);
    assert.equal(MEDIA_LIMITS.maxVideoBytes, 24_000_000);
    assert.equal(MEDIA_LIMITS.maxDocumentBytes, 24_000_000);
    assert.equal(MEDIA_LIMITS.maxTotalMediaBytes, 100 * 1024 * 1024);
    assert.match(mediaUtilsSource, /const DEFAULT_MAX_VIDEO_BYTES = 24000000/u);
    assert.match(mediaUtilsSource, /const DEFAULT_MAX_DOCUMENT_BYTES = 24000000/u);
    assert.match(mediaUtilsSource, /const DEFAULT_MAX_TOTAL_MEDIA_BYTES = 104857600/u);
    assert.match(
        dropzone,
        /const selectedFileBytes = computed[\s\S]*?selectedFiles\.value\.reduce[\s\S]*?hasReachedTotalMediaLimit[\s\S]*?MEDIA_LIMITS\.maxTotalMediaBytes/u,
        'only newly selected files count toward the aggregate upload budget',
    );
    assert.match(
        dropzone,
        /const latestSelectedBytes = latestSelectedFiles\.reduce[\s\S]*?takeFilesWithinTotalBytes\([\s\S]*?filesWithinSlotLimit,[\s\S]*?MEDIA_LIMITS\.maxTotalMediaBytes,[\s\S]*?latestSelectedBytes/u,
        'the aggregate budget is recalculated after asynchronous processing',
    );

    const mebibyte = 1024 * 1024;
    const aggregateSelection = takeFilesWithinTotalBytes([
        { name: 'fits-exactly.pdf', size: 20 * mebibyte },
        { name: 'over-budget.jpg', size: 1 },
    ], MEDIA_LIMITS.maxTotalMediaBytes, 80 * mebibyte);
    assert.deepEqual(
        aggregateSelection.acceptedFiles.map((file) => file.name),
        ['fits-exactly.pdf'],
    );
    assert.deepEqual(
        aggregateSelection.rejectedFiles.map((file) => file.name),
        ['over-budget.jpg'],
    );
    assert.equal(aggregateSelection.totalBytes, MEDIA_LIMITS.maxTotalMediaBytes);

    assert.equal(resolveMediaType({ name: 'cover.webp', type: 'image/webp' }), 'image');
    assert.equal(resolveMediaType({ name: 'reel.mov', type: 'video/quicktime' }), 'video');
    assert.equal(resolveMediaType({ name: 'guide.pdf', type: 'application/pdf' }), 'document');
    assert.equal(resolveMediaType({ name: 'payload.exe', type: 'application/octet-stream' }), null);
    assert.match(dropzone, /extension === 'svg'[\s\S]*?dropzone\.errors\.svg_not_allowed/u);

    const oversizedVideo = await prepareMediaFile({
        name: 'reel.mp4',
        type: 'video/mp4',
        size: MEDIA_LIMITS.maxVideoBytes + 1,
    });
    const oversizedDocument = await prepareMediaFile({
        name: 'guide.pdf',
        type: 'application/pdf',
        size: MEDIA_LIMITS.maxDocumentBytes + 1,
    });
    assert.equal(oversizedVideo.file, null);
    assert.match(oversizedVideo.error, /^Video too large/u);
    assert.equal(oversizedDocument.file, null);
    assert.match(oversizedDocument.error, /^Document too large/u);

    assert.match(socialPostController, /'media_files' => \[[\s\S]*?'max:'\.self::MAX_MEDIA_ITEMS/u);
    assert.match(socialPostController, /'media_files\.\*' => \[[\s\S]*?'mimes:'\.self::MEDIA_UPLOAD_EXTENSIONS[\s\S]*?'max:'\.self::MAX_MEDIA_UPLOAD_KILOBYTES/u);
    assert.match(socialPostController, /\$request->file\('media_files', \[\]\)/u);
    assert.match(socialPostController, /persistWithStoredMediaUploads\(/u);
    assert.match(socialPostController, /prepareClientMediaAssets\([\s\S]*?\$validated\['media_uploads'\] = \[[\s\S]*?\.\.\.\$newMediaUploads/u);
    for (const metadataKey of ['source', 'disk', 'path', 'name', 'mime_type', 'size']) {
        assert.match(socialPostController, new RegExp(`'media_assets\\.\\*\\.${metadataKey}'`), metadataKey);
    }
    assert.match(socialMediaAssetService, /foreach \(\(array\) \(\$payload\['media_uploads'\] \?\? \[\]\) as \$asset\)/u);
    for (const mimeType of ['image/jpeg', 'video/mp4', 'video/quicktime', 'video/webm', 'application/pdf']) {
        assert.match(socialMediaAssetService, new RegExp(`'${mimeType.replace('/', '\\/')}'`), mimeType);
    }
});

test('server-upload metadata survives normalization and serialization while manual URLs stay minimal', () => {
    const serverUpload = {
        type: 'video',
        url: 'https://malikia.test/storage/social/posts/1/reel.mp4',
        source: 'upload',
        disk: 'public',
        path: 'social/posts/1/reel.mp4',
        name: 'reel.mp4',
        mime_type: 'video/mp4',
        size: 1_234_567,
        title: 'Launch reel',
    };
    const normalized = normalizeSocialMediaAsset(serverUpload);
    const serialized = serializeSocialMediaAsset(normalized);

    for (const key of ['source', 'disk', 'path', 'name', 'mime_type', 'size']) {
        assert.equal(normalized[key], serverUpload[key], `normalized:${key}`);
        assert.equal(serialized[key], serverUpload[key], `serialized:${key}`);
    }

    const uploadedImage = {
        ...serverUpload,
        type: 'image',
        url: 'https://malikia.test/storage/social/posts/1/cover.jpg',
        path: 'social/posts/1/cover.jpg',
        name: 'cover.jpg',
        mime_type: 'image/jpeg',
        title: '',
    };
    const imageState = normalizeSocialMediaState([uploadedImage], uploadedImage.url);
    assert.equal(imageState.image_url, '');
    assert.equal(imageState.media_assets.length, 1);
    assert.equal(imageState.media_assets[0].path, uploadedImage.path);

    const manualUrl = serializeSocialMediaAsset({
        type: 'image',
        url: 'https://cdn.example.test/cover.jpg',
        source: 'url',
        disk: 'untrusted',
        path: 'outside/tenant.jpg',
        name: 'injected.jpg',
        mime_type: 'image/jpeg',
        size: 999,
    });
    assert.deepEqual(Object.keys(manualUrl).sort(), [
        'alt_text',
        'thumbnail_offset',
        'thumbnail_url',
        'title',
        'type',
        'url',
    ]);
});

test('the template manager keeps and uploads the complete multi-media collection', () => {
    assert.match(templateManager, /const mediaFiles = ref\(\[\]\)/u);
    assert.match(templateManager, /media_assets: mediaState\.media_assets/u);
    assert.match(templateManager, /media_assets: serializeSocialMediaAssets\(form\.value\.media_assets\)/u);
    assert.match(templateManager, /formData\.append\('media_files\[\]', mediaFile\)/u);
    assert.match(templateManager, /appendMediaAssets\(formData, form\.value\.media_assets\)/u);
    assert.doesNotMatch(templateManager, /appendFormDataValue\(formData, 'image_file'/u);
    assert.match(
        templateManager,
        /<DropzoneInput[\s\S]*?v-model="mediaFiles"[\s\S]*?mode="media"[\s\S]*?multiple[\s\S]*?:max-files="SOCIAL_MEDIA_MAX_ITEMS"[\s\S]*?:existing-items="existingMediaItems"[\s\S]*?@remove-existing="removeExistingMedia"/u,
    );
    assert.match(templateManager, /const mediaLimitReached = computed[\s\S]*?SOCIAL_MEDIA_MAX_ITEMS/u);
    assert.match(
        templateManager,
        /<SocialMediaAssetPicker[\s\S]*?:disabled="!canManage \|\| busy \|\| mediaLimitReached"/u,
    );
});

test('generic media copy exists in every locale without provider branding', () => {
    const helpPatterns = {
        fr: /^(?=.*image)(?=.*vidéo)(?=.*pdf)(?=.*détect)/iu,
        en: /^(?=.*image)(?=.*video)(?=.*pdf)(?=.*detect)/iu,
        es: /^(?=.*im[aá]gen)(?=.*vídeo)(?=.*pdf)(?=.*detect)/iu,
    };
    const removedFields = [
        'buffer_media',
        'media_type',
        'media_url',
        'media_alt_text',
        'media_title',
        'media_thumbnail_url',
        'media_thumbnail_offset',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const composerCopy = readJson(`resources/js/i18n/modules/${locale}/social.json`)
            .social.composer_manager;
        const dropzoneCopy = readJson(`resources/js/i18n/modules/${locale}/shared_ui.json`).dropzone;

        for (const field of ['media', 'media_help']) {
            assert.equal(typeof composerCopy.fields[field], 'string', `${locale}:fields:${field}`);
            assert.notEqual(composerCopy.fields[field].trim(), '', `${locale}:fields:${field}`);
        }
        const templateCopy = readJson(`resources/js/i18n/modules/${locale}/social.json`)
            .social.template_manager;
        for (const field of ['media', 'media_help']) {
            assert.equal(typeof templateCopy.fields[field], 'string', `${locale}:template-fields:${field}`);
            assert.notEqual(templateCopy.fields[field].trim(), '', `${locale}:template-fields:${field}`);
        }

        for (const field of removedFields) {
            assert.equal(Object.hasOwn(composerCopy.fields, field), false, `${locale}:removed-field:${field}`);
        }
        for (const action of ['add_media', 'remove_media']) {
            assert.equal(Object.hasOwn(composerCopy.actions, action), false, `${locale}:removed-action:${action}`);
        }
        assert.equal(Object.hasOwn(composerCopy, 'media_types'), false, `${locale}:removed-media-types`);
        assert.doesNotMatch(composerCopy.fields.media, /buffer/iu, `${locale}:media`);
        assert.doesNotMatch(composerCopy.fields.media_help, /buffer/iu, `${locale}:media-help`);

        for (const type of ['image', 'video', 'document']) {
            assert.equal(typeof dropzoneCopy.media_types[type], 'string', `${locale}:media-types:${type}`);
            assert.notEqual(dropzoneCopy.media_types[type].trim(), '', `${locale}:media-types:${type}`);
        }
        for (const key of ['upload_media', 'media_hint', 'limit_reached']) {
            assert.equal(typeof dropzoneCopy[key], 'string', `${locale}:dropzone:${key}`);
            assert.notEqual(dropzoneCopy[key].trim(), '', `${locale}:dropzone:${key}`);
        }
        for (const key of [
            'unsupported_media_format',
            'too_many_files',
            'too_large',
            'video_too_large',
            'document_too_large',
            'total_too_large',
        ]) {
            assert.equal(typeof dropzoneCopy.errors[key], 'string', `${locale}:dropzone-errors:${key}`);
            assert.notEqual(dropzoneCopy.errors[key].trim(), '', `${locale}:dropzone-errors:${key}`);
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
