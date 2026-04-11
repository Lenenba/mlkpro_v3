<script setup>
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    block: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['pick-asset']);
const { t } = useI18n();
const tp = (key, params = {}) => t(`mega_menu.admin.payload.${key}`, params);

const cloneRow = (value) => JSON.parse(JSON.stringify(value));

const addRow = (key, template) => {
    if (!Array.isArray(props.block.payload[key])) {
        props.block.payload[key] = [];
    }
    props.block.payload[key].push(cloneRow(template));
};

const removeRow = (key, index) => {
    if (!Array.isArray(props.block.payload[key])) {
        return;
    }
    props.block.payload[key].splice(index, 1);
};

const pickAsset = (field, altField = null) => {
    emit('pick-asset', { field, altField });
};
</script>

<template>
    <div class="space-y-4">
        <template v-if="block.type === 'navigation_group'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('group_title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('description') }}</label>
                    <textarea v-model="block.payload.description" rows="2" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('links') }}</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('links', { label: tp('defaults.new_link'), href: '/', note: '', badge: '', target: '_self' })">
                        {{ tp('add_link') }}
                    </button>
                </div>
                <div v-for="(link, index) in (block.payload.links || [])" :key="`nav-link-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('label') }}</label>
                            <input v-model="link.label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('href') }}</label>
                            <input v-model="link.href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('note') }}</label>
                            <input v-model="link.note" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('badge') }}</label>
                            <input v-model="link.badge" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('links', index)">
                            {{ tp('remove') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'product_showcase'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('section_title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('description') }}</label>
                    <textarea v-model="block.payload.description" rows="2" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('products') }}</h4>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="addRow('items', { label: tp('defaults.product'), href: '/pricing', note: '', badge: '', summary: '', target: '_self', image_url: '', image_alt: '', image_title: '' })"
                    >
                        {{ tp('add_product') }}
                    </button>
                </div>
                <div v-for="(item, index) in (block.payload.items || [])" :key="`showcase-item-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('label') }}</label>
                            <input v-model="item.label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('href') }}</label>
                            <input v-model="item.href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('note') }}</label>
                            <input v-model="item.note" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('badge') }}</label>
                            <input v-model="item.badge" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('preview_summary') }}</label>
                            <textarea v-model="item.summary" rows="3" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('preview_image') }}</label>
                            <div class="mt-1 flex gap-2">
                                <input v-model="item.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="$emit('pick-asset', { target: item, field: 'image_url', altField: 'image_alt' })"
                                >
                                    {{ tp('library') }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('image_alt') }}</label>
                            <input v-model="item.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('image_title') }}</label>
                            <input v-model="item.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('items', index)">
                            {{ tp('remove') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'category_list'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('group_title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('description') }}</label>
                    <textarea v-model="block.payload.description" rows="2" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('categories') }}</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('categories', { label: tp('defaults.category'), href: '/', meta: '' })">
                        {{ tp('add_category') }}
                    </button>
                </div>
                <div v-for="(category, index) in (block.payload.categories || [])" :key="`category-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-3">
                        <input v-model="category.label" type="text" :placeholder="tp('label')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="category.href" type="text" :placeholder="tp('href')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="category.meta" type="text" :placeholder="tp('meta')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('categories', index)">
                            {{ tp('remove') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'quick_links'">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('section_title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('pills') }}</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('links', { label: tp('defaults.quick_link'), href: '/pricing', target: '_self' })">
                        {{ tp('add_pill') }}
                    </button>
                </div>
                <div v-for="(link, index) in (block.payload.links || [])" :key="`quick-link-${index}`" class="grid gap-3 rounded-sm border border-stone-200 p-3 md:grid-cols-[1fr_1fr_auto] dark:border-neutral-700">
                    <input v-model="link.label" type="text" :placeholder="tp('label')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <input v-model="link.href" type="text" :placeholder="tp('href')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <button type="button" class="rounded-sm border border-red-200 px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('links', index)">
                        {{ tp('remove') }}
                    </button>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'cards'">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('section_title') }}</label>
                <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('cards') }}</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('cards', { title: tp('defaults.card_title'), body: tp('defaults.card_body_html'), href: '/', badge: '', image_url: '', image_alt: '', image_title: '' })">
                        {{ tp('add_card') }}
                    </button>
                </div>
                <div v-for="(card, index) in (block.payload.cards || [])" :key="`card-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <input v-model="card.title" type="text" :placeholder="tp('title')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="card.badge" type="text" :placeholder="tp('badge')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="card.href" type="text" :placeholder="tp('href')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 md:col-span-2" />
                        <div class="md:col-span-2">
                            <RichTextEditor v-model="card.body" :label="tp('card_body')" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('image_url') }}</label>
                            <div class="mt-1 flex gap-2">
                                <input v-model="card.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('image_url', 'image_alt')">
                                    {{ tp('library') }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('image_alt') }}</label>
                            <input v-model="card.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ tp('image_title') }}</label>
                            <input v-model="card.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('cards', index)">
                            {{ tp('remove') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'featured_content' || block.type === 'promo_banner'">
            <div class="grid gap-3 md:grid-cols-2">
                <div v-if="block.type === 'featured_content'">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('eyebrow') }}</label>
                    <input v-model="block.payload.eyebrow" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div v-else>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('badge') }}</label>
                    <input v-model="block.payload.badge" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="md:col-span-2">
                    <RichTextEditor v-model="block.payload.body" :label="tp('body')" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('cta_label') }}</label>
                    <input v-model="block.payload.cta_label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('cta_href') }}</label>
                    <input v-model="block.payload.cta_href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('image_url') }}</label>
                    <div class="mt-1 flex gap-2">
                        <input v-model="block.payload.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('image_url', 'image_alt')">
                            {{ tp('library') }}
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('image_alt') }}</label>
                    <input v-model="block.payload.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('image_title') }}</label>
                    <input v-model="block.payload.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'image'">
            <div class="grid gap-3 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('image_url') }}</label>
                    <div class="mt-1 flex gap-2">
                        <input v-model="block.payload.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('image_url', 'image_alt')">
                            {{ tp('library') }}
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('alt_text') }}</label>
                    <input v-model="block.payload.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('image_title') }}</label>
                    <input v-model="block.payload.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('caption') }}</label>
                    <input v-model="block.payload.caption" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('optional_link') }}</label>
                    <input v-model="block.payload.href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'cta'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <RichTextEditor v-model="block.payload.body" :label="tp('body')" />
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('button_label') }}</label>
                        <input v-model="block.payload.button_label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('button_href') }}</label>
                        <input v-model="block.payload.button_href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'text'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <RichTextEditor v-model="block.payload.body" :label="tp('body')" />
            </div>
        </template>

        <template v-else-if="block.type === 'html'">
            <RichTextEditor v-model="block.payload.html" :label="tp('sanitized_html')" />
        </template>

        <template v-else-if="block.type === 'module_shortcut'">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('section_title') }}</label>
                <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('shortcuts') }}</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('shortcuts', { label: tp('defaults.shortcut'), route_name: 'dashboard', description: '', icon: '' })">
                        {{ tp('add_shortcut') }}
                    </button>
                </div>
                <div v-for="(shortcut, index) in (block.payload.shortcuts || [])" :key="`shortcut-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <input v-model="shortcut.label" type="text" :placeholder="tp('label')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="shortcut.icon" type="text" :placeholder="tp('icon')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="shortcut.route_name" type="text" :placeholder="tp('route_name')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="shortcut.description" type="text" :placeholder="tp('description')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('shortcuts', index)">
                            {{ tp('remove') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'demo_preview'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('title') }}</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <RichTextEditor v-model="block.payload.body" :label="tp('body')" />
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('preview_image') }}</label>
                    <div class="mt-1 flex gap-2">
                        <input v-model="block.payload.preview_image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('preview_image_url', 'preview_image_alt')">
                            {{ tp('library') }}
                        </button>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('preview_image_alt') }}</label>
                        <input v-model="block.payload.preview_image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('preview_image_title') }}</label>
                        <input v-model="block.payload.preview_image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                </div>
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tp('metrics') }}</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('metrics', { label: tp('defaults.metric'), value: tp('defaults.metric_value') })">
                        {{ tp('add_metric') }}
                    </button>
                </div>
                <div v-for="(metric, index) in (block.payload.metrics || [])" :key="`metric-${index}`" class="grid gap-3 rounded-sm border border-stone-200 p-3 md:grid-cols-[1fr_1fr_auto] md:items-end dark:border-neutral-700">
                    <input v-model="metric.label" type="text" :placeholder="tp('label')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <input v-model="metric.value" type="text" :placeholder="tp('value')" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <button type="button" class="rounded-sm border border-red-200 px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('metrics', index)">
                        {{ tp('remove') }}
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
