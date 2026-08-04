import '../css/app.css';
import './bootstrap';
import 'preline'; // Import de Preline.js
import { createInertiaApp, router } from '@inertiajs/vue3';
import { Fragment, createApp, defineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import AppSeo from './Components/Seo/AppSeo.vue';
import { createI18nInstance, ensureI18nLocale } from './i18n';
import { applyAccessibilityPreferences, readAccessibilityPreferences } from './utils/accessibility';
import { createPrelineInitializer, refreshPrelineOverlays } from './utils/preline';

let i18nInstance = null;
let sessionReloading = false;
let csrfRefreshPromise = null;

// Initialisation du nom de l'application
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const setDocumentLang = (locale) => {
    if (typeof document !== 'undefined' && locale) {
        document.documentElement.lang = locale;
    }
};

const translate = (key, fallback, params = {}) => {
    if (i18nInstance?.global?.t) {
        return i18nInstance.global.t(key, params);
    }

    return fallback;
};

const dispatchToast = (type, message) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent('mlk-toast', {
        detail: { type, message },
    }));
};

const refreshCsrfToken = async () => {
    if (typeof window === 'undefined' || !window.axios) {
        return;
    }

    if (csrfRefreshPromise) {
        return csrfRefreshPromise;
    }

    csrfRefreshPromise = window.axios
        .get('/sanctum/csrf-cookie')
        .catch(() => {})
        .finally(() => {
            csrfRefreshPromise = null;
        });

    return csrfRefreshPromise;
};

const handleSessionExpired = (status) => {
    if (status !== 419 || sessionReloading || typeof window === 'undefined') {
        return;
    }
    sessionReloading = true;
    dispatchToast('warning', translate('session.expired_retry', 'Session expiree. Veuillez reessayer.'));
    setTimeout(() => {
        sessionReloading = false;
    }, 4000);
};

applyAccessibilityPreferences(readAccessibilityPreferences());

const ensurePrelineTabsHaveActive = () => {
    if (typeof document === 'undefined') {
        return;
    }

    document
        .querySelectorAll('[role="tablist"]:not(select):not(.--prevent-on-load-init)')
        .forEach((tabList) => {
            const toggles = Array.from(tabList.querySelectorAll('[data-hs-tab]'));
            if (!toggles.length) {
                return;
            }

            let activeToggle = toggles.find((toggle) => toggle.classList.contains('active'));
            if (!activeToggle) {
                activeToggle = toggles.find((toggle) => toggle.getAttribute('aria-selected') === 'true');
            }

            if (!activeToggle) {
                activeToggle = toggles[0];
            }

            if (!activeToggle.classList.contains('active')) {
                activeToggle.classList.add('active');
            }

            if (activeToggle.getAttribute('aria-selected') !== 'true') {
                activeToggle.setAttribute('aria-selected', 'true');
            }

            const targetId = activeToggle.getAttribute('data-hs-tab');
            if (targetId) {
                const target = document.querySelector(targetId);
                if (target && target.classList.contains('hidden')) {
                    target.classList.remove('hidden');
                }
            }
        });
};

const patchPrelineTabs = () => {
    if (typeof window === 'undefined' || !window.HSTabs || window.HSTabs.__mlkPatched) {
        return;
    }

    const safeAutoInit = () => {
        if (typeof document === 'undefined') {
            return;
        }

        if (!Array.isArray(window.$hsTabsCollection)) {
            window.$hsTabsCollection = [];
        }

        if (!window.HSTabs.__mlkAccessibilityPatched) {
            document.addEventListener('keydown', (event) => {
                if (typeof window.HSTabs?.accessibility === 'function') {
                    window.HSTabs.accessibility(event);
                }
            });
            window.HSTabs.__mlkAccessibilityPatched = true;
        }

        window.$hsTabsCollection = window.$hsTabsCollection.filter(
            (entry) => entry?.element?.el && document.contains(entry.element.el),
        );

        document
            .querySelectorAll('[role="tablist"]:not(select):not(.--prevent-on-load-init)')
            .forEach((tabList) => {
                const toggles = Array.from(tabList.querySelectorAll('[data-hs-tab]'));
                if (!toggles.length) {
                    return;
                }

                let activeToggle = toggles.find((toggle) => toggle.classList.contains('active'));
                if (!activeToggle) {
                    activeToggle = toggles.find((toggle) => toggle.getAttribute('aria-selected') === 'true');
                }
                if (!activeToggle) {
                    activeToggle = toggles[0];
                }

                if (activeToggle && !activeToggle.classList.contains('active')) {
                    activeToggle.classList.add('active');
                }
                if (activeToggle && activeToggle.getAttribute('aria-selected') !== 'true') {
                    activeToggle.setAttribute('aria-selected', 'true');
                }

                if (activeToggle) {
                    const targetId = activeToggle.getAttribute('data-hs-tab');
                    if (targetId) {
                        const target = document.querySelector(targetId);
                        if (target && target.classList.contains('hidden')) {
                            target.classList.remove('hidden');
                        }
                    }
                }

                const alreadyInit = window.$hsTabsCollection.find(
                    (entry) => entry?.element?.el === tabList,
                );
                if (!alreadyInit) {
                    new window.HSTabs(tabList);
                }
            });
    };

    window.HSTabs.autoInit = safeAutoInit;

    window.HSTabs.__mlkPatched = true;
};

patchPrelineTabs();

const initializePreline = createPrelineInitializer({
    beforeInitialize: () => {
        refreshPrelineOverlays();
        ensurePrelineTabsHaveActive();
    },
    onError: (error) => {
        if (import.meta.env.DEV) {
            console.warn('[preline] autoInit failed', error);
        }
    },
});

const authRoutePaths = [
    '/login',
    '/register',
    '/onboarding',
    '/forgot-password',
    '/confirm-password',
    '/two-factor-challenge',
    '/verify-email',
];

const isAuthenticationRoutePath = (url) => {
    if (typeof window === 'undefined' || !url) {
        return false;
    }

    const destination = new URL(String(url), window.location.origin);

    return authRoutePaths.includes(destination.pathname)
        || destination.pathname.startsWith('/reset-password/');
};

const isZiggyBoundaryPage = (page) => {
    const component = String(page?.component || '');

    return component.startsWith('Auth/') || component.startsWith('Onboarding/');
};

const shouldReloadForZiggyPage = (page) => {
    if (typeof document === 'undefined') {
        return false;
    }

    const currentGroup = document.documentElement.dataset.ziggyGroup;

    // Les pages d'authentification et d'onboarding utilisent volontairement la
    // carte complète. Toute traversée de cette frontière recharge le document
    // afin que la surface d'arrivée reçoive sa propre carte Ziggy.
    return isZiggyBoundaryPage(page)
        ? currentGroup !== 'full'
        : currentGroup === 'full';
};

// Configuration de l'application Inertia
const inertiaPages = import.meta.glob([
    './Pages/**/*.vue',
    '!./Pages/Demo/**/*.vue',
]);

const normalizeInertiaPath = (path) => String(path || '')
    .replace(/\\/g, '/')
    .replace(/\/+/g, '/')
    .toLowerCase();

const SeoLayout = defineComponent({
    name: 'SeoLayout',
    setup(_, { slots }) {
        return () => h(Fragment, null, [
            h(AppSeo),
            slots.default ? slots.default() : null,
        ]);
    },
});

const wrapWithLayouts = (layouts, page) => [...layouts, page]
    .reverse()
    .reduce((child, layout) => {
        layout.inheritAttrs = !!layout.inheritAttrs;
        return h(layout, { ...page.props }, () => child);
    });

const attachSeoLayout = (pageComponent) => {
    if (!pageComponent || pageComponent.__mlkSeoLayoutApplied) {
        return pageComponent;
    }

    const existingLayout = pageComponent.layout;

    if (!existingLayout) {
        pageComponent.layout = SeoLayout;
    } else if (typeof existingLayout === 'function') {
        pageComponent.layout = (render, page) => render(SeoLayout, null, {
            default: () => existingLayout(render, page),
        });
    } else {
        const layouts = Array.isArray(existingLayout) ? existingLayout : [existingLayout];
        pageComponent.layout = (render, page) => wrapWithLayouts([SeoLayout, ...layouts], page);
    }

    pageComponent.__mlkSeoLayoutApplied = true;

    return pageComponent;
};

const resolveInertiaPage = (name) => {
    const normalizedName = String(name || '')
        .replace(/\\/g, '/')
        .replace(/^\/+|\/+$/g, '')
        .replace(/\.vue$/i, '');

    const pagePath = `./Pages/${normalizedName}.vue`;
    const directMatch = inertiaPages[pagePath];
    if (directMatch) {
        return directMatch().then((module) => {
            attachSeoLayout(module.default || module);
            return module;
        });
    }

    // Fallback for inconsistent casing and path separators coming from backend names.
    const normalizedPath = normalizeInertiaPath(pagePath);
    const caseInsensitiveKey = Object.keys(inertiaPages).find(
        (key) => normalizeInertiaPath(key) === normalizedPath,
    );
    if (caseInsensitiveKey) {
        return inertiaPages[caseInsensitiveKey]().then((module) => {
            attachSeoLayout(module.default || module);
            return module;
        });
    }

    return Promise.reject(new Error(`Page not found: ${pagePath}`));
};

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolveInertiaPage(name),
    async setup({ el, App, props, plugin }) {
        const initialLocale = props.initialPage?.props?.locale || 'fr';
        i18nInstance = await createI18nInstance(initialLocale);
        setDocumentLang(initialLocale);

        // Création de l'application Vue
        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18nInstance);

        const mountedApp = vueApp.mount(el);
        initializePreline();

        return mountedApp;
    },
    progress: {
        color: '#4B5563', // Couleur de la barre de progression Inertia
    },
});

// Les vues d’authentification doivent recevoir leur document complet : leur
// validation peut ensuite rediriger vers une surface Ziggy différente.
router.on('before', (event) => {
    const visit = event?.detail?.visit;

    if (visit?.method?.toLowerCase() !== 'get' || !isAuthenticationRoutePath(visit.url)) {
        return;
    }

    window.location.assign(new URL(String(visit.url), window.location.origin).href);

    return false;
});

// Recharger le document aux frontières Ziggy, sinon réinitialiser Preline.js
// une seule fois après chaque navigation Inertia.
router.on('navigate', (event) => {
    if (shouldReloadForZiggyPage(event?.detail?.page)) {
        window.location.reload();

        return;
    }

    initializePreline();
});

router.on('success', async (event) => {
    const locale = event?.detail?.page?.props?.locale;
    if (i18nInstance && locale && i18nInstance.global.locale.value !== locale) {
        const resolvedLocale = await ensureI18nLocale(i18nInstance, locale);
        setDocumentLang(resolvedLocale);
    }
});

router.on('error', (event) => {
    const status = event?.detail?.response?.status;
    handleSessionExpired(status);
});

router.on('invalid', (event) => {
    const status = event?.detail?.response?.status;
    handleSessionExpired(status);
});

if (window?.axios?.interceptors?.response) {
    window.axios.interceptors.response.use(
        (response) => response,
        async (error) => {
            const status = error?.response?.status;
            const config = error?.config;

            if (status === 419 && config && !config.__mlkRetry) {
                config.__mlkRetry = true;
                try {
                    await refreshCsrfToken();
                    return window.axios(config);
                } catch (retryError) {
                    handleSessionExpired(status);
                    return Promise.reject(retryError);
                }
            }

            handleSessionExpired(status);
            return Promise.reject(error);
        },
    );
}
