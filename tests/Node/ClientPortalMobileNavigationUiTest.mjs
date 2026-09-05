import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('client sidebar expands on mobile and keeps the desktop rail compact', () => {
    const sidebar = read('resources/js/Layouts/UI/Sidebar.vue');

    assert.match(
        sidebar,
        /isClient \? 'w-64 max-w-\[calc\(100vw-1rem\)\] lg:w-16 lg:max-w-none' : 'w-16'/,
    );
    assert.match(sidebar, /isClient \? 'w-full lg:w-16' : 'w-16'/);
    assert.match(sidebar, /motion-reduce:transition-none motion-reduce:duration-0/);
});

test('only client portal links opt into expanded mobile navigation', () => {
    const link = read('resources/js/Components/UI/LinkAncor.vue');
    const clientLink = read('resources/js/Components/Portal/ClientPortalSidebarLink.vue');

    assert.match(link, /mobileExpanded:[\s\S]*?default: false/);
    assert.match(clientLink, /<LinkAncor[\s\S]*?mobile-expanded/);
});

test('expanded client links expose touch rows and labels only below desktop', () => {
    const link = read('resources/js/Components/UI/LinkAncor.vue');

    assert.match(
        link,
        /min-h-11 w-full flex-row justify-start[\s\S]*lg:min-h-0 lg:w-auto lg:flex-col/,
    );
    assert.match(link, /v-else-if="mobileExpanded"[\s\S]*lg:sr-only/);
    assert.match(link, /mobileExpanded \? 'hidden lg:block' : null/);
});
