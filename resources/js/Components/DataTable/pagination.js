export const DATA_TABLE_PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];
export const DATA_TABLE_DEFAULT_PER_PAGE = 10;

export const normalizeDataTablePerPage = (value, fallback = DATA_TABLE_DEFAULT_PER_PAGE) => {
    const parsed = Number.parseInt(value, 10);
    const normalizedFallback = DATA_TABLE_PER_PAGE_OPTIONS.includes(fallback)
        ? fallback
        : DATA_TABLE_DEFAULT_PER_PAGE;

    return DATA_TABLE_PER_PAGE_OPTIONS.includes(parsed)
        ? parsed
        : normalizedFallback;
};

export const resolveDataTablePerPage = (...candidates) => {
    for (const candidate of candidates) {
        const parsed = Number.parseInt(candidate, 10);
        if (DATA_TABLE_PER_PAGE_OPTIONS.includes(parsed)) {
            return parsed;
        }
    }

    return DATA_TABLE_DEFAULT_PER_PAGE;
};

export const dataTablePerPageSelectOptions = DATA_TABLE_PER_PAGE_OPTIONS.map((value) => ({
    value,
    label: String(value),
}));
