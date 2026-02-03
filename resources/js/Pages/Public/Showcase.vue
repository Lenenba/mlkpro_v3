<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Price from '@/Components/Store/Price.vue';

const props = defineProps({
    company: { type: Object, default: () => ({}) },
    services: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    hero_service: { type: Object, default: () => null },
    request_url: { type: String, default: null },
    stats: { type: Object, default: () => ({}) },
});

const { t, locale } = useI18n();

const company = computed(() => props.company || {});
const companyName = computed(() => company.value?.name || t('public_showcase.company_fallback'));
const pageTitle = computed(() => t('public_showcase.title', { company: companyName.value }));

const heroService = computed(() => props.hero_service || null);
const heroBackgroundIndex = ref(0);
const heroBackgroundInterval = ref(null);
const resolveHeroCopy = (value) => {
    const trimmed = String(value || '').trim();
    if (!trimmed) {
        return '';
    }
    return trimmed.replace(/\{company\}/g, companyName.value);
};
const normalizeHeroImages = (value) => {
    if (!Array.isArray(value)) {
        return [];
    }
    return value
        .map((item) => String(item || '').trim())
        .filter((item) => item.length);
};
const heroSlides = computed(() => normalizeHeroImages(company.value?.store_settings?.hero_images));
const heroCopyHtml = computed(() => resolveHeroCopy(company.value?.store_settings?.hero_copy?.[locale.value]));
const heroCaptions = computed(() => {
    const captions = company.value?.store_settings?.hero_captions || {};
    return {
        fr: Array.isArray(captions.fr) ? captions.fr : [],
        en: Array.isArray(captions.en) ? captions.en : [],
    };
});
const heroSlideCaption = computed(() => {
    const list = heroCaptions.value?.[locale.value] || [];
    return resolveHeroCopy(list[heroBackgroundIndex.value] || '');
});
const heroSlideCopyHtml = computed(() => heroSlideCaption.value || heroCopyHtml.value);
const heroImage = computed(() => (
    heroSlides.value[heroBackgroundIndex.value]
    || heroService.value?.image_url
    || null
));
const headerAccent = computed(() => company.value?.store_settings?.header_color || '#0f172a');

const searchQuery = ref('');
const selectedCategory = ref('');

const categoryOptions = computed(() => (props.categories || []).map((category) => ({
    id: String(category.id),
    name: category.name,
})));

const filteredServices = computed(() => {
    const query = String(searchQuery.value || '').trim().toLowerCase();
    const categoryId = selectedCategory.value;
    return (props.services || []).filter((service) => {
        const matchesSearch = !query
            || String(service?.name || '').toLowerCase().includes(query)
            || String(service?.description || '').toLowerCase().includes(query)
            || String(service?.category_name || '').toLowerCase().includes(query);
        const matchesCategory = !categoryId || String(service?.category_id) === categoryId;
        return matchesSearch && matchesCategory;
    });
});

const companyLocation = computed(() => {
    const parts = [
        company.value?.city,
        company.value?.province,
        company.value?.country,
    ].filter((item) => item && String(item).trim() !== '');
    return parts.join(', ');
});

const stats = computed(() => ({
    services: Number(props.stats?.services || 0),
    categories: Number(props.stats?.categories || 0),
}));

const clearHeroCarousel = () => {
    if (heroBackgroundInterval.value && typeof window !== 'undefined') {
        window.clearInterval(heroBackgroundInterval.value);
        heroBackgroundInterval.value = null;
    }
};
const startHeroCarousel = () => {
    clearHeroCarousel();
    if (typeof window === 'undefined' || heroSlides.value.length <= 1) {
        return;
    }
    heroBackgroundInterval.value = window.setInterval(() => {
        heroBackgroundIndex.value = (heroBackgroundIndex.value + 1) % heroSlides.value.length;
    }, 7000);
};
const setHeroBackground = (index) => {
    const total = heroSlides.value.length;
    if (!total) {
        return;
    }
    const next = Math.max(0, Math.min(index, total - 1));
    heroBackgroundIndex.value = next;
    startHeroCarousel();
};
const nextHeroSlide = () => {
    const total = heroSlides.value.length;
    if (!total) {
        return;
    }
    setHeroBackground((heroBackgroundIndex.value + 1) % total);
};
const prevHeroSlide = () => {
    const total = heroSlides.value.length;
    if (!total) {
        return;
    }
    const next = heroBackgroundIndex.value - 1;
    setHeroBackground(next < 0 ? total - 1 : next);
};

watch(heroSlides, () => {
    heroBackgroundIndex.value = 0;
    startHeroCarousel();
}, { immediate: true });

onBeforeUnmount(() => {
    clearHeroCarousel();
});

const contactItems = computed(() => {
    const items = [];
    if (company.value?.phone) {
        items.push({
            key: 'phone',
            label: t('public_showcase.contact.phone'),
            value: company.value.phone,
            href: `tel:${company.value.phone}`,
        });
    }
    if (company.value?.email) {
        items.push({
            key: 'email',
            label: t('public_showcase.contact.email'),
            value: company.value.email,
            href: `mailto:${company.value.email}`,
        });
    }
    if (companyLocation.value) {
        items.push({
            key: 'location',
            label: t('public_showcase.contact.location'),
            value: companyLocation.value,
            href: null,
        });
    }
    return items;
});
</script>

<template>
    <div class="public-showcase">
        <Head :title="pageTitle" />

        <header class="showcase-hero" :style="{ '--hero-accent': headerAccent }">
            <div class="showcase-hero-bg">
                <div class="showcase-hero-glow showcase-hero-glow--one"></div>
                <div class="showcase-hero-glow showcase-hero-glow--two"></div>
            </div>
            <div class="showcase-hero-shell">
                <div class="showcase-hero-text">
                    <span class="showcase-hero-eyebrow">{{ t('public_showcase.eyebrow') }}</span>
                    <h1 class="showcase-hero-title">
                        {{ t('public_showcase.headline', { company: companyName }) }}
                    </h1>
                    <p class="showcase-hero-subtitle">
                        {{ company?.description || t('public_showcase.subheadline') }}
                    </p>
                    <div v-if="heroSlideCopyHtml" class="showcase-hero-copy" v-html="heroSlideCopyHtml"></div>
                    <div v-if="companyLocation" class="showcase-hero-location">
                        {{ companyLocation }}
                    </div>
                    <div class="showcase-hero-cta">
                        <a
                            v-if="request_url"
                            :href="request_url"
                            class="showcase-cta-primary"
                        >
                            {{ t('public_showcase.cta_request') }}
                        </a>
                        <a
                            v-else-if="company?.phone"
                            :href="`tel:${company.phone}`"
                            class="showcase-cta-primary"
                        >
                            {{ t('public_showcase.cta_contact') }}
                        </a>
                        <a
                            v-else-if="company?.email"
                            :href="`mailto:${company.email}`"
                            class="showcase-cta-primary"
                        >
                            {{ t('public_showcase.cta_contact') }}
                        </a>
                        <a v-else href="#contact" class="showcase-cta-secondary">
                            {{ t('public_showcase.cta_contact') }}
                        </a>
                    </div>
                    <div class="showcase-hero-stats">
                        <span class="showcase-hero-stat">
                            {{ t('public_showcase.stats_services', { count: stats.services }) }}
                        </span>
                        <span class="showcase-hero-stat">
                            {{ t('public_showcase.stats_categories', { count: stats.categories }) }}
                        </span>
                    </div>
                </div>

                <div class="showcase-hero-visual">
                    <div class="showcase-hero-image">
                        <Transition name="hero-fade" mode="out-in">
                            <template v-if="heroImage">
                                <img
                                    :key="heroImage"
                                    :src="heroImage"
                                    :alt="heroService?.name || companyName"
                                    loading="lazy"
                                    decoding="async"
                                >
                            </template>
                            <template v-else>
                                <div class="showcase-hero-placeholder">
                                    {{ companyName }}
                                </div>
                            </template>
                        </Transition>
                        <div v-if="heroSlides.length > 1" class="showcase-hero-controls">
                            <button type="button" class="showcase-hero-control" @click="prevHeroSlide" aria-label="Previous slide">
                                &lsaquo;
                            </button>
                            <button type="button" class="showcase-hero-control" @click="nextHeroSlide" aria-label="Next slide">
                                &rsaquo;
                            </button>
                        </div>
                        <div v-if="heroSlides.length > 1" class="showcase-hero-dots" role="tablist">
                            <button
                                v-for="(slide, index) in heroSlides"
                                :key="`hero-dot-${slide}-${index}`"
                                type="button"
                                class="showcase-hero-dot"
                                :class="{ 'is-active': index === heroBackgroundIndex }"
                                :aria-label="`Slide ${index + 1}`"
                                @click="setHeroBackground(index)"
                            />
                        </div>
                    </div>
                    <div v-if="heroService" class="showcase-hero-card">
                        <div class="showcase-hero-card-header">
                            <span class="showcase-hero-tag">{{ t('public_showcase.featured') }}</span>
                            <span class="showcase-hero-name">{{ heroService.name }}</span>
                        </div>
                        <p class="showcase-hero-description">
                            {{ heroService.description || t('public_showcase.empty_description') }}
                        </p>
                        <div class="showcase-hero-meta">
                            <span class="showcase-hero-category">
                                {{ heroService.category_name || t('public_showcase.category_fallback') }}
                            </span>
                            <span v-if="heroService.price && heroService.price > 0" class="showcase-hero-price">
                                <span>{{ t('public_showcase.price_from') }}</span>
                                <Price :current="heroService.price" size="sm" />
                            </span>
                            <span v-else class="showcase-hero-price">
                                {{ t('public_showcase.price_quote') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <section class="showcase-filters">
            <div class="showcase-filter">
                <label class="showcase-filter-label">{{ t('public_showcase.filters.search') }}</label>
                <input
                    v-model="searchQuery"
                    type="search"
                    class="showcase-filter-input"
                    :placeholder="t('public_showcase.filters.search_placeholder')"
                />
            </div>
            <div class="showcase-filter">
                <label class="showcase-filter-label">{{ t('public_showcase.filters.category') }}</label>
                <select v-model="selectedCategory" class="showcase-filter-input">
                    <option value="">{{ t('public_showcase.filters.all') }}</option>
                    <option v-for="category in categoryOptions" :key="category.id" :value="category.id">
                        {{ category.name }}
                    </option>
                </select>
            </div>
        </section>

        <section class="showcase-services">
            <div class="showcase-section-header">
                <h2>{{ t('public_showcase.sections.services') }}</h2>
                <p>{{ t('public_showcase.sections.services_hint') }}</p>
            </div>

            <div v-if="filteredServices.length" class="showcase-grid">
                <article v-for="service in filteredServices" :key="service.id" class="service-card">
                    <div class="service-card-media">
                        <img
                            v-if="service.image_url"
                            :src="service.image_url"
                            :alt="service.name"
                            loading="lazy"
                            decoding="async"
                        >
                        <div v-else class="service-card-placeholder">
                            {{ service.name?.charAt(0) || '?' }}
                        </div>
                        <span class="service-card-category">
                            {{ service.category_name || t('public_showcase.category_fallback') }}
                        </span>
                    </div>
                    <div class="service-card-body">
                        <h3>{{ service.name }}</h3>
                        <p>{{ service.description || t('public_showcase.empty_description') }}</p>
                        <div class="service-card-footer">
                            <div class="service-card-price">
                                <template v-if="service.price && service.price > 0">
                                    <span>{{ t('public_showcase.price_from') }}</span>
                                    <Price :current="service.price" size="sm" />
                                </template>
                                <span v-else>{{ t('public_showcase.price_quote') }}</span>
                            </div>
                            <a
                                v-if="request_url"
                                :href="request_url"
                                class="service-card-cta"
                            >
                                {{ t('public_showcase.cta_request') }}
                            </a>
                        </div>
                    </div>
                </article>
            </div>
            <div v-else class="showcase-empty">
                {{ t('public_showcase.empty') }}
            </div>
        </section>

        <section id="contact" class="showcase-contact">
            <div class="showcase-section-header">
                <h2>{{ t('public_showcase.contact.title') }}</h2>
                <p>{{ t('public_showcase.contact.subtitle') }}</p>
            </div>
            <div v-if="contactItems.length" class="showcase-contact-grid">
                <div v-for="item in contactItems" :key="item.key" class="showcase-contact-card">
                    <span class="showcase-contact-label">{{ item.label }}</span>
                    <a v-if="item.href" :href="item.href" class="showcase-contact-value">
                        {{ item.value }}
                    </a>
                    <span v-else class="showcase-contact-value">
                        {{ item.value }}
                    </span>
                </div>
            </div>
            <div v-else class="showcase-contact-empty">
                {{ t('public_showcase.contact.empty') }}
            </div>
            <div class="showcase-contact-actions">
                <a v-if="request_url" :href="request_url" class="showcase-cta-primary">
                    {{ t('public_showcase.cta_request') }}
                </a>
                <a v-else-if="company?.phone" :href="`tel:${company.phone}`" class="showcase-cta-primary">
                    {{ t('public_showcase.cta_contact') }}
                </a>
                <a v-else-if="company?.email" :href="`mailto:${company.email}`" class="showcase-cta-primary">
                    {{ t('public_showcase.cta_contact') }}
                </a>
            </div>
        </section>
    </div>
</template>

<style scoped>
.public-showcase {
    background: #f8fafc;
    min-height: 100vh;
}

.showcase-hero {
    position: relative;
    overflow: hidden;
    background: #0b1120;
    color: #f8fafc;
    padding: clamp(2.5rem, 6vw, 5rem) clamp(1.5rem, 6vw, 5rem);
}

.showcase-hero-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.showcase-hero-glow {
    position: absolute;
    border-radius: 999px;
    filter: blur(120px);
    opacity: 0.35;
}

.showcase-hero-glow--one {
    width: 420px;
    height: 420px;
    left: -180px;
    top: -120px;
    background: radial-gradient(circle, rgba(15, 23, 42, 0.6), rgba(15, 23, 42, 0));
}

.showcase-hero-glow--two {
    width: 380px;
    height: 380px;
    right: -140px;
    bottom: -140px;
    background: radial-gradient(circle, rgba(15, 23, 42, 0.5), rgba(15, 23, 42, 0));
}

.showcase-hero-shell {
    position: relative;
    display: grid;
    gap: 2rem;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    align-items: center;
    z-index: 1;
}

.showcase-hero-text {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-width: 520px;
}

.showcase-hero-eyebrow {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.2em;
    color: rgba(226, 232, 240, 0.7);
    font-weight: 700;
}

.showcase-hero-title {
    font-size: clamp(2.1rem, 3.4vw, 3.1rem);
    line-height: 1.1;
    font-weight: 700;
}

.showcase-hero-subtitle {
    font-size: 1.05rem;
    line-height: 1.6;
    color: #e2e8f0;
}

.showcase-hero-copy {
    font-size: 0.92rem;
    line-height: 1.6;
    color: rgba(226, 232, 240, 0.9);
}

.showcase-hero-copy :deep(p) {
    margin: 0.25rem 0;
}

.showcase-hero-copy :deep(strong) {
    color: #f8fafc;
}

.showcase-hero-location {
    font-size: 0.9rem;
    color: rgba(226, 232, 240, 0.8);
}

.showcase-hero-cta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 0.5rem;
}

.showcase-cta-primary {
    background: var(--hero-accent);
    color: #0b1120;
    border-radius: 999px;
    padding: 0.65rem 1.5rem;
    font-size: 0.85rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.25);
}

.showcase-cta-primary:hover {
    transform: translateY(-1px);
}

.showcase-cta-muted {
    font-size: 0.85rem;
    color: rgba(226, 232, 240, 0.7);
}

.showcase-cta-secondary {
    border-radius: 999px;
    padding: 0.6rem 1.4rem;
    font-size: 0.82rem;
    font-weight: 700;
    color: #f8fafc;
    text-decoration: none;
    border: 1px solid rgba(248, 250, 252, 0.4);
}

.showcase-hero-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
}

.showcase-hero-stat {
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    padding: 0.3rem 0.9rem;
    font-size: 0.72rem;
    font-weight: 600;
    color: #e2e8f0;
    background: rgba(15, 23, 42, 0.6);
}

.showcase-hero-visual {
    position: relative;
    display: grid;
    gap: 1.5rem;
}

.showcase-hero-image {
    position: relative;
    border-radius: 18px;
    overflow: hidden;
    background: #0f172a;
    min-height: 260px;
    box-shadow: 0 22px 50px rgba(15, 23, 42, 0.35);
}

.showcase-hero-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.showcase-hero-placeholder {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: 600;
    color: #cbd5f5;
}

.showcase-hero-controls {
    position: absolute;
    right: 14px;
    bottom: 14px;
    display: flex;
    gap: 0.5rem;
    z-index: 2;
}

.showcase-hero-control {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    border: none;
    background: rgba(15, 23, 42, 0.75);
    color: #f8fafc;
    font-size: 1.1rem;
    cursor: pointer;
}

.showcase-hero-dots {
    position: absolute;
    left: 14px;
    bottom: 16px;
    display: flex;
    gap: 0.4rem;
    z-index: 2;
}

.showcase-hero-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    border: 1px solid rgba(248, 250, 252, 0.7);
    background: transparent;
    cursor: pointer;
}

.showcase-hero-dot.is-active {
    background: var(--hero-accent);
    border-color: var(--hero-accent);
}

.hero-fade-enter-active,
.hero-fade-leave-active {
    transition: opacity 0.35s ease;
}

.hero-fade-enter-from,
.hero-fade-leave-to {
    opacity: 0;
}

.showcase-hero-card {
    background: #ffffff;
    color: #0f172a;
    border-radius: 16px;
    padding: 1.2rem 1.4rem;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.2);
}

.showcase-hero-card-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.6rem;
}

.showcase-hero-tag {
    font-size: 0.65rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    font-weight: 700;
    background: var(--hero-accent);
    color: #0b1120;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
}

.showcase-hero-name {
    font-size: 0.9rem;
    font-weight: 600;
}

.showcase-hero-description {
    font-size: 0.85rem;
    color: #475569;
    line-height: 1.5;
}

.showcase-hero-meta {
    margin-top: 0.8rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
    font-size: 0.8rem;
    color: #64748b;
}

.showcase-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    padding: 1.8rem clamp(1.5rem, 6vw, 5rem);
    background: #f8fafc;
}

.showcase-filter {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.showcase-filter-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.showcase-filter-input {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.65rem 0.85rem;
    font-size: 0.9rem;
    background: #ffffff;
    color: #0f172a;
}

.showcase-services {
    padding: 0  clamp(1.5rem, 6vw, 5rem) 3.5rem;
}

.showcase-section-header {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    margin-bottom: 1.5rem;
}

.showcase-section-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
}

.showcase-section-header p {
    font-size: 0.95rem;
    color: #64748b;
}

.showcase-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.4rem;
}

.service-card {
    background: #ffffff;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.service-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.15);
}

.service-card-media {
    position: relative;
    height: 180px;
    background: #0f172a;
    color: #e2e8f0;
}

.service-card-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.service-card-placeholder {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 700;
    color: #94a3b8;
    background: #f1f5f9;
}

.service-card-category {
    position: absolute;
    bottom: 12px;
    left: 12px;
    background: rgba(15, 23, 42, 0.85);
    color: #f8fafc;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.25rem 0.7rem;
    border-radius: 999px;
}

.service-card-body {
    padding: 1.1rem 1.2rem 1.2rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    flex: 1;
}

.service-card-body h3 {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}

.service-card-body p {
    font-size: 0.85rem;
    color: #64748b;
    line-height: 1.5;
    flex: 1;
}

.service-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
}

.service-card-price {
    font-size: 0.78rem;
    color: #475569;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.service-card-cta {
    border-radius: 999px;
    padding: 0.4rem 0.9rem;
    font-size: 0.75rem;
    font-weight: 700;
    background: #0f172a;
    color: #ffffff;
    text-decoration: none;
}

.showcase-empty {
    border-radius: 16px;
    padding: 2rem;
    background: #ffffff;
    border: 1px dashed #cbd5f5;
    color: #64748b;
    text-align: center;
}

.showcase-contact {
    padding: 0 clamp(1.5rem, 6vw, 5rem) 4rem;
    background: #f8fafc;
}

.showcase-contact-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}

.showcase-contact-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 1rem 1.2rem;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.showcase-contact-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #94a3b8;
    font-weight: 700;
}

.showcase-contact-value {
    font-size: 0.92rem;
    font-weight: 600;
    color: #0f172a;
    text-decoration: none;
}

.showcase-contact-actions {
    margin-top: 1.2rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.showcase-contact-empty {
    border-radius: 16px;
    padding: 1.2rem;
    background: #ffffff;
    border: 1px dashed #cbd5f5;
    color: #64748b;
    text-align: center;
    margin-bottom: 1rem;
}

@media (max-width: 640px) {
    .showcase-hero {
        padding: 2rem 1.2rem 2.5rem;
    }

    .showcase-hero-card {
        padding: 1rem;
    }
}
</style>
