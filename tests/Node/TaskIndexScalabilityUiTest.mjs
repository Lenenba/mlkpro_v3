import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const taskIndex = read('resources/js/Pages/Task/Index.vue');
const taskTable = read('resources/js/Pages/Task/UI/TaskTable.vue');

test('task index forwards the bounded task window to its workspace', () => {
    assert.match(taskIndex, /taskWindow:\s*\{[\s\S]*?type:\s*Object/u);
    assert.match(taskIndex, /:taskWindow="taskWindow"/u);
    assert.match(taskTable, /taskWindow:\s*\{[\s\S]*?type:\s*Object/u);
});

test('task schedule sends its selected range and reloads bounded metadata', () => {
    assert.match(taskTable, /range:\s*scheduleRange\.value/u);
    assert.match(
        taskTable,
        /only:\s*\['tasks',\s*'taskWindow',\s*'filters',\s*'stats',\s*'count'\]/u,
    );
    assert.match(
        taskTable,
        /const setScheduleRange = \(range\) => \{[\s\S]*?clearSelectedDate\(\);[\s\S]*?autoFilter\(\);[\s\S]*?\};/u,
    );
    assert.match(taskTable, /props\.filters\?\.range !== scheduleRange\.value/u);
});

test('task schedule uses server date bounds and discloses truncated results', () => {
    assert.match(taskTable, /const serverScheduleRangeBounds = \(\) =>/u);
    assert.match(taskTable, /props\.taskWindow\?\.range_start/u);
    assert.match(taskTable, /props\.taskWindow\?\.range_end/u);
    assert.match(taskTable, /v-if="!isLoading && taskWindowTruncated"/u);
    assert.match(taskTable, /tasks\.window\.truncated/u);
    assert.match(taskTable, /const hasMatchingTasks = computed/u);
    assert.doesNotMatch(
        taskTable,
        /<div v-if="taskList\.length" class="flex flex-wrap items-center justify-between[^>]*>[\s\S]*?tasks\.schedule\.range_label/u,
        'the range selector remains available when the selected period is empty',
    );

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/tasks.json`));
        const label = messages.tasks?.window?.truncated;

        assert.equal(typeof label, 'string', `${locale}: tasks.window.truncated is translated`);
        assert.match(label, /\{shown\}/u);
        assert.match(label, /\{total\}/u);
    }
});
