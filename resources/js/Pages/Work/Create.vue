<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, watch, ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import dayjs from 'dayjs';
import { buildOccurrenceDates, buildPreviewEvents } from '@/utils/schedule';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import FloatingNumberMiniInput from '@/Components/FloatingNumberMiniInput.vue';
import SelectableItem from '@/Components/SelectableItem.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import DatePicker from '@/Components/DatePicker.vue';
import TimePicker from '@/Components/TimePicker.vue';
import Checkbox from '@/Components/Checkbox.vue';
import ProductTableList from '@/Components/ProductTableList.vue';
import InputError from '@/Components/InputError.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    works: Object,
    tasks: {
        type: Array,
        default: () => [],
    },
    work: Object,
    customer: Object,
    lastWorkNumber: String,
    teamMembers: Array,
    lockedFromQuote: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const { t } = useI18n();
const companyName = computed(() => page.props.auth?.account?.company?.name || t('jobs.company_fallback'));
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);
const customerLabel = computed(() => {
    const label = props.customer?.company_name
        || `${props.customer?.first_name || ''} ${props.customer?.last_name || ''}`.trim();
    return label || t('jobs.form.customer_fallback');
});

const Frequence = computed(() => [
    { id: 'Daily', name: t('jobs.recurrence.frequency.daily') },
    { id: 'Weekly', name: t('jobs.recurrence.frequency.weekly') },
    { id: 'Monthly', name: t('jobs.recurrence.frequency.monthly') },
    { id: 'Yearly', name: t('jobs.recurrence.frequency.yearly') },
]);

const endOptions = computed(() => [
    { id: 'Never', name: t('jobs.recurrence.ends.never') },
    { id: 'On', name: t('jobs.recurrence.ends.on') },
    { id: 'After', name: t('jobs.recurrence.ends.after') },
]);

const statusOptions = computed(() => [
    { id: 'to_schedule', name: t('jobs.status.to_schedule') },
    { id: 'scheduled', name: t('jobs.status.scheduled') },
    { id: 'en_route', name: t('jobs.status.en_route') },
    { id: 'in_progress', name: t('jobs.status.in_progress') },
    { id: 'tech_complete', name: t('jobs.status.tech_complete') },
    { id: 'pending_review', name: t('jobs.status.pending_review') },
    { id: 'validated', name: t('jobs.status.validated') },
    { id: 'auto_validated', name: t('jobs.status.auto_validated') },
    { id: 'dispute', name: t('jobs.status.dispute') },
    { id: 'closed', name: t('jobs.status.closed') },
    { id: 'cancelled', name: t('jobs.status.cancelled') },
    { id: 'completed', name: t('jobs.status.completed') },
]);

const billingModes = computed(() => [
    { id: 'per_task', name: t('jobs.billing.modes.per_task') },
    { id: 'per_segment', name: t('jobs.billing.modes.per_segment') },
    { id: 'end_of_job', name: t('jobs.billing.modes.end_of_job') },
    { id: 'deferred', name: t('jobs.billing.modes.deferred') },
]);

const billingGroupings = computed(() => [
    { id: 'single', name: t('jobs.billing.groupings.single') },
    { id: 'periodic', name: t('jobs.billing.groupings.periodic') },
]);

const billingCycles = computed(() => [
    { id: 'weekly', name: t('jobs.billing.cycles.weekly') },
    { id: 'biweekly', name: t('jobs.billing.cycles.biweekly') },
    { id: 'monthly', name: t('jobs.billing.cycles.monthly') },
    { id: 'every_n_tasks', name: t('jobs.billing.cycles.every_n_tasks') },
]);

const toIsoDate = (dateInput) => {
    if (!dateInput) {
        return '';
    }
    if (typeof dateInput === 'string') {
        const match = dateInput.match(/^\d{4}-\d{2}-\d{2}/);
        if (match) {
            return match[0];
        }
    }
    const date = dayjs(dateInput);
    return date.isValid() ? date.format('YYYY-MM-DD') : '';
};

const defaultStartDate = toIsoDate(props.work?.start_date) || dayjs().format('YYYY-MM-DD');

const form = useForm({
    customer_id: props.work?.customer_id ?? props.customer?.id ?? null,
    job_title: props.work?.job_title ?? '',
    instructions: props.work?.instructions ?? '',
    start_date: defaultStartDate,
    end_date: toIsoDate(props.work?.end_date),
    start_time: props.work?.start_time ?? '',
    end_time: props.work?.end_time ?? '',
    products: props.work?.products?.map(product => ({
        id: product.id,
        name: product.name,
        quantity: Number(product.pivot?.quantity ?? 1),
        price: Number(product.pivot?.price ?? product.price ?? 0),
        total: Number(product.pivot?.total ?? 0),
    })) || [{ id: null, name: '', quantity: 1, price: 0, total: 0 }],
    later: Boolean(props.work?.later),
    ends: props.work?.ends ?? 'Never',
    frequencyNumber: props.work?.frequencyNumber ?? 1,
    frequency: props.work?.frequency ?? 'Weekly',
    totalVisits: props.work?.totalVisits ?? 0,
    repeatsOn: props.work?.repeatsOn ?? [],
    status: props.work?.status ?? 'scheduled',
    subtotal: props.work?.subtotal ?? 0,
    total: props.work?.total ?? 0,
    team_member_ids: props.work?.team_members?.map(member => member.id) ?? [],
    billing_mode: props.work?.billing_mode ?? props.customer?.billing_mode ?? 'end_of_job',
    billing_cycle: props.work?.billing_cycle ?? props.customer?.billing_cycle ?? '',
    billing_grouping: props.work?.billing_grouping ?? props.customer?.billing_grouping ?? 'single',
    billing_delay_days: props.work?.billing_delay_days ?? props.customer?.billing_delay_days ?? '',
    billing_date_rule: props.work?.billing_date_rule ?? props.customer?.billing_date_rule ?? '',
});

const calendarTeamFilter = ref('');
const teamSearchQuery = ref('');

const useTeamSearch = computed(() => (props.teamMembers?.length || 0) > 3);
const normalizedTeamMembers = computed(() =>
    (props.teamMembers || []).map((member) => ({
        id: Number(member.id),
        name: member.user?.name ?? t('jobs.form.team.member_fallback'),
        email: member.user?.email ?? '',
        title: member.title ?? '',
    }))
);
const selectedTeamMemberIds = computed(() =>
    new Set((form.team_member_ids || []).map((id) => Number(id)))
);
const selectedTeamMembers = computed(() =>
    normalizedTeamMembers.value.filter((member) => selectedTeamMemberIds.value.has(member.id))
);
const filteredTeamMembers = computed(() => {
    const members = normalizedTeamMembers.value;
    if (!useTeamSearch.value) {
        return members;
    }
    const term = teamSearchQuery.value.trim().toLowerCase();
    if (!term) {
        return members;
    }
    return members.filter((member) =>
        [member.name, member.email, member.title]
            .filter(Boolean)
            .some((value) => value.toLowerCase().includes(term))
    );
});
const calendarTeamOptions = computed(() =>
    normalizedTeamMembers.value.map((member) => ({
        id: String(member.id),
        name: member.name,
    }))
);

const toggleTeamMember = (memberId) => {
    const id = Number(memberId);
    if (!Number.isFinite(id)) {
        return;
    }
    if (selectedTeamMemberIds.value.has(id)) {
        form.team_member_ids = (form.team_member_ids || []).filter((value) => Number(value) !== id);
        return;
    }
    form.team_member_ids = [...(form.team_member_ids || []), id];
};

const removeTeamMember = (memberId) => {
    const id = Number(memberId);
    form.team_member_ids = (form.team_member_ids || []).filter((value) => Number(value) !== id);
};

const isLockedFromQuote = computed(() => Boolean(props.lockedFromQuote));
const lineItemsError = computed(() => {
    if (form.errors.products) {
        return form.errors.products;
    }
    const keys = Object.keys(form.errors || {});
    const key = keys.find((errorKey) => errorKey.startsWith('products'));
    return key ? form.errors[key] : '';
});

const primaryProperty = computed(() => {
    const properties = props.customer?.properties || [];
    return properties.find((property) => property.is_default) || properties[0] || null;
});

const formatDateLabel = (dateInput) => {
    if (!dateInput) {
        return '-';
    }
    const date = dayjs(dateInput);
    if (!date.isValid()) {
        return '-';
    }
    return date.format('DD/MM/YYYY');
};


const getExactDay = (dateInput, tempo) => {
    const weekDaysMap = {
        'Su': 0,
        'Mo': 1,
        'Tu': 2,
        'We': 3,
        'Th': 4,
        'Fr': 5,
        'Sa': 6,
    };

    const repeats = Array.isArray(form.repeatsOn) ? form.repeatsOn : [];
    const frequencyCount = Math.max(1, Number(form.frequencyNumber) || 1);

    let endDate = dateInput.add(frequencyCount - 1, tempo);
    if (!repeats.length) {
        return endDate;
    }

    if (tempo === 'week') {
        const target = repeats[repeats.length - 1];
        if (weekDaysMap[target] !== undefined) {
            endDate = endDate.day(weekDaysMap[target]);
        }
    } else if (tempo === 'month') {
        const target = repeats[repeats.length - 1];
        const dayNumber = Number(target);
        if (Number.isFinite(dayNumber)) {
            endDate = endDate.date(dayNumber);
        }
    }

    return endDate;
};

// Fonction pour calculer le total des visites
const calculateTotalVisits = () => {
    if (!form.start_date) {
        form.totalVisits = 0;
        if (form.ends !== 'On') {
            form.end_date = '';
        }
        return;
    }

    const currentDate = dayjs(form.start_date);
    if (!currentDate.isValid()) {
        form.totalVisits = 0;
        if (form.ends !== 'On') {
            form.end_date = '';
        }
        return;
    }

    const repeats = Array.isArray(form.repeatsOn) ? form.repeatsOn : [];
    const repeatCount = repeats.length;
    const frequencyCount = Math.max(1, Number(form.frequencyNumber) || 1);

    let count = 0;
    let endDate = null;

    if (form.ends === 'After') {
        if (form.frequency === 'Daily') {
            count = frequencyCount;
            endDate = currentDate.add(frequencyCount - 1, 'day');
        } else if (form.frequency === 'Weekly') {
            count = repeatCount ? frequencyCount * repeatCount : 0;
            endDate = getExactDay(currentDate, 'week');
        } else if (form.frequency === 'Monthly') {
            count = repeatCount ? frequencyCount * repeatCount : 0;
            endDate = getExactDay(currentDate, 'month');
        }

        form.end_date = endDate ? toIsoDate(endDate) : '';
    } else if (form.ends === 'On' && form.end_date) {
        const end = dayjs(form.end_date);
        if (!end.isValid() || end.isBefore(currentDate, 'day')) {
            count = 0;
        } else if (form.frequency === 'Daily') {
            count = end.diff(currentDate, 'day') + 1;
        } else if (form.frequency === 'Weekly') {
            const weeks = end.diff(currentDate, 'week');
            count = repeatCount ? (weeks + 1) * repeatCount : 0;
        } else if (form.frequency === 'Monthly') {
            const months = end.diff(currentDate, 'month');
            count = repeatCount ? (months + 1) * repeatCount : 0;
        }
    } else {
        form.end_date = '';
    }

    form.totalVisits = Math.max(0, Math.trunc(count));
};

// Surveiller les changements des valeurs et recalculer automatiquement
watch([() => form.start_date, () => form.frequency, () => form.ends, () => form.end_date, () => form.frequencyNumber, () => form.repeatsOn], () => {
    calculateTotalVisits();
}, { deep: true });


// Array of days of the week for weekly recurrence
const daysOfWeek = computed(() => [
    { value: 'Mo', label: t('jobs.recurrence.weekdays.mo') },
    { value: 'Tu', label: t('jobs.recurrence.weekdays.tu') },
    { value: 'We', label: t('jobs.recurrence.weekdays.we') },
    { value: 'Th', label: t('jobs.recurrence.weekdays.th') },
    { value: 'Fr', label: t('jobs.recurrence.weekdays.fr') },
    { value: 'Sa', label: t('jobs.recurrence.weekdays.sa') },
    { value: 'Su', label: t('jobs.recurrence.weekdays.su') },
]);

// Array of day numbers (1 to 31) for monthly recurrence
const daysOfMonth = [];
for (let i = 1; i <= 31; i++) {
    daysOfMonth.push(i);
}

// Watch for changes in `frequency` and reset `repeatsOn` on any change
watch(() => form.frequency, () => {
    form.repeatsOn = [];
});

function formatTasksForFullCalendar(tasks) {
    if (!Array.isArray(tasks) || tasks.length === 0) {
        return [];
    }

    return tasks
        .map((task) => {
            if (!task?.due_date) {
                return null;
            }

            const startTime = task.start_time ? String(task.start_time).slice(0, 8) : '';
            const endTime = task.end_time ? String(task.end_time).slice(0, 8) : '';
            const start = startTime ? `${task.due_date}T${startTime}` : task.due_date;
            const end = endTime ? `${task.due_date}T${endTime}` : null;
            const assigneeLabel = task.assignee?.name ? ` - ${task.assignee.name}` : '';

            return {
                id: task.id,
                title: `${task.title}${assigneeLabel}`,
                start,
                end,
                allDay: !startTime,
                extendedProps: {
                    preview: false,
                    assigned_team_member_id: task.assigned_team_member_id,
                    work_id: task.work_id,
                },
            };
        })
        .filter(Boolean);
}

const hasTasksForWork = computed(() => {
    const workId = props.work?.id;
    if (!workId) {
        return false;
    }

    return (props.tasks || []).some((task) => Number(task.work_id) === Number(workId));
});

const previewAssignees = computed(() => {
    const selectedIds = (form.team_member_ids ?? [])
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id));

    const members = props.teamMembers || [];
    const memberMap = new Map(
        members.map((member) => [Number(member.id), member.user?.name ?? t('jobs.form.team.member_fallback')])
    );

    if (selectedIds.length) {
        return selectedIds.map((id) => ({ id, name: memberMap.get(id) || '' }));
    }

    return members.map((member) => ({
        id: member.id,
        name: member.user?.name ?? t('jobs.form.team.member_fallback'),
    }));
});

const previewEvents = computed(() => {
    if (!form.start_date) {
        return [];
    }

    if (props.work?.id && hasTasksForWork.value) {
        return [];
    }

    return buildPreviewEvents({
        startDate: form.start_date,
        endDate: form.end_date || null,
        frequency: form.frequency,
        repeatsOn: form.repeatsOn,
        totalVisits: form.totalVisits,
        startTime: form.start_time,
        endTime: form.end_time,
        title: form.job_title,
        workId: props.work?.id || null,
        assignees: previewAssignees.value,
        preview: true,
    });
});

const buildRange = ({ date, startTime, endTime, start, end, allDay }) => {
    if (start && end) {
        const startDate = dayjs(start);
        const endDate = dayjs(end);
        if (startDate.isValid() && endDate.isValid()) {
            if (allDay) {
                return {
                    start: startDate.startOf('day'),
                    end: endDate.endOf('day'),
                    allDay: true,
                };
            }
            return {
                start: startDate,
                end: endDate.isBefore(startDate) ? startDate : endDate,
                allDay: false,
            };
        }
    }

    if (!date) {
        return null;
    }

    const baseDate = dayjs(date);
    if (!baseDate.isValid()) {
        return null;
    }

    if (!startTime) {
        return {
            start: baseDate.startOf('day'),
            end: baseDate.endOf('day'),
            allDay: true,
        };
    }

    const startDate = dayjs(`${baseDate.format('YYYY-MM-DD')}T${String(startTime).slice(0, 8)}`);
    if (!startDate.isValid()) {
        return null;
    }

    const rawEnd = endTime ? dayjs(`${baseDate.format('YYYY-MM-DD')}T${String(endTime).slice(0, 8)}`) : startDate;
    const endDate = rawEnd.isValid() ? rawEnd : startDate;

    return {
        start: startDate,
        end: endDate.isBefore(startDate) ? startDate : endDate,
        allDay: false,
    };
};

const rangesOverlap = (left, right) => {
    if (!left || !right) {
        return false;
    }
    return !left.end.isBefore(right.start) && !left.start.isAfter(right.end);
};

const taskRanges = computed(() => (props.tasks || [])
    .map((task) => {
        const memberId = Number(task?.assigned_team_member_id);
        if (!Number.isFinite(memberId)) {
            return null;
        }

        const range = buildRange({
            date: task.due_date,
            startTime: task.start_time,
            endTime: task.end_time,
        });

        if (!range) {
            return null;
        }

        return {
            memberId,
            range,
        };
    })
    .filter(Boolean)
);

const recurrenceRanges = computed(() => {
    if (!form.start_date) {
        return [];
    }

    const dates = buildOccurrenceDates({
        startDate: form.start_date,
        endDate: form.end_date || null,
        frequency: form.frequency,
        repeatsOn: form.repeatsOn,
        totalVisits: form.totalVisits,
    });

    if (!dates.length) {
        return [];
    }

    return dates
        .map((date) => buildRange({
            date: date.format('YYYY-MM-DD'),
            startTime: form.start_time,
            endTime: form.end_time,
        }))
        .filter(Boolean);
});

const availabilityByMember = computed(() => {
    const map = new Map();
    const selectedMembers = selectedTeamMembers.value;
    if (!selectedMembers.length) {
        return map;
    }

    if (!form.start_date) {
        selectedMembers.forEach((member) => {
            map.set(member.id, { status: 'unknown', label: t('jobs.form.team.availability.unknown') });
        });
        return map;
    }

    const ranges = recurrenceRanges.value;
    if (!ranges.length) {
        selectedMembers.forEach((member) => {
            map.set(member.id, { status: 'unknown', label: t('jobs.form.team.availability.unknown') });
        });
        return map;
    }

    selectedMembers.forEach((member) => {
        const memberTasks = taskRanges.value.filter((entry) => entry.memberId === member.id);
        const hasConflict = ranges.some((range) =>
            memberTasks.some((task) => rangesOverlap(range, task.range))
        );

        map.set(member.id, {
            status: hasConflict ? 'busy' : 'available',
            label: hasConflict
                ? t('jobs.form.team.availability.busy')
                : t('jobs.form.team.availability.available'),
        });
    });

    return map;
});

const availabilityClass = (status) => {
    if (status === 'available') {
        return 'text-emerald-600 dark:text-emerald-400';
    }
    if (status === 'busy') {
        return 'text-red-600 dark:text-red-400';
    }
    return 'text-stone-500 dark:text-neutral-500';
};

const availabilityForMember = (memberId) => availabilityByMember.value.get(memberId);

const filteredEvents = computed(() => {
    const events = [
        ...formatTasksForFullCalendar(props.tasks),
        ...previewEvents.value,
    ];
    const selectedId = calendarTeamFilter.value ? Number(calendarTeamFilter.value) : null;

    if (!selectedId) {
        return events;
    }

    return events.filter(
        (event) => Number(event.extendedProps?.assigned_team_member_id) === selectedId
    );
});

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth', // Affiche la semaine en cours
    weekends: true, // initial value
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'timeGridWeek,dayGridMonth', // Options de vue semaine/mois
    },
    height: 'auto',
    dayMaxEventRows: 2,
    eventDisplay: 'block',
    events: filteredEvents.value, // Assignation des events au calendrier
    dateClick(info) {
        const clickedDate = dayjs(info.date).format('YYYY-MM-DD');
        form.start_date = clickedDate;
    },
    eventClassNames(info) {
        const classes = ['calendar-event'];
        if (info.event.extendedProps?.preview) {
            classes.push('preview-event');
        }
        return classes;
    },
    eventClick(arg) {
        if (arg.event.extendedProps?.preview) {
            return;
        }

        const workId = arg.event.extendedProps?.work_id;
        if (workId) {
            router.get(route('work.show', workId));
        }
    },
}));

// Handler to update the subtotal when the child component emits an update
const updateSubtotal = (newSubtotal) => {
    const value = Number(newSubtotal) || 0;
    form.subtotal = Math.round(value * 100) / 100;
};

const totalWithTaxes = computed(() => {
    const subtotal = Number(form.subtotal) || 0;
    form.total = Math.round(subtotal * 100) / 100;
    return form.total;
});

// Soumettre le formulaire
const submit = () => {
    const routeName = props.work?.id ? 'work.update' : 'work.store';
    const routeParams = props.work?.id ? props.work.id : undefined;
    const productsPayload = (form.products || []).filter((line) => {
        if (!line) {
            return false;
        }
        const name = typeof line.name === 'string' ? line.name.trim() : '';
        return Boolean(line.id || name);
    });

    form.transform((data) => ({
        ...data,
        products: productsPayload.length ? productsPayload : null,
    }));

    form[props.work?.id ? 'put' : 'post'](route(routeName, routeParams), {
        onSuccess: () => {
            console.log('work saved successfully!');
        },
    });
};

const calendarRef = ref(null);

const loadCalendar = () => {
    nextTick(() => {
        if (calendarRef.value) {
            const calendarApi = calendarRef.value.getApi(); // Récupère FullCalendar
            calendarApi.updateSize(); // 🔹 Met à jour la taille pour l'afficher correctement
            console.log("Calendar reloaded");
        }
    });
};

const tabListeners = [];

const getPlanningTabTarget = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    const params = new URLSearchParams(window.location.search);
    const tab = (params.get('tab') || '').toLowerCase();
    if (!['planning', 'planification', 'planifier', 'schedule'].includes(tab)) {
        return null;
    }

    const mode = (params.get('mode') || '').toLowerCase();
    if (['recurring', 'recurrent', 'repeat'].includes(mode)) {
        return '#bar-with-underline-2';
    }

    return '#bar-with-underline-1';
};

const openScheduleTab = (targetId) => {
    const trigger = document.querySelector(`[data-hs-tab="${targetId}"]`);
    if (!trigger) {
        return;
    }

    trigger.click();
    setTimeout(() => {
        const panel = document.querySelector(targetId);
        if (panel?.scrollIntoView) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 150);
};

const setupTabListeners = () => {
    document.querySelectorAll("[data-hs-tab]").forEach((tab) => {
        const handler = () => {
            const targetTab = document.querySelector(tab.getAttribute("data-hs-tab"));
            if (targetTab && calendarRef.value) {
                setTimeout(() => {
                    loadCalendar(); // Met a jour FullCalendar apres l'affichage
                }, 100);
            }
        };

        tab.addEventListener("click", handler);
        tabListeners.push({ tab, handler });
    });
};

const teardownTabListeners = () => {
    tabListeners.forEach(({ tab, handler }) => {
        tab.removeEventListener("click", handler);
    });
    tabListeners.length = 0;
};

onMounted(() => {
    loadCalendar(); // Charge FullCalendar au montage du composant
    setupTabListeners();
    const targetTab = getPlanningTabTarget();
    if (targetTab) {
        openScheduleTab(targetTab);
    }
});

onBeforeUnmount(() => {
    teardownTabListeners();
});

</script>

<template>

    <Head :title="$t('jobs.create_title')" />
    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl">
            <form @submit.prevent="submit">
                    <div
                        class="p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
                        <!-- Header -->
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <img v-if="companyLogo"
                                    :src="companyLogo"
                                    :alt="companyName"
                                    class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                                    loading="lazy"
                                    decoding="async" />
                                <div>
                                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                        {{ companyName }}
                                    </p>
                                    <h1 class="text-xl inline-block font-semibold text-stone-800 dark:text-green-100">
                                        {{ $t('jobs.form.job_for', { customer: customerLabel }) }}
                                    </h1>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="col-span-2 space-x-2">
                                <div class="mb-4" x-data="{ open: false }">
                                    <FloatingInput v-model="form.job_title" :label="$t('jobs.form.job_title')" :required="true" :disabled="isLockedFromQuote" />
                                    <InputError class="mt-1" :message="form.errors.job_title" />
                                    <FloatingTextarea v-model="form.instructions" :label="$t('jobs.form.instructions')" :disabled="isLockedFromQuote" />
                                </div>
                                <div v-if="isLockedFromQuote" class="mb-2 text-xs text-amber-600">
                                    {{ $t('jobs.form.locked_notice') }}
                                </div>
                                <div class="flex flex-row space-x-6">
                                    <div class="lg:col-span-3">
                                        <p>
                                            {{ $t('jobs.form.property_address') }}
                                        </p>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ primaryProperty?.country ?? '-' }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ primaryProperty?.street1 ?? '-' }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ primaryProperty?.state ?? '-' }} - {{ primaryProperty?.zip ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <p>
                                            {{ $t('jobs.form.contact_details') }}
                                        </p>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ customer.first_name }} {{ customer.last_name }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ customer.email }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ customer.phone }}
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="bg-white p-4 rounded-sm border border-stone-100 dark:bg-neutral-900 dark:border-neutral-700">
                                <div class="lg:col-span-3">
                                    <p>
                                        {{ $t('jobs.form.job_details') }}
                                    </p>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span>{{ $t('jobs.form.job_label') }}:</span>
                                        <span>{{ lastWorkNumber }} </span>
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span>{{ $t('jobs.form.rating_label') }}:</span>
                                        <span class="flex flex-row space-x-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" class="lucide lucide-star h-4 w-4">
                                                <path
                                                    d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="mt-4">
                                        <FloatingSelect v-model="form.status" :label="$t('jobs.form.status_label')" :options="statusOptions" />
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between mt-5">
                                        <button type="button" disabled
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-200 bg-white text-green-800 shadow-sm hover:bg-green-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-green-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-green-300 dark:hover:bg-green-700 dark:focus:bg-green-700">
                                            {{ $t('jobs.form.add_custom_fields') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-4 p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700"
                        data-testid="demo-work-line-items"
                    >
                        <ProductTableList
                            v-model="form.products"
                            :read-only="isLockedFromQuote"
                            :allow-mixed-types="true"
                            :enable-price-lookup="true"
                            @update:subtotal="updateSubtotal"
                        />
                        <InputError class="mt-2" :message="lineItemsError" />
                    </div>
                    <div class="mt-4">

                        <!-- Audience -->
                        <div
                            class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                            <!-- Tab Nav -->
                            <nav class="relative z-0 flex border-b border-stone-200 dark:border-neutral-700"
                                aria-label="Tabs" role="tablist" aria-orientation="horizontal">
                                <!-- Nav Item -->
                                <button type="button"
                                    class="hs-tab-active:border-t-green-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-stone-300 focus:outline-none focus:border-t-stone-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-green-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600 active"
                                    id="bar-with-underline-item-1" aria-selected="true"
                                    data-hs-tab="#bar-with-underline-1" aria-controls="bar-with-underline-1" role="tab">
                                    <span class="flex gap-x-4">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-calendar">
                                            <path d="M8 2v4" />
                                            <path d="M16 2v4" />
                                            <rect width="18" height="18" x="3" y="4" rx="2" />
                                            <path d="M3 10h18" />
                                        </svg>
                                            <span class="grow text-center md:text-start">
                                                <span class="block text-xs md:text-sm text-stone-700 dark:text-neutral-300">
                                                {{ $t('jobs.form.tabs.single.title') }}
                                            </span>
                                            <span
                                                class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                                                <span
                                                    class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                                                    {{ $t('jobs.form.tabs.single.subtitle') }}
                                                </span>
                                            </span>
                                        </span>
                                    </span>
                                </button>
                                <!-- End Nav Item -->

                                <!-- Nav Item -->
                                <button type="button"
                                    class="hs-tab-active:border-t-green-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-stone-300 focus:outline-none focus:border-t-stone-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-green-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600"
                                    id="bar-with-underline-item-2" aria-selected="false"
                                    data-hs-tab="#bar-with-underline-2" aria-controls="bar-with-underline-2" role="tab">
                                    <span class="flex gap-x-4">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-calendar-sync">
                                            <path d="M11 10v4h4" />
                                            <path d="m11 14 1.535-1.605a5 5 0 0 1 8 1.5" />
                                            <path d="M16 2v4" />
                                            <path d="m21 18-1.535 1.605a5 5 0 0 1-8-1.5" />
                                            <path d="M21 22v-4h-4" />
                                            <path d="M21 8.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4.3" />
                                            <path d="M3 10h4" />
                                            <path d="M8 2v4" />
                                        </svg>
                                        <span class="grow text-center md:text-start">
                                            <span class="block text-xs md:text-sm text-stone-700 dark:text-neutral-300">
                                                {{ $t('jobs.form.tabs.recurring.title') }}
                                            </span>
                                            <span
                                                class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                                                <span
                                                    class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                                                    {{ $t('jobs.form.tabs.recurring.subtitle') }}
                                                </span>
                                            </span>
                                        </span>
                                    </span>
                                </button>
                                <!-- End Nav Item -->
                            </nav>
                            <!-- End Tab Nav -->

                            <!-- Tab Content -->
                            <div class="p-5">
                                <!-- Tab Content Item -->
                                <div id="bar-with-underline-1" role="tabpanel"
                                    aria-labelledby="bar-with-underline-item-1">
                                    <div>
                                        <div class="grid grid-cols-3 gap-6">
                                            <!-- Premier div (1/3) -->
                                            <div class="col-span-1 mb-4 order-1" x-data="{ open: false }">
                                                <div
                                                    class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                                                    <div
                                                        class="flex flex-row bg-white dark:bg-neutral-900 border-b border-stone-200 rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                        <h3
                                                            class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                                                            {{ $t('jobs.form.planning_title') }}
                                                        </h3>
                                                    </div>
                                                    <div class="p-4 md:p-5">
                                                        <div class="flex flex-row space-x-1 mb-4">
                                                            <DatePicker v-model="form.start_date" :label="$t('jobs.form.start_date')" :required="true"
                                                                :placeholder="$t('jobs.form.pick_date')" />
                                                        </div>
                                                        <InputError class="mt-1" :message="form.errors.start_date" />
                                                        <div class="flex flex-row space-x-1">
                                                            <TimePicker v-model="form.start_time" :label="$t('jobs.form.start_time')"
                                                                :placeholder="$t('jobs.form.pick_time')" />
                                                            <TimePicker v-model="form.end_time"
                                                                :label="$t('jobs.form.end_time')"
                                                                :placeholder="$t('jobs.form.pick_time')" />
                                                        </div>
                                                        <div
                                                            class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-300">
                                                                {{ $t('jobs.form.quick_summary') }}
                                                            </div>
                                                            <div class="mt-2 space-y-1">
                                                                <div class="flex items-center justify-between">
                                                                    <span>{{ $t('jobs.form.summary_date') }}</span>
                                                                    <span>{{ formatDateLabel(form.start_date) }}</span>
                                                                </div>
                                                                <div class="flex items-center justify-between">
                                                                    <span>{{ $t('jobs.form.summary_time') }}</span>
                                                                    <span>
                                                                        {{ form.start_time || '-' }}
                                                                        <span v-if="form.end_time"> - {{ form.end_time }}</span>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <p class="mt-2 text-[11px] text-stone-500 dark:text-neutral-500">
                                                                {{ $t('jobs.form.calendar_tip') }}
                                                            </p>
                                                        </div>
                                                        <div class="mt-4 block">
                                                            <label class="flex items-center">
                                                                <Checkbox name="later"
                                                                    v-model:checked="form.later" />
                                                                <span class="ms-2 text-sm text-stone-600 dark:text-neutral-400">{{ $t('jobs.form.plan_later') }}</span>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div
                                                    class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70 mt-4">
                                                    <div
                                                        class="flex flex-row bg-white dark:bg-neutral-900 border-b border-stone-200 rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                        <h3
                                                            class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                                                            {{ $t('jobs.form.team.title') }}
                                                        </h3>
                                                    </div>
                                                    <div class="p-4 md:p-5">
                                                        <div class="space-y-3">
                                                            <div v-if="!teamMembers?.length"
                                                                class="text-sm text-stone-600 dark:text-neutral-400">
                                                                {{ $t('jobs.form.team.empty') }}
                                                            </div>
                                                            <div v-else class="space-y-3">
                                                                <template v-if="useTeamSearch">
                                                                    <FloatingInput
                                                                        v-model="teamSearchQuery"
                                                                        :label="$t('jobs.form.team.search_label')"
                                                                    />
                                                                    <div v-if="selectedTeamMembers.length"
                                                                        class="rounded-sm border border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                                                        <div class="text-[11px] font-semibold text-stone-600 dark:text-neutral-300">
                                                                            {{ $t('jobs.form.team.selected_title') }}
                                                                        </div>
                                                                        <div class="mt-2 flex flex-wrap gap-2">
                                                                            <span
                                                                                v-for="member in selectedTeamMembers"
                                                                                :key="`selected-${member.id}`"
                                                                                class="inline-flex flex-col items-start gap-1 rounded-full border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                                                            >
                                                                                <span class="flex items-center gap-2">
                                                                                    <span>{{ member.name }}</span>
                                                                                    <button
                                                                                        type="button"
                                                                                        class="text-stone-400 hover:text-stone-600 dark:text-neutral-500 dark:hover:text-neutral-300"
                                                                                        @click="removeTeamMember(member.id)"
                                                                                    >
                                                                                        &times;
                                                                                    </button>
                                                                                </span>
                                                                                <span
                                                                                    v-if="availabilityForMember(member.id)"
                                                                                    class="text-[10px]"
                                                                                    :class="availabilityClass(availabilityForMember(member.id).status)"
                                                                                >
                                                                                    {{ availabilityForMember(member.id).label }}
                                                                                </span>
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                    <div v-if="filteredTeamMembers.length"
                                                                        class="max-h-56 space-y-2 overflow-y-auto">
                                                                        <button
                                                                            v-for="member in filteredTeamMembers"
                                                                            :key="`member-${member.id}`"
                                                                            type="button"
                                                                            class="flex w-full items-start justify-between gap-3 rounded-sm border border-stone-200 bg-white p-2 text-left text-sm text-stone-700 transition hover:border-green-400 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                                                            @click="toggleTeamMember(member.id)"
                                                                        >
                                                                            <div class="flex flex-col">
                                                                                <span class="text-sm">
                                                                                    {{ member.name }}
                                                                                </span>
                                                                                <span v-if="member.email" class="text-xs text-stone-500 dark:text-neutral-500">
                                                                                    {{ member.email }}
                                                                                </span>
                                                                                <span v-else-if="member.title" class="text-xs text-stone-500 dark:text-neutral-500">
                                                                                    {{ member.title }}
                                                                                </span>
                                                                                <span
                                                                                    v-if="selectedTeamMemberIds.has(member.id) && availabilityForMember(member.id)"
                                                                                    class="text-xs"
                                                                                    :class="availabilityClass(availabilityForMember(member.id).status)"
                                                                                >
                                                                                    {{ availabilityForMember(member.id).label }}
                                                                                </span>
                                                                            </div>
                                                                            <span
                                                                                v-if="selectedTeamMemberIds.has(member.id)"
                                                                                class="text-[10px] font-semibold uppercase text-emerald-600 dark:text-emerald-300"
                                                                            >
                                                                                {{ $t('jobs.form.team.selected_badge') }}
                                                                            </span>
                                                                        </button>
                                                                    </div>
                                                                    <div v-else class="text-xs text-stone-500 dark:text-neutral-500">
                                                                        {{ $t('jobs.form.team.search_empty') }}
                                                                    </div>
                                                                </template>
                                                                <template v-else>
                                                                    <label v-for="member in teamMembers" :key="member.id"
                                                                        class="flex items-start gap-3">
                                                                        <Checkbox v-model:checked="form.team_member_ids"
                                                                            :value="member.id" />
                                                                        <div class="flex flex-col">
                                                                            <span
                                                                                class="text-sm text-stone-800 dark:text-neutral-200">
                                                                                {{ member.user?.name ?? $t('jobs.form.team.member_fallback') }}
                                                                            </span>
                                                                            <span
                                                                                class="text-xs text-stone-500 dark:text-neutral-500">
                                                                                {{ member.user?.email ?? '-' }}
                                                                            </span>
                                                                            <span v-if="member.title"
                                                                                class="text-xs text-stone-500 dark:text-neutral-500">
                                                                                {{ member.title }}
                                                                            </span>
                                                                            <span
                                                                                v-if="selectedTeamMemberIds.has(Number(member.id)) && availabilityForMember(Number(member.id))"
                                                                                class="text-xs"
                                                                                :class="availabilityClass(availabilityForMember(Number(member.id)).status)"
                                                                            >
                                                                                {{ availabilityForMember(Number(member.id)).label }}
                                                                            </span>
                                                                        </div>
                                                                    </label>
                                                                </template>
                                                            </div>
                                                            <div v-if="teamMembers?.length"
                                                                class="text-xs text-stone-500 dark:text-neutral-500">
                                                                Selectionner un membre pour filtrer le calendrier.
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Deuxième div (2/3) -->
                                            <div class="col-span-2 order-2">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
                                                    <div class="flex items-start gap-2">
                                                        <span class="mt-1 h-2 w-2 rounded-full bg-emerald-500"></span>
                                                        <div>
                                                            <div class="text-sm font-semibold text-stone-700 dark:text-neutral-300">
                                                                {{ $t('jobs.form.calendar.title') }}
                                                            </div>
                                                            <p class="text-xs text-stone-500 dark:text-neutral-500">
                                                                {{ $t('jobs.form.calendar.subtitle') }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <FloatingSelect
                                                            v-model="calendarTeamFilter"
                                                            :label="$t('jobs.form.calendar.filter_label')"
                                                            :options="calendarTeamOptions"
                                                            :placeholder="$t('jobs.form.calendar.all_members')"
                                                            dense
                                                            class="w-full sm:w-56"
                                                        />
                                                    </div>
                                                </div>
                                                <div
                                                    class="rounded-md border border-stone-200 bg-white/90 p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/70">
                                                    <FullCalendar :options="calendarOptions"  ref="calendarRef"/>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <!-- End Tab Content Item -->

                                <!-- Tab Content Item -->
                                <div id="bar-with-underline-2" class="hidden" role="tabpanel"
                                    aria-labelledby="bar-with-underline-item-2">
                                    <div class="grid grid-cols-3 gap-6">
                                        <!-- Premier div (1/3) -->
                                        <div class="col-span-1 mb-4 order-1" x-data="{ open: false }">
                                            <div
                                                class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                                                <div
                                                    class="flex flex-row bg-white dark:bg-neutral-900 border-b border-stone-200 rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                    <h3 class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                                                        {{ $t('jobs.form.planning_title') }}
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <DatePicker v-model="form.start_date" :label="$t('jobs.form.start_date')" :required="true"
                                                        :placeholder="$t('jobs.form.pick_date')" />
                                                    <InputError class="mt-1" :message="form.errors.start_date" />
                                                    <div class="flex flex-row space-x-1 my-4">
                                                        <TimePicker v-model="form.start_time" :label="$t('jobs.form.start_time')"
                                                            :placeholder="$t('jobs.form.pick_time')" />
                                                        <TimePicker v-model="form.end_time" :label="$t('jobs.form.end_time')"
                                                            :placeholder="$t('jobs.form.pick_time')" />
                                                    </div>
                                                    <div
                                                        class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                                        <div class="text-xs font-semibold text-stone-700 dark:text-neutral-300">
                                                            {{ $t('jobs.form.quick_summary') }}
                                                        </div>
                                                        <div class="mt-2 space-y-1">
                                                            <div class="flex items-center justify-between">
                                                                <span>{{ $t('jobs.form.summary_date') }}</span>
                                                                <span>{{ formatDateLabel(form.start_date) }}</span>
                                                            </div>
                                                            <div class="flex items-center justify-between">
                                                                <span>{{ $t('jobs.form.summary_time') }}</span>
                                                                <span>
                                                                    {{ form.start_time || '-' }}
                                                                    <span v-if="form.end_time"> - {{ form.end_time }}</span>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <p class="mt-2 text-[11px] text-stone-500 dark:text-neutral-500">
                                                            {{ $t('jobs.form.calendar_tip') }}
                                                        </p>
                                                    </div>

                                                </div>
                                            </div>
                                            <div
                                                class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70 mt-4"
                                                data-testid="demo-work-recurrence">
                                                <div
                                                    class="flex flex-row bg-white dark:bg-neutral-900 border-b border-stone-200 rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                    <h3 class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                                                        {{ $t('jobs.form.recurrence.title') }}
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
                                                        {{ $t('jobs.form.recurrence.subtitle') }}
                                                    </p>
                                                    <div id="hs-modal-custom-recurrence-event" class="mt-4 space-y-4">
                                                        <div>
                                                            <label for="hs-pro-ccremre"
                                                                class="mb-1.5 block text-[13px] text-stone-400 dark:text-neutral-500">
                                                                {{ $t('jobs.form.recurrence.repeat_every') }}
                                                            </label>
                                                            <FloatingSelect v-model="form.frequency"
                                                                :label="$t('jobs.recurrence.frequency.label')" :options="Frequence" />
                                                        </div>

                                                        <div v-if="form.frequency !== 'Daily'">
                                                            <label
                                                                class="mb-1.5 block text-[13px] text-stone-400 dark:text-neutral-500">
                                                                {{ $t('jobs.form.recurrence.repeat_on') }}
                                                            </label>
                                                            <p class="mb-2 text-[11px] text-stone-400 dark:text-neutral-500">
                                                                {{ $t('jobs.form.recurrence.repeat_hint') }}
                                                            </p>
                                                            <div v-if="form.frequency === 'Weekly'"
                                                                class="flex flex-wrap items-center gap-2">
                                                                <SelectableItem :LoopValue="daysOfWeek" v-model="form.repeatsOn" />
                                                            </div>
                                                            <div v-else-if="form.frequency === 'Monthly'"
                                                                class="flex flex-wrap gap-2">
                                                                <SelectableItem :LoopValue="daysOfMonth" v-model="form.repeatsOn" />
                                                            </div>
                                                        </div>

                                                        <div>
                                                            <FloatingSelect :label="$t('jobs.recurrence.ends.label')" v-model="form.ends"
                                                                :options="endOptions" />

                                                            <div class="w-full mt-4" v-if="form.ends === 'On'">
                                                                <DatePicker v-model="form.end_date" :label="$t('jobs.form.end_date')"
                                                                    :placeholder="$t('jobs.form.pick_date')" />
                                                            </div>
                                                            <div class="w-full flex items-center gap-x-2 mt-4" v-if="form.ends === 'After'">
                                                                <FloatingNumberMiniInput v-model="form.frequencyNumber"
                                                                    :label="$t('jobs.form.recurrence.count_label')" />
                                                                <span
                                                                    class="text-xs text-stone-400 dark:text-neutral-500">{{ $t('jobs.form.recurrence.times_suffix') }}</span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div
                                                        class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                                        <div class="text-xs font-semibold text-stone-700 dark:text-neutral-300">
                                                            {{ $t('jobs.form.recurrence.preview_title') }}
                                                        </div>
                                                        <div class="mt-2 grid grid-cols-3 gap-3">
                                                            <div class="flex flex-col">
                                                                <span
                                                                    class="text-[11px] text-stone-500 dark:text-neutral-500">
                                                                    {{ $t('jobs.form.recurrence.preview_first') }}
                                                                </span>
                                                                <span
                                                                    class="text-xs text-stone-700 dark:text-neutral-300">
                                                                    {{ formatDateLabel(form.start_date) }}
                                                                </span>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span
                                                                    class="text-[11px] text-stone-500 dark:text-neutral-500">
                                                                    {{ $t('jobs.form.recurrence.preview_last') }}
                                                                </span>
                                                                <span
                                                                    class="text-xs text-stone-700 dark:text-neutral-300">
                                                                    {{ formatDateLabel(form.end_date) }}
                                                                </span>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span
                                                                    class="text-[11px] text-stone-500 dark:text-neutral-500">
                                                                    {{ $t('jobs.form.recurrence.preview_total') }}
                                                                </span>
                                                                <span
                                                                    class="text-xs text-stone-700 dark:text-neutral-300">
                                                                    {{ form.totalVisits }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div
                                                class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70 mt-4">
                                                <div
                                                    class="flex flex-row bg-white dark:bg-neutral-900 border-b border-stone-200 rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                    <h3 class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                                                        {{ $t('jobs.form.team.title') }}
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <div class="space-y-3">
                                                        <div v-if="!teamMembers?.length"
                                                            class="text-sm text-stone-600 dark:text-neutral-400">
                                                            {{ $t('jobs.form.team.empty') }}
                                                        </div>
                                                        <div v-else class="space-y-3">
                                                            <template v-if="useTeamSearch">
                                                                <FloatingInput
                                                                    v-model="teamSearchQuery"
                                                                    :label="$t('jobs.form.team.search_label')"
                                                                />
                                                                <div v-if="selectedTeamMembers.length"
                                                                    class="rounded-sm border border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                                                    <div class="text-[11px] font-semibold text-stone-600 dark:text-neutral-300">
                                                                        {{ $t('jobs.form.team.selected_title') }}
                                                                    </div>
                                                                    <div class="mt-2 flex flex-wrap gap-2">
                                                                        <span
                                                                            v-for="member in selectedTeamMembers"
                                                                            :key="`selected-recurring-${member.id}`"
                                                                            class="inline-flex flex-col items-start gap-1 rounded-full border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                                                        >
                                                                            <span class="flex items-center gap-2">
                                                                                <span>{{ member.name }}</span>
                                                                                <button
                                                                                    type="button"
                                                                                    class="text-stone-400 hover:text-stone-600 dark:text-neutral-500 dark:hover:text-neutral-300"
                                                                                    @click="removeTeamMember(member.id)"
                                                                                >
                                                                                    &times;
                                                                                </button>
                                                                            </span>
                                                                            <span
                                                                                v-if="availabilityForMember(member.id)"
                                                                                class="text-[10px]"
                                                                                :class="availabilityClass(availabilityForMember(member.id).status)"
                                                                            >
                                                                                {{ availabilityForMember(member.id).label }}
                                                                            </span>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                                <div v-if="filteredTeamMembers.length"
                                                                    class="max-h-56 space-y-2 overflow-y-auto">
                                                                    <button
                                                                        v-for="member in filteredTeamMembers"
                                                                        :key="`member-recurring-${member.id}`"
                                                                        type="button"
                                                                        class="flex w-full items-start justify-between gap-3 rounded-sm border border-stone-200 bg-white p-2 text-left text-sm text-stone-700 transition hover:border-green-400 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                                                        @click="toggleTeamMember(member.id)"
                                                                    >
                                                                        <div class="flex flex-col">
                                                                            <span class="text-sm">
                                                                                {{ member.name }}
                                                                            </span>
                                                                            <span v-if="member.email" class="text-xs text-stone-500 dark:text-neutral-500">
                                                                                {{ member.email }}
                                                                            </span>
                                                                            <span v-else-if="member.title" class="text-xs text-stone-500 dark:text-neutral-500">
                                                                                {{ member.title }}
                                                                            </span>
                                                                            <span
                                                                                v-if="selectedTeamMemberIds.has(member.id) && availabilityForMember(member.id)"
                                                                                class="text-xs"
                                                                                :class="availabilityClass(availabilityForMember(member.id).status)"
                                                                            >
                                                                                {{ availabilityForMember(member.id).label }}
                                                                            </span>
                                                                        </div>
                                                                        <span
                                                                            v-if="selectedTeamMemberIds.has(member.id)"
                                                                            class="text-[10px] font-semibold uppercase text-emerald-600 dark:text-emerald-300"
                                                                        >
                                                                            {{ $t('jobs.form.team.selected_badge') }}
                                                                        </span>
                                                                    </button>
                                                                </div>
                                                                <div v-else class="text-xs text-stone-500 dark:text-neutral-500">
                                                                    {{ $t('jobs.form.team.search_empty') }}
                                                                </div>
                                                            </template>
                                                            <template v-else>
                                                                <label v-for="member in teamMembers" :key="member.id"
                                                                    class="flex items-start gap-3">
                                                                    <Checkbox v-model:checked="form.team_member_ids"
                                                                        :value="member.id" />
                                                                    <div class="flex flex-col">
                                                                        <span
                                                                            class="text-sm text-stone-800 dark:text-neutral-200">
                                                                            {{ member.user?.name ?? $t('jobs.form.team.member_fallback') }}
                                                                        </span>
                                                                        <span
                                                                            class="text-xs text-stone-500 dark:text-neutral-500">
                                                                            {{ member.user?.email ?? '-' }}
                                                                        </span>
                                                                        <span v-if="member.title"
                                                                            class="text-xs text-stone-500 dark:text-neutral-500">
                                                                            {{ member.title }}
                                                                        </span>
                                                                        <span
                                                                            v-if="selectedTeamMemberIds.has(Number(member.id)) && availabilityForMember(Number(member.id))"
                                                                            class="text-xs"
                                                                            :class="availabilityClass(availabilityForMember(Number(member.id)).status)"
                                                                        >
                                                                            {{ availabilityForMember(Number(member.id)).label }}
                                                                        </span>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                        </div>
                                                        <div v-if="teamMembers?.length"
                                                            class="text-xs text-stone-500 dark:text-neutral-500">
                                                            {{ $t('jobs.form.team.filter_hint') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Deuxième div (2/3) -->
                                        <div class="col-span-2 order-2">
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
                                                <div class="flex items-start gap-2">
                                                    <span class="mt-1 h-2 w-2 rounded-full bg-emerald-500"></span>
                                                    <div>
                                                        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-300">
                                                            {{ $t('jobs.form.calendar.title') }}
                                                        </div>
                                                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                                                            {{ $t('jobs.form.calendar.subtitle') }}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center">
                                                    <FloatingSelect
                                                        v-model="calendarTeamFilter"
                                                        :label="$t('jobs.form.calendar.filter_label')"
                                                        :options="calendarTeamOptions"
                                                        :placeholder="$t('jobs.form.calendar.all_members')"
                                                        dense
                                                        class="w-full sm:w-56"
                                                    />
                                                </div>
                                            </div>
                                            <div
                                                class="rounded-md border border-stone-200 bg-white/90 p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/70"
                                                data-testid="demo-work-calendar">
                                                <FullCalendar :options="calendarOptions"   ref="calendarRef"/>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- End Tab Content Item -->

                            </div>
                            <!-- End Tab Content -->
                        </div>
                        <!-- End Audience -->
                    </div>
                    <div
                        class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70 mt-4">
                        <div
                            class="flex flex-row bg-white dark:bg-neutral-900 border-b border-stone-200 rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                            <h3 class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                                {{ $t('jobs.form.billing.title') }}
                            </h3>
                        </div>
                        <div class="p-4 md:p-5">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <FloatingSelect v-model="form.billing_mode" :label="$t('jobs.form.billing.mode')"
                                    :options="billingModes" />
                                <FloatingSelect v-model="form.billing_grouping" :label="$t('jobs.form.billing.grouping')"
                                    :options="billingGroupings" />
                            </div>

                            <div v-if="form.billing_mode === 'per_segment' || form.billing_grouping === 'periodic' || form.billing_mode === 'deferred'"
                                class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                                <FloatingSelect v-model="form.billing_cycle" :label="$t('jobs.form.billing.cycle')"
                                    :options="billingCycles" />
                                <FloatingInput v-if="form.billing_mode === 'deferred'"
                                    v-model="form.billing_delay_days" type="number" :label="$t('jobs.form.billing.delay')" />
                            </div>

                            <div v-if="form.billing_mode === 'deferred'" class="mt-3">
                                <FloatingInput v-model="form.billing_date_rule"
                                    :label="$t('jobs.form.billing.date_rule')" />
                            </div>

                            <label v-if="form.billing_mode === 'end_of_job'" class="mt-4 flex items-center">
                                <Checkbox name="later" v-model:checked="form.later" />
                                <span class="ms-2 text-sm text-stone-600 dark:text-neutral-400">{{ $t('jobs.form.billing.reminder') }}</span>
                            </label>
                        </div>
                    </div>
                    <div
                        class="mt-4 mb-4 grid grid-cols-1 gap-4 justify-between bg-white  dark:bg-neutral-900 dark:border-neutral-700">

                        <div class="flex justify-between">
                            <button type="button"
                                class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700 action-feedback">
                                {{ $t('jobs.form.actions.cancel') }}
                            </button>
                            <div>
                                <button type="button" disabled
                                    class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-stone-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-stone-500 action-feedback">
                                    {{ $t('jobs.form.actions.save_and_create_another') }}
                                </button>
                                <button id="hs-pro-in1trsbgwmdid1" type="submit"
                                    class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500 action-feedback">
                                    {{ $t('jobs.form.actions.save_job') }}
                                </button>
                            </div>
                        </div>
                    </div>
            </form>
        </div>

    </AuthenticatedLayout>
</template>

<style scoped>
:deep(.fc) {
    font-size: 0.75rem;
}

:deep(.fc .fc-toolbar) {
    margin-bottom: 0.75rem;
}

:deep(.fc .fc-toolbar-title) {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1c1917;
}

:deep(.dark .fc .fc-toolbar-title) {
    color: #f5f5f4;
}

:deep(.fc .fc-button) {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 0.375rem;
    border: 1px solid #e7e5e4;
    background: #ffffff;
    color: #57534e;
    box-shadow: none;
}

:deep(.fc .fc-button:hover) {
    background: #f5f5f4;
}

:deep(.fc .fc-button:disabled) {
    opacity: 0.45;
}

:deep(.fc .fc-button-primary:not(:disabled).fc-button-active),
:deep(.fc .fc-button-primary:not(:disabled):active) {
    background: #10b981;
    border-color: #10b981;
    color: #ffffff;
}

:deep(.dark .fc .fc-button) {
    border-color: #404040;
    background: #1f2937;
    color: #e5e7eb;
}

:deep(.dark .fc .fc-button:hover) {
    background: #111827;
}

:deep(.dark .fc .fc-button-primary:not(:disabled).fc-button-active),
:deep(.dark .fc .fc-button-primary:not(:disabled):active) {
    background: #34d399;
    border-color: #34d399;
    color: #0f172a;
}

:deep(.fc .fc-scrollgrid) {
    border-color: #e7e5e4;
    border-radius: 0.5rem;
    overflow: hidden;
}

:deep(.dark .fc .fc-scrollgrid) {
    border-color: #404040;
}

:deep(.fc .fc-col-header-cell) {
    background: #fafaf9;
}

:deep(.dark .fc .fc-col-header-cell) {
    background: #111827;
}

:deep(.fc .fc-col-header-cell-cushion) {
    padding: 0.35rem 0.25rem;
    font-size: 0.7rem;
    color: #78716c;
}

:deep(.dark .fc .fc-col-header-cell-cushion) {
    color: #a3a3a3;
}

:deep(.fc .fc-daygrid-day) {
    border-color: #e7e5e4;
}

:deep(.dark .fc .fc-daygrid-day) {
    border-color: #404040;
}

:deep(.fc .fc-daygrid-day-frame) {
    padding: 0.35rem;
}

:deep(.fc .fc-daygrid-day-number) {
    font-size: 0.7rem;
    font-weight: 600;
    color: #57534e;
}

:deep(.dark .fc .fc-daygrid-day-number) {
    color: #d4d4d4;
}

:deep(.fc .fc-daygrid-day.fc-day-today) {
    background: #ecfdf5;
}

:deep(.dark .fc .fc-daygrid-day.fc-day-today) {
    background: rgba(16, 185, 129, 0.16);
}

:deep(.fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number) {
    color: #047857;
}

:deep(.dark .fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number) {
    color: #a7f3d0;
}

:deep(.fc .calendar-event) {
    border-radius: 0.5rem;
    border: 1px solid #d1fae5;
    background: #ecfdf5;
    color: #065f46;
    padding: 0.1rem 0.35rem;
    font-size: 0.7rem;
}

:deep(.dark .fc .calendar-event) {
    border-color: rgba(16, 185, 129, 0.4);
    background: rgba(16, 185, 129, 0.15);
    color: #a7f3d0;
}

:deep(.fc .calendar-event.preview-event) {
    background-color: #e2e8f0;
    border-color: #cbd5e1;
    color: #334155;
}

:deep(.dark .fc .calendar-event.preview-event) {
    background-color: #1f2937;
    border-color: #374151;
    color: #e5e7eb;
}
</style>
