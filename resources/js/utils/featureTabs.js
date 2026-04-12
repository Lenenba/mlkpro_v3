import {
    CalendarDays,
    CircleDollarSign,
    ClipboardCheck,
    ClipboardList,
    FileText,
    Wrench,
} from 'lucide-vue-next';
import { featureTabsCopy, featureTabsShowcaseCopy } from './publicCopy';

export const featureTabIconMap = {
    'calendar-days': CalendarDays,
    'file-text': FileText,
    'clipboard-check': ClipboardCheck,
    'clipboard-list': ClipboardList,
    'circle-dollar-sign': CircleDollarSign,
    wrench: Wrench,
};

export const featureTabIconOptions = [
    { value: 'calendar-days', label: 'Calendar Days' },
    { value: 'file-text', label: 'File Text' },
    { value: 'clipboard-check', label: 'Clipboard Check' },
    { value: 'clipboard-list', label: 'Clipboard List' },
    { value: 'circle-dollar-sign', label: 'Circle Dollar Sign' },
    { value: 'wrench', label: 'Wrench' },
];

export const defaultFeatureTabsTriggerFontSize = 28;
export const defaultFeatureTabsStyle = 'editorial';
export const featureTabStyleOptions = [
    { value: 'editorial', label: 'Editorial' },
    { value: 'workflow', label: 'Workflow' },
];

export const normalizeFeatureTabsTriggerFontSize = (value) => {
    const parsed = Number.parseInt(value, 10);
    if (!Number.isFinite(parsed)) {
        return defaultFeatureTabsTriggerFontSize;
    }

    return Math.min(Math.max(parsed, 18), 40);
};

export const normalizeFeatureTabsStyle = (value) => (
    value === 'workflow' ? 'workflow' : defaultFeatureTabsStyle
);

export const sanitizeFeatureTabIconKey = (value) => (
    Object.prototype.hasOwnProperty.call(featureTabIconMap, value) ? value : ''
);

const normalizeFeatureTabsLocale = (locale = 'fr') => {
    const value = String(locale || 'fr').toLowerCase();

    if (value.startsWith('fr')) {
        return 'fr';
    }

    if (value.startsWith('es')) {
        return 'es';
    }

    return 'en';
};

const normalizeItems = (items) => (
    Array.isArray(items)
        ? items
            .map((item) => String(item || '').trim())
            .filter((item) => item.length > 0)
        : []
);

export const createFeatureTabChild = (overrides = {}) => ({
    id: overrides.id || `feature-tab-child-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    label: overrides.label || '',
    title: overrides.title || '',
    body: overrides.body || '',
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
    cta_label: overrides.cta_label || '',
    cta_href: overrides.cta_href || '',
});

const normalizeChildren = (children) => (
    Array.isArray(children) ? children.map((child) => createFeatureTabChild(child)) : []
);

export const createFeatureTab = (overrides = {}) => ({
    id: overrides.id || `feature-tab-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    label: overrides.label || '',
    icon: sanitizeFeatureTabIconKey(overrides.icon || ''),
    items: normalizeItems(overrides.items),
    children: normalizeChildren(overrides.children),
    title: overrides.title || '',
    body: overrides.body || '',
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
    cta_label: overrides.cta_label || '',
    cta_href: overrides.cta_href || '',
    metric: overrides.metric || '',
    story: overrides.story || '',
    person: overrides.person || '',
    role: overrides.role || '',
    avatar_url: overrides.avatar_url || '',
    avatar_alt: overrides.avatar_alt || '',
});

export const ensureFeatureTabs = (tabs) => (
    Array.isArray(tabs) ? tabs.map((tab) => createFeatureTab(tab)) : []
);

export const resolveFeatureTabIconComponent = (tab) => (
    featureTabIconMap[sanitizeFeatureTabIconKey(tab?.icon) || 'wrench'] || Wrench
);

export const defaultFeatureTabs = (locale = 'fr') => {
    const resolvedLocale = normalizeFeatureTabsLocale(locale);
    const copy = featureTabsCopy[resolvedLocale] || featureTabsCopy.en || [];

    return ensureFeatureTabs(copy);
};

export const defaultFeatureTabsShowcaseSection = (locale = 'fr') => {
    const resolvedLocale = normalizeFeatureTabsLocale(locale);
    const copy = featureTabsShowcaseCopy[resolvedLocale] || featureTabsShowcaseCopy.en || {};

    return {
        layout: 'feature_tabs',
        background_color: '#f7f2e8',
        image_position: 'left',
        alignment: 'center',
        density: 'normal',
        tone: 'default',
        kicker: copy.kicker || '',
        title: copy.title || '',
        body: copy.body || '',
        feature_tabs_style: 'workflow',
        feature_tabs_font_size: defaultFeatureTabsTriggerFontSize,
        feature_tabs: defaultFeatureTabs(resolvedLocale),
        primary_label: '',
        primary_href: '',
        secondary_label: '',
        secondary_href: '',
    };
};
