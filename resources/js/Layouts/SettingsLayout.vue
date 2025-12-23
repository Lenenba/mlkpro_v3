<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    active: {
        type: String,
        required: true,
    },
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || 'Compte');
const userEmail = computed(() => page.props.auth?.user?.email || '');
const avatarUrl = computed(() => page.props.auth?.user?.profile_picture || '');
const avatarInitial = computed(() => {
    const label = (userName.value || userEmail.value || '?').trim();
    return label.length ? label[0].toUpperCase() : '?';
});

const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));

const navGroups = computed(() => {
    const groups = [
        {
            label: 'Compte',
            items: [
                {
                    id: 'profile',
                    label: 'Profil',
                    description: 'Identite et acces',
                    route: 'profile.edit',
                    icon: 'user',
                },
                {
                    id: 'security',
                    label: 'Securite',
                    description: 'Mot de passe & 2FA',
                    disabled: true,
                    badge: 'Bientot',
                    icon: 'shield',
                },
            ],
        },
        {
            label: 'Plateforme',
            items: [
                {
                    id: 'company',
                    label: 'Entreprise',
                    description: 'Infos et categories',
                    route: 'settings.company.edit',
                    icon: 'building',
                    ownerOnly: true,
                },
                {
                    id: 'billing',
                    label: 'Facturation',
                    description: 'Plan et paiements',
                    route: 'settings.billing.edit',
                    icon: 'card',
                    ownerOnly: true,
                },
                {
                    id: 'notifications',
                    label: 'Notifications',
                    description: 'Emails & alertes',
                    disabled: true,
                    badge: 'Bientot',
                    icon: 'bell',
                    ownerOnly: true,
                },
            ],
        },
    ];

    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => !item.ownerOnly || isOwner.value),
        }))
        .filter((group) => group.items.length);
});
</script>

<template>
    <AuthenticatedLayout>
        <div class="settings-shell">
            <aside class="settings-panel">
                <div class="settings-profile">
                    <div class="settings-avatar">
                        <img v-if="avatarUrl" :src="avatarUrl" :alt="userName" />
                        <span v-else>{{ avatarInitial }}</span>
                    </div>
                    <div>
                        <p class="settings-name">{{ userName }}</p>
                        <p class="settings-email">{{ userEmail }}</p>
                    </div>
                </div>

                <div v-for="group in navGroups" :key="group.label" class="settings-group">
                    <p class="settings-group__label">{{ group.label }}</p>
                    <div class="settings-group__list">
                        <template v-for="item in group.items" :key="item.id">
                            <Link v-if="!item.disabled && item.route" :href="route(item.route)"
                                class="settings-link"
                                :class="{ 'is-active': props.active === item.id }"
                                :aria-current="props.active === item.id ? 'page' : null">
                                <span class="settings-icon" aria-hidden="true">
                                    <svg v-if="item.icon === 'user'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M20 21a8 8 0 0 0-16 0" />
                                        <circle cx="12" cy="7" r="4" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'shield'" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 2 3 5v6c0 5.2 3.6 10 9 11 5.4-1 9-5.8 9-11V5z" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'building'" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 22V2l6 4 6-4v20" />
                                        <path d="M6 12h12" />
                                        <path d="M6 18h12" />
                                        <path d="M6 6h12" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'card'" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="5" width="20" height="14" rx="2" />
                                        <line x1="2" x2="22" y1="10" y2="10" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'bell'" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7" />
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                                    </svg>
                                </span>
                                <span>
                                    <span class="settings-link__label">{{ item.label }}</span>
                                    <span class="settings-link__desc">{{ item.description }}</span>
                                </span>
                                <span v-if="item.badge" class="settings-badge">{{ item.badge }}</span>
                            </Link>

                            <div v-else class="settings-link is-disabled">
                                <span class="settings-icon" aria-hidden="true">
                                    <svg v-if="item.icon === 'shield'" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 2 3 5v6c0 5.2 3.6 10 9 11 5.4-1 9-5.8 9-11V5z" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'bell'" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7" />
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                                    </svg>
                                </span>
                                <span>
                                    <span class="settings-link__label">{{ item.label }}</span>
                                    <span class="settings-link__desc">{{ item.description }}</span>
                                </span>
                                <span v-if="item.badge" class="settings-badge">{{ item.badge }}</span>
                            </div>
                        </template>
                    </div>
                </div>
            </aside>

            <section class="settings-content">
                <slot />
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap');

.settings-shell {
    display: grid;
    gap: 18px;
    margin: 0 auto;
    max-width: 1200px;
    padding: 12px;
}

@media (min-width: 1024px) {
    .settings-shell {
        grid-template-columns: 280px minmax(0, 1fr);
        align-items: start;
    }
}

.settings-panel {
    --panel-bg: #ffffff;
    --panel-border: rgba(15, 23, 42, 0.08);
    --panel-muted: rgba(15, 23, 42, 0.6);
    --panel-text: #0f172a;
    --panel-card: rgba(15, 23, 42, 0.03);
    --panel-accent: rgba(16, 185, 129, 0.85);
    --panel-accent-bg: rgba(16, 185, 129, 0.1);
    --panel-link: rgba(15, 23, 42, 0.04);
    --panel-link-hover: rgba(15, 23, 42, 0.08);
    --panel-link-border: rgba(15, 23, 42, 0.1);
    --panel-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
    padding: 18px;
    border-radius: 2px;
    border: 1px solid var(--panel-border);
    background: var(--panel-bg);
    box-shadow: var(--panel-shadow);
    font-family: "Space Grotesk", "IBM Plex Sans", sans-serif;
}

:global(.dark) .settings-panel {
    --panel-bg: #0b0f14;
    --panel-border: rgba(255, 255, 255, 0.08);
    --panel-muted: rgba(226, 232, 240, 0.7);
    --panel-text: #e2e8f0;
    --panel-card: rgba(255, 255, 255, 0.06);
    --panel-accent: rgba(16, 185, 129, 0.75);
    --panel-accent-bg: rgba(16, 185, 129, 0.14);
    --panel-link: rgba(15, 23, 42, 0.7);
    --panel-link-hover: rgba(15, 23, 42, 0.9);
    --panel-link-border: rgba(255, 255, 255, 0.08);
    --panel-shadow: 0 24px 60px rgba(5, 8, 12, 0.5);
    background: var(--panel-bg);
}

.settings-profile {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: 2px;
    background: var(--panel-card);
    border: 1px solid var(--panel-border);
    color: var(--panel-text);
}

.settings-avatar {
    width: 44px;
    height: 44px;
    border-radius: 2px;
    overflow: hidden;
    display: grid;
    place-items: center;
    background: rgba(16, 185, 129, 0.15);
    color: var(--panel-text);
    font-weight: 600;
}

.settings-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.settings-name {
    font-weight: 600;
    color: var(--panel-text);
}

.settings-email {
    font-size: 0.75rem;
    color: var(--panel-muted);
}

.settings-group {
    margin-top: 18px;
}

.settings-group__label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--panel-muted);
    margin-bottom: 8px;
}

.settings-group__list {
    display: grid;
    gap: 8px;
}

.settings-link {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 2px;
    border: 1px solid var(--panel-link-border);
    background: var(--panel-link);
    color: var(--panel-text);
    transition: transform 150ms ease, background 150ms ease, border-color 150ms ease;
}

.settings-link:hover {
    transform: translateY(-1px);
    background: var(--panel-link-hover);
}

.settings-link.is-active {
    border-color: var(--panel-accent);
    background: var(--panel-accent-bg);
}

.settings-link.is-disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.settings-icon {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border-radius: 2px;
    background: rgba(15, 23, 42, 0.06);
    color: var(--panel-text);
}

:global(.dark) .settings-icon {
    background: rgba(255, 255, 255, 0.08);
}

.settings-icon svg {
    width: 18px;
    height: 18px;
}

.settings-link__label {
    font-size: 0.9rem;
    font-weight: 600;
}

.settings-link__desc {
    display: block;
    font-size: 0.75rem;
    color: var(--panel-muted);
}

.settings-badge {
    font-size: 0.65rem;
    padding: 2px 8px;
    border-radius: 2px;
    border: 1px solid var(--panel-link-border);
    color: var(--panel-muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.settings-content {
    min-width: 0;
}
</style>
