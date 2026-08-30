export const SOCIAL_MEDIA_MAX_ITEMS = 20;

export const SOCIAL_MEDIA_TYPES = Object.freeze(['image', 'video', 'document']);

export const SOCIAL_MEDIA_EXTENSIONS = Object.freeze([
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'mp4',
    'mov',
    'webm',
    'pdf',
]);

const normalizedString = (value) => String(value || '').trim();

const normalizedSize = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const size = Number(value);

    return Number.isFinite(size) && size >= 0 ? size : null;
};

export const normalizeSocialMediaAsset = (asset) => {
    const type = normalizedString(asset?.type).toLowerCase();
    const url = normalizedString(asset?.url);

    if (!SOCIAL_MEDIA_TYPES.includes(type) || url === '') {
        return null;
    }

    const normalized = {
        type,
        url,
        alt_text: normalizedString(asset?.alt_text),
        title: normalizedString(asset?.title),
        thumbnail_url: normalizedString(asset?.thumbnail_url),
        thumbnail_offset: asset?.thumbnail_offset ?? '',
    };
    const source = normalizedString(asset?.source).toLowerCase();
    const disk = normalizedString(asset?.disk);
    const path = normalizedString(asset?.path);
    const name = normalizedString(asset?.name);
    const mimeType = normalizedString(asset?.mime_type).toLowerCase();
    const size = normalizedSize(asset?.size);

    if (source !== '') {
        normalized.source = source;
    }
    if (disk !== '') {
        normalized.disk = disk;
    }
    if (path !== '') {
        normalized.path = path;
    }
    if (name !== '') {
        normalized.name = name;
    }
    if (mimeType !== '') {
        normalized.mime_type = mimeType;
    }
    if (size !== null) {
        normalized.size = size;
    }

    return normalized;
};

export const serializeSocialMediaAsset = (asset) => {
    const normalized = normalizeSocialMediaAsset(asset);
    if (!normalized) {
        return null;
    }

    const serialized = {
        type: normalized.type,
        url: normalized.url,
        alt_text: normalized.alt_text,
        title: normalized.title,
        thumbnail_url: normalized.thumbnail_url,
        thumbnail_offset: normalized.thumbnail_offset,
    };

    if (normalized.source !== 'upload') {
        return serialized;
    }

    for (const key of ['source', 'disk', 'path', 'name', 'mime_type', 'size']) {
        if (normalized[key] !== undefined && normalized[key] !== null && normalized[key] !== '') {
            serialized[key] = normalized[key];
        }
    }

    return serialized;
};

export const normalizeSocialMediaAssets = (payload) => (Array.isArray(payload) ? payload : [])
    .map((asset) => normalizeSocialMediaAsset(asset))
    .filter(Boolean);

export const serializeSocialMediaAssets = (payload) => (Array.isArray(payload) ? payload : [])
    .map((asset) => serializeSocialMediaAsset(asset))
    .filter(Boolean);

export const normalizeSocialMediaState = (payload, primaryImageUrl = '') => {
    const primaryUrl = normalizedString(primaryImageUrl);
    const assets = normalizeSocialMediaAssets(payload);
    const firstAsset = assets[0] || null;
    const usesPrimaryImageField = firstAsset?.type === 'image'
        && firstAsset.url === primaryUrl
        && firstAsset.source !== 'upload'
        && firstAsset.alt_text === ''
        && firstAsset.title === ''
        && firstAsset.thumbnail_url === ''
        && firstAsset.thumbnail_offset === '';

    if (usesPrimaryImageField) {
        return {
            image_url: primaryUrl,
            media_assets: assets.slice(1),
        };
    }

    return {
        image_url: assets.length > 0 ? '' : primaryUrl,
        media_assets: assets,
    };
};
