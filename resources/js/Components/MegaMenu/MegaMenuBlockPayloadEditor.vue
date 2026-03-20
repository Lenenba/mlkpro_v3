<script setup>
import RichTextEditor from '@/Components/RichTextEditor.vue';

const props = defineProps({
    block: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['pick-asset']);

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
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Group title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Description</label>
                    <textarea v-model="block.payload.description" rows="2" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Links</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('links', { label: 'New link', href: '/', note: '', badge: '', target: '_self' })">
                        Add link
                    </button>
                </div>
                <div v-for="(link, index) in (block.payload.links || [])" :key="`nav-link-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Label</label>
                            <input v-model="link.label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Href</label>
                            <input v-model="link.href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Note</label>
                            <input v-model="link.note" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Badge</label>
                            <input v-model="link.badge" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('links', index)">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'product_showcase'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Section title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Description</label>
                    <textarea v-model="block.payload.description" rows="2" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Products</h4>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="addRow('items', { label: 'Product', href: '/pricing', note: '', badge: '', summary: '', target: '_self', image_url: '', image_alt: '', image_title: '' })"
                    >
                        Add product
                    </button>
                </div>
                <div v-for="(item, index) in (block.payload.items || [])" :key="`showcase-item-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Label</label>
                            <input v-model="item.label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Href</label>
                            <input v-model="item.href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Note</label>
                            <input v-model="item.note" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Badge</label>
                            <input v-model="item.badge" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Preview summary</label>
                            <textarea v-model="item.summary" rows="3" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Preview image</label>
                            <div class="mt-1 flex gap-2">
                                <input v-model="item.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="$emit('pick-asset', { target: item, field: 'image_url', altField: 'image_alt' })"
                                >
                                    Library
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Image alt</label>
                            <input v-model="item.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Image title</label>
                            <input v-model="item.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('items', index)">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'category_list'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Group title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Description</label>
                    <textarea v-model="block.payload.description" rows="2" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Categories</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('categories', { label: 'Category', href: '/', meta: '' })">
                        Add category
                    </button>
                </div>
                <div v-for="(category, index) in (block.payload.categories || [])" :key="`category-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-3">
                        <input v-model="category.label" type="text" placeholder="Label" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="category.href" type="text" placeholder="Href" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="category.meta" type="text" placeholder="Meta" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('categories', index)">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'quick_links'">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Section title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Pills</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('links', { label: 'Quick link', href: '/pricing', target: '_self' })">
                        Add pill
                    </button>
                </div>
                <div v-for="(link, index) in (block.payload.links || [])" :key="`quick-link-${index}`" class="grid gap-3 rounded-sm border border-stone-200 p-3 md:grid-cols-[1fr_1fr_auto] dark:border-neutral-700">
                    <input v-model="link.label" type="text" placeholder="Label" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <input v-model="link.href" type="text" placeholder="Href" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <button type="button" class="rounded-sm border border-red-200 px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('links', index)">
                        Remove
                    </button>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'cards'">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Section title</label>
                <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Cards</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('cards', { title: 'Card title', body: '<p>Card body</p>', href: '/', badge: '', image_url: '', image_alt: '', image_title: '' })">
                        Add card
                    </button>
                </div>
                <div v-for="(card, index) in (block.payload.cards || [])" :key="`card-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <input v-model="card.title" type="text" placeholder="Title" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="card.badge" type="text" placeholder="Badge" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="card.href" type="text" placeholder="Href" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 md:col-span-2" />
                        <div class="md:col-span-2">
                            <RichTextEditor v-model="card.body" label="Card body" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Image URL</label>
                            <div class="mt-1 flex gap-2">
                                <input v-model="card.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('image_url', 'image_alt')">
                                    Library
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Image alt</label>
                            <input v-model="card.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Image title</label>
                            <input v-model="card.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('cards', index)">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'featured_content' || block.type === 'promo_banner'">
            <div class="grid gap-3 md:grid-cols-2">
                <div v-if="block.type === 'featured_content'">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Eyebrow</label>
                    <input v-model="block.payload.eyebrow" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div v-else>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Badge</label>
                    <input v-model="block.payload.badge" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="md:col-span-2">
                    <RichTextEditor v-model="block.payload.body" label="Body" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">CTA label</label>
                    <input v-model="block.payload.cta_label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">CTA href</label>
                    <input v-model="block.payload.cta_href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Image URL</label>
                    <div class="mt-1 flex gap-2">
                        <input v-model="block.payload.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('image_url', 'image_alt')">
                            Library
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Image alt</label>
                    <input v-model="block.payload.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Image title</label>
                    <input v-model="block.payload.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'image'">
            <div class="grid gap-3 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Image URL</label>
                    <div class="mt-1 flex gap-2">
                        <input v-model="block.payload.image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('image_url', 'image_alt')">
                            Library
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Alt text</label>
                    <input v-model="block.payload.image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Image title</label>
                    <input v-model="block.payload.image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Caption</label>
                    <input v-model="block.payload.caption" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Optional link</label>
                    <input v-model="block.payload.href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'cta'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <div>
                    <RichTextEditor v-model="block.payload.body" label="Body" />
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Button label</label>
                        <input v-model="block.payload.button_label" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Button href</label>
                        <input v-model="block.payload.button_href" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'text'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <RichTextEditor v-model="block.payload.body" label="Body" />
            </div>
        </template>

        <template v-else-if="block.type === 'html'">
            <RichTextEditor v-model="block.payload.html" label="Sanitized HTML" />
        </template>

        <template v-else-if="block.type === 'module_shortcut'">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Section title</label>
                <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Shortcuts</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('shortcuts', { label: 'Shortcut', route_name: 'dashboard', description: '', icon: '' })">
                        Add shortcut
                    </button>
                </div>
                <div v-for="(shortcut, index) in (block.payload.shortcuts || [])" :key="`shortcut-${index}`" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-2">
                        <input v-model="shortcut.label" type="text" placeholder="Label" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="shortcut.icon" type="text" placeholder="Icon" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="shortcut.route_name" type="text" placeholder="Route name" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <input v-model="shortcut.description" type="text" placeholder="Description" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('shortcuts', index)">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'demo_preview'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Title</label>
                    <input v-model="block.payload.title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                </div>
                <RichTextEditor v-model="block.payload.body" label="Body" />
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Preview image</label>
                    <div class="mt-1 flex gap-2">
                        <input v-model="block.payload.preview_image_url" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                        <button type="button" class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="pickAsset('preview_image_url', 'preview_image_alt')">
                            Library
                        </button>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Preview image alt</label>
                        <input v-model="block.payload.preview_image_alt" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Preview image title</label>
                        <input v-model="block.payload.preview_image_title" type="text" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    </div>
                </div>
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Metrics</h4>
                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addRow('metrics', { label: 'Metric', value: 'Value' })">
                        Add metric
                    </button>
                </div>
                <div v-for="(metric, index) in (block.payload.metrics || [])" :key="`metric-${index}`" class="grid gap-3 rounded-sm border border-stone-200 p-3 md:grid-cols-[1fr_1fr_auto] md:items-end dark:border-neutral-700">
                    <input v-model="metric.label" type="text" placeholder="Label" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <input v-model="metric.value" type="text" placeholder="Value" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                    <button type="button" class="rounded-sm border border-red-200 px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50" @click="removeRow('metrics', index)">
                        Remove
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
