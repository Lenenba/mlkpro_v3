<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Header from './UI/Header.vue';
import Card from '@/Components/UI/Card.vue';
import CardNoHeader from '@/Components/UI/CardNoHeader.vue';
import DescriptionList from '@/Components/UI/DescriptionList.vue';
import CardNav from '@/Components/UI/CardNav.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    customer: Object,
    stats: {
        type: Object,
        default: () => ({}),
    },
    schedule: {
        type: Object,
        default: () => ({ tasks: [], upcomingJobs: [] }),
    },
    billing: {
        type: Object,
        default: () => ({ summary: {}, recentPayments: [] }),
    },
    activity: {
        type: Array,
        default: () => [],
    },
    lastInteraction: {
        type: Object,
        default: null,
    },
});

const properties = computed(() => props.customer?.properties || []);
const tags = computed(() => props.customer?.tags || []);

const formatDate = (value) => humanizeDate(value);
const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const propertyTypes = [
    { id: 'physical', name: 'Physical' },
    { id: 'billing', name: 'Billing' },
    { id: 'other', name: 'Other' },
];

const propertyTypeLabel = (type) => propertyTypes.find((option) => option.id === type)?.name || type;

const propertyHeading = (property) => {
    const chunks = [propertyTypeLabel(property.type), property.country].filter(Boolean);
    return chunks.join(' • ') || 'Property';
};

const editingTags = ref(false);
const tagsForm = useForm({
    tags: (props.customer?.tags || []).join(', '),
});

const startEditTags = () => {
    tagsForm.tags = (props.customer?.tags || []).join(', ');
    tagsForm.clearErrors();
    editingTags.value = true;
};

const cancelEditTags = () => {
    tagsForm.clearErrors();
    editingTags.value = false;
};

const submitTags = () => {
    if (tagsForm.processing) {
        return;
    }

    tagsForm.patch(route('customer.tags.update', props.customer.id), {
        preserveScroll: true,
        onSuccess: () => cancelEditTags(),
    });
};

const editingNotes = ref(false);
const notesForm = useForm({
    description: props.customer?.description || '',
});

const startEditNotes = () => {
    notesForm.description = props.customer?.description || '';
    notesForm.clearErrors();
    editingNotes.value = true;
};

const cancelEditNotes = () => {
    notesForm.clearErrors();
    editingNotes.value = false;
};

const submitNotes = () => {
    if (notesForm.processing) {
        return;
    }

    notesForm.patch(route('customer.notes.update', props.customer.id), {
        preserveScroll: true,
        onSuccess: () => cancelEditNotes(),
    });
};

const activityHref = (log) => {
    const type = log?.subject_type || '';
    const id = log?.subject_id;

    if (!id) {
        return null;
    }

    if (type.endsWith('Quote')) {
        return route('customer.quote.show', id);
    }

    if (type.endsWith('Invoice')) {
        return route('invoice.show', id);
    }

    if (type.endsWith('Work')) {
        return route('work.show', id);
    }

    if (type.endsWith('Customer')) {
        return route('customer.show', props.customer.id);
    }

    return null;
};

const showAddProperty = ref(false);
const editingPropertyId = ref(null);

const newPropertyForm = useForm({
    type: 'physical',
    is_default: false,
    street1: '',
    street2: '',
    city: '',
    state: '',
    zip: '',
    country: '',
});

const editPropertyForm = useForm({
    type: 'physical',
    street1: '',
    street2: '',
    city: '',
    state: '',
    zip: '',
    country: '',
});

const resetNewPropertyForm = () => {
    newPropertyForm.reset();
    newPropertyForm.type = 'physical';
    newPropertyForm.is_default = false;
    newPropertyForm.clearErrors();
};

const cancelEditProperty = () => {
    editingPropertyId.value = null;
    editPropertyForm.reset();
    editPropertyForm.clearErrors();
};

const startAddProperty = () => {
    cancelEditProperty();
    showAddProperty.value = true;
};

const cancelAddProperty = () => {
    showAddProperty.value = false;
    resetNewPropertyForm();
};

const submitNewProperty = () => {
    if (newPropertyForm.processing) {
        return;
    }

    newPropertyForm.post(route('customer.properties.store', { customer: props.customer.id }), {
        preserveScroll: true,
        onSuccess: () => cancelAddProperty(),
    });
};

const startEditProperty = (property) => {
    showAddProperty.value = false;
    resetNewPropertyForm();

    editingPropertyId.value = property.id;
    editPropertyForm.clearErrors();
    editPropertyForm.type = property.type || 'physical';
    editPropertyForm.street1 = property.street1 || '';
    editPropertyForm.street2 = property.street2 || '';
    editPropertyForm.city = property.city || '';
    editPropertyForm.state = property.state || '';
    editPropertyForm.zip = property.zip || '';
    editPropertyForm.country = property.country || '';
};

const submitEditProperty = () => {
    if (!editingPropertyId.value || editPropertyForm.processing) {
        return;
    }

    editPropertyForm.put(
        route('customer.properties.update', {
            customer: props.customer.id,
            property: editingPropertyId.value,
        }),
        {
            preserveScroll: true,
            onSuccess: () => cancelEditProperty(),
        }
    );
};

const setDefaultProperty = (property) => {
    if (property.is_default) {
        return;
    }

    router.put(
        route('customer.properties.default', {
            customer: props.customer.id,
            property: property.id,
        }),
        {},
        { preserveScroll: true }
    );
};

const deleteProperty = (property) => {
    if (!confirm('Delete this property?')) {
        return;
    }

    router.delete(
        route('customer.properties.destroy', {
            customer: props.customer.id,
            property: property.id,
        }),
        { preserveScroll: true }
    );
};
</script>

<template>
    <Head :title="customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() || 'Customer'" />
    <AuthenticatedLayout>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            <div class="md:col-span-2">
                <Header :customer="customer" />

                <Card class="mt-5">
                    <template #title>
                        <div class="flex items-center justify-between gap-3">
                            <span>Properties</span>
                            <button
                                type="button"
                                @click="showAddProperty ? cancelAddProperty() : startAddProperty()"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                Add property
                            </button>
                        </div>
                    </template>

                    <div
                        v-if="showAddProperty"
                        class="mb-6 rounded-sm border border-gray-200 bg-gray-50 p-4 dark:bg-neutral-900 dark:border-neutral-700"
                    >
                        <form class="space-y-3" @submit.prevent="submitNewProperty">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <FloatingSelect v-model="newPropertyForm.type" label="Type" :options="propertyTypes" />
                                    <InputError class="mt-1" :message="newPropertyForm.errors.type" />
                                </div>
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                    <input
                                        type="checkbox"
                                        v-model="newPropertyForm.is_default"
                                        class="size-3.5 rounded border-gray-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700"
                                    />
                                    Set as default
                                </label>
                                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.street1" label="Street 1" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.street1" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.street2" label="Street 2" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.street2" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.city" label="City" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.city" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.state" label="State" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.state" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.zip" label="Zip code" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.zip" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.country" label="Country" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.country" />
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    @click="cancelAddProperty"
                                    class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="newPropertyForm.processing"
                                    class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                >
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>

                    <div v-if="!properties.length" class="text-sm text-gray-500 dark:text-neutral-400">
                        No properties yet.
                    </div>

                    <ul v-else class="flex flex-col divide-y divide-gray-200 dark:divide-neutral-700">
                        <li v-for="property in properties" :key="property.id" class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex gap-x-3">
                                    <div class="py-2.5 px-3 border rounded-sm dark:border-neutral-700">
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="24"
                                            height="24"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            class="lucide lucide-house"
                                        >
                                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                                            <path
                                                d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"
                                            />
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-medium text-gray-800 dark:text-neutral-200">
                                                {{ propertyHeading(property) }}
                                            </p>
                                            <span
                                                v-if="property.is_default"
                                                class="inline-flex items-center rounded-sm bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-500/10 dark:text-green-400"
                                            >
                                                Default
                                            </span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-neutral-500">
                                            {{ property.street1
                                            }}<span v-if="property.street2">, {{ property.street2 }}</span>
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-neutral-500">
                                            {{ property.city }}<span v-if="property.state"> - {{ property.state }}</span
                                            ><span v-if="property.zip"> - {{ property.zip }}</span>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2 justify-end">
                                    <button
                                        type="button"
                                        :disabled="property.is_default"
                                        @click="setDefaultProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                    >
                                        Set as default
                                    </button>
                                    <button
                                        type="button"
                                        @click="startEditProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-gray-200 text-gray-800 hover:bg-gray-300 focus:outline-none focus:bg-gray-300 dark:bg-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-500"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        @click="deleteProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-red-100 text-red-700 hover:bg-red-200 focus:outline-none focus:bg-red-200 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>

                            <div
                                v-if="editingPropertyId === property.id"
                                class="mt-4 rounded-sm border border-gray-200 bg-gray-50 p-4 dark:bg-neutral-900 dark:border-neutral-700"
                            >
                                <form class="space-y-3" @submit.prevent="submitEditProperty">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <FloatingSelect v-model="editPropertyForm.type" label="Type" :options="propertyTypes" />
                                            <InputError class="mt-1" :message="editPropertyForm.errors.type" />
                                        </div>
                                        <div></div>
                                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.street1" label="Street 1" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.street1" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.street2" label="Street 2" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.street2" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.city" label="City" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.city" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.state" label="State" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.state" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.zip" label="Zip code" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.zip" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.country" label="Country" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.country" />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            @click="cancelEditProperty"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="editPropertyForm.processing"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            Save changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </li>
                    </ul>
                </Card>

                <CardNav class="mt-5" :customer="customer" :stats="stats" />

                <Card class="mt-5">
                    <template #title>Schedule</template>

                    <div class="space-y-5">
                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-neutral-200">Upcoming jobs</h3>
                                <Link
                                    :href="route('jobs.index')"
                                    class="text-xs font-medium text-green-700 hover:underline dark:text-green-400"
                                >
                                    View all
                                </Link>
                            </div>
                            <div class="mt-3 space-y-2">
                                <div
                                    v-for="work in schedule?.upcomingJobs || []"
                                    :key="work.id"
                                    class="flex items-center justify-between gap-3 rounded-sm border border-gray-200 px-3 py-2 text-sm dark:border-neutral-700"
                                >
                                    <div>
                                        <Link
                                            :href="route('work.show', work.id)"
                                            class="font-medium text-gray-800 hover:underline dark:text-neutral-200"
                                        >
                                            {{ work.job_title }}
                                        </Link>
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-neutral-400">
                                            Starts {{ formatDate(work.start_date || work.created_at) }}
                                        </div>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-neutral-400">
                                        {{ work.status }}
                                    </div>
                                </div>
                                <div
                                    v-if="!(schedule?.upcomingJobs || []).length"
                                    class="text-sm text-gray-500 dark:text-neutral-400"
                                >
                                    No upcoming jobs.
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-neutral-200">Tasks</h3>
                                <Link
                                    :href="route('task.index')"
                                    class="text-xs font-medium text-green-700 hover:underline dark:text-green-400"
                                >
                                    View all
                                </Link>
                            </div>
                            <div class="mt-3 space-y-2">
                                <div
                                    v-for="task in schedule?.tasks || []"
                                    :key="task.id"
                                    class="flex items-start justify-between gap-3 rounded-sm border border-gray-200 px-3 py-2 text-sm dark:border-neutral-700"
                                >
                                    <div>
                                        <div class="font-medium text-gray-800 dark:text-neutral-200">
                                            {{ task.title }}
                                        </div>
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-neutral-400">
                                            <span v-if="task.due_date">Due {{ formatDate(task.due_date) }}</span>
                                            <span v-else>No due date</span>
                                        </div>
                                    </div>
                                    <div class="text-right text-xs text-gray-500 dark:text-neutral-400">
                                        <div class="capitalize">{{ task.status }}</div>
                                        <div v-if="task.assignee">{{ task.assignee }}</div>
                                    </div>
                                </div>
                                <div v-if="!(schedule?.tasks || []).length" class="text-sm text-gray-500 dark:text-neutral-400">
                                    No tasks yet.
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card class="mt-5">
                    <template #title>Recent activity for this client</template>

                    <div class="space-y-3 text-sm">
                        <div
                            v-for="log in activity"
                            :key="log.id"
                            class="rounded-sm border border-gray-200 px-3 py-2 dark:border-neutral-700"
                        >
                            <div class="text-xs uppercase text-gray-500 dark:text-neutral-400">
                                {{ log.subject }} • {{ formatDate(log.created_at) }}
                            </div>
                            <div class="mt-1 text-sm text-gray-800 dark:text-neutral-200">
                                <Link v-if="activityHref(log)" :href="activityHref(log)" class="hover:underline">
                                    {{ log.description || log.action }}
                                </Link>
                                <span v-else>{{ log.description || log.action }}</span>
                            </div>
                        </div>
                        <div v-if="!activity.length" class="text-sm text-gray-500 dark:text-neutral-400">
                            No recent activity yet.
                        </div>
                    </div>
                </Card>
            </div>
            <div>
                <CardNoHeader>
                    <template #title>Contact information</template>
                    <DescriptionList :item="customer" />
                </CardNoHeader>
                <CardNoHeader class="mt-5">
                    <template #title>Tags</template>

                    <div v-if="!editingTags" class="space-y-3">
                        <div v-if="tags.length" class="flex flex-wrap gap-2">
                            <span
                                v-for="tag in tags"
                                :key="tag"
                                class="inline-flex items-center rounded-sm bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800 dark:bg-neutral-700 dark:text-neutral-200"
                            >
                                {{ tag }}
                            </span>
                        </div>
                        <div v-else class="text-sm text-gray-500 dark:text-neutral-400">No tags yet.</div>

                        <div class="flex justify-end">
                            <button
                                type="button"
                                @click="startEditTags"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Edit
                            </button>
                        </div>
                    </div>

                    <form v-else class="space-y-3" @submit.prevent="submitTags">
                        <div>
                            <FloatingInput v-model="tagsForm.tags" label="Tags (comma separated)" />
                            <InputError class="mt-1" :message="tagsForm.errors.tags" />
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <button
                                type="button"
                                @click="cancelEditTags"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="tagsForm.processing"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                Save
                            </button>
                        </div>
                    </form>
                </CardNoHeader>
                <CardNoHeader class="mt-5">
                    <template #title>Last client interaction</template>

                    <div v-if="lastInteraction" class="space-y-1 text-sm">
                        <div class="text-xs uppercase text-gray-500 dark:text-neutral-400">
                            {{ lastInteraction.subject }} • {{ formatDate(lastInteraction.created_at) }}
                        </div>
                        <div class="text-sm text-gray-800 dark:text-neutral-200">
                            {{ lastInteraction.description || lastInteraction.action }}
                        </div>
                    </div>
                    <div v-else class="text-sm text-gray-500 dark:text-neutral-400">No interactions yet.</div>
                </CardNoHeader>
                <Card class="mt-5">
                    <template #title>Billing history</template>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-sm border border-gray-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-gray-500 dark:text-neutral-400">Invoiced</div>
                            <div class="mt-1 text-sm font-semibold text-gray-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.total_invoiced) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-gray-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-gray-500 dark:text-neutral-400">Paid</div>
                            <div class="mt-1 text-sm font-semibold text-gray-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.total_paid) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-gray-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-gray-500 dark:text-neutral-400">Balance due</div>
                            <div class="mt-1 text-sm font-semibold text-gray-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.balance_due) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-neutral-200">Recent payments</h3>
                        <div class="mt-3 space-y-2 text-sm">
                            <div
                                v-for="payment in billing?.recentPayments || []"
                                :key="payment.id"
                                class="flex items-start justify-between gap-3 rounded-sm border border-gray-200 px-3 py-2 dark:border-neutral-700"
                            >
                                <div>
                                    <Link
                                        v-if="payment.invoice"
                                        :href="route('invoice.show', payment.invoice.id)"
                                        class="font-medium text-gray-800 hover:underline dark:text-neutral-200"
                                    >
                                        {{ payment.invoice.number || 'Invoice' }}
                                    </Link>
                                    <div v-else class="font-medium text-gray-800 dark:text-neutral-200">Payment</div>
                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-neutral-400">
                                        Paid {{ formatDate(payment.paid_at || payment.created_at) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-gray-800 dark:text-neutral-200">
                                        {{ formatCurrency(payment.amount) }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-neutral-400">
                                        {{ payment.method || payment.status || '' }}
                                    </div>
                                </div>
                            </div>
                            <div
                                v-if="!(billing?.recentPayments || []).length"
                                class="text-sm text-gray-500 dark:text-neutral-400"
                            >
                                No payments yet.
                            </div>
                        </div>
                    </div>
                </Card>
                <Card class="mt-5">
                    <template #title>Internal notes</template>

                    <div v-if="!editingNotes" class="space-y-3">
                        <p class="text-sm text-gray-700 whitespace-pre-wrap dark:text-neutral-200">
                            {{ customer.description || 'No notes yet.' }}
                        </p>
                        <div class="flex justify-end">
                            <button
                                type="button"
                                @click="startEditNotes"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Edit
                            </button>
                        </div>
                    </div>

                    <form v-else class="space-y-3" @submit.prevent="submitNotes">
                        <div>
                            <FloatingTextarea v-model="notesForm.description" label="Internal notes" />
                            <InputError class="mt-1" :message="notesForm.errors.description" />
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <button
                                type="button"
                                @click="cancelEditNotes"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="notesForm.processing"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                Save
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
