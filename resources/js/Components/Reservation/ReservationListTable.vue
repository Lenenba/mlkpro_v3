<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    CalendarClock,
    CalendarX2,
    CircleHelp,
    Eye,
    Globe2,
    ListChecks,
    LoaderCircle,
    Pencil,
    RefreshCw,
    Smartphone,
    Sparkles,
    Trash2,
    TriangleAlert,
    UserRoundCheck,
    Webhook,
} from 'lucide-vue-next';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import { DATA_TABLE_PER_PAGE_OPTIONS, normalizeDataTablePerPage } from '@/Components/DataTable/pagination';
import ReservationStatusBadge from '@/Components/Reservation/ReservationStatusBadge.vue';
import EntityAvatar from '@/Components/UI/EntityAvatar.vue';
import {
    reservationListCanDelete,
    reservationListCanEdit,
    reservationListCanView,
    reservationListClient,
    reservationListEntityName,
    reservationListImageSource,
    reservationListService,
    reservationListServiceName,
    reservationListSourceKey,
    reservationListQuickStatusAction,
    reservationListSecondaryStatusActions,
    reservationListSortColumn,
    reservationListSortDirection,
    reservationListSortValue,
    reservationListTeamMember,
} from '@/utils/reservationList';

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    links: {
        type: Array,
        default: () => [],
    },
    total: {
        type: Number,
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    showTeamMember: {
        type: Boolean,
        default: true,
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
    perPage: {
        type: Number,
        default: null,
    },
    paginationLabel: {
        type: String,
        default: '',
    },
    hasActiveFilters: {
        type: Boolean,
        default: false,
    },
    statusActionError: {
        type: String,
        default: '',
    },
    statusUpdatingId: {
        type: [Number, String],
        default: null,
    },
    isDateSort: {
        type: Boolean,
        default: false,
    },
    isDateSortAsc: {
        type: Boolean,
        default: true,
    },
    isStatusSort: {
        type: Boolean,
        default: false,
    },
    sort: {
        type: String,
        default: 'date_asc',
    },
});

const emit = defineEmits([
    'open',
    'edit',
    'delete',
    'retry',
    'clear-filters',
    'toggle-date-sort',
    'sort-status',
    'sort',
    'set-sort',
    'per-page',
    'transition-status',
]);

const { locale, t } = useI18n();
const failedServiceImages = ref(new Set());

watch(
    () => props.rows,
    () => {
        failedServiceImages.value = new Set();
    },
);

const sourceRows = computed(() => (Array.isArray(props.rows) ? props.rows : []));
const normalizedRows = computed(() => sourceRows.value.filter((reservation) => reservationListCanView(reservation)));
const displayedTotal = computed(() => (
    normalizedRows.value.length === sourceRows.value.length
        ? (props.total ?? normalizedRows.value.length)
        : normalizedRows.value.length
));
const normalizedLinks = computed(() => (Array.isArray(props.links) ? props.links : []));
const pageCount = computed(() => normalizedLinks.value
    .map((link) => String(link?.label ?? '').replace(/<[^>]*>/g, '').trim())
    .filter((label) => /^\d+$/u.test(label))
    .length);
const hasMobilePagination = computed(() => pageCount.value > 1);
const mobileSortColumn = computed(() => reservationListSortColumn(props.sort));
const mobileSortDirection = computed(() => reservationListSortDirection(props.sort));
const normalizedPerPage = computed(() => normalizeDataTablePerPage(props.perPage));

const localeCode = computed(() => String(locale.value || 'fr-CA'));
const safeTimezone = computed(() => {
    try {
        new Intl.DateTimeFormat(localeCode.value, { timeZone: props.timezone }).format();
        return props.timezone;
    } catch {
        return 'UTC';
    }
});

const validDate = (value) => {
    const date = value ? new Date(value) : null;

    return date && !Number.isNaN(date.getTime()) ? date : null;
};

const formatDate = (value) => {
    const date = validDate(value);

    if (!date) {
        return t('reservations.list.unavailable');
    }

    return new Intl.DateTimeFormat(localeCode.value, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: safeTimezone.value,
    }).format(date);
};

const formatTime = (value) => {
    const date = validDate(value);

    if (!date) {
        return t('reservations.list.unavailable');
    }

    return new Intl.DateTimeFormat(localeCode.value, {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: safeTimezone.value,
    }).format(date);
};

const formatTimeRange = (reservation) => {
    const start = formatTime(reservation?.starts_at || reservation?.start);
    const endValue = reservation?.ends_at || reservation?.end;

    return validDate(endValue) ? `${start} – ${formatTime(endValue)}` : start;
};

const reservationReference = (reservation) => t('reservations.list.reference', {
    id: reservation?.id || '—',
});

const serviceName = (reservation) => reservationListServiceName(
    reservation,
    t('reservations.details.service_fallback'),
);

const clientEntity = (reservation) => reservationListClient(reservation);
const teamMemberEntity = (reservation) => reservationListTeamMember(reservation);

const clientName = (reservation) => reservationListEntityName(
    clientEntity(reservation),
    reservation?.client_name || t('reservations.details.customer_fallback'),
);

const teamMemberName = (reservation) => reservationListEntityName(
    teamMemberEntity(reservation),
    reservation?.team_member_name || t('reservations.list.unassigned'),
);

const clientImage = (reservation) => reservationListImageSource(clientEntity(reservation));
const teamMemberImage = (reservation) => reservationListImageSource(teamMemberEntity(reservation));

const clientAvatarShape = (reservation) => {
    const client = clientEntity(reservation);
    const kind = String(client?.type || client?.customer_type || client?.client_type || '').toLowerCase();

    return ['company', 'organization', 'business'].some((value) => kind.includes(value))
        ? 'rounded'
        : 'circle';
};

const serviceImageKey = (reservation) => `${reservation?.id || 'reservation'}:${serviceImage(reservation)}`;
const serviceImage = (reservation) => reservationListImageSource(
    reservationListService(reservation),
    { requireImageFlag: true },
);
const showServiceImage = (reservation) => {
    const source = serviceImage(reservation);

    return Boolean(source) && !failedServiceImages.value.has(`${reservation?.id || 'reservation'}:${source}`);
};
const markServiceImageFailed = (reservation) => {
    const nextFailures = new Set(failedServiceImages.value);
    nextFailures.add(serviceImageKey(reservation));
    failedServiceImages.value = nextFailures;
};

const sourceKey = (reservation) => reservationListSourceKey(reservation);
const sourceLabel = (reservation) => t(`reservations.details.sources.${sourceKey(reservation)}`);
const sourceIcon = (reservation) => ({
    staff: UserRoundCheck,
    client: Smartphone,
    api: Webhook,
    public_booking: Globe2,
    unknown: CircleHelp,
})[sourceKey(reservation)] || CircleHelp;

const canEdit = (reservation) => reservationListCanEdit(reservation, props.canManage);
const canDelete = (reservation) => reservationListCanDelete(reservation, props.canManage);
const quickStatusAction = (reservation) => reservationListQuickStatusAction(reservation);
const secondaryStatusActions = (reservation) => reservationListSecondaryStatusActions(reservation);
const rowHasManagementActions = (reservation) => (
    canEdit(reservation)
    || canDelete(reservation)
    || secondaryStatusActions(reservation).length > 0
);
const statusActionLabel = (action) => action?.labelKey
    ? t(`reservations.actions.${action.labelKey}`)
    : '';
const isStatusUpdating = (reservation) => Number(props.statusUpdatingId) === Number(reservation?.id);
const transitionStatus = (reservation, action) => {
    if (!action?.status || isStatusUpdating(reservation)) {
        return;
    }

    emit('transition-status', reservation, action.status);
};
const openReservation = (reservation) => {
    if (reservationListCanView(reservation)) {
        emit('open', reservation);
    }
};

const openLabel = (reservation) => t('reservations.list.open', {
    service: serviceName(reservation),
    client: clientName(reservation),
});

const actionsLabel = (reservation) => t('reservations.list.actions_for', {
    reference: reservationReference(reservation),
});

const isColumnSort = (column) => {
    const value = String(props.sort || '');

    return value === column || value.startsWith(`${column}_`);
};

const isColumnSortAscending = (column) => {
    const value = String(props.sort || '');

    return isColumnSort(column) && value !== `${column}_desc`;
};

const columnAriaSort = (column) => {
    if (!isColumnSort(column)) {
        return 'none';
    }

    return isColumnSortAscending(column) ? 'ascending' : 'descending';
};

const setMobileSortColumn = (event) => {
    emit('set-sort', reservationListSortValue(event?.target?.value, mobileSortDirection.value));
};

const setMobileSortDirection = (direction) => {
    emit('set-sort', reservationListSortValue(mobileSortColumn.value, direction));
};

const setMobilePerPage = (event) => {
    emit('per-page', normalizeDataTablePerPage(event?.target?.value, normalizedPerPage.value));
};
</script>

<template>
    <section
        data-reservation-list-table
        class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
        aria-labelledby="reservation-list-title"
    >
        <header class="flex flex-col gap-3 border-b border-stone-200 px-4 py-4 dark:border-neutral-700 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="flex min-w-0 items-start gap-3">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" aria-hidden="true">
                    <ListChecks class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="reservation-list-title" class="text-base font-semibold text-stone-900 dark:text-white">
                            {{ $t('reservations.list.title') }}
                        </h2>
                        <span class="reservation-list-count">
                            {{ $t('reservations.list.count', { count: displayedTotal }) }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('reservations.list.subtitle') }}
                    </p>
                </div>
            </div>
            <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true" />
                {{ $t('reservations.list.timezone', { timezone: safeTimezone }) }}
            </span>
        </header>

        <div
            v-if="statusActionError"
            class="mx-4 mt-4 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200 sm:mx-5"
            role="alert"
        >
            {{ statusActionError }}
        </div>

        <div
            v-if="error"
            class="reservation-list-error"
            role="alert"
        >
            <div class="flex items-start gap-3">
                <TriangleAlert class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                <div>
                    <p class="font-semibold">{{ $t('reservations.list.error_title') }}</p>
                    <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">{{ error }}</p>
                </div>
            </div>
            <button
                type="button"
                class="reservation-list-error__retry"
                @click="emit('retry')"
            >
                <RefreshCw class="h-4 w-4" aria-hidden="true" />
                {{ $t('reservations.list.retry') }}
            </button>
        </div>

        <div
            v-else
            class="relative"
            :aria-busy="loading ? 'true' : 'false'"
        >
        <div
            v-if="loading && !normalizedRows.length"
            class="p-4 sm:p-5"
            role="status"
            aria-live="polite"
        >
            <div class="flex items-center gap-2 text-sm font-medium text-stone-600 dark:text-neutral-300">
                <LoaderCircle class="h-4 w-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                {{ $t('reservations.list.loading') }}
            </div>
            <div class="mt-4 grid gap-3" aria-hidden="true">
                <div
                    v-for="index in 4"
                    :key="`reservation-list-skeleton-${index}`"
                    class="h-20 animate-pulse rounded-lg bg-stone-100 motion-reduce:animate-none dark:bg-neutral-800"
                />
            </div>
        </div>

        <div v-else-if="!normalizedRows.length" class="p-4 sm:p-5">
            <div class="flex flex-col items-center rounded-xl border border-dashed border-stone-300 bg-stone-50 px-5 py-10 text-center dark:border-neutral-700 dark:bg-neutral-950">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white text-stone-500 shadow-sm ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-neutral-300 dark:ring-neutral-700" aria-hidden="true">
                    <CalendarX2 class="h-6 w-6" />
                </span>
                <h3 class="mt-4 text-sm font-semibold text-stone-900 dark:text-white">
                    {{ $t('reservations.list.empty_title') }}
                </h3>
                <p class="mt-1 max-w-md text-sm text-stone-500 dark:text-neutral-400">
                    {{ hasActiveFilters ? $t('reservations.list.empty_filtered') : $t('reservations.list.empty_description') }}
                </p>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="mt-4 inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400"
                    @click="emit('clear-filters')"
                >
                    <RefreshCw class="h-4 w-4" aria-hidden="true" />
                    {{ $t('reservations.actions.clear_filters') }}
                </button>
            </div>
        </div>

        <template v-else>
            <div
                data-reservation-mobile-toolbar
                class="border-b border-stone-200 bg-stone-50 px-3 py-3 dark:border-neutral-700 dark:bg-neutral-950 lg:hidden"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                    {{ $t('reservations.list.mobile_controls') }}
                </p>
                <div class="reservation-list-mobile-grid">
                    <label class="reservation-list-mobile-grid__primary">
                        <span>{{ $t('reservations.list.sort_by') }}</span>
                        <select
                            :value="mobileSortColumn"
                            class="reservation-list-mobile-select reservation-list-mobile-select--full"
                            data-testid="reservation-mobile-sort-column"
                            @change="setMobileSortColumn"
                        >
                            <option value="date">{{ $t('reservations.table.when') }}</option>
                            <option value="service">{{ $t('reservations.table.item') }}</option>
                            <option value="client">{{ $t('reservations.table.customer') }}</option>
                            <option v-if="showTeamMember" value="team_member">{{ $t('planning.form.member') }}</option>
                            <option value="status">{{ $t('reservations.table.status') }}</option>
                        </select>
                    </label>

                    <div
                        class="reservation-list-mobile-field"
                        role="group"
                        :aria-label="$t('reservations.list.sort_direction')"
                    >
                        <span class="text-xs font-medium text-stone-600 dark:text-neutral-300">
                            {{ $t('reservations.list.sort_direction') }}
                        </span>
                        <span class="inline-flex min-h-10 rounded-lg border border-stone-300 bg-white p-0.5 dark:border-neutral-600 dark:bg-neutral-900">
                            <button
                                type="button"
                                class="min-w-10 rounded-md px-2.5 py-1.5 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                :class="mobileSortDirection === 'asc'
                                    ? 'bg-emerald-600 text-white'
                                    : 'text-stone-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                :aria-label="$t('reservations.list.sort_ascending')"
                                :aria-pressed="mobileSortDirection === 'asc'"
                                data-testid="reservation-mobile-sort-asc"
                                @click="setMobileSortDirection('asc')"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                class="min-w-10 rounded-md px-2.5 py-1.5 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                :class="mobileSortDirection === 'desc'
                                    ? 'bg-emerald-600 text-white'
                                    : 'text-stone-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                :aria-label="$t('reservations.list.sort_descending')"
                                :aria-pressed="mobileSortDirection === 'desc'"
                                data-testid="reservation-mobile-sort-desc"
                                @click="setMobileSortDirection('desc')"
                            >
                                ↓
                            </button>
                        </span>
                    </div>

                    <label class="reservation-list-mobile-field">
                        <span>{{ $t('reservations.list.rows_per_page') }}</span>
                        <select
                            :value="normalizedPerPage"
                            class="reservation-list-mobile-select"
                            data-testid="reservation-mobile-per-page"
                            @change="setMobilePerPage"
                        >
                            <option
                                v-for="option in DATA_TABLE_PER_PAGE_OPTIONS"
                                :key="`reservation-mobile-per-page-${option}`"
                                :value="option"
                            >
                                {{ option }}
                            </option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="hidden lg:block">
                <AdminDataTable
                    embedded
                    :rows="normalizedRows"
                    :links="normalizedLinks"
                    :show-pagination="normalizedRows.length > 0"
                    show-per-page
                    :per-page="perPage"
                    container-class="reservation-list-desktop-table"
                >
                    <template #head>
                        <tr>
                            <th scope="col" class="reservation-list-column reservation-list-column--date" :aria-sort="columnAriaSort('date')">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-1.5 px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-stone-600 transition-colors hover:text-stone-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500 dark:text-neutral-300 dark:hover:text-white"
                                    :aria-pressed="isDateSort"
                                    @click="emit('toggle-date-sort')"
                                >
                                    {{ $t('reservations.table.when') }}
                                    <svg
                                        class="h-3.5 w-3.5 transition-all"
                                        :class="[
                                            isDateSort ? 'opacity-100' : 'reservation-list-sort-inactive',
                                            isDateSort && isDateSortAsc ? 'rotate-180' : '',
                                        ]"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        aria-hidden="true"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="reservation-list-column reservation-list-column--service" :aria-sort="columnAriaSort('service')">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-1.5 px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-stone-600 transition-colors hover:text-stone-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500 dark:text-neutral-300 dark:hover:text-white"
                                    :aria-pressed="isColumnSort('service')"
                                    @click="emit('sort', 'service')"
                                >
                                    {{ $t('reservations.table.item') }}
                                    <svg
                                        class="h-3.5 w-3.5 transition-all"
                                        :class="[
                                            isColumnSort('service') ? 'opacity-100' : 'reservation-list-sort-inactive',
                                            isColumnSortAscending('service') ? 'rotate-180' : '',
                                        ]"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        aria-hidden="true"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="reservation-list-column reservation-list-column--client" :aria-sort="columnAriaSort('client')">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-1.5 px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-stone-600 transition-colors hover:text-stone-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500 dark:text-neutral-300 dark:hover:text-white"
                                    :aria-pressed="isColumnSort('client')"
                                    @click="emit('sort', 'client')"
                                >
                                    {{ $t('reservations.table.customer') }}
                                    <svg
                                        class="h-3.5 w-3.5 transition-all"
                                        :class="[
                                            isColumnSort('client') ? 'opacity-100' : 'reservation-list-sort-inactive',
                                            isColumnSortAscending('client') ? 'rotate-180' : '',
                                        ]"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        aria-hidden="true"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th v-if="showTeamMember" scope="col" class="reservation-list-column reservation-list-column--team" :aria-sort="columnAriaSort('team_member')">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-1.5 px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-stone-600 transition-colors hover:text-stone-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500 dark:text-neutral-300 dark:hover:text-white"
                                    :aria-pressed="isColumnSort('team_member')"
                                    @click="emit('sort', 'team_member')"
                                >
                                    {{ $t('planning.form.member') }}
                                    <svg
                                        class="h-3.5 w-3.5 transition-all"
                                        :class="[
                                            isColumnSort('team_member') ? 'opacity-100' : 'reservation-list-sort-inactive',
                                            isColumnSortAscending('team_member') ? 'rotate-180' : '',
                                        ]"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        aria-hidden="true"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-36 px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide">
                                {{ $t('reservations.table.source') }}
                            </th>
                            <th scope="col" class="min-w-36" :aria-sort="columnAriaSort('status')">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-1.5 px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-stone-600 transition-colors hover:text-stone-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500 dark:text-neutral-300 dark:hover:text-white"
                                    :aria-pressed="isStatusSort"
                                    @click="emit('sort-status')"
                                >
                                    {{ $t('reservations.table.status') }}
                                    <svg
                                        class="h-3.5 w-3.5 transition-all"
                                        :class="[
                                            isStatusSort ? 'opacity-100' : 'reservation-list-sort-inactive',
                                            isColumnSortAscending('status') ? 'rotate-180' : '',
                                        ]"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        aria-hidden="true"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="w-16 px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide">
                                <span class="sr-only">{{ $t('reservations.table.actions') }}</span>
                            </th>
                        </tr>
                    </template>

                    <template #row="{ row: reservation }">
                        <tr>
                            <td class="px-4 py-3 align-middle">
                                <button
                                    type="button"
                                    class="reservation-list-date"
                                    :aria-label="openLabel(reservation)"
                                    @click="openReservation(reservation)"
                                >
                                    <span class="reservation-list-date__primary">
                                        <CalendarClock class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                                        <span class="truncate">{{ formatDate(reservation.starts_at || reservation.start) }}</span>
                                    </span>
                                    <span class="reservation-list-date__time">
                                        {{ formatTimeRange(reservation) }}
                                    </span>
                                </button>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <button
                                    type="button"
                                    class="reservation-list-service"
                                    :aria-label="openLabel(reservation)"
                                    @click="openReservation(reservation)"
                                >
                                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-stone-200 bg-stone-100 text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                        <img
                                            v-if="showServiceImage(reservation)"
                                            :src="serviceImage(reservation)"
                                            :alt="$t('reservations.list.service_image_alt', { service: serviceName(reservation) })"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                            @error="markServiceImageFailed(reservation)"
                                        />
                                        <Sparkles v-else class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-stone-900 dark:text-white">{{ serviceName(reservation) }}</span>
                                        <span class="mt-0.5 block truncate text-xs text-stone-500 dark:text-neutral-400">{{ reservationReference(reservation) }}</span>
                                    </span>
                                </button>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <div class="reservation-list-person reservation-list-person--client">
                                    <EntityAvatar
                                        :src="clientImage(reservation)"
                                        :name="clientName(reservation)"
                                        :alt="clientName(reservation)"
                                        :shape="clientAvatarShape(reservation)"
                                        size="sm"
                                    />
                                    <span class="min-w-0 truncate text-sm font-medium text-stone-700 dark:text-neutral-200" :title="clientName(reservation)">
                                        {{ clientName(reservation) }}
                                    </span>
                                </div>
                            </td>
                            <td v-if="showTeamMember" class="px-4 py-3 align-middle">
                                <div class="reservation-list-person reservation-list-person--team">
                                    <EntityAvatar
                                        :src="teamMemberImage(reservation)"
                                        :name="teamMemberName(reservation)"
                                        :alt="teamMemberName(reservation)"
                                        size="sm"
                                    />
                                    <span class="min-w-0 truncate text-sm text-stone-600 dark:text-neutral-300" :title="teamMemberName(reservation)">
                                        {{ teamMemberName(reservation) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <span class="inline-flex max-w-36 items-center gap-1.5 rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                                    <component :is="sourceIcon(reservation)" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ sourceLabel(reservation) }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <div class="flex flex-col items-start gap-1.5">
                                    <ReservationStatusBadge :status="reservation.status" size="sm" />
                                    <span
                                        v-if="reservation.outcome_review_required_at"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[0.6875rem] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-200"
                                        :title="$t('reservations.outcome_review.description')"
                                    >
                                        <TriangleAlert class="h-3 w-3" aria-hidden="true" />
                                        {{ $t('reservations.outcome_review.badge') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-end align-middle">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        v-if="quickStatusAction(reservation)"
                                        type="button"
                                        class="inline-flex min-h-9 max-w-36 items-center justify-center rounded-sm border px-3 py-1.5 text-xs font-semibold transition disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-900"
                                        :class="quickStatusAction(reservation).destructive
                                            ? 'border-rose-200 bg-white text-rose-700 hover:bg-rose-50 focus-visible:ring-rose-500 dark:border-rose-500/30 dark:bg-neutral-900 dark:text-rose-300 dark:hover:bg-rose-500/10'
                                            : 'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-500 dark:border-emerald-500 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400'"
                                        :disabled="isStatusUpdating(reservation)"
                                        :data-testid="`reservation-primary-action-${reservation.id}`"
                                        @click="transitionStatus(reservation, quickStatusAction(reservation))"
                                    >
                                        <span class="truncate">{{ statusActionLabel(quickStatusAction(reservation)) }}</span>
                                    </button>

                                    <AdminDataTableActions
                                        :label="actionsLabel(reservation)"
                                        menu-width-class="w-48"
                                        :trigger-test-id="`reservation-actions-trigger-${reservation.id}`"
                                        :menu-test-id="`reservation-actions-menu-${reservation.id}`"
                                    >
                                        <button
                                            type="button"
                                            class="reservation-list-action"
                                            @click="openReservation(reservation)"
                                        >
                                            <Eye class="h-4 w-4 text-stone-500 dark:text-neutral-400" aria-hidden="true" />
                                            {{ $t('reservations.actions.view') }}
                                        </button>
                                        <button
                                            v-if="canEdit(reservation)"
                                            type="button"
                                            class="reservation-list-action"
                                            @click="emit('edit', reservation)"
                                        >
                                            <Pencil class="h-4 w-4 text-stone-500 dark:text-neutral-400" aria-hidden="true" />
                                            {{ $t('reservations.actions.edit') }}
                                        </button>
                                        <div
                                            v-if="secondaryStatusActions(reservation).length || canDelete(reservation)"
                                            class="my-1 border-t border-stone-200 dark:border-neutral-700"
                                        />
                                        <button
                                            v-for="action in secondaryStatusActions(reservation)"
                                            :key="`reservation-status-action-${reservation.id}-${action.status}`"
                                            type="button"
                                            class="reservation-list-action"
                                            :class="action.destructive ? 'reservation-list-action--delete' : ''"
                                            :disabled="isStatusUpdating(reservation)"
                                            @click="transitionStatus(reservation, action)"
                                        >
                                            {{ statusActionLabel(action) }}
                                        </button>
                                        <div
                                            v-if="canDelete(reservation) && secondaryStatusActions(reservation).length"
                                            class="my-1 border-t border-stone-200 dark:border-neutral-700"
                                        />
                                        <button
                                            v-if="canDelete(reservation)"
                                            type="button"
                                            class="reservation-list-action reservation-list-action--delete"
                                            @click="emit('delete', reservation)"
                                        >
                                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                                            {{ $t('reservations.actions.delete') }}
                                        </button>
                                    </AdminDataTableActions>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <template #pagination_prefix>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ paginationLabel }}
                        </div>
                    </template>
                </AdminDataTable>
            </div>

            <div class="space-y-3 p-3 lg:hidden">
                <article
                    v-for="reservation in normalizedRows"
                    :key="`reservation-mobile-${reservation.id}`"
                    class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-start justify-between gap-3 border-b border-stone-100 px-3.5 py-3 dark:border-neutral-800">
                        <button
                            type="button"
                            class="min-w-0 rounded-md text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-900"
                            :aria-label="openLabel(reservation)"
                            @click="openReservation(reservation)"
                        >
                            <span class="block truncate text-sm font-semibold text-stone-900 dark:text-white">
                                {{ formatDate(reservation.starts_at || reservation.start) }}
                            </span>
                            <span class="mt-0.5 block text-xs font-medium tabular-nums text-stone-500 dark:text-neutral-400">
                                {{ formatTimeRange(reservation) }}
                            </span>
                        </button>
                        <div class="flex flex-col items-end gap-1.5">
                            <ReservationStatusBadge :status="reservation.status" size="sm" />
                            <span
                                v-if="reservation.outcome_review_required_at"
                                class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[0.6875rem] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-200"
                            >
                                <TriangleAlert class="h-3 w-3" aria-hidden="true" />
                                {{ $t('reservations.outcome_review.badge') }}
                            </span>
                        </div>
                    </div>

                    <div class="p-3.5">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-lg text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-900"
                            :aria-label="openLabel(reservation)"
                            @click="openReservation(reservation)"
                        >
                            <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-stone-200 bg-stone-100 text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                <img
                                    v-if="showServiceImage(reservation)"
                                    :src="serviceImage(reservation)"
                                    :alt="$t('reservations.list.service_image_alt', { service: serviceName(reservation) })"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                    @error="markServiceImageFailed(reservation)"
                                />
                                <Sparkles v-else class="h-6 w-6" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-base font-semibold text-stone-900 dark:text-white">{{ serviceName(reservation) }}</span>
                                <span class="mt-0.5 block truncate text-xs text-stone-500 dark:text-neutral-400">{{ reservationReference(reservation) }}</span>
                            </span>
                        </button>

                        <dl class="mt-4 grid gap-3" :class="showTeamMember ? 'grid-cols-2' : 'grid-cols-1'">
                            <div class="min-w-0 rounded-lg bg-stone-50 p-2.5 dark:bg-neutral-800">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('reservations.table.customer') }}
                                </dt>
                                <dd class="mt-2 flex min-w-0 items-center gap-2">
                                    <EntityAvatar
                                        :src="clientImage(reservation)"
                                        :name="clientName(reservation)"
                                        :alt="clientName(reservation)"
                                        :shape="clientAvatarShape(reservation)"
                                        size="sm"
                                    />
                                    <span class="min-w-0 truncate text-xs font-medium text-stone-700 dark:text-neutral-200">{{ clientName(reservation) }}</span>
                                </dd>
                            </div>
                            <div v-if="showTeamMember" class="min-w-0 rounded-lg bg-stone-50 p-2.5 dark:bg-neutral-800">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('planning.form.member') }}
                                </dt>
                                <dd class="mt-2 flex min-w-0 items-center gap-2">
                                    <EntityAvatar
                                        :src="teamMemberImage(reservation)"
                                        :name="teamMemberName(reservation)"
                                        :alt="teamMemberName(reservation)"
                                        size="sm"
                                    />
                                    <span class="min-w-0 truncate text-xs text-stone-600 dark:text-neutral-300">{{ teamMemberName(reservation) }}</span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <footer class="flex items-center justify-between gap-3 border-t border-stone-100 bg-stone-50 px-3.5 py-2.5 dark:border-neutral-800 dark:bg-neutral-950">
                        <span class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium text-stone-500 dark:text-neutral-400">
                            <component :is="sourceIcon(reservation)" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span class="truncate">{{ sourceLabel(reservation) }}</span>
                        </span>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <button
                                v-if="quickStatusAction(reservation)"
                                type="button"
                                class="inline-flex min-h-9 max-w-32 items-center justify-center rounded-sm border px-3 py-1.5 text-xs font-semibold transition disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-950"
                                :class="quickStatusAction(reservation).destructive
                                    ? 'border-rose-200 bg-white text-rose-700 hover:bg-rose-50 focus-visible:ring-rose-500 dark:border-rose-500/30 dark:bg-neutral-900 dark:text-rose-300 dark:hover:bg-rose-500/10'
                                    : 'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-500 dark:border-emerald-500 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400'"
                                :disabled="isStatusUpdating(reservation)"
                                @click="transitionStatus(reservation, quickStatusAction(reservation))"
                            >
                                <span class="truncate">{{ statusActionLabel(quickStatusAction(reservation)) }}</span>
                            </button>
                            <button
                                v-else
                                type="button"
                                class="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400 dark:focus-visible:ring-offset-neutral-950"
                                @click="openReservation(reservation)"
                            >
                                <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ $t('reservations.actions.view') }}
                            </button>
                            <AdminDataTableActions
                                v-if="rowHasManagementActions(reservation) || quickStatusAction(reservation)"
                                :label="actionsLabel(reservation)"
                                menu-width-class="w-48"
                            >
                                <button
                                    v-if="quickStatusAction(reservation)"
                                    type="button"
                                    class="reservation-list-action"
                                    @click="openReservation(reservation)"
                                >
                                    <Eye class="h-4 w-4 text-stone-500 dark:text-neutral-400" aria-hidden="true" />
                                    {{ $t('reservations.actions.view') }}
                                </button>
                                <button
                                    v-if="canEdit(reservation)"
                                    type="button"
                                    class="reservation-list-action"
                                    @click="emit('edit', reservation)"
                                >
                                    <Pencil class="h-4 w-4 text-stone-500 dark:text-neutral-400" aria-hidden="true" />
                                    {{ $t('reservations.actions.edit') }}
                                </button>
                                <div
                                    v-if="secondaryStatusActions(reservation).length"
                                    class="my-1 border-t border-stone-200 dark:border-neutral-700"
                                />
                                <button
                                    v-for="action in secondaryStatusActions(reservation)"
                                    :key="`reservation-mobile-status-action-${reservation.id}-${action.status}`"
                                    type="button"
                                    class="reservation-list-action"
                                    :class="action.destructive ? 'reservation-list-action--delete' : ''"
                                    :disabled="isStatusUpdating(reservation)"
                                    @click="transitionStatus(reservation, action)"
                                >
                                    {{ statusActionLabel(action) }}
                                </button>
                                <div
                                    v-if="canDelete(reservation) && (canEdit(reservation) || secondaryStatusActions(reservation).length)"
                                    class="my-1 border-t border-stone-200 dark:border-neutral-700"
                                />
                                <button
                                    v-if="canDelete(reservation)"
                                    type="button"
                                    class="reservation-list-action reservation-list-action--delete"
                                    @click="emit('delete', reservation)"
                                >
                                    <Trash2 class="h-4 w-4" aria-hidden="true" />
                                    {{ $t('reservations.actions.delete') }}
                                </button>
                            </AdminDataTableActions>
                        </div>
                    </footer>
                </article>

                <div v-if="paginationLabel || hasMobilePagination" class="flex flex-col gap-3 border-t border-stone-200 px-1 pt-4 dark:border-neutral-700">
                    <p v-if="paginationLabel" class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ paginationLabel }}
                    </p>
                    <AdminPaginationLinks v-if="hasMobilePagination" :links="normalizedLinks" />
                </div>
            </div>
        </template>

        <div
            v-if="loading && normalizedRows.length"
            class="reservation-list-loading-overlay"
            role="status"
            aria-live="polite"
            data-reservation-list-loading-overlay
        >
            <LoaderCircle class="h-4 w-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
            {{ $t('reservations.list.loading') }}
        </div>
        </div>
    </section>
</template>

<style scoped>
.reservation-list-count {
    display: inline-flex;
    border-radius: 0.125rem;
    background: rgb(245 245 244);
    padding: 0.125rem 0.5rem;
    color: rgb(87 83 78);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1rem;
    font-variant-numeric: tabular-nums;
}

:global(.dark) .reservation-list-count {
    background: rgb(38 38 38);
    color: rgb(212 212 212);
}

.reservation-list-error {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
    margin: 1rem;
    border: 1px solid rgb(254 205 211);
    border-radius: 0.125rem;
    background: rgb(255 241 242);
    padding: 1.25rem;
    color: rgb(159 18 57);
}

:global(.dark) .reservation-list-error {
    border-color: rgb(136 19 55);
    background: rgb(76 5 25);
    color: rgb(254 205 211);
}

.reservation-list-error__retry {
    display: inline-flex;
    min-height: 2.5rem;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    border: 1px solid rgb(253 164 175);
    border-radius: 0.125rem;
    background: white;
    padding: 0.5rem 0.75rem;
    color: rgb(190 18 60);
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.25rem;
    transition: background-color 150ms ease, border-color 150ms ease;
}

.reservation-list-error__retry:hover {
    background: rgb(255 228 230);
}

.reservation-list-error__retry:focus-visible,
.reservation-list-mobile-select:focus-visible,
.reservation-list-date:focus-visible,
.reservation-list-service:focus-visible,
.reservation-list-action:focus-visible {
    outline: 2px solid transparent;
    outline-offset: 2px;
    box-shadow: 0 0 0 2px rgb(16 185 129), 0 0 0 4px white;
}

.reservation-list-error__retry:focus-visible,
.reservation-list-action--delete:focus-visible {
    box-shadow: 0 0 0 2px rgb(244 63 94), 0 0 0 4px white;
}

:global(.dark) .reservation-list-error__retry {
    border-color: rgb(159 18 57);
    background: rgb(76 5 25);
    color: rgb(254 205 211);
}

:global(.dark) .reservation-list-error__retry:hover {
    background: rgb(136 19 55);
}

.reservation-list-mobile-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.reservation-list-mobile-grid__primary {
    display: grid;
    min-width: 0;
    grid-column: span 2 / span 2;
    gap: 0.25rem;
    color: rgb(87 83 78);
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1rem;
}

.reservation-list-mobile-field {
    display: grid;
    gap: 0.25rem;
    color: rgb(87 83 78);
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1rem;
}

:global(.dark) .reservation-list-mobile-grid__primary,
:global(.dark) .reservation-list-mobile-field {
    color: rgb(212 212 212);
}

.reservation-list-mobile-select {
    min-height: 2.5rem;
    border: 1px solid rgb(214 211 209);
    border-radius: 0.125rem;
    background: white;
    padding: 0.5rem 0.75rem;
    color: rgb(41 37 36);
    font-size: 0.875rem;
    line-height: 1.25rem;
}

.reservation-list-mobile-select--full {
    width: 100%;
}

.reservation-list-mobile-select:focus-visible {
    border-color: rgb(16 185 129);
}

:global(.dark) .reservation-list-mobile-select {
    border-color: rgb(82 82 82);
    background: rgb(23 23 23);
    color: rgb(245 245 245);
}

.reservation-list-column--date {
    min-width: 11rem;
}

.reservation-list-column--service {
    min-width: 15rem;
}

.reservation-list-column--client {
    min-width: 13rem;
}

.reservation-list-column--team {
    min-width: 12rem;
}

.reservation-list-sort-inactive {
    opacity: 0.35;
}

.reservation-list-date {
    display: block;
    max-width: 11rem;
    border-radius: 0.125rem;
    text-align: start;
}

.reservation-list-date__primary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: rgb(28 25 23);
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.25rem;
}

.reservation-list-date:hover .reservation-list-date__primary {
    color: rgb(4 120 87);
}

.reservation-list-date__time {
    display: block;
    margin-top: 0.25rem;
    padding-left: 1.5rem;
    color: rgb(120 113 108);
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1rem;
    font-variant-numeric: tabular-nums;
}

:global(.dark) .reservation-list-date__primary {
    color: white;
}

:global(.dark) .reservation-list-date:hover .reservation-list-date__primary {
    color: rgb(110 231 183);
}

:global(.dark) .reservation-list-date__time {
    color: rgb(163 163 163);
}

.reservation-list-service {
    display: flex;
    max-width: 15rem;
    align-items: center;
    gap: 0.75rem;
    border-radius: 0.125rem;
    text-align: start;
}

.reservation-list-person {
    display: flex;
    align-items: center;
    gap: 0.625rem;
}

.reservation-list-person--client {
    max-width: 13rem;
}

.reservation-list-person--team {
    max-width: 12rem;
}

.reservation-list-action {
    display: flex;
    min-height: 2.25rem;
    width: 100%;
    align-items: center;
    gap: 0.625rem;
    border-radius: 0.125rem;
    padding: 0.5rem 0.625rem;
    color: rgb(41 37 36);
    font-size: 0.8125rem;
    font-weight: 500;
    line-height: 1.25rem;
    transition: background-color 150ms ease, color 150ms ease;
}

.reservation-list-action:hover {
    background: rgb(245 245 244);
}

.reservation-list-action--delete {
    color: rgb(225 29 72);
}

.reservation-list-action--delete:hover {
    background: rgb(255 241 242);
}

:global(.dark) .reservation-list-action {
    color: rgb(229 229 229);
}

:global(.dark) .reservation-list-action:hover {
    background: rgb(38 38 38);
}

:global(.dark) .reservation-list-action--delete {
    color: rgb(253 164 175);
}

:global(.dark) .reservation-list-action--delete:hover {
    background: rgb(76 5 25);
}

.reservation-list-loading-overlay {
    pointer-events: none;
    position: absolute;
    inset: 0 0 auto;
    z-index: 20;
    display: flex;
    min-height: 2.75rem;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    border-bottom: 1px solid rgb(167 243 208);
    background: rgb(255 255 255 / 0.95);
    padding: 0.5rem 1rem;
    color: rgb(6 95 70);
    font-size: 0.875rem;
    font-weight: 500;
    line-height: 1.25rem;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.06);
}

:global(.dark) .reservation-list-loading-overlay {
    border-color: rgb(6 78 59);
    background: rgb(23 23 23 / 0.95);
    color: rgb(167 243 208);
}

@media (min-width: 640px) {
    .reservation-list-error {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        margin: 1.25rem;
    }

    .reservation-list-mobile-grid {
        grid-template-columns: minmax(0, 1fr) auto auto;
        align-items: end;
    }

    .reservation-list-mobile-grid__primary {
        grid-column: span 1 / span 1;
    }
}

:deep(.reservation-list-desktop-table) {
    padding: 0;
}

:deep(.reservation-list-desktop-table > div:last-child) {
    margin-left: 1.25rem;
    margin-right: 1.25rem;
    padding-bottom: 1rem;
}

:deep(.reservation-list-desktop-table .admin-data-table__table tbody > tr > td) {
    border-bottom: 1px solid rgb(231 229 228 / 0.8);
}

:deep(.dark .reservation-list-desktop-table .admin-data-table__table tbody > tr > td) {
    border-bottom-color: rgb(64 64 64 / 0.8);
}
</style>
