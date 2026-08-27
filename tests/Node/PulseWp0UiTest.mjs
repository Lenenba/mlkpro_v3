import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const dropzone = read('resources/js/Components/DropzoneInput.vue');
const composer = read('resources/js/Pages/Social/Components/SocialPostComposer.vue');
const history = read('resources/js/Pages/Social/Components/SocialPostHistory.vue');
const accountManager = read('resources/js/Pages/Social/Components/SocialAccountManager.vue');

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
