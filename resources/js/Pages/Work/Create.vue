<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, watch, ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import dayjs from 'dayjs';
import { buildPreviewEvents } from '@/utils/schedule';
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
const companyName = computed(() => page.props.auth?.account?.company?.name || 'Entreprise');
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);

const Frequence = [
    { id: 'Daily', name: 'Quotidien' },
    { id: 'Weekly', name: 'Hebdomadaire' },
    { id: 'Monthly', name: 'Mensuel' },
    { id: 'Yearly', name: 'Annuel' },
];

const endOptions = [
    { id: 'Never', name: 'Jamais' },
    { id: 'On', name: 'Le' },
    { id: 'After', name: 'Apres' },
];

const statusOptions = [
    { id: 'to_schedule', name: 'A planifier' },
    { id: 'scheduled', name: 'Planifie' },
    { id: 'en_route', name: 'En route' },
    { id: 'in_progress', name: 'En cours' },
    { id: 'tech_complete', name: 'Tech termine' },
    { id: 'pending_review', name: 'En attente de validation' },
    { id: 'validated', name: 'Valide' },
    { id: 'auto_validated', name: 'Auto valide' },
    { id: 'dispute', name: 'Litige' },
    { id: 'closed', name: 'Cloture' },
    { id: 'cancelled', name: 'Annule' },
    { id: 'completed', name: 'Termine (ancien)' },
];

const billingModes = [
    { id: 'per_task', name: 'Par tache' },
    { id: 'per_segment', name: 'Par segment' },
    { id: 'end_of_job', name: 'Fin de job' },
    { id: 'deferred', name: 'Differe' },
];

const billingGroupings = [
    { id: 'single', name: 'Une facture' },
    { id: 'periodic', name: 'Regrouper' },
];

const billingCycles = [
    { id: 'weekly', name: 'Chaque semaine' },
    { id: 'biweekly', name: 'Toutes les 2 semaines' },
    { id: 'monthly', name: 'Chaque mois' },
    { id: 'every_n_tasks', name: 'Chaque N taches' },
];

const defaultStartDate = props.work?.start_date ?? dayjs().format('YYYY-MM-DD');

const form = useForm({
    customer_id: props.work?.customer_id ?? props.customer?.id ?? null,
    job_title: props.work?.job_title ?? '',
    instructions: props.work?.instructions ?? '',
    start_date: defaultStartDate,
    end_date: props.work?.end_date ?? '',
    start_time: props.work?.start_time ?? '',
    end_time: props.work?.end_time ?? '',
    products: props.work?.products?.map(product => ({
        id: product.id,
        name: product.name,
        quantity: Number(product.pivot?.quantity ?? 1),
        price: Number(product.pivot?.price ?? product.price ?? 0),
        total: Number(product.pivot?.total ?? 0),
    })) || [{ id: null, name: '', quantity: 1, price: 0, total: 0 }],
    later: props.work?.later ?? false,
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

const isLockedFromQuote = computed(() => Boolean(props.lockedFromQuote));

const primaryProperty = computed(() => {
    const properties = props.customer?.properties || [];
    return properties.find((property) => property.is_default) || properties[0] || null;
});

const toIsoDate = (dateInput) => {
    if (!dateInput) {
        return '';
    }
    const date = dayjs(dateInput);
    return date.isValid() ? date.format('YYYY-MM-DD') : '';
};

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
const daysOfWeek = [
    { value: 'Mo', label: 'Lu' },
    { value: 'Tu', label: 'Ma' },
    { value: 'We', label: 'Me' },
    { value: 'Th', label: 'Je' },
    { value: 'Fr', label: 'Ve' },
    { value: 'Sa', label: 'Sa' },
    { value: 'Su', label: 'Di' },
];

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
        members.map((member) => [Number(member.id), member.user?.name ?? 'Membre equipe'])
    );

    if (selectedIds.length) {
        return selectedIds.map((id) => ({ id, name: memberMap.get(id) || '' }));
    }

    return members.map((member) => ({
        id: member.id,
        name: member.user?.name ?? 'Membre equipe',
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

    <Head title="Creer un job" />
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
                                    class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700" />
                                <div>
                                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                        {{ companyName }}
                                    </p>
                                    <h1 class="text-xl inline-block font-semibold text-stone-800 dark:text-green-100">
                                        Job pour : {{ customer.company_name }}
                                    </h1>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="col-span-2 space-x-2">
                                <div class="mb-4" x-data="{ open: false }">
                                    <FloatingInput v-model="form.job_title" label="Titre du job" :required="true" :disabled="isLockedFromQuote" />
                                    <InputError class="mt-1" :message="form.errors.job_title" />
                                    <FloatingTextarea v-model="form.instructions" label="Instructions" :disabled="isLockedFromQuote" />
                                </div>
                                <div v-if="isLockedFromQuote" class="mb-2 text-xs text-amber-600">
                                    Ce job est verrouille car il provient d'un devis accepte. Seule la planification peut etre modifiee.
                                </div>
                                <div class="flex flex-row space-x-6">
                                    <div class="lg:col-span-3">
                                        <p>
                                            Adresse du bien
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
                                            Coordonnees
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
                                        Details du job
                                    </p>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span> Job :</span>
                                        <span>{{ lastWorkNumber }} </span>
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span> Note :</span>
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
                                        <FloatingSelect v-model="form.status" label="Statut" :options="statusOptions" />
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between mt-5">
                                        <button type="button" disabled
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-200 bg-white text-green-800 shadow-sm hover:bg-green-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-green-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-green-300 dark:hover:bg-green-700 dark:focus:bg-green-700">
                                            Ajouter des champs</button>
                                    </div>
                                </div>
                            </div>
                        </div>
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
                                                JOB UNIQUE
                                            </span>
                                            <span
                                                class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                                                <span
                                                    class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                                                    Un job unique avec une ou plusieurs visites
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
                                                JOB RECURRENT
                                            </span>
                                            <span
                                                class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                                                <span
                                                    class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                                                    Un job contractuel avec des visites repetees
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
                                                            PLANIFICATION
                                                        </h3>
                                                    </div>
                                                    <div class="p-4 md:p-5">
                                                        <div class="flex flex-row space-x-1 mb-4">
                                                            <DatePicker v-model="form.start_date" label="Date de debut" :required="true"
                                                                placeholder="Choisir une date" />
                                                        </div>
                                                        <InputError class="mt-1" :message="form.errors.start_date" />
                                                        <div class="flex flex-row space-x-1">
                                                            <TimePicker v-model="form.start_time" label="Heure de debut"
                                                                placeholder="Choisir une heure" />
                                                            <TimePicker v-model="form.end_time"
                                                                label="Heure de fin"
                                                                placeholder="Choisir une heure" />
                                                        </div>
                                                        <div
                                                            class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-300">
                                                                Resume rapide
                                                            </div>
                                                            <div class="mt-2 space-y-1">
                                                                <div class="flex items-center justify-between">
                                                                    <span>Date</span>
                                                                    <span>{{ formatDateLabel(form.start_date) }}</span>
                                                                </div>
                                                                <div class="flex items-center justify-between">
                                                                    <span>Heure</span>
                                                                    <span>
                                                                        {{ form.start_time || '-' }}
                                                                        <span v-if="form.end_time"> - {{ form.end_time }}</span>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <p class="mt-2 text-[11px] text-stone-500 dark:text-neutral-500">
                                                                Cliquer un jour sur le calendrier pour remplir la date.
                                                            </p>
                                                        </div>
                                                        <div class="mt-4 block">
                                                            <label class="flex items-center">
                                                                <Checkbox name="remember"
                                                                    v-model:checked="form.later" />
                                                                <span class="ms-2 text-sm text-stone-600 dark:text-neutral-400">Planifier plus tard</span>
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
                                                            EQUIPE
                                                        </h3>
                                                    </div>
                                                    <div class="p-4 md:p-5">
                                                        <div class="space-y-3">
                                                            <div v-if="!teamMembers?.length"
                                                                class="text-sm text-stone-600 dark:text-neutral-400">
                                                                Aucun membre pour l'instant.
                                                            </div>
                                                            <div v-else class="space-y-2">
                                                                <label v-for="member in teamMembers" :key="member.id"
                                                                    class="flex items-start gap-3">
                                                                    <Checkbox v-model:checked="form.team_member_ids"
                                                                        :value="member.id" />
                                                                    <div class="flex flex-col">
                                                                        <span
                                                                            class="text-sm text-stone-800 dark:text-neutral-200">
                                                                            {{ member.user?.name ?? 'Membre equipe' }}
                                                                        </span>
                                                                        <span
                                                                            class="text-xs text-stone-500 dark:text-neutral-500">
                                                                            {{ member.user?.email ?? '-' }}
                                                                        </span>
                                                                        <span v-if="member.title"
                                                                            class="text-xs text-stone-500 dark:text-neutral-500">
                                                                            {{ member.title }}
                                                                        </span>
                                                                    </div>
                                                                </label>
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
                                                                Calendrier
                                                            </div>
                                                            <p class="text-xs text-stone-500 dark:text-neutral-500">
                                                                Le calendrier affiche les interventions deja planifiees.
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <select v-model="calendarTeamFilter"
                                                            class="h-8 w-full rounded-md border border-stone-200 bg-white/90 px-2 pe-8 text-[11px] text-stone-700 shadow-sm focus:border-emerald-500 focus:ring-emerald-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 sm:w-auto">
                                                            <option value="">Tous les membres</option>
                                                            <option v-for="member in teamMembers" :key="member.id"
                                                                :value="member.id">
                                                                {{ member.user?.name ?? 'Membre equipe' }}
                                                            </option>
                                                        </select>
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
                                                        PLANIFICATION
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <DatePicker v-model="form.start_date" label="Date de debut" :required="true"
                                                        placeholder="Choisir une date" />
                                                    <InputError class="mt-1" :message="form.errors.start_date" />
                                                    <div class="flex flex-row space-x-1 my-4">
                                                        <TimePicker v-model="form.start_time" label="Heure de debut"
                                                            placeholder="Choisir une heure" />
                                                        <TimePicker v-model="form.end_time" label="Heure de fin"
                                                            placeholder="Choisir une heure" />
                                                    </div>
                                                    <div
                                                        class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                                        <div class="text-xs font-semibold text-stone-700 dark:text-neutral-300">
                                                            Resume rapide
                                                        </div>
                                                        <div class="mt-2 space-y-1">
                                                            <div class="flex items-center justify-between">
                                                                <span>Date</span>
                                                                <span>{{ formatDateLabel(form.start_date) }}</span>
                                                            </div>
                                                            <div class="flex items-center justify-between">
                                                                <span>Heure</span>
                                                                <span>
                                                                    {{ form.start_time || '-' }}
                                                                    <span v-if="form.end_time"> - {{ form.end_time }}</span>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <p class="mt-2 text-[11px] text-stone-500 dark:text-neutral-500">
                                                            Cliquer un jour sur le calendrier pour remplir la date.
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
                                                        RECURRENCE
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
                                                        Definis la cadence des visites recurrentes.
                                                    </p>
                                                    <div id="hs-modal-custom-recurrence-event" class="mt-4 space-y-4">
                                                        <div>
                                                            <label for="hs-pro-ccremre"
                                                                class="mb-1.5 block text-[13px] text-stone-400 dark:text-neutral-500">
                                                                Repeter chaque :
                                                            </label>
                                                            <FloatingSelect v-model="form.frequency"
                                                                label="Frequence" :options="Frequence" />
                                                        </div>

                                                        <div v-if="form.frequency !== 'Daily'">
                                                            <label
                                                                class="mb-1.5 block text-[13px] text-stone-400 dark:text-neutral-500">
                                                                Repete le :
                                                            </label>
                                                            <p class="mb-2 text-[11px] text-stone-400 dark:text-neutral-500">
                                                                Choisis les jours ou les visites se repetent.
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
                                                            <FloatingSelect :label="'Fin'" v-model="form.ends"
                                                                :options="endOptions" />

                                                            <div class="w-full mt-4" v-if="form.ends === 'On'">
                                                                <DatePicker v-model="form.end_date" label="Date de fin"
                                                                    placeholder="Choisir une date" />
                                                            </div>
                                                            <div class="w-full flex items-center gap-x-2 mt-4" v-if="form.ends === 'After'">
                                                                <FloatingNumberMiniInput v-model="form.frequencyNumber"
                                                                    label="Nombre" />
                                                                <span
                                                                    class="text-xs text-stone-400 dark:text-neutral-500">fois</span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div
                                                        class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                                        <div class="text-xs font-semibold text-stone-700 dark:text-neutral-300">
                                                            Apercu des visites
                                                        </div>
                                                        <div class="mt-2 grid grid-cols-3 gap-3">
                                                            <div class="flex flex-col">
                                                                <span
                                                                    class="text-[11px] text-stone-500 dark:text-neutral-500">
                                                                    Premiere
                                                                </span>
                                                                <span
                                                                    class="text-xs text-stone-700 dark:text-neutral-300">
                                                                    {{ formatDateLabel(form.start_date) }}
                                                                </span>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span
                                                                    class="text-[11px] text-stone-500 dark:text-neutral-500">
                                                                    Derniere
                                                                </span>
                                                                <span
                                                                    class="text-xs text-stone-700 dark:text-neutral-300">
                                                                    {{ formatDateLabel(form.end_date) }}
                                                                </span>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span
                                                                    class="text-[11px] text-stone-500 dark:text-neutral-500">
                                                                    Total
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
                                                        EQUIPE
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <div class="space-y-3">
                                                        <div v-if="!teamMembers?.length"
                                                            class="text-sm text-stone-600 dark:text-neutral-400">
                                                            Aucun membre pour l'instant.
                                                        </div>
                                                        <div v-else class="space-y-2">
                                                            <label v-for="member in teamMembers" :key="member.id"
                                                                class="flex items-start gap-3">
                                                                <Checkbox v-model:checked="form.team_member_ids"
                                                                    :value="member.id" />
                                                                <div class="flex flex-col">
                                                                    <span
                                                                        class="text-sm text-stone-800 dark:text-neutral-200">
                                                                        {{ member.user?.name ?? 'Membre equipe' }}
                                                                    </span>
                                                                    <span
                                                                        class="text-xs text-stone-500 dark:text-neutral-500">
                                                                        {{ member.user?.email ?? '-' }}
                                                                    </span>
                                                                    <span v-if="member.title"
                                                                        class="text-xs text-stone-500 dark:text-neutral-500">
                                                                        {{ member.title }}
                                                                    </span>
                                                                </div>
                                                            </label>
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
                                                            Calendrier
                                                        </div>
                                                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                                                            Le calendrier affiche les interventions deja planifiees.
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center">
                                                    <select v-model="calendarTeamFilter"
                                                        class="h-8 w-full rounded-md border border-stone-200 bg-white/90 px-2 pe-8 text-[11px] text-stone-700 shadow-sm focus:border-emerald-500 focus:ring-emerald-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 sm:w-auto">
                                                        <option value="">Tous les membres</option>
                                                        <option v-for="member in teamMembers" :key="member.id"
                                                            :value="member.id">
                                                            {{ member.user?.name ?? 'Membre equipe' }}
                                                        </option>
                                                    </select>
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
                                FACTURATION
                            </h3>
                        </div>
                        <div class="p-4 md:p-5">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <FloatingSelect v-model="form.billing_mode" label="Mode de facturation"
                                    :options="billingModes" />
                                <FloatingSelect v-model="form.billing_grouping" label="Regroupement"
                                    :options="billingGroupings" />
                            </div>

                            <div v-if="form.billing_mode === 'per_segment' || form.billing_grouping === 'periodic' || form.billing_mode === 'deferred'"
                                class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                                <FloatingSelect v-model="form.billing_cycle" label="Cycle"
                                    :options="billingCycles" />
                                <FloatingInput v-if="form.billing_mode === 'deferred'"
                                    v-model="form.billing_delay_days" type="number" label="Delai (jours)" />
                            </div>

                            <div v-if="form.billing_mode === 'deferred'" class="mt-3">
                                <FloatingInput v-model="form.billing_date_rule"
                                    label="Regle de date (ex: 1er du mois)" />
                            </div>

                            <label v-if="form.billing_mode === 'end_of_job'" class="mt-4 flex items-center">
                                <Checkbox name="remember" v-model:checked="form.later" />
                                <span class="ms-2 text-sm text-stone-600 dark:text-neutral-400">Me rappeler de facturer a la fermeture du
                                    job</span>
                            </label>
                        </div>
                    </div>
                    <div
                        class="mt-4 mb-4 grid grid-cols-1 gap-4 justify-between bg-white  dark:bg-neutral-900 dark:border-neutral-700">

                        <div class="flex justify-between">
                            <button type="button"
                                class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700 action-feedback">
                                Annuler
                            </button>
                            <div>
                                <button type="button" disabled
                                    class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-stone-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-stone-500 action-feedback">
                                    Sauvegarder et creer un autre
                                </button>
                                <button id="hs-pro-in1trsbgwmdid1" type="submit"
                                    class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500 action-feedback">
                                    Sauvegarder le job
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
