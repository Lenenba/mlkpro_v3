import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const createPage = fs.readFileSync(
    new URL('../../resources/js/Pages/SuperAdmin/DemoWorkspaces/Create.vue', import.meta.url),
    'utf8',
);

test('scenario volume selectors use scenario-specific options with a global fallback', () => {
    assert.match(createPage, /const globalDataVolumes = computed\(\(\) => props\.options\.data_volumes \|\| \[\]\);/u);
    assert.match(createPage, /const scenarioVolumes = scenarioLookup\.value\[scenarioKey\]\?\.data_volumes;/u);
    assert.match(createPage, /if \(!Array\.isArray\(scenarioVolumes\) \|\| !scenarioVolumes\.length\) \{\s*return globalDataVolumes\.value;/u);
    assert.match(createPage, /const formDataVolumes = computed\(\(\) => resolveScenarioDataVolumes\(form\.scenario_key\)\);/u);
    assert.match(createPage, /const templateDataVolumes = computed\(\(\) => resolveScenarioDataVolumes\(templateForm\.scenario_key\)\);/u);
    assert.match(createPage, /v-model="form\.data_volume" :options="formDataVolumes"/u);
    assert.match(createPage, /v-model="templateForm\.data_volume" :options="templateDataVolumes"/u);
    assert.doesNotMatch(createPage, /v-model="(?:form|templateForm)\.data_volume" :options="options\.data_volumes"/u);
});

test('the preview and scenario changes stay aligned with the selected scenario volume', () => {
    assert.match(createPage, /formDataVolumes\.value\.find\(\(volume\) => volume\.value === form\.data_volume\)/u);
    assert.match(createPage, /selectedDataVolume\.value\?\.description/u);
    assert.match(createPage, /selectedDataVolume\.value\?\.counts \|\| \{\}/u);
    assert.match(createPage, /const scenarioDefault = scenarioLookup\.value\[scenarioKey\]\?\.default_volume;/u);
    assert.match(createPage, /availableVolumes\.find\(\(volume\) => volume\.value === scenarioDefault\)\?\.value\s*\|\| availableVolumes\[0\]\?\.value/u);
    assert.match(createPage, /ensureScenarioDataVolume\(form, scenarioKey, formDataVolumes\.value, props\.defaults\.data_volume\);/u);
    assert.match(createPage, /ensureScenarioDataVolume\([\s\S]*?templateDataVolumes\.value,[\s\S]*?props\.template_defaults\.data_volume,/u);
});
