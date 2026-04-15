import { computed, ref, watch } from 'vue';

export const useDataTableSelection = (rows, options = {}) => {
    const selected = ref(Array.isArray(options.initialSelected) ? [...options.initialSelected] : []);
    const selectAllRef = ref(null);
    const rowKey = options.rowKey ?? 'id';
    const isRowSelectable = options.isRowSelectable ?? ((row) => !row?.__skeleton);

    const resolveRows = () => {
        const source = typeof rows === 'function' ? rows() : rows?.value ?? rows;

        return Array.isArray(source) ? source : [];
    };

    const resolveValue = (row, index) => {
        if (typeof rowKey === 'function') {
            return rowKey(row, index);
        }

        if (row && typeof row === 'object' && rowKey in row) {
            return row[rowKey];
        }

        return index;
    };

    const selectableRows = computed(() =>
        resolveRows().filter((row, index) => isRowSelectable(row, index))
    );

    const selectableValues = computed(() =>
        selectableRows.value
            .map((row, index) => resolveValue(row, index))
            .filter((value, index, collection) => collection.indexOf(value) === index)
    );

    const selectedOnPage = computed(() =>
        selected.value.filter((value) => selectableValues.value.includes(value))
    );

    const allSelected = computed(() =>
        selectableValues.value.length > 0 && selectedOnPage.value.length === selectableValues.value.length
    );

    const someSelected = computed(() =>
        selectedOnPage.value.length > 0 && !allSelected.value
    );

    const selectedCount = computed(() =>
        Array.from(new Set(selected.value.filter((value) => value !== null && value !== undefined))).length
    );

    watch(selected, (value) => {
        const normalized = Array.from(
            new Set((Array.isArray(value) ? value : []).filter((item) => item !== null && item !== undefined))
        );

        if (
            normalized.length !== value.length
            || normalized.some((item, index) => item !== value[index])
        ) {
            selected.value = normalized;
        }
    }, { deep: true });

    watch([allSelected, someSelected], () => {
        if (selectAllRef.value) {
            selectAllRef.value.indeterminate = someSelected.value;
        }
    });

    const toggleAll = (eventOrValue) => {
        const checked = typeof eventOrValue === 'boolean'
            ? eventOrValue
            : Boolean(eventOrValue?.target?.checked);

        const pageValues = new Set(selectableValues.value);

        if (checked) {
            const nextSelection = new Set(selected.value);
            pageValues.forEach((value) => nextSelection.add(value));
            selected.value = Array.from(nextSelection);

            return;
        }

        selected.value = selected.value.filter((value) => !pageValues.has(value));
    };

    const toggleSelection = (value, eventOrValue) => {
        const checked = typeof eventOrValue === 'boolean'
            ? eventOrValue
            : Boolean(eventOrValue?.target?.checked);

        if (value === null || value === undefined) {
            return;
        }

        const nextSelection = new Set(selected.value);

        if (checked) {
            nextSelection.add(value);
        } else {
            nextSelection.delete(value);
        }

        selected.value = Array.from(nextSelection);
    };

    const clearSelection = () => {
        selected.value = [];
    };

    const isSelected = (row, index) => selected.value.includes(resolveValue(row, index));

    return {
        selected,
        selectedCount,
        selectAllRef,
        allSelected,
        someSelected,
        selectableRows,
        toggleAll,
        toggleSelection,
        clearSelection,
        isSelected,
    };
};
