<script setup>
import { computed, onMounted, ref, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: {
        type: String,
    },
    options: {
        type: Array,
        required: true,
    },
    filterable: {
        type: Boolean,
        default: false,
    },
    filterPlaceholder: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: '',
    },
    optionValue: {
        type: String,
        default: 'id',
    },
    optionLabel: {
        type: String,
        default: 'name',
    },
    dense: {
        type: Boolean,
        default: false,
    },
});
const model = defineModel({
    type: [String, Number, Array],
    required: true,
});

const input = ref(null);
const filterQuery = ref('');
const attrs = useAttrs();

const normalizedOptions = computed(() =>
    (props.options || []).map((option, index) => {
        if (option && typeof option === 'object') {
            const value = option[props.optionValue] ?? option.value ?? option.id ?? index;
            const label = option[props.optionLabel] ?? option.label ?? option.name ?? String(value ?? '');
            const searchBase = option.search ?? '';
            const search = [label, value, searchBase]
                .map((item) => (item ?? '').toString().trim())
                .filter(Boolean)
                .join(' ')
                .toLowerCase();
            return {
                value,
                label,
                disabled: Boolean(option.disabled),
                key: option.key ?? value ?? label ?? index,
                search,
            };
        }

        return {
            value: option,
            label: String(option),
            disabled: false,
            key: option ?? index,
            search: String(option ?? '').toLowerCase(),
        };
    })
);

const filteredOptions = computed(() => {
    if (!props.filterable) {
        return normalizedOptions.value;
    }

    const query = filterQuery.value.trim().toLowerCase();
    if (!query) {
        return normalizedOptions.value;
    }

    const matches = normalizedOptions.value.filter((option) => option.search.includes(query));
    const selectedValues = Array.isArray(model.value) ? model.value : [model.value];
    const selectedSet = new Set(selectedValues.map((value) => String(value ?? '')));
    const selectedOptions = normalizedOptions.value.filter((option) => selectedSet.has(String(option.value ?? '')));
    const combined = [...selectedOptions, ...matches];

    return combined.filter((option, index, list) =>
        list.findIndex((entry) => entry.key === option.key) === index
    );
});

const hasSelection = computed(() => {
    if (Array.isArray(model.value)) {
        return model.value.length > 0;
    }

    if (model.value !== '' && model.value !== null && model.value !== undefined) {
        return true;
    }

    if (props.placeholder) {
        return true;
    }

    const emptyOption = normalizedOptions.value.find((option) =>
        option.value === '' || option.value === null || option.value === undefined
    );
    return Boolean(emptyOption && String(emptyOption.label ?? '').trim().length);
});

const selectAttrs = computed(() => {
    const { class: className, ...rest } = attrs;
    return rest;
});

const selectClass = computed(() => {
    const baseClass = props.dense
        ? 'peer block w-full rounded-sm border-stone-200 bg-white px-2.5 py-2 text-xs leading-4 text-stone-700 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:focus:ring-neutral-600 focus:pt-4 focus:pb-1'
        : 'peer p-4 pe-9 block w-full border-stone-200 rounded-sm text-sm focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:focus:ring-neutral-600 focus:pt-6 focus:pb-2 autofill:pt-6 autofill:pb-2';
    const filledClass = hasSelection.value
        ? (props.dense ? 'pt-4 pb-1' : 'pt-6 pb-2')
        : '';

    return [baseClass, filledClass, attrs.class].filter(Boolean);
});

const labelClass = computed(() => (
    props.dense
        ? [
            'absolute top-0 start-0 w-full px-2.5 py-2 pe-9 h-full truncate pointer-events-none transition ease-in-out duration-100 border border-transparent text-xs leading-4 text-stone-500 dark:text-neutral-500 peer-disabled:opacity-50 peer-disabled:pointer-events-none peer-focus:text-[10px] peer-focus:-translate-y-1 peer-focus:leading-3 peer-focus:text-stone-500 dark:peer-focus:text-neutral-500',
            hasSelection.value ? 'text-[10px] -translate-y-1 leading-3 text-stone-500 dark:text-neutral-500' : '',
        ]
        : [
            'absolute top-0 start-0 w-full p-4 pe-9 h-full truncate pointer-events-none transition ease-in-out duration-100 origin-[0_0] border border-transparent text-sm text-stone-500 dark:text-neutral-500 peer-disabled:opacity-50 peer-disabled:pointer-events-none peer-focus:scale-90 peer-focus:-translate-y-1.5 peer-focus:text-stone-500 dark:peer-focus:text-neutral-500',
            hasSelection.value ? 'scale-90 -translate-y-1.5 text-stone-500 dark:text-neutral-500' : '',
        ]
));

const isMultiple = computed(() => Boolean(selectAttrs.value?.multiple));

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <!-- Floating Select -->
    <div>
        <div v-if="filterable" class="mb-2">
            <input
                v-model="filterQuery"
                type="text"
                class="block w-full rounded-sm border-stone-200 bg-white px-3 py-2 text-xs text-stone-600 placeholder:text-stone-400 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:placeholder:text-neutral-500 dark:focus:ring-neutral-600"
                :placeholder="filterPlaceholder || label"
                :aria-label="filterPlaceholder || label"
                autocomplete="off"
            />
        </div>
        <div class="relative">
            <select v-model="model" ref="input" v-bind="selectAttrs" :class="selectClass">
                <option v-if="placeholder && !isMultiple" value="">{{ placeholder }}</option>
                <option v-for="option in filteredOptions" :key="option.key" :value="option.value" :disabled="option.disabled">
                    {{ option.label }}
                </option>
            </select>
            <label
                :class="labelClass">
                <span>{{ label }}</span>
                <span v-if="required" class="text-red-500 dark:text-red-400"> *</span>
            </label>
        </div>
    </div>
    <!-- End Floating Select -->
</template>
