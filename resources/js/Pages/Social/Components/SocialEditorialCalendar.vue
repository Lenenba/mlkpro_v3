<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialPlatformLogo from '@/Pages/Social/Components/SocialPlatformLogo.vue';

const props = defineProps({
    initialPosts: {
        type: Array,
        default: () => ([]),
    },
    initialSummary: {
        type: Object,
        default: () => ({}),
    },
    initialAccess: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const normalizePosts = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
    can_publish: Boolean(payload?.can_publish),
    can_submit_for_approval: Boolean(payload?.can_submit_for_approval),
    can_approve: Boolean(payload?.can_approve),
});

const posts = ref(normalizePosts(props.initialPosts));
const summary = ref(normalizeSummary(props.initialSummary));
const access = ref(normalizeAccess(props.initialAccess));
const viewMode = ref('week');
const anchorDate = ref(new Date());
const selectedDayKey = ref('');
const activePostId = ref(null);
const rescheduleInputs = ref({});
const busyPostId = ref(null);
const isLoading = ref(false);
const error = ref('');
const info = ref('');

const dayFormatter = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const monthFormatter = new Intl.DateTimeFormat(undefined, {
    month: 'long',
    year: 'numeric',
});
const timeFormatter = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
});

const toDate = (value) => {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
};

const startOfDay = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
const addDays = (date, amount) => {
    const next = new Date(date);
    next.setDate(next.getDate() + amount);

    return next;
};
const addMonths = (date, amount) => new Date(date.getFullYear(), date.getMonth() + amount, 1);
const dateKey = (date) => {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${year}-${month}-${day}`;
};
const startOfWeek = (date) => {
    const start = startOfDay(date);
    const day = start.getDay() || 7;
    start.setDate(start.getDate() - day + 1);

    return start;
};
const calendarDateFor = (post) => toDate(post?.calendar_at || post?.scheduled_for || post?.published_at || post?.updated_at);
const localInputValue = (value) => {
    const date = toDate(value);
    if (!date) {
        return '';
    }

    const offset = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
};
const suggestedScheduleInput = () => {
    const next = new Date();
    next.setDate(next.getDate() + 1);
    next.setMinutes(0, 0, 0);
    next.setHours(Math.max(next.getHours(), 9));

    return localInputValue(next.toISOString());
};

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const todayKey = computed(() => dateKey(new Date()));
const visibleStart = computed(() => {
    if (viewMode.value === 'month') {
        const first = new Date(anchorDate.value.getFullYear(), anchorDate.value.getMonth(), 1);

        return startOfWeek(first);
    }

    return startOfWeek(anchorDate.value);
});
const visibleDays = computed(() => {
    const days = [];
    const count = viewMode.value === 'month' ? 42 : 7;

    for (let index = 0; index < count; index += 1) {
        const date = addDays(visibleStart.value, index);
        days.push({
            key: dateKey(date),
            date,
            isToday: dateKey(date) === todayKey.value,
            isCurrentMonth: date.getMonth() === anchorDate.value.getMonth(),
        });
    }

    return days;
});
const visibleTitle = computed(() => {
    if (viewMode.value === 'month') {
        return monthFormatter.format(anchorDate.value);
    }

    const first = visibleDays.value[0]?.date;
    const last = visibleDays.value[visibleDays.value.length - 1]?.date;

    return first && last
        ? `${dayFormatter.format(first)} - ${dayFormatter.format(last)}`
        : monthFormatter.format(anchorDate.value);
});
const postsByDay = computed(() => {
    const grouped = new Map();

    posts.value.forEach((post) => {
        const date = calendarDateFor(post);
        if (!date) {
            return;
        }

        const key = dateKey(date);
        const bucket = grouped.get(key) || [];
        bucket.push(post);
        grouped.set(key, bucket);
    });

    grouped.forEach((bucket) => {
        bucket.sort((left, right) => {
            const leftDate = calendarDateFor(left)?.getTime() || 0;
            const rightDate = calendarDateFor(right)?.getTime() || 0;

            return leftDate - rightDate;
        });
    });

    return grouped;
});
const selectedDayPosts = computed(() => postsByDay.value.get(selectedDayKey.value) || []);
const activePost = computed(() => {
    const selected = selectedDayPosts.value.find((post) => Number(post?.id) === Number(activePostId.value));

    return selected || selectedDayPosts.value[0] || null;
});
const calendarSummary = computed(() => {
    const base = {
        draft: 0,
        scheduled: Number(summary.value.scheduled || 0),
        approval: Number(summary.value.pending_approval || 0),
        published: Number(summary.value.published || 0),
        attention: Number(summary.value.attention || 0),
    };

    posts.value.forEach((post) => {
        const bucket = String(post?.calendar_bucket || 'draft');
        if (bucket === 'draft') {
            base.draft += 1;
        }
    });

    return base;
});
const summaryItems = computed(() => ([
    {
        key: 'draft',
        label: t('social.calendar_manager.summary.draft'),
        value: calendarSummary.value.draft,
    },
    {
        key: 'scheduled',
        label: t('social.calendar_manager.summary.scheduled'),
        value: calendarSummary.value.scheduled,
    },
    {
        key: 'approval',
        label: t('social.calendar_manager.summary.approval'),
        value: calendarSummary.value.approval,
    },
    {
        key: 'published',
        label: t('social.calendar_manager.summary.published'),
        value: calendarSummary.value.published,
    },
]));

watch(() => props.initialPosts, (value) => {
    posts.value = normalizePosts(value);
}, { deep: true });

watch(() => props.initialSummary, (value) => {
    summary.value = normalizeSummary(value);
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

watch(visibleDays, (days) => {
    if (!days.length) {
        selectedDayKey.value = '';
        return;
    }

    if (!selectedDayKey.value || !days.some((day) => day.key === selectedDayKey.value)) {
        const today = days.find((day) => day.isToday);
        selectedDayKey.value = today?.key || days[0].key;
    }
}, { immediate: true });

watch(selectedDayPosts, (value) => {
    if (!value.some((post) => Number(post?.id) === Number(activePostId.value))) {
        activePostId.value = value[0]?.id || null;
    }
}, { immediate: true });

watch(posts, (value) => {
    const next = { ...rescheduleInputs.value };

    value.forEach((post) => {
        if (!post?.can_reschedule || next[post.id]) {
            return;
        }

        next[post.id] = localInputValue(post.scheduled_for) || suggestedScheduleInput();
    });

    rescheduleInputs.value = next;
}, { deep: true, immediate: true });

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const refreshFromPayload = (payload) => {
    if (Array.isArray(payload?.calendar_posts)) {
        posts.value = normalizePosts(payload.calendar_posts);
    }

    if (payload?.summary) {
        summary.value = normalizeSummary(payload.summary);
    }
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('social.calendar'));
        refreshFromPayload(response.data);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.calendar_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const setMode = (mode) => {
    viewMode.value = mode;
};
const moveWindow = (direction) => {
    anchorDate.value = viewMode.value === 'month'
        ? addMonths(anchorDate.value, direction)
        : addDays(anchorDate.value, direction * 7);
};
const goToday = () => {
    anchorDate.value = new Date();
    selectedDayKey.value = todayKey.value;
};
const selectDay = (day) => {
    selectedDayKey.value = day.key;
};
const selectPost = (post) => {
    activePostId.value = post?.id || null;
};
const openComposer = (post) => {
    if (!post?.id) {
        return;
    }

    router.visit(route('social.composer', { draft: post.id }));
};

const reschedulePost = async (post, clearSchedule = false) => {
    if (!canManage.value || !post?.can_reschedule) {
        return;
    }

    busyPostId.value = post.id;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.put(route('social.posts.reschedule', post.id), {
            scheduled_for: clearSchedule ? null : String(rescheduleInputs.value[post.id] || '').trim(),
        });

        refreshFromPayload(response.data);
        info.value = String(response.data?.message || t('social.calendar_manager.messages.reschedule_success'));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.calendar_manager.messages.reschedule_error'));
    } finally {
        busyPostId.value = null;
    }
};

const formatDay = (date) => dayFormatter.format(date);
const formatTime = (post) => {
    const date = calendarDateFor(post);

    return date ? timeFormatter.format(date) : t('social.calendar_manager.empty_value');
};
const postTitle = (post) => {
    const text = String(post?.text || '').trim();
    if (text !== '') {
        return text.length > 92 ? `${text.slice(0, 89)}...` : text;
    }

    const source = String(post?.source_label || '').trim();
    if (source !== '') {
        return source;
    }

    const cta = String(post?.link_cta_label || '').trim();
    if (cta !== '') {
        return cta;
    }

    return t('social.calendar_manager.untitled_post');
};
const targetSummary = (post) => {
    const count = Number(post?.selected_accounts_count || post?.targets?.length || 0);

    return t('social.calendar_manager.target_count', { count });
};
const statusLabel = (post) => t(`social.composer_manager.statuses.${post?.status || 'draft'}`);
const bucketClass = (bucket) => {
    if (bucket === 'scheduled') {
        return 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200';
    }

    if (bucket === 'approval') {
        return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200';
    }

    if (bucket === 'published') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200';
    }

    if (bucket === 'attention') {
        return 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200';
    }

    if (bucket === 'publishing') {
        return 'border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-200';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-200';
};
const dayCellClass = (day) => {
    if (day.key === selectedDayKey.value) {
        return 'border-sky-400 bg-sky-50 dark:border-sky-500/60 dark:bg-sky-500/10';
    }

    if (!day.isCurrentMonth && viewMode.value === 'month') {
        return 'border-stone-200 bg-stone-50/70 dark:border-neutral-800 dark:bg-neutral-900/50';
    }

    return 'border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900';
};
const dayPostLimit = computed(() => viewMode.value === 'month' ? 3 : 5);
</script>

<template>
    <div class="space-y-5">
        <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ visibleTitle }}
                    </h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="item in summaryItems"
                            :key="item.key"
                            class="rounded-md bg-stone-100 px-3 py-1 text-xs font-medium text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                        >
                            {{ item.label }}: {{ item.value }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <SecondaryButton type="button" :disabled="isLoading" @click="moveWindow(-1)">
                        {{ t('social.calendar_manager.actions.previous') }}
                    </SecondaryButton>
                    <SecondaryButton type="button" :disabled="isLoading" @click="goToday">
                        {{ t('social.calendar_manager.actions.today') }}
                    </SecondaryButton>
                    <SecondaryButton type="button" :disabled="isLoading" @click="moveWindow(1)">
                        {{ t('social.calendar_manager.actions.next') }}
                    </SecondaryButton>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm font-medium transition"
                        :class="viewMode === 'week'
                            ? 'border-sky-500 bg-sky-50 text-sky-700 dark:border-sky-500/60 dark:bg-sky-500/10 dark:text-sky-200'
                            : 'border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        @click="setMode('week')"
                    >
                        {{ t('social.calendar_manager.actions.week') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm font-medium transition"
                        :class="viewMode === 'month'
                            ? 'border-sky-500 bg-sky-50 text-sky-700 dark:border-sky-500/60 dark:bg-sky-500/10 dark:text-sky-200'
                            : 'border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        @click="setMode('month')"
                    >
                        {{ t('social.calendar_manager.actions.month') }}
                    </button>
                    <SecondaryButton type="button" :disabled="isLoading" @click="load">
                        {{ t('social.calendar_manager.actions.reload') }}
                    </SecondaryButton>
                </div>
            </div>
        </section>

        <div
            v-if="!canManage"
            class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.calendar_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.calendar_manager.read_only_description') }}</div>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
        >
            {{ error }}
        </div>

        <div
            v-if="info"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
        >
            {{ info }}
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),360px]">
            <section class="rounded-md border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="hidden grid-cols-7 gap-2 px-1 pb-2 text-xs font-semibold uppercase text-stone-400 dark:text-neutral-500 md:grid">
                    <div v-for="day in visibleDays.slice(0, 7)" :key="`head-${day.key}`">
                        {{ day.date.toLocaleDateString(undefined, { weekday: 'short' }) }}
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 md:grid-cols-7">
                    <div
                        v-for="day in visibleDays"
                        :key="day.key"
                        class="min-h-[150px] rounded-md border p-2 transition"
                        :class="dayCellClass(day)"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 rounded-md px-1 py-1 text-left transition hover:bg-white/70 dark:hover:bg-neutral-800/70"
                            @click="selectDay(day)"
                        >
                            <span
                                class="text-sm font-semibold"
                                :class="day.isToday ? 'text-sky-700 dark:text-sky-300' : 'text-stone-900 dark:text-neutral-100'"
                            >
                                {{ formatDay(day.date) }}
                            </span>
                            <span
                                v-if="postsByDay.get(day.key)?.length"
                                class="rounded-full bg-stone-900 px-2 py-0.5 text-xs font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900"
                            >
                                {{ postsByDay.get(day.key).length }}
                            </span>
                        </button>

                        <div v-if="postsByDay.get(day.key)?.length" class="mt-2 space-y-2">
                            <button
                                v-for="post in postsByDay.get(day.key).slice(0, dayPostLimit)"
                                :key="post.id"
                                type="button"
                                class="block w-full rounded-md border px-2 py-2 text-left text-xs transition hover:border-sky-300"
                                :class="bucketClass(post.calendar_bucket)"
                                @click="selectDay(day); selectPost(post)"
                            >
                                <span class="block font-semibold">{{ formatTime(post) }}</span>
                                <span class="mt-1 block line-clamp-2 leading-4">{{ postTitle(post) }}</span>
                            </button>

                            <div
                                v-if="postsByDay.get(day.key).length > dayPostLimit"
                                class="px-1 text-xs text-stone-500 dark:text-neutral-400"
                            >
                                {{ t('social.calendar_manager.more_posts', {
                                    count: postsByDay.get(day.key).length - dayPostLimit,
                                }) }}
                            </div>
                        </div>

                        <div v-else class="mt-3 px-1 text-xs text-stone-400 dark:text-neutral-500">
                            {{ t('social.calendar_manager.empty_day') }}
                        </div>
                    </div>
                </div>
            </section>

            <aside class="space-y-4">
                <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.calendar_manager.day_detail_title') }}
                        </h3>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ selectedDayPosts.length }}
                        </span>
                    </div>

                    <div v-if="selectedDayPosts.length" class="mt-3 space-y-2">
                        <button
                            v-for="post in selectedDayPosts"
                            :key="post.id"
                            type="button"
                            class="w-full rounded-md border px-3 py-2 text-left text-sm transition"
                            :class="Number(activePost?.id) === Number(post.id)
                                ? 'border-sky-400 bg-sky-50 dark:border-sky-500/60 dark:bg-sky-500/10'
                                : 'border-stone-200 hover:border-sky-300 dark:border-neutral-700 dark:hover:border-sky-500/40'"
                            @click="selectPost(post)"
                        >
                            <span class="block font-semibold text-stone-900 dark:text-neutral-100">
                                {{ postTitle(post) }}
                            </span>
                            <span class="mt-1 block text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatTime(post) }} · {{ statusLabel(post) }}
                            </span>
                        </button>
                    </div>

                    <div v-else class="mt-3 rounded-md border border-dashed border-stone-300 bg-stone-50 px-4 py-5 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400">
                        {{ t('social.calendar_manager.empty_selection') }}
                    </div>
                </section>

                <section
                    v-if="activePost"
                    class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="bucketClass(activePost.calendar_bucket)">
                                {{ statusLabel(activePost) }}
                            </span>
                            <h3 class="mt-3 text-base font-semibold leading-6 text-stone-900 dark:text-neutral-100">
                                {{ postTitle(activePost) }}
                            </h3>
                            <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ targetSummary(activePost) }}
                            </p>
                        </div>
                        <img
                            v-if="activePost.image_url"
                            :src="activePost.image_url"
                            :alt="t('social.calendar_manager.preview_image_alt')"
                            class="h-16 w-16 rounded-md object-cover"
                        >
                    </div>

                    <div v-if="activePost.targets?.length" class="mt-4 flex flex-wrap gap-2">
                        <span
                            v-for="target in activePost.targets"
                            :key="target.id"
                            class="inline-flex items-center gap-1 rounded-md bg-stone-100 px-2.5 py-1 text-xs text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                        >
                            <SocialPlatformLogo :platform="target.platform" class="h-3.5 w-3.5" />
                            {{ target.label || target.platform || t('social.calendar_manager.empty_value') }}
                        </span>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <SecondaryButton type="button" @click="openComposer(activePost)">
                            {{ t('social.calendar_manager.actions.open_composer') }}
                        </SecondaryButton>
                    </div>

                    <div
                        v-if="canManage && activePost.can_reschedule"
                        class="mt-4 rounded-md border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/60"
                    >
                        <FloatingInput
                            v-model="rescheduleInputs[activePost.id]"
                            type="datetime-local"
                            :label="t('social.calendar_manager.fields.scheduled_for')"
                            :disabled="busyPostId === activePost.id || isLoading"
                        />

                        <div class="mt-3 flex flex-wrap gap-2">
                            <PrimaryButton
                                type="button"
                                :disabled="busyPostId === activePost.id || isLoading"
                                @click="reschedulePost(activePost)"
                            >
                                {{ t('social.calendar_manager.actions.reschedule') }}
                            </PrimaryButton>
                            <SecondaryButton
                                v-if="activePost.scheduled_for"
                                type="button"
                                :disabled="busyPostId === activePost.id || isLoading"
                                @click="reschedulePost(activePost, true)"
                            >
                                {{ t('social.calendar_manager.actions.clear_schedule') }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <p
                        v-else-if="activePost.is_queued_publication"
                        class="mt-4 rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                    >
                        {{ t('social.calendar_manager.queued_notice') }}
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
