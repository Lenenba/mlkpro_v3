import { router } from '@inertiajs/vue3';

const buildPayload = (form) => {
    const payload = typeof form.data === 'function'
        ? form.data()
        : { ...form };

    const nextPayload = Object.fromEntries(
        Object.entries(payload).filter(([, value]) => value !== '' && value !== null && value !== undefined)
    );

    if (typeof window !== 'undefined') {
        const currentUrl = new URL(window.location.href);
        const currentPerPage = currentUrl.searchParams.get('per_page');

        if (currentPerPage !== null && nextPayload.per_page === undefined) {
            nextPayload.per_page = currentPerPage;
        }
    }

    delete nextPayload.page;

    return nextPayload;
};

export default function useDataTableFilters(form, url, options = {}) {
    const baseOptions = {
        preserveState: true,
        preserveScroll: true,
        ...options,
    };

    const apply = (visitOptions = {}) => {
        router.get(url, buildPayload(form), {
            ...baseOptions,
            ...visitOptions,
        });
    };

    const clear = (visitOptions = {}) => {
        form.reset();
        router.get(url, buildPayload(form), {
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
