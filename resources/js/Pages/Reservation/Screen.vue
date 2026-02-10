<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import dayjs from 'dayjs';
import 'dayjs/locale/fr';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';

const { t, locale } = useI18n();
const dayjsLocale = computed(() => (String(locale.value || '').toLowerCase().startsWith('fr') ? 'fr' : 'en'));

const props = defineProps({
    queue: {
        type: Object,
        default: () => ({
            stats: { waiting: 0, called: 0, in_service: 0 },
            now_serving: null,
            up_next: null,
            waiting: [],
            total_active: 0,
            generated_at: null,
        }),
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
    settings: {
        type: Object,
        default: () => ({
            queue_mode_enabled: false,
            business_preset: 'service_general',
            queue_grace_minutes: 5,
        }),
    },
    screen: {
        type: Object,
        default: () => ({
            anonymize_clients: true,
        }),
    },
});

const queueData = ref({ ...(props.queue || {}) });
const loading = ref(false);
const error = ref('');
const nowLabel = ref(dayjs().toISOString());
let refreshTimer = null;

const queueModeEnabled = computed(() => Boolean(props.settings?.queue_mode_enabled));
const anonymizeClients = computed(() => Boolean(props.screen?.anonymize_clients));
const presetLabel = computed(() => t(`settings.reservations.presets.${props.settings?.business_preset || 'service_general'}`));

const statusBadgeClass = (status) => reservationStatusBadgeClass(status);
const formatDateTime = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('DD MMM HH:mm') : '-');
const formatNow = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('HH:mm:ss') : '-');

const refreshScreen = async () => {
    loading.value = true;
    error.value = '';
    nowLabel.value = dayjs().toISOString();

    try {
        const response = await axios.get(route('reservation.screen.data'), {
            params: {
                anonymize: anonymizeClients.value ? 1 : 0,
            },
        });
        queueData.value = response?.data?.queue || {};
        nowLabel.value = response?.data?.fetched_at || nowLabel.value;
    } catch (err) {
        error.value = err?.response?.data?.message || t('reservations.queue.screen.refresh_error');
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    refreshTimer = setInterval(() => {
        refreshScreen();
    }, 10000);
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});
</script>

<template>
    <Head :title="$t('reservations.queue.screen.title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('reservations.queue.screen.title') }}
                        </h1>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('reservations.queue.screen.subtitle') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-cyan-100 px-2 py-0.5 text-xs font-semibold text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300">
                            {{ presetLabel }}
                        </span>
                        <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ $t('reservations.queue.screen.updated_at') }}: {{ formatNow(nowLabel) }}
                        </span>
                        <Link
                            :href="route('reservation.screen', { anonymize: anonymizeClients ? 0 : 1 })"
                            class="rounded-sm border border-stone-300 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ anonymizeClients ? $t('reservations.queue.screen.show_names') : $t('reservations.queue.screen.hide_names') }}
                        </Link>
                        <button
                            type="button"
                            class="rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
                            :disabled="loading"
                            @click="refreshScreen"
                        >
                            {{ loading ? $t('planning.filters.loading') : $t('reservations.queue.screen.refresh') }}
                        </button>
                    </div>
                </div>
                <div v-if="error" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    {{ error }}
                </div>
            </section>

            <section
                v-if="!queueModeEnabled"
                class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
            >
                {{ $t('reservations.queue.screen.disabled') }}
            </section>

            <template v-else>
                <section class="grid gap-3 lg:grid-cols-3">
                    <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.now_serving') }}</p>
                        <template v-if="queueData.now_serving">
                            <p class="mt-2 text-2xl font-bold text-stone-900 dark:text-neutral-100">
                                {{ queueData.now_serving.queue_number }}
                            </p>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-300">
                                {{ queueData.now_serving.display_client_name }} · {{ queueData.now_serving.service_name }}
                            </p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ queueData.now_serving.team_member_name }}
                            </p>
                            <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(queueData.now_serving.status)">
                                {{ $t(`reservations.queue.status.${queueData.now_serving.status}`) || queueData.now_serving.status }}
                            </span>
                        </template>
                        <p v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">-</p>
                    </article>

                    <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.up_next') }}</p>
                        <template v-if="queueData.up_next">
                            <p class="mt-2 text-2xl font-bold text-stone-900 dark:text-neutral-100">
                                {{ queueData.up_next.queue_number }}
                            </p>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-300">
                                {{ queueData.up_next.display_client_name }} · {{ queueData.up_next.service_name }}
                            </p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.queue.columns.position') }}: {{ queueData.up_next.position ?? '-' }}
                                · ETA {{ queueData.up_next.eta_minutes !== null && queueData.up_next.eta_minutes !== undefined ? `${queueData.up_next.eta_minutes} min` : '-' }}
                            </p>
                        </template>
                        <p v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">-</p>
                    </article>

                    <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.overview') }}</p>
                        <div class="mt-2 space-y-1 text-sm text-stone-700 dark:text-neutral-200">
                            <div>{{ $t('reservations.queue.cards.waiting') }}: {{ queueData.stats?.waiting || 0 }}</div>
                            <div>{{ $t('reservations.queue.cards.called') }}: {{ queueData.stats?.called || 0 }}</div>
                            <div>{{ $t('reservations.queue.cards.in_service') }}: {{ queueData.stats?.in_service || 0 }}</div>
                            <div>{{ $t('reservations.queue.screen.total_active') }}: {{ queueData.total_active || 0 }}</div>
                        </div>
                    </article>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.queue.screen.waiting_list') }}</h2>
                    <div v-if="!(queueData.waiting || []).length" class="mt-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                        {{ $t('reservations.queue.empty') }}
                    </div>
                    <div v-else class="mt-3 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <div class="min-w-full inline-block align-middle">
                            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                                <thead>
                                    <tr>
                                        <th scope="col" class="min-w-28">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.queue.columns.ticket') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-40">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.customer') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-40">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.item') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-40">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('planning.form.member') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-32">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.queue.columns.position') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-32">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.status') }}
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <tr
                                        v-for="item in (queueData.waiting || [])"
                                        :key="`screen-waiting-${item.id}`"
                                    >
                                        <td class="size-px whitespace-nowrap px-4 py-2 font-medium text-stone-700 dark:text-neutral-200">{{ item.queue_number }}</td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ item.display_client_name }}</td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ item.service_name }}</td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ item.team_member_name }}</td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ item.position ?? '-' }}
                                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                                · ETA {{ item.eta_minutes !== null && item.eta_minutes !== undefined ? `${item.eta_minutes} min` : '-' }}
                                            </span>
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2">
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(item.status)">
                                                {{ $t(`reservations.queue.status.${item.status}`) || item.status }}
                                            </span>
                                            <div v-if="item.call_expires_at" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                                {{ $t('reservations.queue.screen.call_expires') }}: {{ formatDateTime(item.call_expires_at) }}
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
