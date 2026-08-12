import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = readFileSync(
    resolve('resources/js/Components/QuickCreate/RequestQuickForm.vue'),
    'utf8',
);

test('request quick create keeps address state, autocomplete and manual fields aligned', () => {
    assert.match(source, /assignGeoapifyAddress, useGeoapifyAddressAutocomplete/);
    assert.match(source, /assignGeoapifyAddress\(form, details\)/);

    for (const field of ['street1', 'street2', 'city', 'state', 'zip', 'country']) {
        assert.match(source, new RegExp(`${field}: ''`));
        assert.match(source, new RegExp(`form\\.${field} = ''`));
        assert.match(source, new RegExp(`v-model="form\\.${field}"`));
    }

    assert.match(source, /resetAddressSearch\(\)/);
});

test('request quick create maps its address fields to the service-request payload', () => {
    for (const field of ['street1', 'street2', 'city', 'state', 'country']) {
        assert.match(source, new RegExp(`${field}: form\\.${field} \\|\\| null`));
    }

    assert.match(source, /postal_code: form\.zip \|\| null/);
});
