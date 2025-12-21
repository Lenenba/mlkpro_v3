<script setup>
import { computed } from 'vue';

const props = defineProps({
    files: {
        type: Array,
        default: () => [],
    },
    existing: {
        type: Array,
        default: () => [],
    },
    removedIds: {
        type: Array,
        default: () => [],
    },
    label: {
        type: String,
        default: 'Additional images',
    },
});

const emit = defineEmits(['update:files', 'update:removedIds']);

const visibleExisting = computed(() =>
    props.existing.filter((image) => !props.removedIds.includes(image.id)),
);

const addFiles = (event) => {
    const selected = Array.from(event.target.files || []);
    if (!selected.length) {
        return;
    }
    emit('update:files', [...props.files, ...selected]);
    event.target.value = '';
};

const removeFile = (index) => {
    const next = [...props.files];
    next.splice(index, 1);
    emit('update:files', next);
};

const removeExisting = (id) => {
    emit('update:removedIds', [...props.removedIds, id]);
};

const previewUrl = (file) => {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }
    return null;
};
</script>

<template>
    <div class="space-y-3">
        <div class="text-sm font-medium text-gray-700 dark:text-neutral-300">
            {{ label }}
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <div
                v-for="image in visibleExisting"
                :key="image.id"
                class="relative group border border-gray-200 rounded-lg overflow-hidden bg-white dark:bg-neutral-900 dark:border-neutral-700"
            >
                <img :src="image.url || image.image_url || image.path" :alt="image.id" class="w-full h-24 object-cover" />
                <button
                    type="button"
                    class="absolute top-2 right-2 size-7 inline-flex items-center justify-center rounded-full bg-black/70 text-white opacity-0 group-hover:opacity-100 transition"
                    @click="removeExisting(image.id)"
                >
                    x
                </button>
            </div>

            <div
                v-for="(file, index) in files"
                :key="`file-${index}`"
                class="relative group border border-gray-200 rounded-lg overflow-hidden bg-white dark:bg-neutral-900 dark:border-neutral-700"
            >
                <img :src="previewUrl(file)" alt="New image" class="w-full h-24 object-cover" />
                <button
                    type="button"
                    class="absolute top-2 right-2 size-7 inline-flex items-center justify-center rounded-full bg-black/70 text-white opacity-0 group-hover:opacity-100 transition"
                    @click="removeFile(index)"
                >
                    x
                </button>
            </div>

            <label
                class="flex items-center justify-center h-24 border border-dashed border-gray-300 rounded-lg text-sm text-gray-500 cursor-pointer hover:border-gray-400 dark:border-neutral-600 dark:text-neutral-400"
            >
                Add images
                <input type="file" class="hidden" multiple accept="image/*" @change="addFiles" />
            </label>
        </div>
    </div>
</template>
