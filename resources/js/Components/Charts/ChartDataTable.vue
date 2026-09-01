<script setup>
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    series: {
        type: Array,
        default: () => [],
    },
    caption: {
        type: String,
        default: '',
    },
    categoryLabel: {
        type: String,
        default: '',
    },
    valueLabel: {
        type: String,
        default: '',
    },
    unitLabel: {
        type: String,
        default: '',
    },
    formatter: {
        type: Function,
        default: null,
    },
    openByDefault: {
        type: Boolean,
        default: false,
    },
    chartTitle: {
        type: String,
        default: '',
    },
});

const { locale, t } = useI18n();
const tableId = `chart-data-${useId().replaceAll(':', '')}`;
const isOpen = ref(props.openByDefault);

const pointValue = (point) => {
    if (Array.isArray(point)) {
        return point.length > 1 ? point[1] : point[0];
    }

    if (point !== null && typeof point === 'object') {
        return point.y ?? point.value;
    }

    return point;
};

const pointCategory = (point) => {
    if (Array.isArray(point)) {
        return point.length > 1 ? point[0] : undefined;
    }

    if (point !== null && typeof point === 'object') {
        return point.x ?? point.label;
    }

    return undefined;
};

const normalizedSeries = computed(() => {
    const dataSets = props.series.filter((item) => item
        && typeof item === 'object'
        && !Array.isArray(item)
        && Array.isArray(item.data));

    if (dataSets.length === props.series.length && dataSets.length) {
        return dataSets.map((item, index) => ({
            name: item.name || t('charts.series', { number: index + 1 }),
            data: item.data,
        }));
    }

    return props.series.length
        ? [{ name: props.valueLabel || t('charts.value'), data: props.series }]
        : [];
});

const rowCount = computed(() => Math.max(
    props.categories.length,
    ...normalizedSeries.value.map((item) => item.data.length),
    0,
));

const rows = computed(() => Array.from({ length: rowCount.value }, (_, index) => ({
    key: `${index}-${props.categories[index] ?? ''}`,
    category: props.categories[index]
        ?? pointCategory(normalizedSeries.value[0]?.data[index])
        ?? index + 1,
    values: normalizedSeries.value.map((item) => pointValue(item.data[index])),
})));

const resolvedCaption = computed(() => props.caption || t('charts.data_table_caption'));
const resolvedCategoryLabel = computed(() => props.categoryLabel || t('charts.category'));
const toggleLabel = computed(() => isOpen.value ? t('charts.hide_data') : t('charts.show_data'));
const toggleAccessibleLabel = computed(() => {
    if (!props.chartTitle) {
        return toggleLabel.value;
    }

    return t(isOpen.value ? 'charts.hide_named_data' : 'charts.show_named_data', {
        title: props.chartTitle,
    });
});

const seriesLabel = (series) => props.unitLabel
    ? `${series.name} (${props.unitLabel})`
    : series.name;

const formattedValue = (value, series, row, rowIndex, seriesIndex) => {
    if (props.formatter) {
        const formatted = props.formatter(value, {
            category: row.category,
            rowIndex,
            series,
            seriesIndex,
        });

        if (formatted !== null && formatted !== undefined && formatted !== '') {
            return formatted;
        }
    }

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const numericValue = Number(value);

    return Number.isFinite(numericValue)
        ? numericValue.toLocaleString(locale.value)
        : String(value);
};
</script>

<template>
    <details
        v-if="rows.length"
        class="group rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"
        :open="openByDefault"
        @toggle="isOpen = $event.currentTarget.open"
    >
        <summary
            class="cursor-pointer select-none px-3 py-2 text-xs font-semibold text-stone-600 marker:text-stone-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-600 dark:text-neutral-300 dark:marker:text-neutral-500"
            :aria-controls="tableId"
            :aria-label="toggleAccessibleLabel"
        >
            {{ toggleLabel }}
        </summary>

        <div :id="tableId" class="overflow-x-auto border-t border-stone-200 dark:border-neutral-700">
            <table class="min-w-full divide-y divide-stone-200 text-left text-xs dark:divide-neutral-700">
                <caption class="sr-only">
                    {{ resolvedCaption }}
                </caption>
                <thead class="bg-stone-50 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                    <tr>
                        <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold">
                            {{ resolvedCategoryLabel }}
                        </th>
                        <th
                            v-for="seriesItem in normalizedSeries"
                            :key="seriesItem.name"
                            scope="col"
                            class="whitespace-nowrap px-3 py-2 text-right font-semibold"
                        >
                            {{ seriesLabel(seriesItem) }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 text-stone-700 dark:divide-neutral-800 dark:text-neutral-200">
                    <tr v-for="(row, rowIndex) in rows" :key="row.key">
                        <th scope="row" class="whitespace-nowrap px-3 py-2 font-medium">
                            {{ row.category }}
                        </th>
                        <td
                            v-for="(value, seriesIndex) in row.values"
                            :key="`${row.key}-${seriesIndex}`"
                            class="whitespace-nowrap px-3 py-2 text-right tabular-nums"
                        >
                            {{ formattedValue(
                                value,
                                normalizedSeries[seriesIndex],
                                row,
                                rowIndex,
                                seriesIndex,
                            ) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </details>
</template>
