const quickFormNames = new Set([
    'CustomerQuickForm',
    'ProductQuickForm',
    'ServiceQuickForm',
    'QuoteQuickDialog',
    'RequestQuickForm',
]);

let retrySequence = 0;

export const quickFormRetryUrl = (error, formName, origin) => {
    if (!(error instanceof TypeError) || !quickFormNames.has(formName)) {
        return null;
    }

    const address = error.message.match(/https?:\/\/[^\s"'<>]+/u)?.[0];
    if (!address) {
        return null;
    }

    try {
        const url = new URL(address);
        if (url.origin !== origin || url.username || url.password
            || !new RegExp(`^/build/assets/${formName}-[\\w-]+\\.js$`, 'u').test(url.pathname)) {
            return null;
        }

        return url;
    } catch {
        return null;
    }
};

export const createQuickFormLoader = (formName, loader, {
    origin = globalThis.location?.origin || '',
    importModule = (url) => import(/* @vite-ignore */ url),
} = {}) => {
    let failedFormUrl = null;

    return async () => {
        try {
            if (!failedFormUrl) {
                return await loader();
            }

            // Chromium retains failed imports. Retry only the requested form, never an arbitrary failed dependency.
            const retryUrl = new URL(failedFormUrl);
            retryUrl.searchParams.set('mlk_form_retry', String(++retrySequence));
            return await importModule(retryUrl.href);
        } catch (error) {
            failedFormUrl ||= quickFormRetryUrl(error, formName, origin);
            throw error;
        }
    };
};
