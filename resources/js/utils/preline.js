const scheduleAfterRender = (runtimeWindow, callback) => {
    if (typeof runtimeWindow.requestAnimationFrame === 'function') {
        runtimeWindow.requestAnimationFrame(callback);

        return;
    }

    runtimeWindow.setTimeout(callback, 0);
};

export const refreshPrelineOverlays = ({ getWindow = () => window } = {}) => {
    const runtimeWindow = getWindow();
    const runtimeDocument = runtimeWindow?.document;
    const overlayCollection = runtimeWindow?.$hsOverlayCollection;

    if (!runtimeDocument || !Array.isArray(overlayCollection)) {
        return;
    }

    const currentToggles = Array.from(runtimeDocument.querySelectorAll('[data-hs-overlay]'));
    const staleOverlays = overlayCollection
        .map(({ element }) => element)
        .filter((overlayInstance) => {
            const overlay = overlayInstance?.el;
            const previousToggles = overlayInstance?.toggleButtons;

            if (!overlay?.id || !Array.isArray(previousToggles)) {
                return false;
            }

            const nextToggles = currentToggles.filter(
                (toggle) => toggle.getAttribute('data-hs-overlay') === `#${overlay.id}`,
            );

            return !runtimeDocument.contains(overlay)
                || previousToggles.some((toggle) => !runtimeDocument.contains(toggle))
                || nextToggles.some((toggle) => !previousToggles.includes(toggle));
        });

    staleOverlays.forEach((overlayInstance) => {
        const overlay = overlayInstance.el;

        if (typeof overlayInstance.close === 'function') {
            overlayInstance.close(true);
        }

        if (typeof overlayInstance.destroy === 'function') {
            overlayInstance.destroy();
        }

        if (runtimeDocument.contains(overlay)) {
            overlay.classList.add('hidden');
            overlay.removeAttribute('aria-overlay');
            overlay.removeAttribute('tabindex');
        }

        runtimeDocument.getElementById(`${overlay.id}-backdrop`)?.remove();
    });

    if (staleOverlays.length > 0 && !runtimeDocument.querySelector('.hs-overlay.opened')) {
        runtimeDocument.body.style.overflow = '';
    }
};

export const createPrelineInitializer = ({
    getWindow = () => window,
    beforeInitialize = () => {},
    onError = () => {},
    schedule = scheduleAfterRender,
} = {}) => {
    let pending = false;

    return () => {
        const runtimeWindow = getWindow();
        const autoInit = runtimeWindow?.HSStaticMethods?.autoInit;

        if (pending || typeof autoInit !== 'function') {
            return;
        }

        pending = true;

        try {
            schedule(runtimeWindow, () => {
                pending = false;

                if (typeof runtimeWindow.HSStaticMethods?.autoInit !== 'function') {
                    return;
                }

                try {
                    beforeInitialize();
                    runtimeWindow.HSStaticMethods.autoInit();
                } catch (error) {
                    onError(error);
                }
            });
        } catch (error) {
            pending = false;
            onError(error);
        }
    };
};
