import '../css/app.css';
import './bootstrap';
import 'preline'; // Import de Preline.js
import ApexCharts from 'apexcharts';
import ClipboardJS from 'clipboard';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Initialisation du nom de l'application
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Fonction pour initialiser Preline.js après chaque navigation
const initializePreline = () => {
    if (window.HSStaticMethods && typeof window.HSStaticMethods.autoInit === 'function') {
        setTimeout(() => {
            window.HSStaticMethods.autoInit(); // Réinitialisation des composants Preline.js
        }, 100); // Ajoute un léger délai pour s'assurer que le DOM est rendu
    }
};

// Configuration de l'application Inertia
createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // Création de l'application Vue
        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue);

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
import { router } from '@inertiajs/vue3';
router.on('navigate', () => {
    initializePreline();
});
