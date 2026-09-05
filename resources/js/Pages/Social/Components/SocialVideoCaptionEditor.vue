<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Trash2 } from 'lucide-vue-next';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { parseVideoSrt, validCaptionCues } from '@/utils/socialVideo';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    duration: { type: Number, required: true },
    currentTime: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'seek']);
const { t } = useI18n();
const label = (key, values = {}) => t(`social.video.${key}`, values);
const error = ref('');
const page = ref(0);
const rows = computed(() => props.modelValue.slice(page.value * 20, page.value * 20 + 20));
const pages = computed(() => Math.max(1, Math.ceil(props.modelValue.length / 20)));
const valid = computed(() => validCaptionCues(props.modelValue, props.duration));
const fieldClass = 'w-full rounded-lg border-stone-300 bg-white text-sm text-stone-900 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100';
watch(() => props.modelValue, () => { page.value = Math.min(page.value, pages.value - 1); });

function update(index, key, value) {
    emit('update:modelValue', props.modelValue.map((cue, i) => i === page.value * 20 + index
        ? { ...cue, [key]: key === 'text' ? value : Math.round(Number(value) * 1000) } : cue));
}
function remove(index) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== page.value * 20 + index));
}
function add() {
    const start = Math.max(Math.round(props.currentTime), props.modelValue.at(-1)?.end_ms ?? 0);
    if (start >= props.duration || props.modelValue.length >= 1000) return;
    emit('update:modelValue', [...props.modelValue, { start_ms: start, end_ms: Math.min(start + 3000, props.duration), text: '' }]);
    page.value = Math.floor(props.modelValue.length / 20);
}
async function importSrt(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (!file) return;
    error.value = '';
    try {
        if (file.size > 262144) throw new Error();
        const text = new TextDecoder('utf-8', { fatal: true }).decode(await file.arrayBuffer());
        emit('update:modelValue', parseVideoSrt(text, props.duration));
        page.value = 0;
    } catch { error.value = label('invalid_srt'); }
}
</script>

<template>
    <fieldset :disabled="disabled" class="min-w-0 space-y-4 disabled:opacity-70">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <label class="min-w-0 text-sm text-stone-700 dark:text-neutral-300">{{ label('import_srt') }}
                <input type="file" accept=".srt,text/plain,application/x-subrip" class="mt-2 block max-w-full text-sm" @change="importSrt" />
            </label>
            <SecondaryButton :disabled="modelValue.length >= 1000 || (modelValue.at(-1)?.end_ms || 0) >= duration" @click="add">{{ label('add_caption') }}</SecondaryButton>
        </div>
        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ label('caption_hint') }}</p>
        <p v-if="error || !valid" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error || label('invalid_captions') }}</p>
        <div v-for="(cue, index) in rows" :key="page * 20 + index" class="grid gap-2 rounded-lg bg-stone-50 p-3 dark:bg-neutral-800 sm:grid-cols-[100px_100px_minmax(0,1fr)_auto]">
            <label class="text-xs text-stone-600 dark:text-neutral-300">{{ label('start_seconds') }}<input type="number" min="0" :max="duration / 1000" step="0.01" :value="cue.start_ms / 1000" :class="fieldClass" class="mt-1" @input="update(index, 'start_ms', $event.target.value)" /></label>
            <label class="text-xs text-stone-600 dark:text-neutral-300">{{ label('end_seconds') }}<input type="number" min="0" :max="duration / 1000" step="0.01" :value="cue.end_ms / 1000" :class="fieldClass" class="mt-1" @input="update(index, 'end_ms', $event.target.value)" /></label>
            <label class="text-xs text-stone-600 dark:text-neutral-300">{{ label('caption_number', { number: page * 20 + index + 1 }) }}<textarea rows="2" maxlength="160" :value="cue.text" :class="fieldClass" class="mt-1" @input="update(index, 'text', $event.target.value)" /></label>
            <div class="flex items-center gap-1">
                <button type="button" class="p-2 text-sm text-sky-700 dark:text-sky-300" @click="emit('seek', cue.start_ms)">{{ label('preview_caption') }}</button>
                <button type="button" class="p-2 text-red-600 dark:text-red-300" :aria-label="label('remove_caption', { number: page * 20 + index + 1 })" @click="remove(index)"><Trash2 class="size-4" /></button>
            </div>
        </div>
        <div v-if="pages > 1" class="flex flex-wrap items-center gap-3 text-sm text-stone-700 dark:text-neutral-300">
            <SecondaryButton :disabled="page === 0" @click="page--">{{ label('previous') }}</SecondaryButton>
            <span>{{ page + 1 }} / {{ pages }}</span>
            <SecondaryButton :disabled="page + 1 >= pages" @click="page++">{{ label('next') }}</SecondaryButton>
        </div>
    </fieldset>
</template>
