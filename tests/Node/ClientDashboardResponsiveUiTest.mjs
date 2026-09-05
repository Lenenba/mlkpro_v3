import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const clientDashboard = read('resources/js/Pages/DashboardClient.vue');
const productDashboard = read('resources/js/Pages/DashboardProductsClient.vue');

const componentBlock = (source, marker) => {
    const markerIndex = source.indexOf(marker);
    assert.notEqual(markerIndex, -1, `${marker} exists`);

    const start = source.lastIndexOf('<Modal', markerIndex);
    const end = source.indexOf('</Modal>', markerIndex);
    assert.notEqual(start, -1, `${marker} belongs to a Modal`);
    assert.notEqual(end, -1, `${marker} Modal closes`);

    return source.slice(start, end + '</Modal>'.length);
};

test('client dashboard dialogs keep every control reachable on short mobile viewports', () => {
    const scheduleDialog = componentBlock(clientDashboard, ':show="schedulePreviewOpen"');
    const proofDialog = componentBlock(clientDashboard, ':show="taskProofOpen"');

    assert.match(clientDashboard, /import Modal from '@\/Components\/Modal\.vue';/u);
    assert.equal(clientDashboard.match(/<Modal\b/gu)?.length, 2);
    assert.doesNotMatch(clientDashboard, /fixed inset-0 z-50 flex items-center justify-center px-4 py-6/u);

    for (const dialog of [scheduleDialog, proofDialog]) {
        assert.match(dialog, /full-screen-mobile/u);
        assert.match(dialog, /flex h-dvh min-h-0 flex-col sm:h-auto sm:max-h-\[calc\(100dvh-3rem\)\]/u);
        assert.match(dialog, /min-h-0 flex-1[^"]*overflow-y-auto overscroll-contain/u);
    }
});

test('schedule preview contains horizontal calendar overflow and stacks visit details on mobile', () => {
    const scheduleDialog = componentBlock(clientDashboard, ':show="schedulePreviewOpen"');

    assert.match(scheduleDialog, /overflow-x-auto overscroll-x-contain/u);
    assert.match(scheduleDialog, /min-w-\[40rem\] sm:min-w-0/u);
    assert.match(scheduleDialog, /grid grid-cols-1 gap-3 text-sm[^"]*sm:grid-cols-2/u);
    assert.match(scheduleDialog, /grid-cols-\[minmax\(0,1fr\)_auto\][^"]*gap-3/u);
});

test('product dashboard hero stacks and constrains dynamic content below the small breakpoint', () => {
    assert.match(productDashboard, /w-full min-w-0 max-w-full space-y-5/u);
    assert.match(productDashboard, /grid-class="grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"/u);
    assert.doesNotMatch(productDashboard, /grid-class="grid-cols-1 md:grid-cols-3"/u);
    assert.match(productDashboard, /flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between/u);
    assert.match(productDashboard, /inline-flex max-w-full items-center[^"]*tracking-\[0\.18em\]/u);
    assert.match(productDashboard, /<span class="min-w-0 truncate">\{\{ companyName \}\}<\/span>/u);
    assert.match(
        productDashboard,
        /w-full rounded-sm[^"]*text-left[^"]*sm:w-auto sm:shrink-0 sm:text-right/u,
    );
});

test('product dashboard cards stack narrow rows and bound labels, amounts, and actions', () => {
    const responsiveHeaders = productDashboard.match(
        /flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between/gu,
    ) || [];
    const responsiveOrderRows = productDashboard.match(
        /flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between/gu,
    ) || [];
    const boundedOrderLabels = productDashboard.match(
        /class="\[overflow-wrap:anywhere\][^"]*font-semibold/gu,
    ) || [];

    assert.equal(responsiveHeaders.length, 4);
    assert.equal(responsiveOrderRows.length, 4);
    assert.equal(boundedOrderLabels.length, 4);
    assert.match(productDashboard, /class="min-w-0 space-y-4"/u);
    assert.match(productDashboard, /class="max-w-full text-left sm:shrink-0 sm:text-right"/u);
    assert.match(productDashboard, /inline-flex w-full items-center justify-center[^"]*sm:ms-auto sm:w-auto/u);
    assert.match(productDashboard, /mt-3 flex flex-wrap items-center justify-between gap-2/u);
});
