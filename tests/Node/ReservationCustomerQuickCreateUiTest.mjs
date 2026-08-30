import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('reservation creation uses one coherent customer flow without losing the draft', () => {
    const page = read('resources/js/Pages/Reservation/Index.vue');
    const chooser = read('resources/js/Components/Reservation/ReservationCustomerChooser.vue');
    const quickForm = read('resources/js/Components/QuickCreate/CustomerQuickForm.vue');

    assert.match(page, /import ReservationCustomerChooser from '@\/Components\/Reservation\/ReservationCustomerChooser\.vue'/u);
    assert.match(page, /const canCreateCustomer = computed\(\(\) => Boolean\(props\.access\?\.can_create_customer\)\)/u);
    assert.match(page, /const localClients = ref\(\[\.\.\.\(props\.clients \|\| \[\]\)\]\)/u);
    assert.match(page, /const reservationCustomerMode = ref\('existing'\)/u);
    assert.match(page, /const customerCreationProcessing = ref\(false\)/u);
    assert.match(page, /<ReservationCustomerChooser[\s\S]*?v-model:mode="reservationCustomerMode"[\s\S]*?:can-create="canCreateCustomer"[\s\S]*?@created="handleCustomerCreated"/u);
    assert.match(page, /@processing="customerCreationProcessing = \$event"/u);
    assert.match(page, /:closeable="!reservationForm\.processing && !customerCreationProcessing"/u);
    assert.match(page, /if \(reservationForm\.processing \|\| customerCreationProcessing\.value\)[\s\S]*?return/u);
    assert.match(page, /v-if="activeReservation \|\| reservationCustomerMode === 'existing'"[\s\S]*?id="reservation-editor-form"/u);
    assert.doesNotMatch(page, /showCustomerCreator|openCustomerCreator|closeCustomerCreator/u);
    assert.match(page, /localClients\.value\.unshift\(customer\)/u);
    assert.match(page, /reservationForm\.client_id = String\(customer\.id\)/u);
    assert.match(page, /reservationForm\.clearErrors\('client_id'\)/u);

    assert.match(chooser, /data-testid="reservation-customer-mode-existing"/u);
    assert.match(chooser, /data-testid="reservation-customer-mode-new"/u);
    assert.match(chooser, /data-testid="reservation-customer-search"/u);
    assert.match(chooser, /<CustomerQuickForm[\s\S]*?compact[\s\S]*?:default-portal-access="false"[\s\S]*?@created="handleCreated"/u);
    assert.match(chooser, /if \(!clientCount && canCreate && !props\.modelValue\)[\s\S]*?setMode\('new'\)/u);
    assert.match(chooser, /const isCreatingClient = ref\(false\)/u);
    assert.match(chooser, /const mode = computed\(\(\) => props\.mode\)/u);
    assert.match(chooser, /emit\('update:mode', nextMode\)/u);
    assert.match(chooser, /const handleProcessing = \(processing\) => \{[\s\S]*?emit\('processing', isCreatingClient\.value\)/u);
    assert.match(chooser, /@processing="handleProcessing"/u);
    assert.match(chooser, /:aria-pressed="!modelValue"/u);
    assert.match(chooser, /client\.email,[\s\S]*?client\.phone/u);
    assert.match(chooser, /defineEmits\(\[[^\]]*'rebook'[^\]]*\]\)/u);
    assert.match(chooser, /const selectClient = async \(clientId, event = null\)[\s\S]*?event\?\.detail !== 0[\s\S]*?await nextTick\(\)[\s\S]*?rebookingSection\.value\?\.focus\(\)/u);
    assert.match(chooser, /@click="selectClient\(client\.id, \$event\)"/u);
    assert.match(chooser, /<section[\s\S]*?ref="rebookingSection"[\s\S]*?tabindex="-1"[\s\S]*?data-testid="reservation-rebooking"/u);

    assert.match(quickForm, /defineEmits\(\['created', 'cancel', 'processing'\]\)/u);
    assert.match(quickForm, /defaultPortalAccess:[\s\S]*?default: true/u);
    assert.match(quickForm, /portal_access: props\.defaultPortalAccess/u);
    assert.match(quickForm, /if \(!props\.compact && typeof File !== 'undefined' && form\.logo instanceof File\)/u);
    assert.match(quickForm, /else if \(!props\.compact && form\.logo_icon\)/u);
    assert.match(quickForm, /const portalAccessId = `quick-customer-portal-access-\$\{useId\(\)\.replaceAll\(':', ''\)\}`/u);
    assert.match(quickForm, /const closeOverlay = \(\) => \{[\s\S]*?if \(isSubmitting\.value\)[\s\S]*?emit\('cancel'\)/u);
    assert.match(quickForm, /if \(props\.closeOnSuccess\) \{[\s\S]*?hideOverlay\(\)/u);
    assert.match(quickForm, /emit\('processing', true\)[\s\S]*?finally[\s\S]*?emit\('processing', false\)/u);
    assert.match(quickForm, /<form[^>]*:aria-busy="isSubmitting"[\s\S]*?<fieldset[^>]*:disabled="isSubmitting">/u);
    assert.match(quickForm, /<button type="button" :disabled="isSubmitting" @click="closeOverlay"/u);
    assert.match(quickForm, /v-model="form\.email"[\s\S]*?type="email"[\s\S]*?autocomplete="email"/u);
    assert.match(quickForm, /v-model="form\.phone"[\s\S]*?type="tel"[\s\S]*?autocomplete="tel"/u);
    assert.match(quickForm, /const focusErrorSummary = async \(\)[\s\S]*?await nextTick\(\)[\s\S]*?errorSummary\.value\?\.focus\(\)/u);
    assert.match(quickForm, /if \(!isValid\.value\)[\s\S]*?await focusErrorSummary\(\)/u);
    assert.match(quickForm, /error\.response\?\.status === 422[\s\S]*?errors\.value = [^;]+;[\s\S]*?await focusErrorSummary\(\)/u);
    assert.match(quickForm, /ref="errorSummary"[\s\S]*?role="alert"[\s\S]*?aria-live="assertive"[\s\S]*?tabindex="-1"/u);
});

test('existing customers can reuse recent reservations and usual services safely', () => {
    const page = read('resources/js/Pages/Reservation/Index.vue');
    const chooser = read('resources/js/Components/Reservation/ReservationCustomerChooser.vue');
    const rebookHandler = page.match(/const handleRebook = async \(template\) => \{[\s\S]*?\n\};\n\nconst submitReservation/u)?.[0] || '';

    assert.match(chooser, /route\('reservation\.customer-rebooking', \{[\s\S]*?customer: customerId/u);
    assert.match(chooser, /signal: controller\.signal/u);
    assert.match(chooser, /const requestSequence = \+\+rebookingRequestSequence/u);
    assert.match(chooser, /rebookingAbortController\?\.abort\(\)/u);
    assert.match(chooser, /requestSequence !== rebookingRequestSequence/u);
    assert.match(chooser, /rebookingCache\.has\(customerId\)/u);
    assert.match(chooser, /recent_reservations\.slice\(0, 3\)/u);
    assert.match(chooser, /frequent_services\.slice\(0, 3\)/u);
    assert.match(chooser, /data-testid="reservation-rebooking"/u);
    assert.match(chooser, /v-if="rebookingLoading"/u);
    assert.match(chooser, /v-else-if="rebookingError"/u);
    assert.match(chooser, /loadRebookingInsights\(true\)/u);
    assert.match(chooser, /v-else-if="!hasRebookingInsights"/u);
    assert.match(chooser, /selectRebookingTemplate\(reservation, 'recent_reservation'\)/u);
    assert.match(chooser, /selectRebookingTemplate\(service, 'frequent_service'\)/u);
    assert.match(chooser, /timeZone: props\.timezone/u);
    assert.match(chooser, /catch \{[\s\S]*?timeZone: 'UTC'/u);

    assert.match(page, /<ReservationCustomerChooser[\s\S]*?:timezone="timezone"[\s\S]*?@rebook="handleRebook"/u);
    assert.match(page, /const reservationStartsAtField = ref\(null\)/u);
    assert.match(rebookHandler, /template\?\.service\?\.is_available === true/u);
    assert.match(rebookHandler, /template\?\.team_member\?\.is_available === true/u);
    assert.match(rebookHandler, /reservationForm\.service_id = serviceIsAvailable \? String\(serviceId\) : ''/u);
    assert.match(rebookHandler, /reservationForm\.team_member_id = teamMemberIsAvailable \? String\(teamMemberId\) : ''/u);
    assert.match(rebookHandler, /reservationForm\.starts_at = ''/u);
    assert.match(rebookHandler, /reservationForm\.ends_at = ''/u);
    assert.doesNotMatch(rebookHandler, /reservationForm\.(?:status|internal_notes|client_notes)\s*=/u);
    assert.match(rebookHandler, /await nextTick\(\)[\s\S]*?reservationStartsAtField\.value\?\.focus\(\)/u);
    assert.match(page, /ref="reservationStartsAtField"[\s\S]*?v-model="reservationForm\.starts_at"/u);
});

test('reservation customer creation guidance is translated in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/reservations.json`));

        for (const key of [
            'create_subtitle',
            'edit_subtitle',
            'customer_choice',
            'customer_section_title',
            'customer_section_hint',
            'existing_customer',
            'existing_customer_hint',
            'new_customer',
            'new_customer_choice_hint',
            'new_customer_title',
            'new_customer_hint',
            'create_customer',
            'search_customer',
            'no_customer',
            'no_customer_hint',
            'no_customer_results',
            'selected_customer',
            'selected_customer_hint',
            'no_customer_selected_hint',
            'reservation_section_title',
            'reservation_section_hint',
        ]) {
            const value = messages.reservations?.form?.[key];

            assert.equal(typeof value, 'string', `${locale}:reservations.form.${key}`);
            assert.notEqual(value.trim(), '', `${locale}:reservations.form.${key}`);
        }
    }
});

test('rebooking guidance is translated in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/reservations.json`));

        for (const key of [
            'title',
            'hint',
            'loading',
            'load_error',
            'retry',
            'empty',
            'empty_hint',
            'recent_reservations',
            'frequent_services',
            'duration',
            'reservation_count',
            'last_booked',
            'service_unavailable',
            'team_member_unavailable',
            'choose_another_service',
            'action',
            'action_aria',
        ]) {
            const value = messages.reservations?.form?.rebooking?.[key];

            assert.equal(typeof value, 'string', `${locale}:reservations.form.rebooking.${key}`);
            assert.notEqual(value.trim(), '', `${locale}:reservations.form.rebooking.${key}`);
        }
    }
});
