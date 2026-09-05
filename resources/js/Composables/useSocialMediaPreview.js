import { computed, shallowRef, watch } from 'vue';
import { resolveMediaType } from '../utils/media.js';
import { normalizeSocialMediaAssets } from '../utils/socialMediaAssets.js';

export function useSocialMediaPreview(mediaItems, files) {
    const localAssets = shallowRef([]);

    watch(() => [...files()], (selectedFiles, previousFiles, onCleanup) => {
        const assets = selectedFiles.flatMap((file) => {
            const type = resolveMediaType(file);
            if (!type || !(file instanceof Blob)) {
                return [];
            }

            return [{ type, url: URL.createObjectURL(file), name: file.name || '', mime_type: file.type }];
        });
        localAssets.value = assets;
        onCleanup(() => assets.forEach((asset) => URL.revokeObjectURL(asset.url)));
    }, { immediate: true });

    return computed(() => [
        ...normalizeSocialMediaAssets(mediaItems()),
        ...localAssets.value,
    ]);
}
