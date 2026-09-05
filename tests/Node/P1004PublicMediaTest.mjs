import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import {
    publicStockImageVariants,
    publicStockImageWidths,
    resolvePublicStockImage,
} from '../../resources/js/utils/publicResponsiveImages.js';

const projectRoot = resolve('.');

test('keeps the responsive stock image manifest aligned with the JPEG sources', () => {
    const sources = readdirSync(resolve(projectRoot, 'public/images/landing/stock'))
        .filter((file) => file.endsWith('.jpg'))
        .map((file) => file.replace(/\.jpg$/, ''))
        .sort();

    assert.deepEqual(Object.keys(publicStockImageVariants).sort(), sources);
    assert.deepEqual(publicStockImageWidths, [640, 1280]);

    const variants = readdirSync(resolve(projectRoot, 'public/images/landing/stock/optimized'));
    const expectedVariants = sources.flatMap((key) => publicStockImageWidths.flatMap((width) => [
        `${key}-${width}w.avif`,
        `${key}-${width}w.webp`,
    ]));

    assert.deepEqual(variants.sort(), expectedVariants.sort());
});

test('only resolves generated variants for known local stock images', () => {
    const variants = resolvePublicStockImage('/images/landing/stock/hero-team.jpg?version=1');

    assert.equal(variants?.width, 1800);
    assert.equal(variants?.height, 1197);
    assert.match(variants?.avifSrcSet || '', /hero-team-640w\.avif 640w/);
    assert.match(variants?.webpSrcSet || '', /hero-team-1280w\.webp 1280w/);
    assert.equal(resolvePublicStockImage('https://cdn.example.com/hero-team.jpg'), null);
    assert.equal(resolvePublicStockImage('data:image/jpeg;base64,abc'), null);
});

test('keeps font loading out of the compiled stylesheet and prioritizes public heroes', () => {
    const css = readFileSync(resolve(projectRoot, 'resources/css/app.css'), 'utf8');
    const appTemplate = readFileSync(resolve(projectRoot, 'resources/views/app.blade.php'), 'utf8');
    const responsiveImage = readFileSync(resolve(projectRoot, 'resources/js/Components/Public/PublicResponsiveImage.vue'), 'utf8');

    assert.doesNotMatch(css, /@import\s+url\(['"]https:\/\/fonts\.bunny\.net/i);
    assert.match(appTemplate, /rel="preconnect" href="https:\/\/fonts\.bunny\.net"/);
    assert.match(appTemplate, /https:\/\/fonts\.bunny\.net\/css\?family=Montserrat/);
    assert.match(responsiveImage, /type="image\/avif"/);
    assert.match(responsiveImage, /type="image\/webp"/);
    assert.match(responsiveImage, /<picture v-bind="\$attrs" class="public-responsive-image">/);

    [
        'resources/js/Pages/Welcome.vue',
        'resources/js/Pages/Public/Store.vue',
        'resources/js/Pages/Public/Showcase.vue',
        'resources/js/Components/Public/PublicFrontHero.vue',
    ].forEach((path) => {
        const source = readFileSync(resolve(projectRoot, path), 'utf8');

        assert.match(source, /PublicResponsiveImage/);
        assert.match(source, /fetch-priority/);
    });

    assert.match(
        readFileSync(resolve(projectRoot, 'resources/js/Pages/Welcome.vue'), 'utf8'),
        /heroSlideIndex === 0 \? 'high' : null/,
    );
    assert.match(
        readFileSync(resolve(projectRoot, 'resources/js/Pages/Public/Store.vue'), 'utf8'),
        /heroBackgroundIndex === 0 \? 'high' : null/,
    );
    assert.match(
        readFileSync(resolve(projectRoot, 'resources/js/Pages/Public/Showcase.vue'), 'utf8'),
        /heroBackgroundIndex === 0 \? 'high' : null/,
    );
});
