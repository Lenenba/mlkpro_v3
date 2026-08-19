import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('the onboarding company step uses the shared floating controls consistently', () => {
    const source = read('resources/js/Pages/Onboarding/Index.vue');
    const companyStep = source.slice(
        source.indexOf('v-else-if="step === stepIds.company"'),
        source.indexOf('v-else-if="step === stepIds.type"'),
    );

    assert.match(source, /import FloatingTextarea from '@\/Components\/FloatingTextarea\.vue'/);
    assert.match(companyStep, /<FloatingInput[\s\S]*?v-model="form\.company_name"[\s\S]*?required/);
    assert.match(companyStep, /<DropzoneInput[\s\S]*?v-model="form\.company_logo"/);
    assert.match(companyStep, /<FloatingTextarea[\s\S]*?v-model="form\.company_description"[\s\S]*?maxlength="2000"/);
    assert.match(companyStep, /<FloatingInput[\s\S]*?v-model="addressQuery"[\s\S]*?@input="handleAddressInput"/);
    assert.match(companyStep, /<FloatingSelect[\s\S]*?v-model="form\.currency_code"/);
    assert.match(companyStep, /v-model="form\.company_city"/);
    assert.match(companyStep, /v-model="form\.company_province"/);
    assert.match(companyStep, /v-model="form\.company_country"/);

    assert.doesNotMatch(companyStep, /<textarea\b/);
    assert.doesNotMatch(companyStep, /<input\b/);
    assert.doesNotMatch(companyStep, /<label\b/);
});

test('the onboarding address combobox keeps its autocomplete behavior and semantics', () => {
    const source = read('resources/js/Pages/Onboarding/Index.vue');
    const companyStep = source.slice(
        source.indexOf('v-else-if="step === stepIds.company"'),
        source.indexOf('v-else-if="step === stepIds.type"'),
    );

    assert.match(companyStep, /id="onboarding-company-address"/);
    assert.match(companyStep, /role="combobox"/);
    assert.match(companyStep, /aria-autocomplete="list"/);
    assert.match(companyStep, /aria-controls="onboarding-company-address-suggestions"/);
    assert.match(companyStep, /:aria-expanded="addressSuggestions\.length > 0"/);
    assert.match(companyStep, /:aria-activedescendant="activeAddressSuggestionId"/);
    assert.match(companyStep, /@keydown="handleAddressKeydown"/);
    assert.match(companyStep, /id="onboarding-company-address-suggestions"[\s\S]*?role="listbox"/);
    assert.match(companyStep, /role="option"[\s\S]*?@click="selectAddressSuggestion\(suggestion\)"/);
    assert.match(source, /event\.key === 'ArrowDown'/);
    assert.match(source, /event\.key === 'ArrowUp'/);
    assert.match(source, /event\.key === 'Enter'/);
    assert.match(source, /event\.key === 'Escape'/);
    assert.match(source, /activeAddressSuggestionIndex/);

    assert.match(source, /params\.filter = 'countrycode:ca,us,fr,be,ch,ma,tn'/);
    assert.match(source, /const fallback = await fetchGeoapify\(false\)/);
});

test('manual address validation stays next to each floating field', () => {
    const source = read('resources/js/Pages/Onboarding/Index.vue');
    const companyStep = source.slice(
        source.indexOf('v-else-if="step === stepIds.company"'),
        source.indexOf('v-else-if="step === stepIds.type"'),
    );

    for (const field of ['company_city', 'company_province', 'company_country']) {
        assert.match(
            companyStep,
            new RegExp(`v-model="form\\.${field}"[\\s\\S]*?:message="form\\.errors\\.${field}"`),
            field,
        );
    }

    assert.match(companyStep, /v-if="!showManualAddress"[\s\S]*?form\.errors\.company_country/);
});

test('company settings use the same floating description control', () => {
    const source = read('resources/js/Pages/Settings/Company.vue');

    assert.match(source, /<FloatingTextarea[\s\S]*?v-model="form\.company_description"[\s\S]*?maxlength="2000"/);
    assert.doesNotMatch(source, /<textarea[^>]*v-model="form\.company_description"/);
});

test('the floating textarea forwards native attributes while preserving layout classes', () => {
    const source = read('resources/js/Components/FloatingTextarea.vue');

    assert.match(source, /defineOptions\(\{ inheritAttrs: false \}\)/);
    assert.match(source, /const inputAttrs = \{ \.\.\.attrs \}[\s\S]*?delete inputAttrs\.class/);
    assert.match(source, /<div class="relative" :class="attrs\.class">/);
    assert.match(source, /<textarea[\s\S]*?:required="required"[\s\S]*?v-bind="textareaAttrs"/);
    assert.match(source, /focus:border-green-600 focus:ring-green-600/);
    assert.doesNotMatch(source, /focus:border-green-500|focus:ring-green-500/);
});

test('the floating input forwards its required state to the native control', () => {
    const source = read('resources/js/Components/FloatingInput.vue');

    assert.match(source, /<input[\s\S]*?:required="required"/);
    assert.match(source, /:aria-required="required \? 'true' : undefined"/);
});

test('the floating select associates its label with the native control', () => {
    const source = read('resources/js/Components/FloatingSelect.vue');

    assert.match(source, /const controlId = computed\(\(\) => attrs\.id \|\| generatedId\)/);
    assert.match(source, /<select[\s\S]*?:id="controlId"/);
    assert.match(source, /<label[\s\S]*?:for="controlId"/);
    assert.match(source, /:required="required"/);
    assert.match(source, /:aria-required="required \? 'true' : undefined"/);
});
