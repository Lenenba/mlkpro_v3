import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const menuSource = read('resources/js/Pages/Customer/UI/CustomerActionsMenu.vue');

test('customer actions resend portal invitations only for linked enabled accounts', () => {
    assert.match(menuSource, /const canResendPortalInvitation = computed\(\(\) => \([\s\S]*?props\.canEdit[\s\S]*?props\.customer\?\.portal_access[\s\S]*?props\.customer\?\.portal_user_id[\s\S]*?\)\);/u);
    assert.match(menuSource, /v-if="canResendPortalInvitation"/u);
    assert.match(menuSource, /router\.post\(route\('customer\.portal-invitation\.resend', props\.customer\.id\), \{\}, \{[\s\S]*?preserveScroll: true/u);
});

test('customer invitation resend action prevents duplicate submissions and exposes progress', () => {
    assert.match(menuSource, /if \(!canResendPortalInvitation\.value \|\| resendingPortalInvitation\.value\) \{[\s\S]*?return;/u);
    assert.match(menuSource, /resendingPortalInvitation\.value = true;[\s\S]*?onFinish: \(\) => \{[\s\S]*?resendingPortalInvitation\.value = false;/u);
    assert.match(menuSource, /:disabled="resendingPortalInvitation"/u);
    assert.match(menuSource, /:aria-busy="resendingPortalInvitation"/u);
    assert.match(menuSource, /rounded-sm[^"]*disabled:cursor-not-allowed[^"]*dark:/u);
    assert.match(menuSource, /customers\.actions\.resend_portal_invitation/u);
    assert.match(menuSource, /customers\.actions\.resending_portal_invitation/u);
});

test('customer invitation resend labels exist in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/customer_index.json`));

        for (const key of ['resend_portal_invitation', 'resending_portal_invitation']) {
            const value = messages.customers.actions[key];

            assert.equal(typeof value, 'string', `${locale}:customers.actions.${key}`);
            assert.notEqual(value.trim(), '', `${locale}:customers.actions.${key}`);
        }
    }
});
