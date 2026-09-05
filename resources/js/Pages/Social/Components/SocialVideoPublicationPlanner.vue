<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    project: { type: Object, required: true }, accounts: { type: Array, default: () => [] },
    timezone: { type: String, default: 'UTC' }, canPublish: Boolean, disabled: Boolean, aiAvailable: Boolean,
});
const emit = defineEmits(['updated', 'generate-texts']);
const { t } = useI18n();
const label = (key, values = {}) => t(`social.video.${key}`, values);
const connectionIds = ref([]);
const startDate = ref('');
const time = ref('09:00');
const intervalDays = ref(1);
const publicationMode = ref('drafts');
const rows = ref([]);
const saving = ref(false);
const error = ref('');
const requestId = ref(crypto.randomUUID());
const planned = computed(() => props.project.clips.some((clip) => clip.publication_ids?.length));
const ready = computed(() => props.project.clips.length > 0 && props.project.clips.every((clip) => clip.status === 'ready'));
const validRows = computed(() => rows.value.length > 0 && rows.value.every((row) => row.text.trim() && [...row.text].length <= (row.platform === 'x' ? 280 : 4000)));
const fieldClass = 'w-full rounded-lg border-stone-300 bg-white text-sm text-stone-900 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100';
const requests = new AbortController();
watch(() => [startDate.value, time.value, intervalDays.value, connectionIds.value.join(','), props.project.clips.map((clip) => clip.id).join(',')], () => {
    rows.value = [];
    error.value = '';
    requestId.value = crypto.randomUUID();
});
onUnmounted(() => requests.abort());
const input = () => ({ start_date: startDate.value, time: time.value, interval_days: Number(intervalDays.value), connection_ids: connectionIds.value, clip_ids: props.project.clips.map((clip) => clip.id) });
function message(exception) { return Object.values(exception?.response?.data?.errors || {}).flat()[0] || label('request_failed'); }
async function preview() {
    saving.value = true;
    error.value = '';
    try {
        const response = await axios.post(route('social.videos.publications.preview', props.project.id), input(), { signal: requests.signal });
        rows.value = response.data.rows;
    } catch (exception) { if (!requests.signal.aborted) error.value = message(exception); }
    finally { saving.value = false; }
}
function applyTexts() {
    rows.value = rows.value.map((row) => ({ ...row, text: props.project.intelligence?.texts?.[row.clip_id]?.[row.connection_id] ?? row.text }));
}
async function create() {
    if (saving.value || !validRows.value || planned.value) return;
    saving.value = true;
    error.value = '';
    try {
        const response = await axios.post(route('social.videos.publications.store', props.project.id), {
            ...input(), request_id: requestId.value, mode: publicationMode.value,
            rows: rows.value.map(({ clip_id, connection_id, text }) => ({ clip_id, connection_id, text })),
        }, { signal: requests.signal });
        emit('updated', response.data.project);
    } catch (exception) { if (!requests.signal.aborted) error.value = message(exception); }
    finally { saving.value = false; }
}
</script>

<template>
    <section class="space-y-4 rounded-xl border border-stone-200 p-5 dark:border-neutral-700">
        <h3 class="font-medium text-stone-900 dark:text-neutral-100">{{ label('calendar_title') }}</h3>
        <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
        <div v-if="planned" class="space-y-3">
            <p role="status" class="text-sm text-emerald-700 dark:text-emerald-300">{{ label('calendar_created') }}</p>
            <Link :href="route('social.calendar')" class="text-sm font-medium text-sky-700 hover:underline dark:text-sky-300">{{ label('open_calendar') }}</Link>
        </div>
        <p v-else-if="!ready" class="text-sm text-stone-500 dark:text-neutral-400">{{ label('clips_not_ready') }}</p>
        <p v-else-if="!accounts.length" class="text-sm text-stone-500 dark:text-neutral-400">{{ label('no_accounts') }}</p>
        <fieldset v-else :disabled="disabled || saving" class="space-y-4 disabled:opacity-70">
            <legend class="sr-only">{{ label('calendar_settings') }}</legend>
            <p class="text-xs text-stone-600 dark:text-neutral-400">{{ label('calendar_hint', { timezone }) }}</p>
            <div class="flex flex-wrap gap-3">
                <label v-for="account in accounts" :key="account.id" class="flex items-center gap-2 rounded-lg border border-stone-200 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200"><input v-model="connectionIds" type="checkbox" :value="account.id" :disabled="connectionIds.length >= 5 && !connectionIds.includes(account.id)" class="rounded border-stone-300 text-sky-600" />{{ account.label }} · {{ account.platform }}</label>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm text-stone-700 dark:text-neutral-300">{{ label('first_date') }}<input v-model="startDate" type="date" :class="fieldClass" class="mt-1" /></label>
                <label class="text-sm text-stone-700 dark:text-neutral-300">{{ label('publication_time') }}<input v-model="time" type="time" :class="fieldClass" class="mt-1" /></label>
                <label class="text-sm text-stone-700 dark:text-neutral-300">{{ label('interval_days') }}<input v-model.number="intervalDays" type="number" min="1" max="30" :class="fieldClass" class="mt-1" /></label>
            </div>
            <div class="flex flex-wrap gap-2">
                <SecondaryButton :disabled="!connectionIds.length || !startDate || !time" @click="preview">{{ label('preview_calendar') }}</SecondaryButton>
                <SecondaryButton :disabled="!aiAvailable || !connectionIds.length" @click="emit('generate-texts', connectionIds)">{{ label('generate_texts') }}</SecondaryButton>
                <SecondaryButton v-if="project.intelligence?.texts && rows.length" @click="applyTexts">{{ label('apply_texts') }}</SecondaryButton>
            </div>
            <p v-if="project.intelligence_status === 'failed'" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ label(`ai_errors.${project.intelligence_error_code || 'intelligence_failed'}`) }}</p>
            <div v-if="rows.length" class="space-y-4">
                <article v-for="row in rows" :key="`${row.clip_id}:${row.connection_id}`" class="space-y-2 rounded-lg bg-stone-50 p-4 dark:bg-neutral-800">
                    <div class="flex flex-wrap justify-between gap-2 text-sm text-stone-800 dark:text-neutral-100"><strong>{{ label('clip_number', { number: row.position }) }} · {{ row.account }}</strong><time :datetime="row.scheduled_for">{{ row.local_time }} · {{ timezone }}</time></div>
                    <label class="block text-xs text-stone-600 dark:text-neutral-300">{{ label('publication_text') }}<textarea v-model="row.text" rows="3" :maxlength="row.platform === 'x' ? 280 : 4000" :class="fieldClass" class="mt-1" /></label>
                </article>
                <label class="block text-sm text-stone-700 dark:text-neutral-300">{{ label('publication_mode') }}<select v-model="publicationMode" :class="fieldClass" class="mt-1"><option value="drafts">{{ label('mode_drafts') }}</option><option v-if="canPublish" value="schedule">{{ label('mode_schedule') }}</option></select></label>
                <p class="text-xs text-stone-500 dark:text-neutral-400">{{ label(publicationMode === 'schedule' ? 'schedule_hint' : 'drafts_hint') }}</p>
                <p class="text-xs text-stone-500 dark:text-neutral-400">{{ label('media_public_hint') }}</p>
                <PrimaryButton :disabled="!validRows" @click="create">{{ label(publicationMode === 'schedule' ? 'schedule_series' : 'create_calendar') }}</PrimaryButton>
            </div>
        </fieldset>
    </section>
</template>
