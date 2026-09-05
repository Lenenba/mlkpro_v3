import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = readFileSync(resolve('resources/js/Pages/Public/ReservationKiosk.vue'), 'utf8');

test('the kiosk portrait always fills its frame without letterboxing', () => {
    const portraitFrameStyles = source.match(/\.reservation-kiosk-portrait \{([^}]*)\}/u)?.[1] || '';
    const portraitImageStyles = source.match(/\.reservation-kiosk-portrait__image \{([^}]*)\}/u)?.[1] || '';

    assert.match(source, /class="reservation-kiosk-portrait__image block"/u);
    assert.match(portraitFrameStyles, /overflow: hidden;/u);
    assert.match(portraitImageStyles, /width: 100%;/u);
    assert.match(portraitImageStyles, /height: 100%;/u);
    assert.match(portraitImageStyles, /object-fit: cover;/u);
    assert.match(portraitImageStyles, /object-position: center;/u);
    assert.doesNotMatch(portraitImageStyles, /object-fit: contain;/u);
});
