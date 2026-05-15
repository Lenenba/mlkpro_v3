<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';
import CardTileTabs from '@/Components/UI/CardTileTabs.vue';

const props = defineProps({
    counts: {
        type: Object,
        default: () => ({}),
    },
    modelValue: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue']);

const { hasFeature } = useAccountFeatures();
const { t } = useI18n();
const canJobs = computed(() => hasFeature('jobs'));
const canRequests = computed(() => hasFeature('requests'));
const canQuotes = computed(() => hasFeature('quotes'));
const canInvoices = computed(() => hasFeature('invoices'));

const tabOrder = computed(() => {
    const tabs = [];
    if (canJobs.value) {
        tabs.push('active_works');
    }
    if (canRequests.value) {
        tabs.push('requests');
    }
    if (canQuotes.value) {
        tabs.push('quotes');
    }
    if (canJobs.value) {
        tabs.push('jobs');
    }
    if (canInvoices.value) {
        tabs.push('invoices');
    }
    return tabs;
});

const count = (key) => props.counts?.[key] ?? 0;

const tabMeta = computed(() => ({
    active_works: {
        id: 'active_works',
        buttonId: 'bar-with-underline-item-1',
        panelId: 'bar-with-underline-1',
        label: t('customers.tabs.active_works'),
        initials: 'AW',
        tone: 'rose',
    },
    requests: {
        id: 'requests',
        buttonId: 'bar-with-underline-item-2',
        panelId: 'bar-with-underline-2',
        label: t('customers.tabs.requests.label'),
        initials: 'RQ',
        tone: 'amber',
    },
    quotes: {
        id: 'quotes',
        buttonId: 'bar-with-underline-item-3',
        panelId: 'bar-with-underline-3',
        label: t('customers.tabs.quotes'),
        initials: 'QT',
        tone: 'sky',
    },
    jobs: {
        id: 'jobs',
        buttonId: 'bar-with-underline-item-4',
        panelId: 'bar-with-underline-4',
        label: t('customers.tabs.jobs'),
        initials: 'JB',
        tone: 'emerald',
    },
    invoices: {
        id: 'invoices',
        buttonId: 'bar-with-underline-item-5',
        panelId: 'bar-with-underline-5',
        label: t('customers.tabs.invoices'),
        initials: 'IV',
        tone: 'cyan',
    },
}));

const tabs = computed(() =>
    tabOrder.value.map((key) => ({
        ...tabMeta.value[key],
        meta: t('customers.tabs.items', { count: count(key) }),
    }))
);
</script>

<template>
    <CardTileTabs
        :model-value="modelValue"
        :tabs="tabs"
        aria-label="Tabs"
        @update:model-value="emit('update:modelValue', $event)"
    />
</template>
