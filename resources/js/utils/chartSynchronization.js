export const createChartSynchronization = (synchronize) => {
    if (typeof synchronize !== 'function') {
        throw new TypeError('A chart synchronization callback is required.');
    }

    let synchronizationRequested = false;
    let synchronizationPromise = null;
    let disposed = false;

    const request = () => {
        if (disposed) {
            return Promise.resolve();
        }

        synchronizationRequested = true;

        if (synchronizationPromise) {
            return synchronizationPromise;
        }

        synchronizationPromise = (async () => {
            try {
                while (synchronizationRequested && !disposed) {
                    synchronizationRequested = false;
                    await synchronize();
                }
            } finally {
                synchronizationPromise = null;

                if (synchronizationRequested && !disposed) {
                    void request();
                }
            }
        })();

        return synchronizationPromise;
    };

    const dispose = () => {
        disposed = true;
        synchronizationRequested = false;
    };

    return {
        request,
        dispose,
    };
};
