import '../css/app.css';
import './bootstrap';
import 'preline'; // Import de Preline.js
import ApexCharts from 'apexcharts';
import ClipboardJS from 'clipboard';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { createI18nInstance } from './i18n';
import { applyAccessibilityPreferences, readAccessibilityPreferences } from './utils/accessibility';

let i18nInstance = null;

// Initialisation du nom de l'application
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const setDocumentLang = (locale) => {
    if (typeof document !== 'undefined' && locale) {
        document.documentElement.lang = locale;
    }
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

// Fonction pour initialiser Preline.js après chaque navigation
const initializePreline = () => {
    if (window.HSStaticMethods && typeof window.HSStaticMethods.autoInit === 'function') {
        setTimeout(() => {
            try {
                ensurePrelineTabsHaveActive();
                window.HSStaticMethods.autoInit(); // Réinitialisation des composants Preline.js
            } catch (error) {
                // Evite de casser l'app si Preline rencontre un element invalide.
                if (import.meta.env.DEV) {
                    console.warn('[preline] autoInit failed', error);
                }
            }
        }, 100); // Ajoute un léger délai pour s'assurer que le DOM est rendu
    }
};

// Configuration de l'application Inertia
createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob([
                './Pages/**/*.vue',
                '!./Pages/Demo/**/*.vue',
            ]),
        ),
    setup({ el, App, props, plugin }) {
        const initialLocale = props.initialPage?.props?.locale || 'fr';
        i18nInstance = createI18nInstance(initialLocale);
        setDocumentLang(initialLocale);

        // Création de l'application Vue
        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18nInstance);

        // Initialisation de Preline.js après le montage de l'application
        vueApp.mixin({
            mounted() {
                initializePreline(); // Appeler Preline.js à chaque chargement de composant
            },
        });

        return vueApp.mount(el);
    },
    progress: {
        color: '#4B5563', // Couleur de la barre de progression Inertia
    },
});

// Réinitialiser Preline.js après chaque navigation Inertia
router.on('navigate', () => {
    initializePreline();
});

router.on('success', (event) => {
    const locale = event?.detail?.page?.props?.locale;
    if (i18nInstance && locale && i18nInstance.global.locale.value !== locale) {
        i18nInstance.global.locale.value = locale;
        setDocumentLang(locale);
    }
});


