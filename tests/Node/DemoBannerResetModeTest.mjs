import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const banner = fs.readFileSync(
    new URL('../../resources/js/Components/Demo/DemoBanner.vue', import.meta.url),
    'utf8',
);

test('the demo banner only offers self-service reset to exact legacy demos', () => {
    assert.match(banner, /const resetMode = computed\(\(\) => String\(demo\.value\?\.reset_mode \|\| 'none'\)\);/u);
    assert.match(banner, /const showResetButton = computed\(\(\) => resetMode\.value === 'legacy'\);/u);
    assert.match(banner, /<button\s+v-if="showResetButton"[\s\S]*?@click="resetDemo"/u);
});

test('the demo banner explains managed scenario resets without rendering the reset control', () => {
    assert.match(banner, /const isManagedReset = computed\(\(\) => resetMode\.value === 'managed_baseline'\);/u);
    assert.match(banner, /v-if="isManagedReset"[\s\S]*?Reset is managed from this scenario's saved baseline\./u);
    assert.doesNotMatch(banner, /<button\s+v-if="isManagedReset"[\s\S]*?@click="resetDemo"/u);
});
