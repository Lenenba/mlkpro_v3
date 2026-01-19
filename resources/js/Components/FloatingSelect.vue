<script setup>
import { computed, onMounted, ref, useAttrs, watch } from 'vue';

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
    emptyLabel: {
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
const isOpen = ref(false);
const activeIndex = ref(-1);
const isFocused = ref(false);
const isFiltering = ref(false);
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

const resolveMatch = (value) => {
    const cleaned = String(value ?? '').trim().toLowerCase();
    if (!cleaned) {
        return null;
    }

    const exact = normalizedOptions.value.find((option) => {
        if (String(option.label ?? '').toLowerCase() === cleaned) {
            return true;
        }
        return String(option.value ?? '').toLowerCase() === cleaned;
    });
    if (exact) {
        return exact;
    }

    const searchMatches = normalizedOptions.value.filter((option) => option.search.includes(cleaned));
    if (searchMatches.length === 1) {
        return searchMatches[0];
    }

    return null;
};

const syncFilterQuery = () => {
    const currentValue = Array.isArray(model.value) ? model.value[0] : model.value;
    const match = normalizedOptions.value.find((option) =>
        String(option.value ?? '') === String(currentValue ?? '')
    );
    filterQuery.value = match ? match.label : String(currentValue ?? '');
};

const filteredOptions = computed(() => {
    if (!props.filterable) {
        return normalizedOptions.value;
    }

    const query = isFiltering.value ? filterQuery.value.trim().toLowerCase() : '';
    if (!query) {
        return normalizedOptions.value;
    }

    const matches = normalizedOptions.value.filter((option) => option.search.includes(query));
    if (useFilterInput.value) {
        return matches;
    }
    const selectedValues = Array.isArray(model.value) ? model.value : [model.value];
    const selectedSet = new Set(selectedValues.map((value) => String(value ?? '')));
    const selectedOptions = normalizedOptions.value.filter((option) => selectedSet.has(String(option.value ?? '')));
    const combined = [...selectedOptions, ...matches];

    return combined.filter((option, index, list) =>
        list.findIndex((entry) => entry.key === option.key) === index
    );
});

const setActiveIndex = (index) => {
    const list = filteredOptions.value;
    if (!list.length) {
        activeIndex.value = -1;
        return;
    }

    let nextIndex = Math.max(0, Math.min(index, list.length - 1));
    for (let i = 0; i < list.length; i += 1) {
        const option = list[nextIndex];
        if (!option.disabled) {
            activeIndex.value = nextIndex;
            return;
        }
        nextIndex = (nextIndex + 1) % list.length;
    }

    activeIndex.value = -1;
};

const setActiveFromSelection = () => {
    const selectedValue = Array.isArray(model.value) ? model.value[0] : model.value;
    const selectedIndex = filteredOptions.value.findIndex((option) =>
        String(option.value ?? '') === String(selectedValue ?? '')
    );
    setActiveIndex(selectedIndex >= 0 ? selectedIndex : 0);
};

const moveActiveIndex = (direction) => {
    const list = filteredOptions.value;
    if (!list.length) {
        activeIndex.value = -1;
        return;
    }

    let nextIndex = activeIndex.value;
    if (nextIndex < 0) {
        nextIndex = direction > 0 ? 0 : list.length - 1;
    } else {
        nextIndex = (nextIndex + direction + list.length) % list.length;
    }

    for (let i = 0; i < list.length; i += 1) {
        const option = list[nextIndex];
        if (!option.disabled) {
            activeIndex.value = nextIndex;
            return;
        }
        nextIndex = (nextIndex + direction + list.length) % list.length;
    }
};

const openDropdown = () => {
    if (!useFilterInput.value || isOpen.value || isDisabled.value) {
        return;
    }
    isOpen.value = true;
    setActiveFromSelection();
};

const closeDropdown = () => {
    isOpen.value = false;
    activeIndex.value = -1;
};

const toggleDropdown = () => {
    if (!useFilterInput.value || isDisabled.value) {
        return;
    }
    if (isOpen.value) {
        closeDropdown();
        syncFilterQuery();
    } else {
        isFiltering.value = false;
        openDropdown();
        input.value?.focus();
    }
};

const selectOption = (option) => {
    if (!option || option.disabled) {
        return;
    }
    model.value = option.value;
    filterQuery.value = option.label;
    isFiltering.value = false;
    closeDropdown();
};

const handleFocus = () => {
    if (!useFilterInput.value) {
        return;
    }
    isFocused.value = true;
    isFiltering.value = false;
    openDropdown();
};

const handleBlur = () => {
    if (!useFilterInput.value) {
        return;
    }
    isFocused.value = false;
    isFiltering.value = false;
    closeDropdown();
    syncFilterQuery();
};

const handleInput = () => {
    if (!useFilterInput.value) {
        return;
    }
    isFiltering.value = true;
    if (isFocused.value && !isOpen.value) {
        openDropdown();
    }
};

const handleKeydown = (event) => {
    if (!useFilterInput.value) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!isOpen.value) {
            openDropdown();
            return;
        }
        moveActiveIndex(1);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (!isOpen.value) {
            openDropdown();
            return;
        }
        moveActiveIndex(-1);
        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        const match = resolveMatch(filterQuery.value);
        if (match) {
            selectOption(match);
            return;
        }

        if (isOpen.value && activeIndex.value >= 0) {
            selectOption(filteredOptions.value[activeIndex.value]);
            return;
        }
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        isFiltering.value = false;
        closeDropdown();
        syncFilterQuery();
    }
};

const hasSelection = computed(() => {
    if (props.filterable) {
        return true;
    }

    if (Array.isArray(model.value)) {
        return model.value.length > 0;
    }

    if (model.value !== '' && model.value !== null && model.value !== undefined) {
        return true;
    }

    if (!props.filterable && props.placeholder) {
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

const inputAttrs = computed(() => {
    const { class: className, multiple, ...rest } = attrs;
    return rest;
});

const isDisabled = computed(() => Boolean(selectAttrs.value?.disabled));

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
const useFilterInput = computed(() => props.filterable && !isMultiple.value);

watch(
    () => model.value,
    () => {
        if (useFilterInput.value && !isFocused.value) {
            syncFilterQuery();
        }
    },
    { immediate: true }
);

watch(
    () => useFilterInput.value,
    (enabled) => {
        if (enabled) {
            syncFilterQuery();
        } else {
            filterQuery.value = '';
        }
        isFiltering.value = false;
    }
);

watch(
    () => filterQuery.value,
    (value) => {
        if (!useFilterInput.value) {
            return;
        }

        if (String(value ?? '').trim() === '') {
            model.value = '';
            return;
        }

        if (isFocused.value && isOpen.value && isFiltering.value) {
            setActiveIndex(0);
        }

        const match = resolveMatch(value);
        if (match) {
            model.value = match.value;
        }
    }
);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <!-- Floating Select -->
    <div class="relative">
        <input
            v-if="useFilterInput"
            v-model="filterQuery"
            ref="input"
            v-bind="inputAttrs"
            :class="selectClass"
            :placeholder="filterPlaceholder || placeholder || label"
            :aria-label="filterPlaceholder || label"
            autocomplete="off"
            @focus="handleFocus"
            @blur="handleBlur"
            @input="handleInput"
            @keydown="handleKeydown"
        />
        <button
            v-if="useFilterInput"
            type="button"
            class="absolute inset-y-0 end-0 flex items-center px-3 text-stone-400 hover:text-stone-600 dark:text-neutral-500 dark:hover:text-neutral-300"
            :class="isDisabled ? 'pointer-events-none opacity-50' : ''"
            @mousedown.prevent
            @click="toggleDropdown"
            aria-label="Toggle options"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.104l3.71-3.873a.75.75 0 1 1 1.08 1.04l-4.24 4.43a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd" />
            </svg>
        </button>
        <select v-else v-model="model" ref="input" v-bind="selectAttrs" :class="selectClass">
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
        <div
            v-if="useFilterInput && isOpen"
            class="absolute z-30 mt-1 w-full max-h-60 overflow-auto rounded-sm border border-stone-200 bg-white py-1 text-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:border-neutral-700 dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]"
        >
            <button
                v-for="(option, index) in filteredOptions"
                :key="option.key"
                type="button"
                class="flex w-full items-center px-3 py-2 text-left text-sm text-stone-700 transition dark:text-neutral-200"
                :class="[
                    option.disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-stone-100 dark:hover:bg-neutral-800',
                    index === activeIndex ? 'bg-stone-100 dark:bg-neutral-800' : '',
                ]"
                @mouseenter="activeIndex = index"
                @mousedown.prevent="selectOption(option)"
            >
                {{ option.label }}
            </button>
            <div
                v-if="!filteredOptions.length && emptyLabel"
                class="px-3 py-2 text-xs text-stone-500 dark:text-neutral-400"
            >
                {{ emptyLabel }}
            </div>
        </div>
    </div>
    <!-- End Floating Select -->
</template>
