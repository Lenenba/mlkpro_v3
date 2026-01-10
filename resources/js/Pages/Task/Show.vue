<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    task: {
        type: Object,
        required: true,
    },
    canManage: {
        type: Boolean,
        default: false,
    },
});

const statusLabels = {
    todo: 'A faire',
    in_progress: 'En cours',
    done: 'Terminee',
};

const statusClass = (status) => {
    switch (status) {
        case 'todo':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'done':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        default:
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const statusLabel = computed(() => statusLabels[props.task?.status] || props.task?.status || '-');
const dueLabel = computed(() => humanizeDate(props.task?.due_date) || '-');
const assigneeLabel = computed(() => props.task?.assignee?.user?.name || '-');

const location = computed(() => props.task?.location || null);
const locationAddress = computed(() => {
    if (!location.value) {
        return null;
    }
    if (location.value.address) {
        return location.value.address;
    }
    const parts = [
        location.value.street1,
        location.value.street2,
        location.value.city,
        location.value.state,
        location.value.zip,
        location.value.country,
    ].filter(Boolean);
    return parts.length ? parts.join(', ') : null;
});

const mapUrl = computed(() =>
    locationAddress.value
        ? `https://www.google.com/maps?q=${encodeURIComponent(locationAddress.value)}&output=embed`
        : null
);

const mapLink = computed(() =>
    locationAddress.value
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(locationAddress.value)}`
        : null
);
</script>

<template>
    <Head title="Voir la tache" />
    <AuthenticatedLayout>
        <div class="max-w-4xl mx-auto space-y-4 rise-stagger">
            <div v-if="mapUrl" class="overflow-hidden bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <iframe
                    :src="mapUrl"
                    class="w-full h-56"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
                <div class="p-4">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Adresse</p>
                    <p class="mt-2 text-sm text-stone-800 dark:text-neutral-100">{{ locationAddress }}</p>
                    <a
                        v-if="mapLink"
                        :href="mapLink"
                        target="_blank"
                        rel="noopener"
                        class="mt-3 inline-flex text-xs font-medium text-emerald-600 hover:text-emerald-700"
                    >
                        Ouvrir dans Maps
                    </a>
                </div>
            </div>

            <div v-else class="bg-white border border-stone-200 rounded-sm p-4 text-sm text-stone-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-400">
                Adresse indisponible pour cette tache.
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ task.title || 'Tache' }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                            <span class="py-1 px-2 rounded-full font-medium" :class="statusClass(task.status)">
                                {{ statusLabel }}
                            </span>
                            <span>Due: {{ dueLabel }}</span>
                            <span>Assigne: {{ assigneeLabel }}</span>
                        </div>
                    </div>
                    <Link
                        v-if="canManage"
                        :href="route('task.index')"
                        class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                    >
                        Retour aux taches
                    </Link>
                </div>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <h2 class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Description</h2>
                <p class="mt-2 text-sm text-stone-700 dark:text-neutral-300">
                    {{ task.description || 'Aucune description.' }}
                </p>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <h2 class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Materiaux</h2>
                <div v-if="task.materials?.length" class="mt-3 space-y-2">
                    <div
                        v-for="material in task.materials"
                        :key="material.id || material.label"
                        class="flex items-center justify-between text-sm text-stone-700 dark:text-neutral-300"
                    >
                        <span>{{ material.label || material.product?.name || 'Element' }}</span>
                        <span>{{ material.quantity || 0 }} {{ material.unit || '' }}</span>
                    </div>
                </div>
                <p v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    Aucun materiau.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
