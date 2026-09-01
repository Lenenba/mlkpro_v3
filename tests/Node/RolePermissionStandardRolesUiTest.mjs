import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/Pages/Settings/RolesPermissions.vue', import.meta.url),
    'utf8',
);

test('role settings distinguish editable sector standards from custom and system roles', () => {
    assert.match(
        page,
        /const standardRoles = computed\(\(\) => props\.roles\.filter\(\(role\) => !role\.is_system && role\.is_default\)\)/u,
    );
    assert.match(
        page,
        /const customRoles = computed\(\(\) => props\.roles\.filter\(\(role\) => !role\.is_system && !role\.is_default\)\)/u,
    );
    assert.match(page, /label: 'Rôles standards'/u);
    assert.match(page, /return role\.is_active \? 'Standard' : 'Standard · Inactif'/u);
    assert.match(page, /Ce rôle standard a été préparé pour le secteur de l’entreprise/u);
    assert.match(page, /le désactiver sans perdre sa configuration/u);
    assert.match(page, /Adaptez les rôles standards proposés pour votre secteur/u);
});
