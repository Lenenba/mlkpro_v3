import assert from 'node:assert/strict';
import test from 'node:test';

import { toFormData } from '../../resources/js/utils/formData.js';

test('toFormData preserves nested object and indexed object-array keys', () => {
    const formData = toFormData({
        properties: {
            city: 'Montréal',
            is_default: true,
        },
        materials: [
            { product_id: 12, quantity: 2 },
            { label: 'Serviette', billable: false },
        ],
    });

    assert.deepEqual([...formData.entries()], [
        ['properties[city]', 'Montréal'],
        ['properties[is_default]', '1'],
        ['materials[0][product_id]', '12'],
        ['materials[0][quantity]', '2'],
        ['materials[1][label]', 'Serviette'],
        ['materials[1][billable]', '0'],
    ]);
});

test('toFormData keeps file arrays under repeated array keys', () => {
    const first = new File(['first'], 'first.jpg', { type: 'image/jpeg' });
    const second = new File(['second'], 'second.webp', { type: 'image/webp' });
    const formData = toFormData({ images: [first, second] });
    const entries = [...formData.entries()];

    assert.deepEqual(entries.map(([key]) => key), ['images[]', 'images[]']);
    assert.equal(entries[0][1], first);
    assert.equal(entries[1][1], second);
});
