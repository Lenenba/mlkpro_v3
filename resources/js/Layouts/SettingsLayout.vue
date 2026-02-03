<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SettingsTabs from '@/Components/SettingsTabs.vue';
import { useI18n } from 'vue-i18n';
import { defaultAvatarIcon } from '@/utils/iconPresets';

const props = defineProps({
    active: {
        type: String,
        required: true,
    },
    contentClass: {
        type: String,
        default: 'w-[1400px] max-w-full',
    },
});

const page = usePage();
const { t, locale } = useI18n();
const userName = computed(() => {
    locale.value;
    return page.props.auth?.user?.name || t('account.default_name');
});
const userEmail = computed(() => page.props.auth?.user?.email || '');
const avatarUrl = computed(() =>
    page.props.auth?.user?.profile_picture_url
    || page.props.auth?.user?.profile_picture
    || defaultAvatarIcon
);
const avatarInitial = computed(() => {
    const label = (userName.value || userEmail.value || '?').trim();
    return label.length ? label[0].toUpperCase() : '?';
});

const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));

const navTabs = computed(() => {
    locale.value;
    const groups = [
        {
            label: t('settings.groups.account'),
            items: [
                {
                    id: 'profile',
                    label: t('settings.items.profile.label'),
                    description: t('settings.items.profile.description'),
                    route: 'profile.edit',
                    icon: 'user',
                },
                {
                    id: 'security',
                    label: t('settings.items.security.label'),
                    description: t('settings.items.security.description'),
                    route: 'settings.security.edit',
                    icon: 'shield',
                },
            ],
        },
        {
            label: t('settings.groups.platform'),
            items: [
                {
                    id: 'company',
                    label: t('settings.items.company.label'),
                    description: t('settings.items.company.description'),
                    route: 'settings.company.edit',
                    icon: 'building',
                    ownerOnly: true,
                },
                {
                    id: 'hr',
                    label: t('settings.items.hr.label'),
                    description: t('settings.items.hr.description'),
                    route: 'settings.hr.edit',
                    icon: 'users',
                    ownerOnly: true,
                },
                {
                    id: 'billing',
                    label: t('settings.items.billing.label'),
                    description: t('settings.items.billing.description'),
                    route: 'settings.billing.edit',
                    icon: 'card',
                    ownerOnly: true,
                },
                {
                    id: 'notifications',
                    label: t('settings.items.notifications.label'),
                    description: t('settings.items.notifications.description'),
                    route: 'settings.notifications.edit',
                    icon: 'bell',
                },
                {
                    id: 'support',
                    label: t('settings.items.support.label'),
                    description: t('settings.items.support.description'),
                    route: 'settings.support.index',
                    icon: 'support',
                },
            ],
        },
    ];

    const filteredGroups = groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => !item.ownerOnly || isOwner.value),
        }))
        .filter((group) => group.items.length);

    return filteredGroups
        .flatMap((group) => group.items)
        .filter((item) => item.route || item.disabled)
        .map((item) => ({
            id: item.id,
            label: item.label,
            description: item.description,
            href: item.route ? route(item.route) : null,
            disabled: item.disabled,
            badge: item.badge,
        }));
});
</script>

<template>
    <AuthenticatedLayout>
        <div class="settings-shell">
            <header class="settings-hero">
                <div class="settings-hero__inner" :class="props.contentClass">
                    <div class="settings-hero__card">
                        <div class="settings-avatar">
                            <img v-if="avatarUrl" :src="avatarUrl" :alt="userName" />
                            <span v-else>{{ avatarInitial }}</span>
                        </div>
                        <div class="settings-hero__text">
                            <h1 class="settings-hero__title">{{ t('settings._label') }}</h1>
                            <p class="settings-hero__meta">
                                <span>{{ userName }}</span>
                                <span v-if="userEmail" class="settings-hero__dot">&middot;</span>
                                <span v-if="userEmail">{{ userEmail }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </header>

            <div class="settings-main" :class="props.contentClass">
                <SettingsTabs
                    :model-value="props.active"
                    :tabs="navTabs"
                    id-prefix="settings-main"
                    aria-label="Navigation des parametres"
                />

                <section class="settings-content">
                    <slot />
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.settings-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
    width: 100%;
}

.settings-hero {
    --hero-bg: transparent;
    --hero-border: transparent;
    --hero-muted: rgba(15, 23, 42, 0.65);
    --hero-text: #0f172a;
    --hero-card: rgba(255, 255, 255, 0.9);
    --hero-card-border: rgba(148, 163, 184, 0.3);
    --hero-accent: rgba(16, 185, 129, 0.2);
    display: block;
    padding: 0;
    background: var(--hero-bg);
    border-bottom: 1px solid var(--hero-border);
    font-family: inherit;
    position: relative;
    overflow: visible;
}

:global(.dark) .settings-hero {
    --hero-bg: transparent;
    --hero-border: transparent;
    --hero-muted: rgba(226, 232, 240, 0.72);
    --hero-text: #e2e8f0;
    --hero-card: rgba(15, 23, 42, 0.82);
    --hero-card-border: rgba(148, 163, 184, 0.2);
    --hero-accent: rgba(16, 185, 129, 0.25);
}

.settings-hero__inner {
    display: flex;
    align-items: center;
    gap: 16px;
    margin: 0 auto;
    padding: 0 12px;
    width: 100%;
}

.settings-hero__card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 18px 14px 24px;
    border-radius: 3px;
    background: var(--hero-card);
    border: 1px solid var(--hero-card-border);
    position: relative;
    overflow: hidden;
    width: 100%;
}

.settings-hero__card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px;
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.8), rgba(14, 116, 144, 0.65));
}

.settings-avatar {
    width: 56px;
    height: 56px;
    border-radius: 3px;
    overflow: hidden;
    display: grid;
    place-items: center;
    background: var(--hero-accent);
    color: var(--hero-text);
    font-weight: 600;
    box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.5);
}

.settings-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.settings-hero__text {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.settings-hero__title {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--hero-text);
}

.settings-hero__meta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.75rem;
    color: var(--hero-muted);
}

.settings-hero__dot {
    color: var(--hero-muted);
}

.settings-main {
    display: flex;
    flex-direction: column;
    gap: 16px;
    width: 100%;
    margin: 0 auto;
    padding: 0 12px;
}

.settings-content {
    min-width: 0;
}
</style>



