import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const vueFiles = (directory) => readdirSync(resolve(directory), { withFileTypes: true })
    .flatMap((entry) => {
        const path = `${directory}/${entry.name}`;

        if (entry.isDirectory()) {
            return vueFiles(path);
        }

        return entry.isFile() && entry.name.endsWith('.vue') ? [path] : [];
    });

test('shared data tables keep alternating rows in every module', () => {
    const sharedTable = read('resources/js/Components/DataTable/AdminDataTable.vue');
    const reservationTable = read('resources/js/Components/Reservation/ReservationListTable.vue');

    assert.match(sharedTable, /striped:\s*\{[\s\S]*?default:\s*true/u);
    assert.match(sharedTable, /tbody > tr:nth-child\(odd\)/u);
    assert.match(sharedTable, /tbody > tr:nth-child\(even\)/u);
    assert.match(sharedTable, /\.dark \.admin-data-table--striped/u);
    assert.doesNotMatch(reservationTable, /reservation-list-row/u);

    const stripedOverrides = vueFiles('resources/js')
        .flatMap((file) => {
            const adminTableTags = read(file).match(/<AdminDataTable\b[^>]*>/gu) ?? [];

            return adminTableTags
                .filter((tag) => /(?:(?:v-bind)?:)striped\s*=|striped\s*=\s*["']false["']/u.test(tag))
                .map(() => file);
        });

    assert.deepEqual(stripedOverrides, []);
});
