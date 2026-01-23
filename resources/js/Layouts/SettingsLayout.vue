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
        default: 'w-full max-w-6xl',
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
                    disabled: true,
                    badge: t('settings.items.security.badge'),
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
                    <div class="settings-hero__profile">
                        <div class="settings-avatar">
                            <img v-if="avatarUrl" :src="avatarUrl" :alt="userName" />
                            <span v-else>{{ avatarInitial }}</span>
                        </div>
                        <div>
                            <p class="settings-hero__title">{{ t('settings._label') }}</p>
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
    --hero-bg: #ffffff;
    --hero-border: rgba(15, 23, 42, 0.08);
    --hero-muted: rgba(15, 23, 42, 0.6);
    --hero-text: #0f172a;
    --hero-accent: rgba(16, 185, 129, 0.16);
    display: block;
    padding: 16px 0;
    border-bottom: 1px solid var(--hero-border);
    background: var(--hero-bg);
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
    font-family: inherit;
}

:global(.dark) .settings-hero {
    --hero-bg: #0b0f14;
    --hero-border: rgba(255, 255, 255, 0.08);
    --hero-muted: rgba(226, 232, 240, 0.7);
    --hero-text: #e2e8f0;
    --hero-accent: rgba(16, 185, 129, 0.2);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
}

.settings-hero__inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin: 0 auto;
    padding: 0 12px;
    width: 100%;
}

.settings-hero__profile {
    display: flex;
    align-items: center;
    gap: 12px;
}

.settings-avatar {
    width: 46px;
    height: 46px;
    border-radius: 8px;
    overflow: hidden;
    display: grid;
    place-items: center;
    background: var(--hero-accent);
    color: var(--hero-text);
    font-weight: 600;
}

.settings-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.settings-name {
    font-weight: 600;
    color: var(--hero-text);
}

.settings-hero__title {
    font-size: 0.95rem;
    font-weight: 600;
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
