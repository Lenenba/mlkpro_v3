const normalizeCount = (value) => {
    const count = Number(value ?? 0);

    if (!Number.isFinite(count)) {
        return 0;
    }

    return Math.max(0, Math.trunc(count));
};

const normalizeErrorEntry = (entry) => {
    if (Array.isArray(entry)) {
        return entry.flatMap(normalizeErrorEntry);
    }

    if (entry && typeof entry === 'object') {
        if (typeof entry.message === 'string' && entry.message.trim()) {
            return [entry.message.trim()];
        }

        return Object.values(entry).flatMap(normalizeErrorEntry);
    }

    if (entry === null || entry === undefined) {
        return [];
    }

    const message = String(entry).trim();

    return message ? [message] : [];
};

export const normalizeBulkActionErrors = (errors) =>
    (Array.isArray(errors) ? errors : [])
        .flatMap(normalizeErrorEntry)
        .filter(Boolean);

export const normalizeBulkActionResult = (source = {}) => {
    if (!source || typeof source !== 'object') {
        return null;
    }

    const selectedCount = normalizeCount(source.selected_count ?? source.selectedCount);
    const processedCount = normalizeCount(source.processed_count ?? source.processedCount);
    const successCount = normalizeCount(source.success_count ?? source.successCount ?? processedCount);
    const failedCount = normalizeCount(source.failed_count ?? source.failedCount);
    const skippedCount = normalizeCount(
        source.skipped_count
        ?? source.skippedCount
        ?? Math.max(0, selectedCount - processedCount - failedCount)
    );

    return {
        message: typeof source.message === 'string' ? source.message.trim() : '',
        selectedCount,
        processedCount,
        successCount,
        failedCount,
        skippedCount,
        errors: normalizeBulkActionErrors(source.errors),
    };
};

export const createBulkActionFailureResult = ({
    message = '',
    errors = [],
    selectedCount = 0,
} = {}) => normalizeBulkActionResult({
    message,
    selected_count: selectedCount,
    processed_count: 0,
    success_count: 0,
    failed_count: selectedCount,
    skipped_count: 0,
    errors,
});

export const resolveBulkActionFeedbackType = (result) => {
    if (!result) {
        return 'success';
    }

    if (result.failedCount > 0 || result.errors.length > 0) {
        return result.successCount > 0 || result.skippedCount > 0 || result.processedCount > 0
            ? 'warning'
            : 'error';
    }

    if (result.skippedCount > 0) {
        return 'warning';
    }

    return 'success';
};

export const buildBulkActionSummary = (result, t) => {
    if (!result) {
        return '';
    }

    const metrics = [];
    const hasMetrics = result.selectedCount > 0
        || result.processedCount > 0
        || result.failedCount > 0
        || result.skippedCount > 0;

    if (hasMetrics) {
        metrics.push(t('alerts.bulk_action.summary.processed', { count: result.processedCount }));
        metrics.push(t('alerts.bulk_action.summary.succeeded', { count: result.successCount }));

        if (result.failedCount > 0) {
            metrics.push(t('alerts.bulk_action.summary.failed', { count: result.failedCount }));
        }

        if (result.skippedCount > 0) {
            metrics.push(t('alerts.bulk_action.summary.skipped', { count: result.skippedCount }));
        }
    }

    return [result.message, metrics.join(' · ')].filter(Boolean).join(' ');
};

export const extractBulkActionErrorMessages = (error) => {
    const validation = error?.response?.data?.errors;

    if (validation && typeof validation === 'object') {
        return Object.values(validation)
            .flatMap((value) => normalizeErrorEntry(value))
            .filter(Boolean);
    }

    return normalizeBulkActionErrors(error?.response?.data?.errors);
};

export const resolveBulkActionErrorMessage = (error, t, fallbackKey = 'alerts.bulk_action.request_failed') => {
    const validationMessages = extractBulkActionErrorMessages(error);

    return validationMessages[0]
        || error?.response?.data?.message
        || error?.message
        || t(fallbackKey);
};

export const dispatchBulkActionToast = (result, t) => {
    if (typeof window === 'undefined' || !result) {
        return;
    }

    const message = buildBulkActionSummary(result, t);

    if (!message) {
        return;
    }

    window.dispatchEvent(new CustomEvent('mlk-toast', {
        detail: {
            type: resolveBulkActionFeedbackType(result),
            message,
        },
    }));
};
