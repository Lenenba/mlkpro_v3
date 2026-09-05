const STATUS_AXES = [
    { key: 'editorial', field: 'editorial_status' },
    { key: 'delivery', field: 'delivery_status' },
    { key: 'sync', field: 'sync_status' },
];

const normalizeStatus = (value) => String(value || '').trim().toLowerCase();

export const socialStatusAxes = (record, { includeEditorial = true } = {}) => {
    if (!record || typeof record !== 'object') {
        return [];
    }

    return STATUS_AXES
        .filter((axis) => includeEditorial || axis.key !== 'editorial')
        .map((axis) => ({
            key: axis.key,
            value: normalizeStatus(record[axis.field]),
        }))
        .filter((axis) => axis.value !== '');
};

export const needsSocialDeliveryVerification = (record) => {
    if (!record || typeof record !== 'object') {
        return false;
    }

    if (normalizeStatus(record.delivery_status) === 'unknown') {
        return true;
    }

    return (Array.isArray(record.targets) ? record.targets : [])
        .some((target) => normalizeStatus(target?.delivery_status) === 'unknown');
};

export const socialStatusToneClass = (status) => {
    const normalizedStatus = normalizeStatus(status);

    if (['approved', 'published', 'synced'].includes(normalizedStatus)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (['rejected', 'partial_failed', 'failed', 'error', 'reconnect_required', 'canceled'].includes(normalizedStatus)) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (['pending_approval', 'queued', 'submitted', 'remote_approval_required', 'publishing', 'sending', 'unknown'].includes(normalizedStatus)) {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (normalizedStatus === 'scheduled') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300';
};
