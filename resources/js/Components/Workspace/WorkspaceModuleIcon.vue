<script setup>
const props = defineProps({
    name: {
        type: String,
        default: 'workspace',
    },
    iconClass: {
        type: String,
        default: 'size-6',
    },
});
</script>

<template>
    <svg
        v-if="name === 'customers'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M16 2v2" />
        <path d="M8 2v2" />
        <circle cx="12" cy="11" r="3" />
        <rect x="3" y="4" width="18" height="18" rx="2" />
        <path d="M7 22v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2" />
    </svg>

    <svg
        v-else-if="name === 'requests'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <circle cx="18" cy="18" r="3" />
        <circle cx="6" cy="6" r="3" />
        <path d="M13 6h3a2 2 0 0 1 2 2v7" />
        <line x1="6" x2="6" y1="9" y2="21" />
    </svg>

    <svg
        v-else-if="name === 'quotes'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
        <path d="M14 2v6h6" />
        <path d="M8 13h8" />
        <path d="M8 17h5" />
    </svg>

    <svg
        v-else-if="name === 'next_actions'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
        <path d="M16.5 4.5 19 7" />
    </svg>

    <svg
        v-else-if="name === 'orders'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
        <rect x="8" y="2" width="8" height="4" rx="1" />
        <path d="M9 12h6" />
        <path d="M9 16h6" />
        <path d="M9 8h6" />
    </svg>

    <svg
        v-else-if="name === 'sales'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <circle cx="8" cy="21" r="1" />
        <circle cx="19" cy="21" r="1" />
        <path d="M2.05 2.05h2l2.6 12.4a2 2 0 0 0 2 1.6h9.6a2 2 0 0 0 2-1.6l1.2-6.4H6.2" />
    </svg>

    <svg
        v-else-if="name === 'campaigns'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="m3 3 3 9-3 9 19-9Z" />
        <path d="M6 12h16" />
    </svg>

    <svg
        v-else-if="name === 'loyalty'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <circle cx="12" cy="8" r="6" />
        <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" />
    </svg>

    <svg
        v-else-if="name === 'performance' || name === 'manager_dashboard'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M3 3v18h18" />
        <path d="M18 17V9" />
        <path d="M13 17V5" />
        <path d="M8 17v-3" />
    </svg>

    <svg
        v-else-if="name === 'jobs'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <rect width="20" height="14" x="2" y="7" rx="2" />
        <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
        <path d="M2 13h20" />
    </svg>

    <svg
        v-else-if="name === 'tasks'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M9 11l3 3L22 4" />
        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
    </svg>

    <svg
        v-else-if="name === 'reservations' || name === 'planning'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M21 14V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8" />
        <path d="M16 2v4" />
        <path d="M8 2v4" />
        <path d="M3 10h18" />
        <path d="M16 22a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z" />
        <path d="M16 16v2l1.5 1.5" />
    </svg>

    <svg
        v-else-if="name === 'presence'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <circle cx="12" cy="12" r="10" />
        <polyline points="12 6 12 12 16 14" />
    </svg>

    <svg
        v-else-if="name === 'team'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
    </svg>

    <svg
        v-else-if="name === 'invoices'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
        <path d="M8 6h8" />
        <path d="M8 10h8" />
        <path d="M8 14h6" />
    </svg>

    <svg
        v-else-if="name === 'expenses'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <rect width="18" height="18" x="3" y="3" rx="2" />
        <path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2" />
        <path d="M3 11h3c.8 0 1.6.3 2.1.9l1.1.9c1.6 1.6 4.1 1.6 5.7 0l1.1-.9c.5-.5 1.3-.9 2.1-.9H21" />
    </svg>

    <svg
        v-else-if="name === 'accounting'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <rect x="4" y="2" width="16" height="20" rx="2" />
        <line x1="8" y1="6" x2="16" y2="6" />
        <path d="M8 10h.01" />
        <path d="M12 10h.01" />
        <path d="M16 10h.01" />
        <path d="M8 14h.01" />
        <path d="M12 14h.01" />
        <line x1="16" y1="14" x2="16" y2="18" />
        <path d="M8 18h.01" />
        <line x1="12" y1="18" x2="16" y2="18" />
    </svg>

    <svg
        v-else-if="name === 'finance_approvals'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M12 3l7 4v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V7l7-4Z" />
        <path d="m9.5 12 1.75 1.75L14.5 10.5" />
    </svg>

    <svg
        v-else-if="name === 'tips_owner'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M11 15h2a2 2 0 0 0 0-4h-3a2 2 0 0 1 0-4h3" />
        <path d="M12 18v2" />
        <path d="M12 4v2" />
        <path d="M20 12v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-8" />
        <path d="M2 12h20" />
        <path d="M7 8a2 2 0 1 1 0-4h1a2 2 0 0 1 2 2v2" />
        <path d="M17 8a2 2 0 1 0 0-4h-1a2 2 0 0 0-2 2v2" />
    </svg>

    <svg
        v-else-if="name === 'tips_member'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M19 7V4a1 1 0 0 0-1-1H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-3" />
        <path d="M2 7h20v10H2z" />
        <circle cx="16" cy="12" r="1.5" />
    </svg>

    <svg
        v-else-if="name === 'services'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M14.7 6.3a4 4 0 0 0-5.66 5.66l-6.34 6.34a2 2 0 0 0 2.83 2.83l6.34-6.34a4 4 0 0 0 5.66-5.66l-2.12 2.12-2.83-2.83 2.12-2.12z" />
    </svg>

    <svg
        v-else-if="name === 'categories'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M12 2H2v10l9 9 9-9-9-9z" />
        <path d="M7 7h.01" />
    </svg>

    <svg
        v-else-if="name === 'products'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16v-2" />
        <path d="m7.5 4.27 9 5.15" />
        <polyline points="3.29 7 12 12 20.71 7" />
        <line x1="12" x2="12" y1="22" y2="12" />
    </svg>

    <svg
        v-else-if="name === 'plan_scans'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <circle cx="11" cy="11" r="7" />
        <path d="m21 21-4.3-4.3" />
    </svg>

    <svg
        v-else-if="name === 'company_settings'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M3 21V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14" />
        <path d="M9 21V9h6v12" />
    </svg>

    <svg
        v-else-if="name === 'billing'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <rect width="20" height="14" x="2" y="5" rx="2" />
        <line x1="2" x2="22" y1="10" y2="10" />
    </svg>

    <svg
        v-else-if="name === 'profile'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
        <circle cx="12" cy="7" r="4" />
    </svg>

    <svg
        v-else
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M12 1v2" />
        <path d="M12 21v2" />
        <path d="m4.93 4.93 1.41 1.41" />
        <path d="m17.66 17.66 1.41 1.41" />
        <path d="M1 12h2" />
        <path d="M21 12h2" />
        <path d="m4.93 19.07 1.41-1.41" />
        <path d="m17.66 6.34 1.41-1.41" />
        <circle cx="12" cy="12" r="4" />
    </svg>
</template>
