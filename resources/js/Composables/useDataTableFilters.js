export default function useDataTableFilters(form, url, options = {}) {
    const baseOptions = {
        preserveState: true,
        preserveScroll: true,
        ...options,
    };

    const apply = (visitOptions = {}) => {
        form.get(url, {
            ...baseOptions,
            ...visitOptions,
        });
    };

    const clear = (visitOptions = {}) => {
        form.reset();
        form.get(url, {
            ...baseOptions,
            preserveState: false,
            ...visitOptions,
        });
    };

    return {
        apply,
        clear,
    };
}
