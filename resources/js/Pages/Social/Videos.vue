<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { Film, Scissors, Upload, Trash2 } from 'lucide-vue-next';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialVideoCaptionEditor from '@/Pages/Social/Components/SocialVideoCaptionEditor.vue';
import SocialVideoPublicationPlanner from '@/Pages/Social/Components/SocialVideoPublicationPlanner.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { captionSegments, clipVideoCaptions, durationSegments, validCaptionCues, validVideoSegments, videoCropPosition, videoIsBusy, videoSrt, videoTime } from '@/utils/socialVideo';

const props = defineProps({
    projects: { type: Array, default: () => [] },
    access: { type: Object, default: () => ({}) },
    limits: { type: Object, default: () => ({}) },
    connected_accounts: { type: Array, default: () => [] },
    timezone: { type: String, default: 'UTC' },
    ai_available: { type: Boolean, default: false },
});
const { t } = useI18n();
const label = (key, values = {}) => t(`social.video.${key}`, values);
const projects = ref(props.projects);
const selectedId = ref(props.projects[0]?.id ?? null);
const selected = computed(() => projects.value.find((project) => project.id === selectedId.value));
const canManage = computed(() => Boolean(props.access.can_manage_posts));
const busy = computed(() => videoIsBusy(selected.value));
const file = ref(null);
const uploading = ref(false);
const uploadProgress = ref(0);
const saving = ref(false);
const error = ref('');
const pollingError = ref(false);
const mode = ref('duration');
const segmentSeconds = ref(60);
const manualSegments = ref([]);
const format = ref('portrait');
const framing = ref('crop');
const focalX = ref(50);
const focalY = ref(50);
const activeSegment = ref(null);
const video = ref(null);
const backdrop = ref(null);
const previewError = ref(false);
const confirmDelete = ref(false);
const captions = ref([]);
const captionsEnabled = ref(false);
const captionStyle = ref('white');
const captionPosition = ref('bottom');
const cropPoints = ref([]);
const previewMotion = ref(true);
const currentTime = ref(0);
const subject = ref('');
const planned = computed(() => selected.value?.clips?.some((clip) => clip.publication_ids?.length));
const validEditing = computed(() => validCaptionCues(captions.value, selected.value?.duration_ms));
const activeCaption = computed(() => captionsEnabled.value
    ? captions.value.find((cue) => cue.start_ms <= currentTime.value && cue.end_ms > currentTime.value)?.text : '');
const segmentPlan = computed(() => mode.value === 'duration'
    ? durationSegments(selected.value?.duration_ms, segmentSeconds.value, props.limits.max_clips)
    : manualSegments.value.map((segment) => ({ start_ms: Math.round(Number(segment.start) * 1000), end_ms: Math.round(Number(segment.end) * 1000) })));
const validPlan = computed(() => validVideoSegments(segmentPlan.value, selected.value?.duration_ms, props.limits.max_clips));
const completed = computed(() => (selected.value?.clips || []).filter((clip) => clip.status === 'ready').length);
const frameStyle = computed(() => ({
    aspectRatio: format.value === 'portrait' ? '9 / 16' : '16 / 9',
    maxWidth: format.value === 'portrait' ? '292.5px' : '100%',
}));
const objectStyle = computed(() => {
    const position = videoCropPosition(previewMotion.value ? cropPoints.value : [], currentTime.value, { x: focalX.value, y: focalY.value });
    return { objectPosition: `${position.x}% ${position.y}%` };
});
const fieldClass = 'w-full rounded-lg border-stone-300 bg-white text-sm text-stone-900 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100';
let timer;
let disposed = false;
const requests = new AbortController();

function message(exception) {
    return Object.values(exception?.response?.data?.errors || {}).flat()[0]
        || (exception?.response?.status === 413 ? label('upload_too_large') : label('request_failed'));
}

function replaceProject(project) {
    const index = projects.value.findIndex((item) => item.id === project.id);
    if (index === -1) projects.value.unshift(project);
    else projects.value[index] = project;
}

function hydrate() {
    const settings = selected.value?.settings || {};
    mode.value = settings.mode || 'duration';
    segmentSeconds.value = settings.segment_seconds || 60;
    format.value = settings.format || 'portrait';
    framing.value = settings.framing || 'crop';
    focalX.value = settings.focal_x ?? 50;
    focalY.value = settings.focal_y ?? 50;
    manualSegments.value = (settings.segments || [{ start_ms: 0, end_ms: Math.min(selected.value?.duration_ms || 0, 60000) }])
        .map((segment) => ({ start: segment.start_ms / 1000, end: segment.end_ms / 1000 }));
    activeSegment.value = null;
    previewError.value = false;
    confirmDelete.value = false;
    captions.value = (settings.captions || []).map((cue) => ({ ...cue }));
    captionsEnabled.value = settings.captions_enabled ?? false;
    captionStyle.value = settings.caption_style || 'white';
    captionPosition.value = settings.caption_position || 'bottom';
    cropPoints.value = (settings.crop_points || []).map((point) => ({ ...point }));
    previewMotion.value = true;
    currentTime.value = 0;
}
watch(() => [selectedId.value, selected.value?.preview_url], hydrate, { immediate: true });

function selectFile(candidate) {
    if (!canManage.value || uploading.value) return;
    error.value = '';
    if (!candidate || !/\.(mp4|mov|webm)$/i.test(candidate.name)) {
        error.value = label('invalid_file');
        return;
    }
    if (candidate.size > props.limits.max_upload_kb * 1024) {
        error.value = label('upload_too_large');
        return;
    }
    file.value = candidate;
}

async function upload() {
    if (!file.value || uploading.value) return;
    uploading.value = true;
    uploadProgress.value = 0;
    error.value = '';
    const source = file.value;
    try {
        const started = await axios.post(route('social.videos.uploads.store'), { name: source.name, size: source.size }, { signal: requests.signal });
        const projectId = started.data.project.id;
        replaceProject(started.data.project);
        selectedId.value = projectId;
        const chunkSize = props.limits.chunk_bytes || 1048576;
        for (let offset = 0; offset < source.size; offset += chunkSize) {
            const blob = source.slice(offset, offset + chunkSize);
            const data = new FormData();
            data.append('offset', offset);
            data.append('chunk', blob, 'chunk.bin');
            let response;
            for (let attempt = 0; attempt < 3; attempt++) {
                try {
                    response = await axios.post(route('social.videos.uploads.append', projectId), data, {
                        signal: requests.signal,
                        onUploadProgress: ({ loaded, total }) => { uploadProgress.value = Math.min(100, Math.round((offset + (total ? loaded / total * blob.size : 0)) / source.size * 100)); },
                    });
                    break;
                } catch (exception) {
                    if (disposed || attempt === 2 || (exception.response && exception.response.status < 500)) throw exception;
                }
            }
            replaceProject(response.data.project);
        }
        file.value = null;
    } catch (exception) { if (!disposed) error.value = message(exception); }
    finally { uploading.value = false; }
}

async function generate() {
    if (!validPlan.value || !validEditing.value || busy.value || saving.value) return;
    saving.value = true;
    error.value = '';
    try {
        const response = await axios.post(route('social.videos.render', selected.value.id), {
            mode: mode.value, segment_seconds: Number(segmentSeconds.value), segments: segmentPlan.value,
            format: format.value, framing: framing.value, focal_x: Number(focalX.value), focal_y: Number(focalY.value),
            captions: captions.value, captions_enabled: captionsEnabled.value,
            caption_style: captionStyle.value, caption_position: captionPosition.value, crop_points: cropPoints.value,
        }, { signal: requests.signal });
        replaceProject(response.data.project);
    } catch (exception) { if (!disposed) error.value = message(exception); }
    finally { saving.value = false; }
}

async function retry() {
    saving.value = true;
    error.value = '';
    try {
        const response = await axios.post(route('social.videos.retry', selected.value.id), {}, { signal: requests.signal });
        replaceProject(response.data.project);
    } catch (exception) { if (!disposed) error.value = message(exception); }
    finally { saving.value = false; }
}

async function runIntelligence(task, options = {}) {
    if (busy.value || saving.value) return;
    saving.value = true;
    error.value = '';
    try {
        const response = await axios.post(route('social.videos.intelligence', selected.value.id), { task, ...options }, { signal: requests.signal });
        replaceProject(response.data.project);
    } catch (exception) { if (!disposed) error.value = message(exception); }
    finally { saving.value = false; }
}
function applySuggestions() {
    mode.value = 'manual';
    manualSegments.value = selected.value.intelligence.suggestions.map((item) => ({ start: item.start_ms / 1000, end: item.end_ms / 1000 }));
}
function applyCaptions() {
    captions.value = selected.value.intelligence.captions.map((cue) => ({ ...cue }));
    captionsEnabled.value = true;
}
function applyFraming() {
    cropPoints.value = selected.value.intelligence.crop_points.map((point) => ({ ...point }));
    format.value = selected.value.intelligence.crop_format;
    framing.value = 'crop';
    previewMotion.value = true;
}

async function removeProject() {
    if (!confirmDelete.value) { confirmDelete.value = true; return; }
    saving.value = true;
    error.value = '';
    const id = selected.value.id;
    try {
        await axios.delete(route('social.videos.destroy', id), { signal: requests.signal });
        projects.value = projects.value.filter((project) => project.id !== id);
        selectedId.value = projects.value[0]?.id ?? null;
    } catch (exception) { if (!disposed) error.value = message(exception); }
    finally { saving.value = false; }
}

function addSegment() {
    const start = Number(manualSegments.value.at(-1)?.end || 0);
    if (start >= selected.value.duration_ms / 1000 || manualSegments.value.length >= props.limits.max_clips) return;
    manualSegments.value.push({ start, end: Math.min(start + 60, selected.value.duration_ms / 1000) });
}

async function previewSegment(segment) {
    activeSegment.value = segment;
    await nextTick();
    if (!video.value) return;
    video.value.currentTime = segment.start_ms / 1000;
    try { await video.value.play(); } catch { previewError.value = true; }
}
function checkEnd() {
    currentTime.value = Math.round((video.value?.currentTime || 0) * 1000);
    if (activeSegment.value && video.value?.currentTime >= activeSegment.value.end_ms / 1000) video.value.pause();
}
function seek(time) {
    activeSegment.value = null;
    if (video.value) { video.value.pause(); video.value.currentTime = time / 1000; }
    currentTime.value = time;
}
function addCropPoint() {
    const point = { time_ms: Math.round((video.value?.currentTime || 0) * 1000), x: Number(focalX.value), y: Number(focalY.value) };
    cropPoints.value = [...cropPoints.value.filter((item) => item.time_ms !== point.time_ms), point].sort((a, b) => a.time_ms - b.time_ms);
    previewMotion.value = true;
}
function suggestCuts() {
    const suggestions = captionSegments(captions.value, segmentSeconds.value, selected.value.duration_ms, props.limits.max_clips);
    if (!suggestions.length) { error.value = label('caption_cuts_failed'); return; }
    error.value = '';
    mode.value = 'manual';
    manualSegments.value = suggestions.map((segment) => ({ start: segment.start_ms / 1000, end: segment.end_ms / 1000 }));
}
function downloadSrt(clip) {
    const saved = selected.value.settings?.captions || [];
    const cues = clipVideoCaptions(saved, clip.start_ms, clip.end_ms);
    const url = URL.createObjectURL(new Blob([videoSrt(cues)], { type: 'application/x-subrip;charset=utf-8' }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `clip-${clip.position}.srt`;
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
function syncBackdrop(playing = false) {
    if (!backdrop.value || !video.value) return;
    backdrop.value.currentTime = video.value.currentTime;
    if (playing) backdrop.value.play().catch(() => {});
    else backdrop.value.pause();
}
async function poll() {
    if (disposed) return;
    if (projects.value.some(videoIsBusy)) {
        try {
            const response = await axios.get(route('social.videos.index'), { signal: requests.signal });
            projects.value = response.data.projects;
            pollingError.value = false;
        } catch { if (!disposed) pollingError.value = true; }
    }
    if (!disposed) timer = setTimeout(poll, 3000);
}
onMounted(() => { timer = setTimeout(poll, 3000); });
onUnmounted(() => { disposed = true; clearTimeout(timer); requests.abort(); });
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="label('title')" />
        <div class="space-y-6">
            <SocialWorkspaceHeader active-tab="videos" :title="label('title')" :description="label('description')" />
            <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950 dark:text-red-200">{{ error }}</p>
            <p v-if="pollingError" role="status" class="text-sm text-amber-700 dark:text-amber-300">{{ label('poll_failed') }}</p>
            <section v-if="canManage" class="flex flex-wrap items-center gap-4 rounded-xl border border-dashed border-sky-300 bg-sky-50/50 p-5 dark:border-sky-800 dark:bg-sky-950/20" @dragover.prevent @drop.prevent="selectFile($event.dataTransfer.files[0])">
                <Upload class="size-7 shrink-0 text-sky-600" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <label for="pulse-video-upload" class="block font-semibold text-stone-900 dark:text-neutral-100">{{ label('upload') }}</label>
                    <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ label('upload_hint', { size: Math.floor(limits.max_upload_kb / 1024), minutes: limits.max_duration_ms / 60000 }) }}</p>
                    <input id="pulse-video-upload" type="file" accept=".mp4,.mov,.webm" :disabled="uploading" class="mt-3 block max-w-full text-sm text-stone-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-sky-700 dark:text-neutral-200 dark:file:bg-neutral-800 dark:file:text-sky-300" @change="selectFile($event.target.files[0])" />
                    <p v-if="file" class="mt-2 truncate text-sm text-stone-700 dark:text-neutral-300">{{ file.name }}</p>
                    <div v-if="uploading" class="mt-2" role="status">
                        <progress class="w-full" max="100" :value="uploadProgress" :aria-label="label('upload_progress')" />
                        <span class="text-xs">{{ label('upload_progress') }} {{ uploadProgress }} %</span>
                    </div>
                </div>
                <PrimaryButton :disabled="!file || uploading" @click="upload">{{ uploading ? label('uploading') : label('import') }}</PrimaryButton>
            </section>

            <div v-if="!projects.length" class="rounded-xl border border-stone-200 p-10 text-center dark:border-neutral-700">
                <Film class="mx-auto size-10 text-stone-400" aria-hidden="true" />
                <p class="mt-3 font-medium text-stone-800 dark:text-neutral-100">{{ label('empty') }}</p>
                <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">{{ label('empty_hint') }}</p>
            </div>
            <div v-else class="grid min-w-0 gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
                <nav :aria-label="label('projects')" class="flex gap-2 overflow-x-auto lg:flex-col">
                    <button v-for="project in projects" :key="project.id" type="button" :aria-current="project.id === selectedId ? 'true' : undefined" class="min-w-[180px] rounded-xl border p-4 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-sky-500 lg:min-w-0" :class="project.id === selectedId ? 'border-sky-500 bg-sky-50 dark:bg-sky-950/30' : 'border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900'" @click="selectedId = project.id; error = ''">
                        <span class="block truncate font-medium text-stone-900 dark:text-neutral-100">{{ project.name }}</span>
                        <span class="mt-1 block text-xs text-stone-500 dark:text-neutral-400">{{ videoTime(project.duration_ms) }} · {{ label(`statuses.${project.status}`) }}</span>
                    </button>
                </nav>

                <section v-if="selected" class="min-w-0 space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="min-w-0 break-words text-lg font-semibold text-stone-900 dark:text-neutral-100">{{ selected.name }}</h2>
                        <div v-if="canManage && !busy && !uploading" class="flex flex-wrap gap-2">
                            <SecondaryButton v-if="confirmDelete" @click="confirmDelete = false">{{ label('cancel') }}</SecondaryButton>
                            <SecondaryButton :disabled="saving" @click="removeProject"><Trash2 class="mr-2 size-4" aria-hidden="true" />{{ label(confirmDelete ? 'confirm_delete' : 'delete') }}</SecondaryButton>
                        </div>
                    </div>
                    <p v-if="selected.status === 'uploading'" role="status" class="text-sm text-stone-600 dark:text-neutral-300">{{ uploading ? label('uploading') : label('incomplete_upload') }}</p>
                    <div v-else-if="['pending', 'processing'].includes(selected.status)" class="rounded-xl bg-sky-50 p-8 dark:bg-sky-950/30" role="status">
                        <div class="h-3 w-32 animate-pulse rounded bg-sky-200 dark:bg-sky-800" />
                        <p class="mt-4 text-sm text-sky-800 dark:text-sky-200">{{ label(selected.status === 'pending' ? 'queued' : 'preparing') }}</p>
                        <p class="mt-2 text-xs text-stone-600 dark:text-neutral-400">{{ label('background_hint') }}</p>
                    </div>
                    <div v-else-if="selected.status === 'failed'" class="space-y-3 rounded-xl bg-red-50 p-5 dark:bg-red-950/30" role="alert">
                        <p class="text-sm text-red-700 dark:text-red-200">{{ label(`errors.${selected.error_code || 'processing_failed'}`) }}</p>
                        <SecondaryButton v-if="canManage" :disabled="saving" @click="retry">{{ label('retry') }}</SecondaryButton>
                    </div>

                    <template v-else-if="selected.status === 'ready'">
                        <section v-if="canManage && !planned" class="space-y-3 rounded-xl border border-sky-200 bg-sky-50/50 p-5 dark:border-sky-900 dark:bg-sky-950/20">
                            <h3 class="font-medium text-stone-900 dark:text-neutral-100">{{ label('ai_title') }}</h3>
                            <p class="text-xs text-stone-600 dark:text-neutral-400">{{ label('ai_hint') }}</p>
                            <p v-if="!ai_available" class="text-sm text-amber-700 dark:text-amber-300">{{ label('ai_unavailable') }}</p>
                            <p v-if="['pending', 'processing'].includes(selected.intelligence_status)" role="status" class="animate-pulse text-sm text-sky-700 dark:text-sky-300">{{ label('ai_processing') }}</p>
                            <p v-if="selected.intelligence_status === 'failed'" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ label(`ai_errors.${selected.intelligence_error_code || 'intelligence_failed'}`) }}</p>
                            <div class="flex flex-wrap gap-2">
                                <SecondaryButton :disabled="!ai_available || busy || saving" @click="runIntelligence('transcribe')">{{ label('transcribe') }}</SecondaryButton>
                                <SecondaryButton v-if="selected.intelligence?.captions?.length" :disabled="busy || saving" @click="applyCaptions">{{ label('apply_captions') }}</SecondaryButton>
                                <SecondaryButton :disabled="!ai_available || busy || saving || !(selected.intelligence?.captions?.length || selected.settings?.captions?.length)" @click="runIntelligence('suggest', { seconds: Math.max(10, Math.min(300, Number(segmentSeconds) || 60)) })">{{ label('suggest_moments') }}</SecondaryButton>
                            </div>
                            <div v-if="selected.intelligence?.suggestions?.length" class="space-y-2">
                                <p v-for="(suggestion, index) in selected.intelligence.suggestions" :key="index" class="text-sm text-stone-700 dark:text-neutral-200"><strong>{{ videoTime(suggestion.start_ms) }} – {{ videoTime(suggestion.end_ms) }} · {{ suggestion.title }}</strong><span class="block text-xs text-stone-500 dark:text-neutral-400">{{ suggestion.reason }}</span></p>
                                <SecondaryButton :disabled="busy || saving" @click="applySuggestions">{{ label('apply_suggestions') }}</SecondaryButton>
                            </div>
                            <div class="flex flex-wrap items-end gap-3 border-t border-sky-200 pt-3 dark:border-sky-900">
                                <label class="min-w-0 flex-1 text-sm text-stone-700 dark:text-neutral-300">{{ label('subject') }}<input v-model="subject" maxlength="120" :disabled="busy || saving" :class="fieldClass" class="mt-1" :placeholder="label('subject_placeholder')" /></label>
                                <SecondaryButton :disabled="!ai_available || busy || saving || !subject.trim()" @click="runIntelligence('framing', { format, subject })">{{ label('suggest_framing') }}</SecondaryButton>
                                <SecondaryButton v-if="selected.intelligence?.crop_points?.length" :disabled="busy || saving" @click="applyFraming">{{ label('apply_framing') }}</SecondaryButton>
                                <p class="w-full text-xs text-stone-500 dark:text-neutral-400">{{ label('ai_framing_hint') }}</p>
                            </div>
                        </section>
                        <div class="grid gap-5 xl:grid-cols-2">
                            <div class="space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="relative mx-auto w-full overflow-hidden rounded-lg bg-black" :style="frameStyle">
                                    <video v-if="framing === 'blur'" ref="backdrop" :src="selected.preview_url" muted playsinline preload="metadata" aria-hidden="true" class="pointer-events-none absolute size-full scale-110 object-cover blur-xl" />
                                    <video :key="selected.id" ref="video" :src="selected.preview_url" controls playsinline preload="metadata" :aria-label="label('source_preview')" class="absolute inset-0 size-full" :class="framing === 'crop' ? 'object-cover' : 'object-contain'" :style="objectStyle" @error="previewError = true" @timeupdate="checkEnd" @play="syncBackdrop(true)" @pause="syncBackdrop(false)" @seeked="checkEnd(); syncBackdrop(!video?.paused)" />
                                    <div v-if="activeCaption" class="pointer-events-none absolute break-words left-[5%] right-[5%] rounded bg-black/75 px-3 py-2 text-center text-sm font-bold" :class="[captionPosition === 'top' ? 'top-[9%]' : 'bottom-[18%]', captionStyle === 'yellow' ? 'text-yellow-300' : 'text-white']">{{ activeCaption }}</div>
                                </div>
                                <p v-if="previewError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ label('preview_error') }}</p>
                                <p class="text-center text-xs text-stone-500 dark:text-neutral-400">{{ label('original_kept') }} · {{ videoTime(selected.duration_ms) }}</p>
                            </div>

                            <fieldset :disabled="!canManage || planned || busy || saving" class="min-w-0 space-y-4 rounded-xl border border-stone-200 p-5 disabled:opacity-70 dark:border-neutral-700">
                                <legend class="px-1 font-medium text-stone-900 dark:text-neutral-100">{{ label('settings') }}</legend>
                                <label class="block text-sm text-stone-700 dark:text-neutral-300">{{ label('format') }}
                                    <select v-model="format" :class="fieldClass" class="mt-1"><option value="portrait">{{ label('portrait') }}</option><option value="landscape">{{ label('landscape') }}</option></select>
                                </label>
                                <label class="block text-sm text-stone-700 dark:text-neutral-300">{{ label('framing') }}
                                    <select v-model="framing" :class="fieldClass" class="mt-1"><option value="crop">{{ label('crop') }}</option><option value="blur">{{ label('blur') }}</option></select>
                                </label>
                                <template v-if="framing === 'crop'">
                                    <label class="block text-sm text-stone-700 dark:text-neutral-300">{{ label('horizontal') }}<input v-model.number="focalX" type="range" min="0" max="100" class="mt-2 block w-full accent-sky-600" @input="previewMotion = false" /></label>
                                    <label class="block text-sm text-stone-700 dark:text-neutral-300">{{ label('vertical') }}<input v-model.number="focalY" type="range" min="0" max="100" class="mt-2 block w-full accent-sky-600" @input="previewMotion = false" /></label>
                                    <div class="space-y-2 border-t border-stone-200 pt-3 dark:border-neutral-700">
                                        <h3 class="text-sm font-medium text-stone-800 dark:text-neutral-100">{{ label('motion_title') }}</h3>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ label('motion_hint') }}</p>
                                        <SecondaryButton :disabled="cropPoints.length >= 60" @click="addCropPoint">{{ label('add_crop_point', { time: (currentTime / 1000).toFixed(2) }) }}</SecondaryButton>
                                        <label v-if="cropPoints.length" class="flex items-center gap-2 text-xs text-stone-700 dark:text-neutral-300"><input v-model="previewMotion" type="checkbox" class="rounded border-stone-300 text-sky-600" />{{ label('preview_motion') }}</label>
                                        <ol class="max-h-40 space-y-1 overflow-y-auto">
                                            <li v-for="(point, index) in cropPoints" :key="point.time_ms" class="flex items-center justify-between gap-2 text-xs text-stone-700 dark:text-neutral-300">
                                                <button type="button" class="rounded px-2 py-1 hover:bg-stone-100 dark:hover:bg-neutral-800" @click="seek(point.time_ms); focalX = point.x; focalY = point.y; previewMotion = true">{{ (point.time_ms / 1000).toFixed(2) }} s · {{ point.x }} % / {{ point.y }} %</button>
                                                <button type="button" class="p-2 text-red-600 dark:text-red-300" :aria-label="label('remove_crop_point', { number: index + 1 })" @click="cropPoints.splice(index, 1)"><Trash2 class="size-4" /></button>
                                            </li>
                                        </ol>
                                    </div>
                                </template>
                                <label class="block text-sm text-stone-700 dark:text-neutral-300">{{ label('cut_mode') }}
                                    <select v-model="mode" :class="fieldClass" class="mt-1"><option value="duration">{{ label('by_duration') }}</option><option value="manual">{{ label('manual') }}</option></select>
                                </label>
                                <label v-if="mode === 'duration'" class="block text-sm text-stone-700 dark:text-neutral-300">{{ label('seconds') }}<input v-model.number="segmentSeconds" type="number" min="1" max="300" step="1" :class="fieldClass" class="mt-1" /></label>
                            </fieldset>
                        </div>

                        <section class="space-y-4 rounded-xl border border-stone-200 p-5 dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="font-medium text-stone-900 dark:text-neutral-100">{{ label('captions_title') }}</h3>
                                <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-300"><input v-model="captionsEnabled" type="checkbox" :disabled="!canManage || planned || busy || saving" class="rounded border-stone-300 text-sky-600" />{{ label('burn_captions') }}</label>
                            </div>
                            <fieldset :disabled="!canManage || planned || busy || saving" class="grid gap-3 sm:grid-cols-2">
                                <label class="text-sm text-stone-700 dark:text-neutral-300">{{ label('caption_style') }}<select v-model="captionStyle" :class="fieldClass" class="mt-1"><option value="white">{{ label('caption_white') }}</option><option value="yellow">{{ label('caption_yellow') }}</option></select></label>
                                <label class="text-sm text-stone-700 dark:text-neutral-300">{{ label('caption_position') }}<select v-model="captionPosition" :class="fieldClass" class="mt-1"><option value="bottom">{{ label('caption_bottom') }}</option><option value="top">{{ label('caption_top') }}</option></select></label>
                            </fieldset>
                            <SocialVideoCaptionEditor :key="selected.id" v-model="captions" :duration="selected.duration_ms" :current-time="currentTime" :disabled="!canManage || planned || busy || saving" @seek="seek" />
                            <div v-if="captions.length && canManage" class="flex flex-wrap items-end gap-3 border-t border-stone-200 pt-4 dark:border-neutral-700">
                                <label class="text-xs text-stone-600 dark:text-neutral-300">{{ label('target_seconds') }}<input v-model.number="segmentSeconds" type="number" min="1" max="300" :disabled="busy || saving" :class="fieldClass" class="mt-1 max-w-40" /></label>
                                <SecondaryButton :disabled="busy || saving || !validEditing" @click="suggestCuts">{{ label('caption_cuts') }}</SecondaryButton>
                                <p class="w-full text-xs text-stone-500 dark:text-neutral-400">{{ label('caption_cuts_hint') }}</p>
                            </div>
                        </section>

                        <div class="space-y-4 rounded-xl border border-stone-200 p-5 dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="font-medium text-stone-900 dark:text-neutral-100">{{ label('clips', { count: segmentPlan.length }) }}</h3>
                                <SecondaryButton v-if="mode === 'manual' && canManage" :disabled="planned || busy || saving || manualSegments.length >= limits.max_clips" @click="addSegment">{{ label('add_clip') }}</SecondaryButton>
                            </div>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ label('segments_hint') }}</p>
                            <div v-if="mode === 'manual'" class="space-y-3">
                                <div v-for="(segment, index) in manualSegments" :key="index" class="flex flex-wrap items-end gap-3">
                                    <span class="self-center text-sm text-stone-500">{{ index + 1 }}.</span>
                                    <label class="min-w-0 flex-1 text-xs text-stone-600 dark:text-neutral-300">{{ label('start_seconds') }}<input v-model="segment.start" type="number" min="0" :max="selected.duration_ms / 1000" step="0.01" :disabled="!canManage || planned || busy || saving" :class="fieldClass" class="mt-1" /></label>
                                    <label class="min-w-0 flex-1 text-xs text-stone-600 dark:text-neutral-300">{{ label('end_seconds') }}<input v-model="segment.end" type="number" min="0" :max="selected.duration_ms / 1000" step="0.01" :disabled="!canManage || planned || busy || saving" :class="fieldClass" class="mt-1" /></label>
                                    <button v-if="canManage" type="button" :disabled="busy || saving" :aria-label="label('remove_clip', { number: index + 1 })" class="rounded p-2 text-red-600 disabled:opacity-40 dark:text-red-300" @click="manualSegments.splice(index, 1)"><Trash2 class="size-4" /></button>
                                </div>
                            </div>
                            <p v-if="!validPlan" role="status" class="text-sm text-amber-700 dark:text-amber-300">{{ label('invalid_segments') }}</p>
                            <div v-else class="flex flex-wrap gap-2">
                                <button v-for="(segment, index) in segmentPlan" :key="index" type="button" class="rounded-lg border border-stone-200 px-3 py-2 text-sm text-stone-700 hover:border-sky-500 dark:border-neutral-700 dark:text-neutral-200" @click="previewSegment(segment)">{{ index + 1 }} · {{ videoTime(segment.start_ms) }} – {{ videoTime(segment.end_ms) }}</button>
                            </div>
                            <PrimaryButton v-if="canManage" :disabled="planned || !validPlan || !validEditing || busy || saving" @click="generate"><Scissors class="mr-2 size-4" aria-hidden="true" />{{ label('generate') }}</PrimaryButton>
                            <p v-if="planned" class="text-sm text-stone-600 dark:text-neutral-300">{{ label('already_planned') }}</p>
                            <p v-if="selected.clips.length" class="text-xs text-stone-500 dark:text-neutral-400">{{ label('regenerate_hint') }}</p>
                        </div>

                        <section v-if="selected.clips.length" class="space-y-4">
                            <h3 class="font-medium text-stone-900 dark:text-neutral-100" role="status">{{ label('results', { ready: completed, total: selected.clips.length }) }}</h3>
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <article v-for="clip in selected.clips" :key="clip.id" class="min-w-0 space-y-3 rounded-xl border border-stone-200 p-3 dark:border-neutral-700">
                                    <div class="flex justify-between gap-2 text-sm text-stone-700 dark:text-neutral-200"><span>{{ label('clip_number', { number: clip.position }) }}</span><span>{{ videoTime(clip.end_ms - clip.start_ms) }}</span></div>
                                    <video v-if="clip.preview_url" :src="clip.preview_url" controls playsinline preload="metadata" :aria-label="label('clip_number', { number: clip.position })" class="max-h-80 w-full rounded-lg bg-black" :style="{ aspectRatio: clip.format === 'portrait' ? '9 / 16' : '16 / 9' }" />
                                    <div v-else class="flex min-h-24 items-center justify-center rounded-lg bg-stone-50 p-3 text-center text-sm dark:bg-neutral-800" :class="clip.status === 'failed' ? 'text-red-600 dark:text-red-300' : 'animate-pulse text-stone-600 dark:text-neutral-300'">{{ label(`statuses.${clip.status}`) }}</div>
                                    <a v-if="clip.preview_url" :href="clip.preview_url" :download="`clip-${clip.position}.mp4`" class="inline-block text-sm font-medium text-sky-700 hover:underline dark:text-sky-300">{{ label('download') }}</a>
                                    <button v-if="clip.preview_url && clipVideoCaptions(selected.settings?.captions || [], clip.start_ms, clip.end_ms).length" type="button" class="block text-sm font-medium text-sky-700 hover:underline dark:text-sky-300" @click="downloadSrt(clip)">{{ label('download_srt') }}</button>
                                </article>
                            </div>
                        </section>
                        <SocialVideoPublicationPlanner v-if="canManage && selected.clips.length" :key="selected.id" :project="selected" :accounts="connected_accounts" :timezone="timezone" :can-publish="access.can_publish" :ai-available="ai_available" :disabled="busy || saving" @updated="replaceProject" @generate-texts="runIntelligence('texts', { connection_ids: $event })" />
                    </template>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
