<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    workspaces: { type: Object, default: () => ({ data: [] }) },
    templates: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
    defaults: { type: Object, required: true },
    template_defaults: { type: Object, required: true },
    can_view_tenant: { type: Boolean, default: false },
    can_impersonate: { type: Boolean, default: false },
});

const steps = [
    { key: 'prospect', label: 'Prospect' },
    { key: 'scope', label: 'Scope' },
    { key: 'realism', label: 'Realism' },
    { key: 'access', label: 'Access kit' },
];

const cloneDeep = (value) => JSON.parse(JSON.stringify(value ?? null));

const normalizeBrandingProfile = (profile = {}) => ({
    name: '',
    tagline: '',
    description: '',
    logo_url: '',
    website_url: '',
    contact_url: '',
    support_url: '',
    booking_url: '',
    contact_email: '',
    reply_to_email: '',
    phone: '',
    address_line_1: '',
    address_line_2: '',
    city: '',
    province: '',
    country: '',
    postal_code: '',
    primary_color: '#0F766E',
    secondary_color: '#0F172A',
    accent_color: '#F59E0B',
    surface_color: '#F8FAFC',
    hero_background_color: '#ECFEFF',
    footer_background_color: '#0F172A',
    text_color: '#0F172A',
    muted_color: '#475569',
    facebook_url: '',
    instagram_url: '',
    linkedin_url: '',
    youtube_url: '',
    tiktok_url: '',
    whatsapp_url: '',
    footer_note: '',
    ...cloneDeep(profile || {}),
});

const normalizeState = (source = {}) => {
    const snapshot = cloneDeep(source) || {};

    return {
        ...snapshot,
        selected_modules: [...(snapshot.selected_modules || [])],
        scenario_packs: [...(snapshot.scenario_packs || [])],
        extra_access_roles: [...(snapshot.extra_access_roles || [])],
        extra_access_credentials: [...(snapshot.extra_access_credentials || [])],
        prefill_source: snapshot.prefill_source || '',
        prefill_payload: cloneDeep(snapshot.prefill_payload || {}),
        branding_profile: normalizeBrandingProfile(snapshot.branding_profile || {}),
    };
};

const toWorkspaceFormState = (workspace) => normalizeState({
    ...props.defaults,
    prospect_name: workspace.prospect_name || '',
    prospect_email: workspace.prospect_email || '',
    prospect_company: workspace.prospect_company || '',
    company_name: workspace.company_name || '',
    demo_workspace_template_id: workspace.template?.id || null,
    company_type: workspace.company_type || props.defaults.company_type,
    company_sector: workspace.company_sector || props.defaults.company_sector,
    seed_profile: workspace.seed_profile || props.defaults.seed_profile,
    team_size: workspace.team_size || props.defaults.team_size,
    locale: workspace.locale || props.defaults.locale,
    timezone: workspace.timezone || props.defaults.timezone,
    desired_outcome: workspace.desired_outcome || '',
    internal_notes: workspace.internal_notes || '',
    suggested_flow: workspace.suggested_flow || '',
    selected_modules: workspace.selected_modules || [],
    scenario_packs: workspace.scenario_packs || [],
    extra_access_roles: workspace.extra_access_roles || props.defaults.extra_access_roles || [],
    extra_access_credentials: workspace.extra_access_credentials || [],
    prefill_source: workspace.prefill_source || '',
    prefill_payload: workspace.prefill_payload || {},
    branding_profile: workspace.branding_profile || props.defaults.branding_profile,
    expires_at: workspace.expires_at ? String(workspace.expires_at).slice(0, 10) : props.defaults.expires_at,
});

const currentStep = ref(0);
const templateEditorOpen = ref(false);
const editingTemplateId = ref(null);
const submissionMode = ref('queue');
const prefillDraft = reactive({
    source: 'manual_json',
    payload: '',
});

const dateFromNow = (days) => {
    const date = new Date();
    date.setHours(12, 0, 0, 0);
    date.setDate(date.getDate() + Number(days || 0));

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

const dateDrafts = reactive(
    Object.fromEntries((props.workspaces?.data || []).map((workspace) => [
        workspace.id,
        workspace.expires_at ? String(workspace.expires_at).slice(0, 10) : '',
    ])),
);

const cloneDrafts = reactive(
    Object.fromEntries((props.workspaces?.data || []).map((workspace) => [
        workspace.id,
        {
            company_name: `${workspace.company_name} Copy`,
            prospect_name: workspace.prospect_name || '',
            prospect_email: workspace.prospect_email || '',
            prospect_company: workspace.prospect_company || '',
            clone_data_mode: 'keep_current_profile',
            seed_profile: workspace.seed_profile,
            expires_at: dateFromNow(14),
        },
    ])),
);

const form = useForm(normalizeState(props.defaults));
const templateForm = useForm(normalizeState(props.template_defaults));

const moduleGroups = computed(() => {
    const groups = {};
    for (const module of props.options.modules || []) {
        const category = module.category || 'Other';
        groups[category] = groups[category] || [];
        groups[category].push(module);
    }

    return Object.entries(groups);
});

const scenarioPackLookup = computed(() =>
    Object.fromEntries((props.options.scenario_packs || []).map((pack) => [pack.key, pack])),
);

const toneClass = (tone) => ({
    emerald: 'bg-emerald-100 text-emerald-700',
    blue: 'bg-blue-100 text-blue-700',
    amber: 'bg-amber-100 text-amber-700',
    rose: 'bg-rose-100 text-rose-700',
}[tone] || 'bg-stone-100 text-stone-700');

const filterClass = (value) => (
    props.filters?.status === value
        ? 'border-transparent bg-green-600 text-white'
        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200'
);

const formatNumber = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
const formatDate = (value) => value ? new Date(value).toLocaleDateString() : 'Not set';
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'Not set';
const confirmationAction = ref(null);
const confirmationWorkspace = ref(null);
const destructiveActionPending = ref(false);

const showActionConfirmation = computed(() => Boolean(confirmationAction.value && confirmationWorkspace.value));

const confirmationMeta = computed(() => {
    const workspace = confirmationWorkspace.value;

    if (!workspace || !confirmationAction.value) {
        return {
            title: '',
            body: '',
            impact: '',
            context: '',
            confirmLabel: '',
            pendingLabel: 'Processing...',
            confirmClass: '',
        };
    }

    if (confirmationAction.value === 'purge') {
        return {
            title: `Purge "${workspace.company_name}"?`,
            body: 'This will permanently delete live tenant access and demo data. The workspace will remain visible as purged for lifecycle history.',
            impact: 'Use this only when the demo is no longer needed or has reached the end of its sales lifecycle.',
            context: workspace.owner?.email
                ? `Tenant owner: ${workspace.owner.email}`
                : 'The tenant owner account will also be removed with the demo data.',
            confirmLabel: 'Purge demo',
            pendingLabel: 'Purging demo...',
            confirmClass: 'border-transparent bg-rose-600 text-white hover:bg-rose-700',
        };
    }

    const baselineContext = workspace.baseline_created_at
        ? `Saved baseline: ${formatDateTime(workspace.baseline_created_at)}.`
        : 'No explicit baseline snapshot is recorded yet; the current demo snapshot will be used as the reset reference.';
    const resetContext = workspace.last_reset_at
        ? ` Last reset: ${formatDateTime(workspace.last_reset_at)}.`
        : ' This demo has not been reset yet.';

    return {
        title: `Reset "${workspace.company_name}" to baseline?`,
        body: 'This will delete the current tenant data and reprovision the demo from its saved reference state.',
        impact: 'Use reset when you need to bring the demo back to a clean, story-ready environment.',
        context: `${baselineContext}${resetContext}`,
        confirmLabel: 'Reset demo',
        pendingLabel: 'Resetting demo...',
        confirmClass: 'border-transparent bg-amber-500 text-white hover:bg-amber-600',
    };
});

const brandingSwatches = (profile) => [
    { key: 'primary', label: 'Primary', value: profile?.primary_color || '#0F766E' },
    { key: 'secondary', label: 'Secondary', value: profile?.secondary_color || '#0F172A' },
    { key: 'accent', label: 'Accent', value: profile?.accent_color || '#F59E0B' },
    { key: 'surface', label: 'Surface', value: profile?.surface_color || '#F8FAFC' },
];

const defaultLogoUrl = (companyType, companySector) => {
    if (companyType === 'products' || companySector === 'retail') {
        return '/images/presets/company-4.svg';
    }

    if (companySector === 'restaurant') {
        return '/images/presets/company-2.svg';
    }

    if (['field_services', 'professional_services'].includes(companySector)) {
        return '/images/presets/company-3.svg';
    }

    return '/images/presets/company-1.svg';
};

const resolveRecommendedBrandingProfile = (companyType, companySector, fallback = {}) => {
    const preset = (props.options.presets || []).find(
        (item) => item.company_type === companyType && item.company_sector === companySector,
    );
    const profile = normalizeBrandingProfile(preset?.branding_profile || fallback || {});

    profile.logo_url = String(profile.logo_url || '').trim() || defaultLogoUrl(companyType, companySector);

    return profile;
};

const resolveBrandLogoUrl = (profile = {}, companyType, companySector, fallback = {}) => {
    const explicitLogoUrl = String(profile?.logo_url || '').trim();

    if (explicitLogoUrl) {
        return explicitLogoUrl;
    }

    return resolveRecommendedBrandingProfile(companyType, companySector, fallback).logo_url || '';
};

const activeTemplates = computed(() => (props.templates || []).filter((template) => template.is_active));
const archivedTemplates = computed(() => (props.templates || []).filter((template) => !template.is_active));
const selectedTemplate = computed(() => (props.templates || []).find((template) => template.id === form.demo_workspace_template_id) || null);
const selectedModuleLabels = computed(() =>
    (props.options.modules || [])
        .filter((module) => form.selected_modules.includes(module.key))
        .map((module) => module.label),
);
const selectedProfile = computed(() =>
    (props.options.seed_profiles || []).find((profile) => profile.value === form.seed_profile) || null,
);

const scenarioPackMatches = (pack, companyType, companySector, selectedModules) => {
    const packTypes = pack.company_types || [];
    const packSectors = pack.sectors || [];
    const requiredModules = pack.required_modules || [];

    if (packTypes.length && !packTypes.includes(companyType)) {
        return false;
    }

    if (packSectors.length && !packSectors.includes(companySector)) {
        return false;
    }

    return requiredModules.every((moduleKey) => selectedModules.includes(moduleKey));
};

const resolveScenarioPackDetails = (keys = []) => keys
    .map((key) => scenarioPackLookup.value[key])
    .filter(Boolean);

const formScenarioPacks = computed(() => {
    const matches = (props.options.scenario_packs || []).filter((pack) => (
        scenarioPackMatches(pack, form.company_type, form.company_sector, form.selected_modules || [])
    ));

    return matches.length ? matches : (props.options.scenario_packs || []);
});

const templateScenarioPacks = computed(() => {
    const matches = (props.options.scenario_packs || []).filter((pack) => (
        scenarioPackMatches(pack, templateForm.company_type, templateForm.company_sector, templateForm.selected_modules || [])
    ));

    return matches.length ? matches : (props.options.scenario_packs || []);
});

const selectedScenarioPackDetails = computed(() => resolveScenarioPackDetails(form.scenario_packs || []));
const selectedTemplateScenarioPackDetails = computed(() => resolveScenarioPackDetails(templateForm.scenario_packs || []));
const selectedExtraAccessRoles = computed(() =>
    (props.options.extra_access_roles || []).filter((role) => form.extra_access_roles.includes(role.key)),
);
const resolvedFormLogoUrl = computed(() =>
    resolveBrandLogoUrl(form.branding_profile, form.company_type, form.company_sector, props.defaults.branding_profile),
);
const templateCardLogoUrl = (template) =>
    resolveBrandLogoUrl(
        template?.branding_profile || {},
        template?.company_type,
        template?.company_sector,
        props.template_defaults.branding_profile,
    );

const stepIsValid = computed(() => {
    if (currentStep.value === 0) {
        return Boolean(form.prospect_name && form.company_name && form.company_type && form.company_sector);
    }

    if (currentStep.value === 1) {
        return (form.selected_modules || []).length > 0 && (form.scenario_packs || []).length > 0;
    }

    if (currentStep.value === 2) {
        return Boolean(form.seed_profile && Number(form.team_size) >= 1 && form.locale && form.timezone);
    }

    return Boolean(form.expires_at);
});

const toggleModule = (key) => {
    form.selected_modules = form.selected_modules.includes(key)
        ? form.selected_modules.filter((item) => item !== key)
        : [...form.selected_modules, key];
};

const toggleTemplateModule = (key) => {
    templateForm.selected_modules = templateForm.selected_modules.includes(key)
        ? templateForm.selected_modules.filter((item) => item !== key)
        : [...templateForm.selected_modules, key];
};

const toggleScenarioPack = (key) => {
    form.scenario_packs = form.scenario_packs.includes(key)
        ? form.scenario_packs.filter((item) => item !== key)
        : [...form.scenario_packs, key];
};

const toggleTemplateScenarioPack = (key) => {
    templateForm.scenario_packs = templateForm.scenario_packs.includes(key)
        ? templateForm.scenario_packs.filter((item) => item !== key)
        : [...templateForm.scenario_packs, key];
};

const toggleExtraAccessRole = (key) => {
    form.extra_access_roles = form.extra_access_roles.includes(key)
        ? form.extra_access_roles.filter((item) => item !== key)
        : [...form.extra_access_roles, key];
};

const applyRecommendedScenarioPacks = () => {
    form.scenario_packs = formScenarioPacks.value.map((pack) => pack.key);
};

const applyTemplateRecommendedScenarioPacks = () => {
    templateForm.scenario_packs = templateScenarioPacks.value.map((pack) => pack.key);
};

const applyRecommendedBranding = () => {
    const fallback = resolveRecommendedBrandingProfile(form.company_type, form.company_sector, props.defaults.branding_profile);

    form.branding_profile = {
        ...fallback,
        name: form.branding_profile?.name || form.company_name || fallback.name || '',
        contact_email: form.branding_profile?.contact_email || form.prospect_email || fallback.contact_email || '',
    };
};

const applyTemplateRecommendedBranding = () => {
    const fallback = resolveRecommendedBrandingProfile(templateForm.company_type, templateForm.company_sector, props.template_defaults.branding_profile);

    templateForm.branding_profile = {
        ...fallback,
        name: templateForm.branding_profile?.name || fallback.name || '',
        contact_email: templateForm.branding_profile?.contact_email || fallback.contact_email || '',
    };
};

const applyRecommendedExtraAccessRoles = () => {
    const preset = (props.options.presets || []).find(
        (item) => item.company_type === form.company_type && item.company_sector === form.company_sector,
    );

    form.extra_access_roles = [...(preset?.extra_access_roles || props.defaults.extra_access_roles || [])];
};

const applyPreset = (preset) => {
    form.demo_workspace_template_id = null;
    form.company_type = preset.company_type;
    form.company_sector = preset.company_sector;
    form.selected_modules = [...(preset.modules || [])];
    form.scenario_packs = [...(preset.scenario_packs || [])];
    form.extra_access_roles = [...(preset.extra_access_roles || form.extra_access_roles || [])];
    form.branding_profile = normalizeBrandingProfile(preset.branding_profile || form.branding_profile);
    form.suggested_flow = preset.suggested_flow || '';
};

const applyRecommendedModules = () => {
    const preset = (props.options.presets || []).find(
        (item) => item.company_type === form.company_type && item.company_sector === form.company_sector,
    );

    if (preset) {
        applyPreset(preset);
        return;
    }

    form.demo_workspace_template_id = null;
    form.selected_modules = (props.options.modules || [])
        .filter((module) => !module.company_types || module.company_types.includes(form.company_type))
        .map((module) => module.key);

    applyRecommendedScenarioPacks();
};

const applyTemplate = (template) => {
    form.demo_workspace_template_id = template.id;
    form.company_type = template.company_type;
    form.company_sector = template.company_sector;
    form.seed_profile = template.seed_profile;
    form.team_size = template.team_size;
    form.locale = template.locale;
    form.timezone = template.timezone;
    form.selected_modules = [...(template.selected_modules || [])];
    form.scenario_packs = [...(template.scenario_packs || [])];
    form.branding_profile = normalizeBrandingProfile(template.branding_profile || form.branding_profile);
    form.suggested_flow = template.suggested_flow || '';
    form.expires_at = dateFromNow(template.expiration_days || 14);
    currentStep.value = 0;
};

const clearTemplateSelection = () => {
    form.demo_workspace_template_id = null;
};

const applyPrefill = () => {
    if (!prefillDraft.payload.trim()) {
        return;
    }

    let parsed;

    try {
        parsed = JSON.parse(prefillDraft.payload);
    } catch (error) {
        window.alert('The prefill JSON is invalid.');
        return;
    }

    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        window.alert('The prefill payload must be a JSON object.');
        return;
    }

    const payload = parsed;

    form.prospect_name = payload.prospect_name || form.prospect_name;
    form.prospect_email = payload.prospect_email || form.prospect_email;
    form.prospect_company = payload.prospect_company || payload.company || form.prospect_company;
    form.company_name = payload.company_name || payload.demo_company_name || payload.company || form.company_name;
    form.company_type = payload.company_type || form.company_type;
    form.company_sector = payload.company_sector || payload.sector || form.company_sector;
    form.desired_outcome = payload.desired_outcome || payload.needs || form.desired_outcome;

    if (Array.isArray(payload.selected_modules)) {
        form.selected_modules = [...payload.selected_modules];
    }

    if (Array.isArray(payload.scenario_packs)) {
        form.scenario_packs = [...payload.scenario_packs];
    }

    if (Array.isArray(payload.extra_access_roles)) {
        form.extra_access_roles = [...payload.extra_access_roles];
    }

    form.prefill_source = prefillDraft.source;
    form.prefill_payload = parsed;
};

const nextStep = () => {
    if (stepIsValid.value && currentStep.value < steps.length - 1) {
        currentStep.value += 1;
    }
};

const previousStep = () => {
    if (currentStep.value > 0) {
        currentStep.value -= 1;
    }
};

const resetWorkspaceForm = () => {
    currentStep.value = 0;
    form.reset();
    Object.assign(form, normalizeState(props.defaults));
};

const submit = (saveAsDraft = false) => {
    submissionMode.value = saveAsDraft ? 'draft' : 'queue';

    form.transform((data) => ({
        ...data,
        save_as_draft: saveAsDraft,
    })).post(route('superadmin.demo-workspaces.store'), {
        preserveScroll: true,
        onSuccess: () => {
            if (!saveAsDraft) {
                resetWorkspaceForm();
            }
        },
        onFinish: () => {
            submissionMode.value = 'queue';
            form.transform((data) => data);
        },
    });
};

const openTemplateEditor = () => {
    templateEditorOpen.value = true;
};

const closeTemplateEditor = () => {
    templateEditorOpen.value = false;
    editingTemplateId.value = null;
    templateForm.reset();
    Object.assign(templateForm, normalizeState(props.template_defaults));
};

const currentStatusPayload = () => {
    const payload = {};

    if (props.filters?.status && props.filters.status !== 'all') {
        payload.status = props.filters.status;
    }

    if (props.filters?.sales_status && props.filters.sales_status !== 'all') {
        payload.sales_status = props.filters.sales_status;
    }

    return payload;
};

const closeActionConfirmation = () => {
    if (destructiveActionPending.value) {
        return;
    }

    confirmationAction.value = null;
    confirmationWorkspace.value = null;
};

const openActionConfirmation = (workspace, action) => {
    confirmationAction.value = action;
    confirmationWorkspace.value = workspace;
};

const seedTemplateFromWorkspace = () => {
    templateEditorOpen.value = true;
    editingTemplateId.value = null;
    Object.assign(templateForm, normalizeState({
        ...props.template_defaults,
        name: `${form.company_sector || form.company_type} template`,
        description: form.desired_outcome || '',
        company_type: form.company_type,
        company_sector: form.company_sector,
        seed_profile: form.seed_profile,
        team_size: form.team_size,
        locale: form.locale,
        timezone: form.timezone,
        selected_modules: [...form.selected_modules],
        scenario_packs: [...form.scenario_packs],
        branding_profile: form.branding_profile,
        suggested_flow: form.suggested_flow,
        expiration_days: 14,
    }));
};

const editTemplate = (template) => {
    templateEditorOpen.value = true;
    editingTemplateId.value = template.id;
    Object.assign(templateForm, normalizeState({
        ...props.template_defaults,
        name: template.name,
        description: template.description || '',
        company_type: template.company_type,
        company_sector: template.company_sector,
        seed_profile: template.seed_profile,
        team_size: template.team_size,
        locale: template.locale,
        timezone: template.timezone,
        expiration_days: template.expiration_days,
        selected_modules: [...(template.selected_modules || [])],
        scenario_packs: [...(template.scenario_packs || [])],
        branding_profile: template.branding_profile || props.template_defaults.branding_profile,
        suggested_flow: template.suggested_flow || '',
        is_default: template.is_default,
        is_active: template.is_active,
    }));
};

const submitTemplate = () => {
    const target = editingTemplateId.value
        ? route('superadmin.demo-workspaces.templates.update', editingTemplateId.value)
        : route('superadmin.demo-workspaces.templates.store');

    if (editingTemplateId.value) {
        templateForm.put(target, {
            preserveScroll: true,
            onSuccess: closeTemplateEditor,
        });

        return;
    }

    templateForm.post(target, {
        preserveScroll: true,
        onSuccess: closeTemplateEditor,
    });
};

const duplicateTemplate = (template) => {
    router.post(route('superadmin.demo-workspaces.templates.duplicate', template.id), {}, { preserveScroll: true });
};

const toggleTemplateArchive = (template, isActive) => {
    if (!window.confirm(`Do you want to ${isActive ? 'restore' : 'archive'} template "${template.name}"?`)) return;

    router.patch(route('superadmin.demo-workspaces.templates.archive', template.id), {
        is_active: isActive,
    }, { preserveScroll: true });
};

const updateExpiration = (workspace) => {
    if (!dateDrafts[workspace.id]) return;

    router.patch(route('superadmin.demo-workspaces.expiration.update', workspace.id), {
        expires_at: dateDrafts[workspace.id],
        ...currentStatusPayload(),
    }, { preserveScroll: true });
};

const extendExpiration = (workspace, days) => {
    router.patch(route('superadmin.demo-workspaces.expiration.extend', workspace.id), {
        days,
        ...currentStatusPayload(),
    }, { preserveScroll: true });
};

const updateDeliveryStatus = (workspace, sent) => {
    router.patch(route('superadmin.demo-workspaces.delivery.update', workspace.id), {
        sent,
        ...currentStatusPayload(),
    }, { preserveScroll: true });
};

const updateSalesStatus = (workspace, salesStatus) => {
    router.patch(route('superadmin.demo-workspaces.sales-status.update', workspace.id), {
        sales_status: salesStatus,
        ...currentStatusPayload(),
    }, { preserveScroll: true });
};

const loadWorkspaceIntoCreator = (workspace) => {
    Object.assign(form, toWorkspaceFormState(workspace));
    currentStep.value = 0;
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const cloneWorkspaceRequest = (workspace) => {
    const draft = cloneDrafts[workspace.id];

    router.post(route('superadmin.demo-workspaces.clone', workspace.id), {
        ...draft,
        seed_profile: draft?.clone_data_mode === 'keep_current_profile'
            ? workspace.seed_profile
            : draft.seed_profile,
        ...currentStatusPayload(),
    }, { preserveScroll: true });
};

const saveBaseline = (workspace) => {
    router.put(route('superadmin.demo-workspaces.baseline.snapshot', workspace.id), {
        ...currentStatusPayload(),
    }, { preserveScroll: true });
};

const resetWorkspaceToBaseline = (workspace) => {
    openActionConfirmation(workspace, 'reset');
};

const purgeWorkspace = (workspace) => {
    openActionConfirmation(workspace, 'purge');
};

const confirmDestructiveAction = () => {
    const workspace = confirmationWorkspace.value;

    if (!workspace || !confirmationAction.value || destructiveActionPending.value) {
        return;
    }

    destructiveActionPending.value = true;

    if (confirmationAction.value === 'purge') {
        router.delete(route('superadmin.demo-workspaces.destroy', workspace.id), {
            preserveScroll: true,
            data: currentStatusPayload(),
            onSuccess: closeActionConfirmation,
            onFinish: () => {
                destructiveActionPending.value = false;
            },
        });

        return;
    }

    router.post(route('superadmin.demo-workspaces.baseline.reset', workspace.id), {
        ...currentStatusPayload(),
    }, {
        preserveScroll: true,
        onSuccess: closeActionConfirmation,
        onFinish: () => {
            destructiveActionPending.value = false;
        },
    });
};

const copyAccessKit = async (workspace) => {
    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(workspace.access_kit_text);
            window.alert(`Access kit copied for "${workspace.company_name}".`);
            return;
        }
    } catch (error) {
        // ignore and fallback
    }

    window.prompt('Copy the access kit below:', workspace.access_kit_text);
};
</script>

<template>
    <Head title="Create Demo Workspace" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Create Demo Workspace</h1>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">Build a prospect-ready demo with templates, scenario packs, branding presets, and access-kit generation.</p>
                    </div>
                    <Link :href="route('superadmin.demo-workspaces.index')" class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        Back to demo list
                    </Link>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 p-5 dark:border-neutral-700">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">Template Library</h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-400">Save reusable demo setups with modules, scenario packs, and branding presets.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="openTemplateEditor">New template</button>
                            <button type="button" class="rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700" @click="seedTemplateFromWorkspace">Save current demo as template</button>
                        </div>
                    </div>
                </div>

                <div class="space-y-5 p-5">
                    <div v-if="activeTemplates.length" class="grid gap-4 xl:grid-cols-3">
                        <article v-for="template in activeTemplates" :key="template.id" class="rounded-sm border border-stone-200 bg-stone-50 p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-semibold text-stone-800 dark:text-neutral-100">{{ template.name }}</h3>
                                <span v-if="template.is_default" class="rounded-full bg-green-100 px-2 py-1 text-[11px] font-medium text-green-700">Default</span>
                            </div>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">{{ template.description || 'No description yet.' }}</p>
                            <div class="mt-3 space-y-1 text-sm text-stone-600 dark:text-neutral-400">
                                <div>{{ template.company_type }} / {{ template.company_sector }}</div>
                                <div>{{ template.seed_profile }} · {{ template.team_size }} seats · {{ template.expiration_days }} days</div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span v-for="label in template.module_labels" :key="label" class="rounded-full bg-white px-2 py-1 text-[11px] text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-neutral-200 dark:ring-neutral-700">{{ label }}</span>
                            </div>
                            <div v-if="template.scenario_pack_labels?.length" class="mt-3">
                                <div class="text-[11px] uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Scenario packs</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span v-for="label in template.scenario_pack_labels" :key="`scenario-${template.id}-${label}`" class="rounded-full bg-blue-50 px-2 py-1 text-[11px] text-blue-700 ring-1 ring-blue-200">{{ label }}</span>
                                </div>
                            </div>
                            <div class="mt-3 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <div v-if="templateCardLogoUrl(template)" class="flex size-11 items-center justify-center overflow-hidden rounded-sm border border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                            <img :src="templateCardLogoUrl(template)" :alt="`Logo for ${template.branding_profile?.name || template.name}`" class="max-h-full w-auto object-contain" />
                                        </div>
                                        <div>
                                            <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Brand</div>
                                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ template.branding_profile?.name || template.name }}</div>
                                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ template.branding_profile?.tagline || 'No tagline' }}</div>
                                        </div>
                                    </div>
                                    <div class="flex gap-1">
                                        <span v-for="swatch in brandingSwatches(template.branding_profile)" :key="`template-${template.id}-${swatch.key}`" class="size-4 rounded-full ring-1 ring-stone-200 dark:ring-neutral-700" :style="{ backgroundColor: swatch.value }"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" class="rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700" @click="applyTemplate(template)">Use</button>
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="editTemplate(template)">Edit</button>
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="duplicateTemplate(template)">Duplicate</button>
                                <button type="button" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100" @click="toggleTemplateArchive(template, false)">Archive</button>
                            </div>
                        </article>
                    </div>
                    <div v-else class="rounded-sm border border-dashed border-stone-300 bg-stone-50 p-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">No active template yet.</div>

                    <div v-if="templateEditorOpen" class="rounded-sm border border-stone-200 bg-stone-50 p-5 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-3 border-b border-stone-200 pb-4 dark:border-neutral-700">
                            <h3 class="text-base font-semibold text-stone-800 dark:text-neutral-100">{{ editingTemplateId ? 'Edit template' : 'Create template' }}</h3>
                            <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="closeTemplateEditor">Close</button>
                        </div>
                        <form class="mt-5 space-y-5" @submit.prevent="submitTemplate">
                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <FloatingInput v-model="templateForm.name" label="Template name" required />
                                <FloatingSelect v-model="templateForm.company_type" :options="options.company_types" label="Company type" required />
                                <FloatingSelect v-model="templateForm.company_sector" :options="options.sectors" label="Industry / sector" required />
                                <FloatingSelect v-model="templateForm.seed_profile" :options="options.seed_profiles" label="Seed profile" required option-value="value" option-label="label" />
                                <FloatingInput v-model="templateForm.team_size" type="number" label="Target staff seats" required />
                                <FloatingSelect v-model="templateForm.locale" :options="options.locales" label="Workspace language" required />
                                <FloatingSelect v-model="templateForm.timezone" :options="options.timezones" label="Timezone" required />
                                <FloatingInput v-model="templateForm.expiration_days" type="number" label="Expiration in days" required />
                            </div>
                            <FloatingInput v-model="templateForm.description" label="Template description" />
                            <FloatingTextarea v-model="templateForm.suggested_flow" label="Suggested testing path" />
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"><input v-model="templateForm.is_default" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600" /> Default for this use case</label>
                                <label class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"><input v-model="templateForm.is_active" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600" /> Active in quick library</label>
                            </div>
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Template modules</h4>
                                <div v-for="[category, modules] in moduleGroups" :key="`template-${category}`" class="space-y-2">
                                    <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">{{ category }}</div>
                                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        <button v-for="module in modules" :key="`template-${module.key}`" type="button" class="rounded-sm border p-4 text-left transition" :class="templateForm.selected_modules.includes(module.key) ? 'border-green-600 bg-green-50' : 'border-stone-200 bg-white hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-900'" @click="toggleTemplateModule(module.key)">
                                            <div class="flex items-start justify-between gap-3">
                                                <div><div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ module.label }}</div><p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ module.description }}</p></div>
                                                <span class="inline-flex size-5 items-center justify-center rounded-sm border text-[11px] font-semibold" :class="templateForm.selected_modules.includes(module.key) ? 'border-green-600 bg-green-600 text-white' : 'border-stone-300 text-transparent dark:border-neutral-600'">✓</span>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between gap-3">
                                    <h4 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Scenario packs</h4>
                                    <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="applyTemplateRecommendedScenarioPacks">Use recommended packs</button>
                                </div>
                                <div class="grid gap-3 lg:grid-cols-2">
                                    <button v-for="pack in templateScenarioPacks" :key="`template-pack-${pack.key}`" type="button" class="rounded-sm border p-4 text-left transition" :class="templateForm.scenario_packs.includes(pack.key) ? 'border-blue-600 bg-blue-50' : 'border-stone-200 bg-white hover:border-blue-300 dark:border-neutral-700 dark:bg-neutral-900'" @click="toggleTemplateScenarioPack(pack.key)">
                                        <div class="flex items-start justify-between gap-3">
                                            <div><div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ pack.label }}</div><p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ pack.description }}</p></div>
                                            <span class="inline-flex size-5 items-center justify-center rounded-sm border text-[11px] font-semibold" :class="templateForm.scenario_packs.includes(pack.key) ? 'border-blue-600 bg-blue-600 text-white' : 'border-stone-300 text-transparent dark:border-neutral-600'">✓</span>
                                        </div>
                                    </button>
                                </div>
                            </div>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h4 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Branding</h4>
                                    <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="applyTemplateRecommendedBranding">Use recommended branding</button>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <FloatingInput v-model="templateForm.branding_profile.name" label="Brand name" />
                                    <FloatingInput v-model="templateForm.branding_profile.tagline" label="Tagline" />
                                    <FloatingInput v-model="templateForm.branding_profile.logo_url" label="Logo URL" class="xl:col-span-2" />
                                    <FloatingInput v-model="templateForm.branding_profile.website_url" label="Website URL" />
                                    <FloatingInput v-model="templateForm.branding_profile.contact_email" label="Contact email" />
                                    <FloatingInput v-model="templateForm.branding_profile.phone" label="Phone" />
                                    <FloatingTextarea v-model="templateForm.branding_profile.description" label="Brand description" class="md:col-span-2 xl:col-span-4" />
                                </div>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">Leave the logo URL empty to use the default company logo preset.</p>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <label class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Primary color</div><input v-model="templateForm.branding_profile.primary_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                    <label class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Secondary color</div><input v-model="templateForm.branding_profile.secondary_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                    <label class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Accent color</div><input v-model="templateForm.branding_profile.accent_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                    <label class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Surface color</div><input v-model="templateForm.branding_profile.surface_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                </div>
                            </div>
                            <div class="flex flex-col gap-3 border-t border-stone-200 pt-5 dark:border-neutral-700 sm:flex-row sm:items-center sm:justify-between">
                                <div v-if="Object.keys(templateForm.errors || {}).length" class="text-sm text-red-600">{{ Object.values(templateForm.errors)[0] }}</div>
                                <div class="flex gap-2 sm:ms-auto">
                                    <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" :disabled="templateForm.processing" @click="closeTemplateEditor">Cancel</button>
                                    <button type="submit" class="rounded-sm bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="templateForm.processing">{{ templateForm.processing ? 'Saving…' : editingTemplateId ? 'Update template' : 'Create template' }}</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div v-if="archivedTemplates.length" class="space-y-3">
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Archived templates</h3>
                        <div class="grid gap-3 xl:grid-cols-2">
                            <article v-for="template in archivedTemplates" :key="`archived-${template.id}`" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                <div class="flex items-center justify-between gap-3">
                                    <div><div class="font-semibold text-stone-800 dark:text-neutral-100">{{ template.name }}</div><div>{{ template.company_type }} / {{ template.company_sector }}</div></div>
                                    <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="toggleTemplateArchive(template, true)">Restore</button>
                                </div>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 p-5 dark:border-neutral-700">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">Create a demo tenant</h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-400">Configure the scope, choose a scenario path, tune branding, then generate a prospect-ready access kit.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <div v-for="(step, index) in steps" :key="step.key" class="inline-flex items-center gap-2 rounded-sm border px-3 py-2 text-xs font-medium" :class="index === currentStep ? 'border-green-600 bg-green-50 text-green-700' : index < currentStep ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'">
                                <span class="inline-flex size-5 items-center justify-center rounded-full border text-[11px]" :class="index <= currentStep ? 'border-current' : 'border-stone-300 dark:border-neutral-600'">{{ index + 1 }}</span>
                                {{ step.label }}
                            </div>
                        </div>
                    </div>
                </div>

                <form class="space-y-6 p-5" @submit.prevent="submit">
                    <div v-if="selectedTemplate" class="rounded-sm border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div><span class="font-semibold">Template applied:</span> {{ selectedTemplate.name }}</div>
                            <button type="button" class="rounded-sm border border-blue-200 bg-white px-3 py-2 text-xs font-medium text-blue-700 hover:bg-blue-100" @click="clearTemplateSelection">Clear template</button>
                        </div>
                    </div>

                    <div v-if="currentStep === 0" class="space-y-4">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">CRM / intake prefill</h3>
                                    <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">Paste a discovery payload or CRM export to prefill the prospect context before you review it.</p>
                                </div>
                                <div class="w-full lg:max-w-48">
                                    <FloatingSelect v-model="prefillDraft.source" :options="options.prefill_sources" label="Prefill source" />
                                </div>
                            </div>
                            <div class="mt-4">
                                <FloatingTextarea v-model="prefillDraft.payload" label='Prefill JSON payload, for example {"prospect_name":"Ava","company":"Northwind","company_type":"services"}' />
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="applyPrefill">Apply prefill</button>
                                <span v-if="form.prefill_source" class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-200">Prefill source: {{ form.prefill_source }}</span>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <FloatingInput v-model="form.prospect_name" label="Prospect name" required />
                            <FloatingInput v-model="form.prospect_email" type="email" label="Prospect email" />
                            <FloatingInput v-model="form.prospect_company" label="Prospect company" />
                            <FloatingInput v-model="form.company_name" label="Demo company name" required />
                            <FloatingSelect v-model="form.company_type" :options="options.company_types" label="Company type" required />
                            <FloatingSelect v-model="form.company_sector" :options="options.sectors" label="Industry / sector" required />
                        </div>
                        <FloatingTextarea v-model="form.desired_outcome" label="Prospect needs / demo objective" />
                    </div>

                    <div v-else-if="currentStep === 1" class="space-y-5">
                        <div class="grid gap-3 lg:grid-cols-3">
                            <button v-for="preset in options.presets" :key="preset.key" type="button" class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-left hover:border-green-400 hover:bg-green-50 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:border-green-500" @click="applyPreset(preset)">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ preset.label }}</div>
                                <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ preset.description }}</p>
                            </button>
                        </div>

                        <div class="flex justify-end">
                            <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="applyRecommendedModules">Use recommended stack</button>
                        </div>

                        <div v-for="[category, modules] in moduleGroups" :key="category" class="space-y-3">
                            <div><h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ category }}</h3></div>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                <button v-for="module in modules" :key="module.key" type="button" class="rounded-sm border p-4 text-left transition" :class="form.selected_modules.includes(module.key) ? 'border-green-600 bg-green-50' : 'border-stone-200 bg-white hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-900'" @click="toggleModule(module.key)">
                                    <div class="flex items-start justify-between gap-3">
                                        <div><div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ module.label }}</div><p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ module.description }}</p></div>
                                        <span class="inline-flex size-5 items-center justify-center rounded-sm border text-[11px] font-semibold" :class="form.selected_modules.includes(module.key) ? 'border-green-600 bg-green-600 text-white' : 'border-stone-300 text-transparent dark:border-neutral-600'">✓</span>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <div class="space-y-3 border-t border-stone-200 pt-5 dark:border-neutral-700">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Scenario packs</h3>
                                    <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">Choose the value story you want sales to demo to the prospect.</p>
                                </div>
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="applyRecommendedScenarioPacks">Use recommended packs</button>
                            </div>
                            <div class="grid gap-3 lg:grid-cols-2">
                                <button v-for="pack in formScenarioPacks" :key="pack.key" type="button" class="rounded-sm border p-4 text-left transition" :class="form.scenario_packs.includes(pack.key) ? 'border-blue-600 bg-blue-50' : 'border-stone-200 bg-white hover:border-blue-300 dark:border-neutral-700 dark:bg-neutral-900'" @click="toggleScenarioPack(pack.key)">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ pack.label }}</div>
                                            <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ pack.description }}</p>
                                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">{{ pack.business_objective }}</p>
                                        </div>
                                        <span class="inline-flex size-5 items-center justify-center rounded-sm border text-[11px] font-semibold" :class="form.scenario_packs.includes(pack.key) ? 'border-blue-600 bg-blue-600 text-white' : 'border-stone-300 text-transparent dark:border-neutral-600'">✓</span>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="currentStep === 2" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <FloatingSelect v-model="form.seed_profile" :options="options.seed_profiles" label="Seed profile" required option-value="value" option-label="label" />
                            <FloatingInput v-model="form.team_size" type="number" label="Target staff seats" required />
                            <FloatingSelect v-model="form.locale" :options="options.locales" label="Workspace language" required />
                            <FloatingSelect v-model="form.timezone" :options="options.timezones" label="Timezone" required />
                        </div>
                        <FloatingTextarea v-model="form.internal_notes" label="Internal admin notes" />
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Provisioning preview</div>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">{{ selectedProfile?.description }}</p>
                            <div class="mt-4 grid gap-2 md:grid-cols-3 xl:grid-cols-5">
                                <div v-for="(value, key) in selectedProfile?.counts || {}" :key="key" class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="text-[11px] uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">{{ key }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ value }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="space-y-5">
                        <div class="grid gap-4 md:grid-cols-2">
                            <FloatingInput v-model="form.expires_at" type="date" label="Expiration date" required />
                            <FloatingInput :model-value="selectedModuleLabels.join(', ')" label="Selected modules" readonly />
                        </div>
                        <FloatingTextarea v-model="form.suggested_flow" label="Suggested testing path for the prospect" />

                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.15fr),minmax(320px,0.85fr)]">
                            <div class="space-y-4">
                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Prospect</div>
                                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ form.prospect_name || '-' }}</div>
                                            <div class="text-sm text-stone-600 dark:text-neutral-400">{{ form.prospect_email || 'No email provided' }}</div>
                                            <div class="text-sm text-stone-500 dark:text-neutral-400">{{ form.prospect_company || 'No prospect company set' }}</div>
                                        </div>
                                        <div>
                                            <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Demo company</div>
                                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ form.company_name || '-' }}</div>
                                            <div class="text-sm text-stone-600 dark:text-neutral-400">{{ form.company_type }} / {{ form.company_sector }}</div>
                                            <div class="text-sm text-stone-500 dark:text-neutral-400">{{ form.seed_profile }} · {{ form.team_size }} seats · {{ form.locale }}</div>
                                        </div>
                                    </div>
                                    <div class="mt-4">
                                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Modules</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <span v-for="label in selectedModuleLabels" :key="label" class="rounded-full bg-white px-3 py-1 text-xs text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-neutral-200 dark:ring-neutral-700">{{ label }}</span>
                                        </div>
                                    </div>
                                    <div class="mt-4">
                                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Scenario packs</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <span v-for="pack in selectedScenarioPackDetails" :key="pack.key" class="rounded-full bg-blue-50 px-3 py-1 text-xs text-blue-700 ring-1 ring-blue-200">{{ pack.label }}</span>
                                            <span v-if="!selectedScenarioPackDetails.length" class="text-sm text-stone-500 dark:text-neutral-400">No scenario pack selected yet.</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Branding</h3>
                                            <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">Tune the demo look so the workspace feels closer to the prospect.</p>
                                        </div>
                                        <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="applyRecommendedBranding">Use recommended branding</button>
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        <FloatingInput v-model="form.branding_profile.name" label="Brand name" />
                                        <FloatingInput v-model="form.branding_profile.tagline" label="Tagline" />
                                        <FloatingInput v-model="form.branding_profile.website_url" label="Website URL" />
                                        <FloatingInput v-model="form.branding_profile.contact_email" label="Contact email" />
                                        <FloatingInput v-model="form.branding_profile.phone" label="Phone" />
                                        <FloatingInput v-model="form.branding_profile.logo_url" label="Logo URL" />
                                    </div>
                                    <p class="mt-3 text-xs text-stone-500 dark:text-neutral-400">Leave the logo URL empty to use the default company logo preset.</p>
                                    <div class="mt-4">
                                        <FloatingTextarea v-model="form.branding_profile.description" label="Brand description" />
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                        <label class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Primary</div><input v-model="form.branding_profile.primary_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                        <label class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Secondary</div><input v-model="form.branding_profile.secondary_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                        <label class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Accent</div><input v-model="form.branding_profile.accent_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                        <label class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Surface</div><input v-model="form.branding_profile.surface_color" type="color" class="mt-3 h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900"></label>
                                    </div>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Multi-role access kit</h3>
                                            <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">Choose which secondary logins should be generated for the demo handoff.</p>
                                        </div>
                                        <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="applyRecommendedExtraAccessRoles">Use recommended roles</button>
                                    </div>
                                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        <button v-for="role in options.extra_access_roles || []" :key="role.key" type="button" class="rounded-sm border p-4 text-left transition" :class="form.extra_access_roles.includes(role.key) ? 'border-green-600 bg-green-50' : 'border-stone-200 bg-stone-50 hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-800'" @click="toggleExtraAccessRole(role.key)">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ role.label }}</div>
                                                    <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ role.description }}</p>
                                                </div>
                                                <span class="inline-flex size-5 items-center justify-center rounded-sm border text-[11px] font-semibold" :class="form.extra_access_roles.includes(role.key) ? 'border-green-600 bg-green-600 text-white' : 'border-stone-300 text-transparent dark:border-neutral-600'">✓</span>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <aside class="rounded-sm border border-stone-200 p-4 shadow-sm dark:border-neutral-700" :style="{ backgroundColor: form.branding_profile.surface_color || '#F8FAFC' }">
                                <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex items-start gap-3">
                                            <div v-if="resolvedFormLogoUrl" class="flex size-14 items-center justify-center overflow-hidden rounded-sm border border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                                <img :src="resolvedFormLogoUrl" :alt="`Logo for ${form.branding_profile.name || form.company_name || 'demo brand'}`" class="max-h-full w-auto object-contain" />
                                            </div>
                                            <div>
                                                <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Brand preview</div>
                                                <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">{{ form.branding_profile.name || form.company_name || 'Demo brand' }}</div>
                                                <div class="text-sm text-stone-600 dark:text-neutral-400">{{ form.branding_profile.tagline || 'No tagline set yet.' }}</div>
                                            </div>
                                        </div>
                                        <div class="flex gap-1">
                                            <span v-for="swatch in brandingSwatches(form.branding_profile)" :key="`preview-${swatch.key}`" class="size-4 rounded-full ring-1 ring-stone-200 dark:ring-neutral-700" :style="{ backgroundColor: swatch.value }"></span>
                                        </div>
                                    </div>
                                    <p class="mt-4 text-sm leading-6 text-stone-600 dark:text-neutral-400">{{ form.branding_profile.description || 'Use this space to align the story, tone, and company positioning with the prospect.' }}</p>
                                    <div class="mt-4 grid gap-3 text-sm text-stone-600 dark:text-neutral-400">
                                        <div><span class="font-medium text-stone-800 dark:text-neutral-100">Website:</span> {{ form.branding_profile.website_url || 'Not set' }}</div>
                                        <div><span class="font-medium text-stone-800 dark:text-neutral-100">Contact:</span> {{ form.branding_profile.contact_email || form.prospect_email || 'Not set' }}</div>
                                        <div><span class="font-medium text-stone-800 dark:text-neutral-100">Phone:</span> {{ form.branding_profile.phone || 'Not set' }}</div>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Scenario summary</div>
                                    <div class="mt-3 space-y-3">
                                        <div v-for="pack in selectedScenarioPackDetails" :key="`summary-${pack.key}`" class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ pack.label }}</div>
                                            <div class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ pack.business_objective }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Extra logins to generate</div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <span v-for="role in selectedExtraAccessRoles" :key="role.key" class="rounded-full bg-emerald-50 px-3 py-1 text-xs text-emerald-700 ring-1 ring-emerald-200">{{ role.label }}</span>
                                        <span v-if="!selectedExtraAccessRoles.length" class="text-sm text-stone-500 dark:text-neutral-400">No extra role login selected.</span>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-stone-200 pt-5 dark:border-neutral-700 sm:flex-row sm:items-center sm:justify-between">
                        <div v-if="Object.keys(form.errors || {}).length" class="text-sm text-red-600">{{ Object.values(form.errors)[0] }}</div>
                        <div class="flex gap-2 sm:ms-auto">
                            <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" :disabled="currentStep === 0 || form.processing" @click="previousStep">Back</button>
                            <button v-if="currentStep < steps.length - 1" type="button" class="rounded-sm bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!stepIsValid || form.processing" @click="nextStep">Continue</button>
                            <template v-else>
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" :disabled="form.processing" @click="submit(true)">
                                    {{ form.processing && submissionMode === 'draft' ? 'Saving draft…' : 'Save as draft' }}
                                </button>
                                <button type="submit" class="rounded-sm bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="form.processing">
                                    {{ form.processing && submissionMode === 'queue' ? 'Queueing…' : 'Queue demo workspace' }}
                                </button>
                            </template>
                        </div>
                    </div>
                </form>
            </section>

            <Modal :show="showActionConfirmation" :closeable="!destructiveActionPending" max-width="lg" @close="closeActionConfirmation">
                <div class="p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-[11px] font-medium text-rose-700">
                                Destructive action
                            </div>
                            <h2 class="mt-3 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                {{ confirmationMeta.title }}
                            </h2>
                            <p class="mt-2 text-sm text-stone-600 dark:text-neutral-400">
                                {{ confirmationMeta.body }}
                            </p>
                        </div>

                        <button
                            type="button"
                            class="rounded-sm px-2 py-1 text-sm text-stone-500 hover:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
                            :disabled="destructiveActionPending"
                            @click="closeActionConfirmation"
                        >
                            Close
                        </button>
                    </div>

                    <div class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/80">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            Impact
                        </div>
                        <p class="mt-2 text-sm text-stone-700 dark:text-neutral-200">
                            {{ confirmationMeta.impact }}
                        </p>
                        <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                            {{ confirmationMeta.context }}
                        </p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            :disabled="destructiveActionPending"
                            @click="closeActionConfirmation"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="rounded-sm border px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                            :class="confirmationMeta.confirmClass"
                            :disabled="destructiveActionPending"
                            @click="confirmDestructiveAction"
                        >
                            {{ destructiveActionPending ? confirmationMeta.pendingLabel : confirmationMeta.confirmLabel }}
                        </button>
                    </div>
                </div>
            </Modal>

        </div>
    </AuthenticatedLayout>
</template>
