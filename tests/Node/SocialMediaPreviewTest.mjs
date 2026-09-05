import assert from 'node:assert/strict';
import test from 'node:test';
import { effectScope, nextTick, ref } from 'vue';
import { useSocialMediaPreview } from '../../resources/js/Composables/useSocialMediaPreview.js';
import { socialPreviewAssets } from '../../resources/js/utils/socialMediaAssets.js';

test('preview preserves mixed media order and does not duplicate the legacy cover', () => {
    const media = [{ type: 'video', url: '/clip.mp4' }, { type: 'image', url: '/cover.jpg' }, { type: 'document', url: '/guide.pdf' }];
    assert.deepEqual(socialPreviewAssets(media, '/cover.jpg').map(({ type, url }) => [type, url]), [
        ['video', '/clip.mp4'], ['image', '/cover.jpg'], ['document', '/guide.pdf'],
    ]);
    assert.deepEqual(socialPreviewAssets([], '/cover.jpg').map(({ type, url }) => [type, url]), [['image', '/cover.jpg']]);
});

test('preview excludes unsafe media and thumbnail URLs', () => {
    const media = socialPreviewAssets([
        { type: 'video', url: '//cdn.example.test/clip.mp4', thumbnail_url: 'javascript:alert(1)' },
        { type: 'document', url: 'javascript:alert(1)' },
        { type: 'image', url: 'data:text/html,test' },
        { type: 'document', url: '/\\evil.example.test/file' },
    ]);
    assert.equal(media.length, 1);
    assert.equal(media[0].url, 'https://cdn.example.test/clip.mp4');
    assert.equal(media[0].thumbnail_url, '');
});

test('local videos appear before upload and object URLs are released on removal and disposal', async (t) => {
    const revoked = [];
    let count = 0;
    t.mock.method(URL, 'createObjectURL', () => `blob:preview-${++count}`);
    t.mock.method(URL, 'revokeObjectURL', (url) => revoked.push(url));
    const stored = ref([{ type: 'image', url: '/stored.jpg' }]);
    const files = ref([]);
    const scope = effectScope();
    const preview = scope.run(() => useSocialMediaPreview(() => stored.value, () => files.value));
    t.after(() => scope.stop());
    files.value = [new File(['video'], 'clip.mp4', { type: 'video/mp4' }), new File(['image'], 'cover.jpg', { type: 'image/jpeg' })];
    await nextTick();
    assert.deepEqual(preview.value.map((asset) => asset.type), ['image', 'video', 'image']);
    assert.equal(preview.value[1].url, 'blob:preview-1');
    const oldUrls = preview.value.slice(1).map((asset) => asset.url);
    files.value = files.value.slice(0, 1);
    await nextTick();
    assert.deepEqual(revoked, oldUrls);
    assert.equal(preview.value.length, 2);
    const remainingUrl = preview.value[1].url;
    scope.stop();
    assert.ok(revoked.includes(remainingUrl));
    assert.ok(!revoked.includes('/stored.jpg'));
});
