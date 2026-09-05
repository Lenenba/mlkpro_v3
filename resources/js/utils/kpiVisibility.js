export const KPI_VISIBILITY_STORAGE_PREFIX = 'mlkpro:ui:kpis:v1';
export const KPI_VISIBILITY_CHANGE_EVENT = 'mlkpro:kpi-visibility-change';

const storageSegment = (value, fallback) => {
    const normalized = String(value ?? '').trim() || fallback;

    return encodeURIComponent(normalized);
};

export const buildKpiVisibilityStorageKey = ({
    accountOwnerId,
    userId,
    moduleKey,
}) => [
    KPI_VISIBILITY_STORAGE_PREFIX,
    storageSegment(accountOwnerId, 'personal'),
    storageSegment(userId, 'guest'),
    storageSegment(moduleKey, 'module'),
].join(':');

export const parseKpiVisibilityValue = (value, defaultVisible = true) => {
    if (value === '1') {
        return true;
    }

    if (value === '0') {
        return false;
    }

    return Boolean(defaultVisible);
};

export const readKpiVisibility = (storage, key, defaultVisible = true) => {
    if (!storage || !key) {
        return Boolean(defaultVisible);
    }

    try {
        return parseKpiVisibilityValue(storage.getItem(key), defaultVisible);
    } catch {
        return Boolean(defaultVisible);
    }
};

export const writeKpiVisibility = (storage, key, visible) => {
    if (!storage || !key) {
        return false;
    }

    try {
        storage.setItem(key, visible ? '1' : '0');

        return true;
    } catch {
        return false;
    }
};
