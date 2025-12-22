<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, watch, ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import dayjs from 'dayjs';
import { Head, useForm, router } from '@inertiajs/vue3';
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

const props = defineProps({
    works: Object,
    work: Object,
    customer: Object,
    lastWorkNumber: String,
    teamMembers: Array,
});

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
    totalVisites : props.work?.totalVisits ?? 0,
    repeatsOn: props.work?.repeatsOn ?? [],
    status: props.work?.status ?? 'scheduled',
    subtotal: props.work?.subtotal ?? 0,
    total: props.work?.total ?? 0,
    team_member_ids: props.work?.team_members?.map(member => member.id) ?? [],
});

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

function formatWorksForFullCalendar(works) {
    if (!Array.isArray(works) || works.length === 0) {
        return [];
    }

    const dayMapping = {
        "Su": 0, "Mo": 1, "Tu": 2, "We": 3, "Th": 4, "Fr": 5, "Sa": 6
    };

    let events = [];

    works.forEach(work => {
        if (!work.start_date || !work.frequency || !work.repeatsOn) {
            return;
        }

        let startDate = new Date(work.start_date);
        let totalVisits = work.totalVisits || 1;
        let frequency = work.frequency.toLowerCase();
        let repeatsOn = work.repeatsOn ?? [];

        if (frequency === 'daily') {
            for (let i = 0; i < totalVisits; i++) {
                events.push({
                    id: work.id,
                    title: work.job_title + ' - ' + work.start_time,
                    start: dayjs(startDate).add(i, 'day').format('YYYY-MM-DD'),
                    end: dayjs(startDate).add(i, 'day').format('YYYY-MM-DD'),
                    allDay: true,
                });
            }
        } else if (frequency === 'weekly') {
            for (let i = 0; i < totalVisits; i++) {
                repeatsOn.forEach(day => {
                    let dayIndex = dayMapping[day];
                    let dayDiff = dayIndex - dayjs(startDate).day();
                    let newStartDate = dayjs(startDate).add(i, 'week').add(dayDiff, 'day');

                    events.push({
                        id: work.id,
                        title: work.job_title + ' - ' + work.start_time,
                        start: newStartDate.format('YYYY-MM-DD'),
                        allDay: true,
                    });
                });
            }
        } else if (frequency === 'monthly') {
            for (let i = 0; i < totalVisits; i++) {
                repeatsOn.forEach(day => {
                    let newStartDate = dayjs(startDate).add(i, 'month').date(day);

                    events.push({
                        id: work.id,
                        title: work.job_title + ' - ' + work.start_time,
                        start: newStartDate.format('YYYY-MM-DD'),
                        allDay: true,
                    });
                });
            }
        }
    });

    return events;
}

// Exemple d'utilisation :
const events = formatWorksForFullCalendar(props.works);

const calendarOptions = {
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth', // Affiche la semaine en cours
    weekends: true, // initial value
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'timeGridWeek,dayGridMonth', // Options de vue semaine/mois
    },
    events: events, // Assignation des événements au calendrier
    eventClick(arg) {
        // Redirection vers une page spécifique
        const workId = arg.event.id;
        router.get(`/work/${workId}`); // Adaptez l'URL selon votre configuration
    },
};

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
        onError: (errors) => {
            console.error('Validation errors:', errors);
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
                        class="p-5 space-y-3 flex flex-col bg-gray-100 border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                        <!-- Header -->
                        <div class="flex justify-between items-center mb-4">
                            <h1 class="text-xl inline-block font-semibold text-gray-800 dark:text-green-100">
                                Job pour : {{ customer.company_name }}
                            </h1>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="col-span-2 space-x-2">
                                <div class="mb-4" x-data="{ open: false }">
                                    <FloatingInput v-model="form.job_title" label="Titre du job" />
                                    <FloatingTextarea v-model="form.instructions" label="Instructions" />
                                </div>
                                <div class="flex flex-row space-x-6">
                                    <div class="lg:col-span-3">
                                        <p>
                                            Adresse du bien
                                        </p>
                                        <div class="text-xs text-gray-600 dark:text-neutral-400">
                                            {{ primaryProperty?.country ?? '-' }}
                                        </div>
                                        <div class="text-xs text-gray-600 dark:text-neutral-400">
                                            {{ primaryProperty?.street1 ?? '-' }}
                                        </div>
                                        <div class="text-xs text-gray-600 dark:text-neutral-400">
                                            {{ primaryProperty?.state ?? '-' }} - {{ primaryProperty?.zip ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <p>
                                            Coordonnees
                                        </p>
                                        <div class="text-xs text-gray-600 dark:text-neutral-400">
                                            {{ customer.first_name }} {{ customer.last_name }}
                                        </div>
                                        <div class="text-xs text-gray-600 dark:text-neutral-400">
                                            {{ customer.email }}
                                        </div>
                                        <div class="text-xs text-gray-600 dark:text-neutral-400">
                                            {{ customer.phone }}
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="bg-white p-4 rounded-sm border border-gray-100 dark:bg-neutral-900 dark:border-neutral-700">
                                <div class="lg:col-span-3">
                                    <p>
                                        Details du job
                                    </p>
                                    <div class="text-xs text-gray-600 dark:text-neutral-400 flex justify-between">
                                        <span> Job :</span>
                                        <span>{{ lastWorkNumber }} </span>
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-neutral-400 flex justify-between">
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
                                    <div class="text-xs text-gray-600 dark:text-neutral-400 flex justify-between mt-5">
                                        <button type="button" disabled
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-200 bg-white text-green-800 shadow-sm hover:bg-green-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-green-50 dark:bg-green-800 dark:border-green-700 dark:text-green-300 dark:hover:bg-green-700 dark:focus:bg-green-700">
                                            Ajouter des champs</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">

                        <!-- Audience -->
                        <div
                            class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                            <!-- Tab Nav -->
                            <nav class="relative z-0 flex border-b border-gray-200 dark:border-neutral-700"
                                aria-label="Tabs" role="tablist" aria-orientation="horizontal">
                                <!-- Nav Item -->
                                <button type="button"
                                    class="hs-tab-active:border-t-green-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-gray-300 focus:outline-none focus:border-t-gray-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-green-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600 active"
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
                                                <span class="block text-xs md:text-sm text-gray-700 dark:text-neutral-300">
                                                JOB UNIQUE
                                            </span>
                                            <span
                                                class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                                                <span
                                                    class="block text-xs md:text-sm text-gray-500 dark:text-neutral-500">
                                                    Un job unique avec une ou plusieurs visites
                                                </span>
                                            </span>
                                        </span>
                                    </span>
                                </button>
                                <!-- End Nav Item -->

                                <!-- Nav Item -->
                                <button type="button"
                                    class="hs-tab-active:border-t-green-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-gray-300 focus:outline-none focus:border-t-gray-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-green-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600"
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
                                            <span class="block text-xs md:text-sm text-gray-700 dark:text-neutral-300">
                                                JOB RECURRENT
                                            </span>
                                            <span
                                                class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                                                <span
                                                    class="block text-xs md:text-sm text-gray-500 dark:text-neutral-500">
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
                                            <div class="col-span-1 mb-4" x-data="{ open: false }">
                                                <div
                                                    class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                                                    <div
                                                        class="flex flex-row bg-gray-100 dark:bg-gray-600 border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                        <h3
                                                            class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                                                            PLANIFICATION
                                                        </h3>
                                                    </div>
                                                    <div class="p-4 md:p-5">
                                                        <div class="flex flex-row space-x-1 mb-4">
                                                            <DatePicker v-model="form.start_date" label="Date de debut"
                                                                placeholder="Choisir une date" />
                                                        </div>
                                                        <div class="flex flex-row space-x-1">
                                                            <TimePicker v-model="form.start_time" label="Heure de debut"
                                                                placeholder="Choisir une heure" />
                                                            <TimePicker v-model="form.end_time"
                                                                label="Heure de fin (optionnel)"
                                                                placeholder="Choisir une heure" />
                                                        </div>
                                                        <div class="mt-4 block">
                                                            <label class="flex items-center">
                                                                <Checkbox name="remember"
                                                                    v-model:checked="form.later" />
                                                                <span class="ms-2 text-sm text-gray-600 dark:text-neutral-400">Planifier plus tard</span>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div
                                                    class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70 mt-4">
                                                    <div
                                                        class="flex flex-row bg-gray-100 dark:bg-gray-600 border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                        <h3
                                                            class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                                                            EQUIPE
                                                        </h3>
                                                    </div>
                                                    <div class="p-4 md:p-5">
                                                        <div class="space-y-3">
                                                            <div v-if="!teamMembers?.length"
                                                                class="text-sm text-gray-600 dark:text-neutral-400">
                                                                Aucun membre pour l'instant.
                                                            </div>
                                                            <div v-else class="space-y-2">
                                                                <label v-for="member in teamMembers" :key="member.id"
                                                                    class="flex items-start gap-3">
                                                                    <Checkbox v-model:checked="form.team_member_ids"
                                                                        :value="member.id" />
                                                                    <div class="flex flex-col">
                                                                        <span
                                                                            class="text-sm text-gray-800 dark:text-neutral-200">
                                                                            {{ member.user?.name ?? 'Membre equipe' }}
                                                                        </span>
                                                                        <span
                                                                            class="text-xs text-gray-500 dark:text-neutral-500">
                                                                            {{ member.user?.email ?? '-' }}
                                                                        </span>
                                                                        <span v-if="member.title"
                                                                            class="text-xs text-gray-500 dark:text-neutral-500">
                                                                            {{ member.title }}
                                                                        </span>
                                                                    </div>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Deuxième div (2/3) -->
                                            <div class="col-span-2">
                                                <FullCalendar :options="calendarOptions"  ref="calendarRef"/>
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
                                        <div class="col-span-1 mb-4" x-data="{ open: false }">
                                            <div
                                                class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                                                <div
                                                    class="flex flex-row bg-gray-100 dark:bg-gray-600 border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                    <h3 class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                                                        PLANIFICATION
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <DatePicker v-model="form.start_date" label="Date de debut"
                                                        placeholder="Choisir une date" />
                                                    <div class="flex flex-row space-x-1 my-4">
                                                        <TimePicker v-model="form.start_time" label="Heure de debut"
                                                            placeholder="Choisir une heure" />
                                                        <TimePicker v-model="form.end_time" label="Heure de fin (optionnel)"
                                                            placeholder="Choisir une heure" />
                                                    </div>

                                                    <!-- Body -->
                                                    <div id="hs-modal-custom-recurrence-event"
                                                        class="overflow-y-auto [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-sm [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-thumb]:bg-gray-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                                                        <div class="space-y-3">
                                                            <div class="grid gap-y-5">
                                                                <!-- Item -->
                                                                <div>
                                                                    <label for="hs-pro-ccremre"
                                                                        class="mb-1.5 block text-[13px] text-gray-400 dark:text-neutral-500">
                                                                        Repeter chaque :
                                                                    </label>
                                                                    <FloatingSelect v-model="form.frequency"
                                                                        label="Frequence" :options="Frequence" />
                                                                </div>
                                                                <!-- End Item -->

                                                                <!-- Repeats on block: loop based on the selected frequency -->
                                                                <div v-if="form.frequency !== 'Daily'">
                                                                    <label
                                                                        class="mb-1.5 block text-[13px] text-gray-400 dark:text-neutral-500">
                                                                        Repete le :
                                                                    </label>
                                                                    <!-- If frequency is Weekly, display checkboxes for days of the week -->
                                                                    <div v-if="form.frequency === 'Weekly'"
                                                                        class="flex sm:justify-between items-center gap-x-1">
                                                                        <!-- Checkbox -->
                                                                            <SelectableItem :LoopValue="daysOfWeek" v-model="form.repeatsOn" />
                                                                        <!-- End Checkbox -->
                                                                    </div>
                                                                    <!-- If frequency is Monthly, display checkboxes for days of the month (1 to 31) -->
                                                                    <div v-else-if="form.frequency === 'Monthly'"
                                                                        class="flex flex-wrap gap-2">
                                                                        <!-- Grid -->
                                                                        <div class="mt-2  ">
                                                                            <!-- Checkbox -->
                                                                           <SelectableItem :LoopValue="daysOfMonth" v-model="form.repeatsOn" />
                                                                            <!-- End Checkbox -->
                                                                        </div>
                                                                        <!-- End Grid -->
                                                                    </div>

                                                                    <!-- Optionally, you could also add a block for Daily recurrence if nécessaire -->
                                                                </div>
                                                                <!-- End Repeats on block -->

                                                                <!-- Item -->
                                                                <div>

                                                                    <FloatingSelect  :label="'Fin'" v-model="form.ends"
                                                                        :options="endOptions" />

                                                                        <div class="w-full mt-4" v-if="form.ends === 'On'">
                                                                            <!-- Input -->
                                                                             <DatePicker v-model="form.end_date" label="Date de fin"
                                                                                placeholder="Choisir une date" />
                                                                            <!-- End Input -->
                                                                        </div>
                                                                        <div class="w-full flex items-center gap-x-2 mt-4"  v-if="form.ends === 'After'">
                                                                            <!-- Input -->
                                                                            <FloatingNumberMiniInput v-model="form.frequencyNumber"
                                                                                label="Nombre" />
                                                                            <!-- End Input -->
                                                                            <span
                                                                                class="text-xs text-gray-400 dark:text-neutral-500">fois</span>
                                                                        </div>
                                                                </div>
                                                                <!-- End Item -->
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <!-- End Body -->

                                                    <div class="mt-4 block">
                                                        <label for="hs-pro-ccremre"
                                                            class="mb-1.5 block text-[13px] text-gray-400 dark:text-neutral-500">
                                                            Visites :
                                                        </label>

                                                        <div class="grid grid-cols-3 gap-4">
                                                            <div class="flex flex-col">
                                                                <span for="hs-pro-ccremre"
                                                                    class="block text-sm text-gray-400 dark:text-neutral-500">
                                                                    Premiere :
                                                                </span>
                                                                <span for="hs-pro-ccremre"
                                                                    class="block text-xs text-gray-600 dark:text-neutral-400">
                                                                    {{ formatDateLabel(form.start_date)  }}
                                                                </span>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span for="hs-pro-ccremre"
                                                                    class="block text-sm text-gray-400 dark:text-neutral-500">
                                                                    Derniere :
                                                                </span>
                                                                <span for="hs-pro-ccremre"
                                                                    class="block text-xs text-gray-600 dark:text-neutral-400">
                                                                    {{ formatDateLabel(form.end_date)  }}
                                                                </span>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span for="hs-pro-ccremre"
                                                                    class="block text-sm text-gray-400 dark:text-neutral-500">
                                                                    Total :
                                                                </span>
                                                                <span for="hs-pro-ccremre"
                                                                    class="block text-xs text-gray-600 dark:text-neutral-400">
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
                                                    class="flex flex-row bg-gray-100 dark:bg-gray-600 border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                                                    <h3 class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                                                        EQUIPE
                                                    </h3>
                                                </div>
                                                <div class="p-4 md:p-5">
                                                    <div class="space-y-3">
                                                        <div v-if="!teamMembers?.length"
                                                            class="text-sm text-gray-600 dark:text-neutral-400">
                                                            Aucun membre pour l'instant.
                                                        </div>
                                                        <div v-else class="space-y-2">
                                                            <label v-for="member in teamMembers" :key="member.id"
                                                                class="flex items-start gap-3">
                                                                <Checkbox v-model:checked="form.team_member_ids"
                                                                    :value="member.id" />
                                                                <div class="flex flex-col">
                                                                    <span
                                                                        class="text-sm text-gray-800 dark:text-neutral-200">
                                                                        {{ member.user?.name ?? 'Membre equipe' }}
                                                                    </span>
                                                                    <span
                                                                        class="text-xs text-gray-500 dark:text-neutral-500">
                                                                        {{ member.user?.email ?? '-' }}
                                                                    </span>
                                                                    <span v-if="member.title"
                                                                        class="text-xs text-gray-500 dark:text-neutral-500">
                                                                        {{ member.title }}
                                                                    </span>
                                                                </div>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Deuxième div (2/3) -->
                                        <div class="col-span-2">
                                            <FullCalendar :options="calendarOptions"   ref="calendarRef"/>
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
                            class="flex flex-row bg-gray-100 dark:bg-gray-600 border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                            <h3 class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                                FACTURATION
                            </h3>
                        </div>
                        <div class="p-4 md:p-5">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="form.later" />
                                <span class="ms-2 text-sm text-gray-600 dark:text-neutral-400">Me rappeler de facturer a la fermeture du
                                    job</span>
                            </label>
                        </div>
                    </div>
                    <div
                        class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70 mt-4">
                        <div
                            class="flex flex-row bg-gray-100 dark:bg-gray-600 border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                            <h3 class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                                LIGNES
                            </h3>
                        </div>
                        <div class="p-4 md:p-5">
                            <ProductTableList v-model="form.products" @update:subtotal="updateSubtotal" />
                        </div>
                        <div
                            class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border-t-2 border-t-gray-100 rounded-sm  dark:bg-green-800 dark:border-green-700">

                            <div>
                            </div>
                            <div class="border-l border-gray-200 dark:border-neutral-700 rounded-sm p-4">
                                <!-- List Item -->
                                <div class="py-4 grid grid-cols-2 gap-x-4  dark:border-neutral-700">
                                    <div class="col-span-1">
                                        <p class="text-sm text-gray-500 dark:text-neutral-500">
                                            Sous-total:
                                        </p>
                                    </div>
                                    <div class="col-span-1 flex justify-end">
                                        <p>
                                            <a class="text-sm text-green-600 decoration-2 hover:underline font-medium focus:outline-none focus:underline dark:text-green-400 dark:hover:text-green-500"
                                                href="#">
                                                $ {{ form.subtotal }}
                                            </a>
                                        </p>
                                    </div>
                                </div>
                                <!-- End List Item -->
                                <!-- List Item -->
                                <div
                                    class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200 dark:border-neutral-700">
                                    <div class="col-span-1">
                                        <p class="text-sm text-gray-800 font-bold dark:text-neutral-500">
                                            Montant total:
                                        </p>
                                    </div>
                                    <div class="flex justify-end">
                                        <p class="text-sm text-gray-800 font-bold dark:text-neutral-200">
                                            $ {{ totalWithTaxes?.toFixed(2) }}
                                        </p>
                                    </div>
                                </div>
                                <!-- End List Item -->
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-4 mb-4 grid grid-cols-1 gap-4 justify-between bg-white  dark:bg-green-800 dark:border-green-700">

                        <div class="flex justify-between">
                            <button type="button"
                                class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-gray-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700">
                                Annuler
                            </button>
                            <div>
                                <button type="button" disabled
                                    class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-gray-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-gray-500">
                                    Sauvegarder et creer un autre
                                </button>
                                <button id="hs-pro-in1trsbgwmdid1" type="submit"
                                    class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                                    Sauvegarder le job
                                </button>
                            </div>
                        </div>
                    </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
