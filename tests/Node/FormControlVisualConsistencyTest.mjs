import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('shared form controls use one regular and one compact visual contract', () => {
    const css = read('resources/css/app.css');

    assert.match(css, /\.app-field-control\s*\{[\s\S]*?height: 3\.5rem/);
    assert.match(css, /\.app-field-control-compact\s*\{[\s\S]*?height: 2\.75rem/);
    assert.match(css, /\.app-field-control:focus,[\s\S]*?border-color: #16a34a/);
    assert.match(css, /\.app-floating-label\s*\{[\s\S]*?inset: 0 0 auto[\s\S]*?min-width: 0[\s\S]*?overflow: hidden/);
    assert.match(css, /\.app-floating-label-content\s*\{[\s\S]*?max-width: 100%[\s\S]*?min-width: 0[\s\S]*?text-overflow: ellipsis/);
});

test('floating labels stay bounded across every shared text and date control', () => {
    const files = [
        'resources/js/Components/FloatingInput.vue',
        'resources/js/Components/FloatingSelect.vue',
        'resources/js/Components/FloatingTextarea.vue',
        'resources/js/Components/DatePicker.vue',
        'resources/js/Components/DateTimePicker.vue',
        'resources/js/Components/TimePicker.vue',
    ];

    for (const file of files) {
        const source = read(file);

        assert.match(source, /relative(?: w-full)? min-w-0/, file);
        assert.match(source, /app-floating-label/, file);
        assert.match(source, /app-floating-label-content/, file);
        assert.match(source, /:title="label"/, file);
    }

    for (const file of [
        'resources/js/Components/FloatingInput.vue',
        'resources/js/Components/FloatingSelect.vue',
        'resources/js/Components/DatePicker.vue',
        'resources/js/Components/DateTimePicker.vue',
        'resources/js/Components/TimePicker.vue',
    ]) {
        assert.match(read(file), /app-field-control/, file);
    }
});

test('floating text and select wrappers do not force compact DataTable filters onto full rows', () => {
    const input = read('resources/js/Components/FloatingInput.vue');
    const select = read('resources/js/Components/FloatingSelect.vue');
    const toolbar = read('resources/js/Components/DataTable/AdminDataTableToolbar.vue');
    const taskTable = read('resources/js/Pages/Task/UI/TaskTable.vue');
    const quoteTable = read('resources/js/Pages/Quote/UI/QuoteTable.vue');
    const actionsSlot = (source) => {
        const start = source.indexOf('<template #actions>');
        const end = source.indexOf('</template>', start);

        assert.notEqual(start, -1);
        assert.notEqual(end, -1);

        return source.slice(start, end);
    };

    for (const source of [input, select]) {
        assert.match(source, /<div class="relative min-w-0">/);
        assert.doesNotMatch(source, /<div class="relative w-full min-w-0"/);
    }

    assert.match(input, /v-bind="attrs"/);
    assert.match(select, /return \[baseClass, heightClass, placeholderClass, attrs\.class\]/);
    assert.match(toolbar, /class="flex flex-wrap items-center justify-end gap-2"/);

    const taskActions = actionsSlot(taskTable);
    assert.equal(taskActions.match(/<FloatingSelect/g)?.length, 3);
    assert.equal(taskActions.match(/class="min-w-\[150px\]"/g)?.length, 3);

    const quoteActions = actionsSlot(quoteTable);
    assert.equal(quoteActions.match(/<FloatingSelect/g)?.length, 3);
    for (const width of ['150', '170', '190']) {
        assert.match(quoteActions, new RegExp(`class="min-w-\\[${width}px\\]"`));
    }
});

test('shared date and time fields no longer carry the divergent legacy surface', () => {
    for (const file of [
        'resources/js/Components/DatePicker.vue',
        'resources/js/Components/DateTimePicker.vue',
        'resources/js/Components/TimePicker.vue',
    ]) {
        const source = read(file);

        assert.doesNotMatch(source, /border-stone-300/, file);
        assert.doesNotMatch(source, /focus:ring-green-500/, file);
        assert.doesNotMatch(source, /dark:bg-neutral-800 dark:border-neutral-700 dark:text-white/, file);
    }
});

test('multiple selects remain content-sized while dense selects remain backward compatible', () => {
    const source = read('resources/js/Components/FloatingSelect.vue');

    assert.match(source, /props\.dense[\s\S]*?app-field-control-compact/);
    assert.match(source, /const heightClass = isMultiple\.value[\s\S]*?h-auto py-2/);
    assert.doesNotMatch(source, /isMultiple\.value[\s\S]{0,120}h-14/);
});

test('the advanced customer dialog aligns controls and contains long labels', () => {
    const source = read('resources/js/Components/Customer/CustomerAdvancedFiltersDialog.vue');

    assert.equal(source.match(/\bdense\b/g)?.length ?? 0, 0);
    assert.match(source, /grid gap-3 md:grid-cols-2 xl:grid-cols-4/);
    assert.match(source, /grid max-h-60[\s\S]*?overflow-y-auto/);
    assert.match(source, /:title="option\.label"/);
    assert.match(source, /class="shrink-0 rounded/);
    assert.match(source, /<span class="min-w-0 truncate">\{\{ option\.label \}\}<\/span>/);
    assert.match(source, /sticky bottom-0 z-10/);
    assert.match(source, /min-h-11 whitespace-nowrap/);
});
