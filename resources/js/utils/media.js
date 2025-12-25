const DEFAULT_MAX_IMAGE_DIMENSION = 1600;
const DEFAULT_MAX_IMAGE_BYTES = 1800000;
const DEFAULT_MAX_VIDEO_BYTES = 24000000;
const DEFAULT_QUALITY = 0.82;
const MIN_QUALITY = 0.6;
const QUALITY_STEP = 0.08;
const MAX_QUALITY_PASSES = 5;

const EXTENSION_BY_TYPE = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

const ALLOWED_IMAGE_TYPES = Object.keys(EXTENSION_BY_TYPE);

export const formatBytes = (value) => {
  const bytes = Number(value || 0);
  if (!bytes) {
    return '0 B';
  }
  const units = ['B', 'KB', 'MB', 'GB'];
  const index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const size = bytes / Math.pow(1024, index);
  return `${size.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
};

export const isImageFile = (file) => Boolean(file?.type && file.type.startsWith('image/'));
export const isVideoFile = (file) => Boolean(file?.type && file.type.startsWith('video/'));

const loadImage = async (file) => {
  if (typeof window !== 'undefined' && 'createImageBitmap' in window) {
    try {
      return await window.createImageBitmap(file);
    } catch (error) {
      // Fallback to Image element below.
    }
  }

  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      resolve(img);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('Image load failed.'));
    };
    img.src = url;
  });
};

const canvasToBlob = (canvas, type, quality) => new Promise((resolve) => {
  canvas.toBlob((blob) => resolve(blob), type, quality);
});

const buildFileName = (originalName, type) => {
  const extension = EXTENSION_BY_TYPE[type] || 'jpg';
  const base = (originalName || 'image').replace(/\.[^.]+$/, '');
  return `${base}.${extension}`;
};

export const resizeImageFile = async (file, options = {}) => {
  if (!isImageFile(file)) {
    return { file, resized: false, error: null };
  }

  const maxDimension = options.maxDimension ?? DEFAULT_MAX_IMAGE_DIMENSION;
  const maxBytes = options.maxBytes ?? DEFAULT_MAX_IMAGE_BYTES;
  const startQuality = options.quality ?? DEFAULT_QUALITY;
  const outputPreference = options.outputType;

  let image;
  try {
    image = await loadImage(file);
  } catch (error) {
    return { file, resized: false, error: 'Image processing failed.' };
  }

  const width = image.width || 0;
  const height = image.height || 0;
  if (!width || !height) {
    if (image.close) {
      image.close();
    }
    return { file, resized: false, error: 'Image processing failed.' };
  }

  const scale = Math.min(1, maxDimension / width, maxDimension / height);
  const targetWidth = Math.max(1, Math.round(width * scale));
  const targetHeight = Math.max(1, Math.round(height * scale));

  const shouldResize = scale < 1 || (maxBytes && file.size > maxBytes);
  if (!shouldResize) {
    if (image.close) {
      image.close();
    }
    return { file, resized: false, error: null };
  }

  const canvas = document.createElement('canvas');
  canvas.width = targetWidth;
  canvas.height = targetHeight;
  const context = canvas.getContext('2d');
  if (!context) {
    if (image.close) {
      image.close();
    }
    return { file, resized: false, error: 'Image processing failed.' };
  }

  context.drawImage(image, 0, 0, targetWidth, targetHeight);
  if (image.close) {
    image.close();
  }

  let outputType = outputPreference && ALLOWED_IMAGE_TYPES.includes(outputPreference)
    ? outputPreference
    : (ALLOWED_IMAGE_TYPES.includes(file.type) ? file.type : 'image/jpeg');

  if (outputType === 'image/png' && maxBytes && file.size > maxBytes) {
    outputType = 'image/webp';
  }

  let quality = startQuality;
  let blob = await canvasToBlob(canvas, outputType, quality);
  if (!blob) {
    return { file, resized: false, error: 'Image processing failed.' };
  }

  if (maxBytes && outputType !== 'image/png') {
    let passes = 0;
    while (blob.size > maxBytes && passes < MAX_QUALITY_PASSES && quality > MIN_QUALITY) {
      quality = Math.max(MIN_QUALITY, quality - QUALITY_STEP);
      const nextBlob = await canvasToBlob(canvas, outputType, quality);
      if (!nextBlob) {
        break;
      }
      blob = nextBlob;
      passes += 1;
    }
  }

  if (maxBytes && blob.size > maxBytes && outputType !== 'image/jpeg') {
    const fallbackBlob = await canvasToBlob(canvas, 'image/jpeg', Math.max(MIN_QUALITY, quality));
    if (fallbackBlob && fallbackBlob.size < blob.size) {
      blob = fallbackBlob;
      outputType = 'image/jpeg';
    }
  }

  if (maxBytes && blob.size > maxBytes) {
    return {
      file: null,
      resized: false,
      error: `Image too large. Max ${formatBytes(maxBytes)}.`,
    };
  }

  const resizedFile = new File([blob], buildFileName(file.name, outputType), {
    type: outputType,
    lastModified: Date.now(),
  });

  return { file: resizedFile, resized: true, error: null };
};

export const prepareMediaFile = async (file, options = {}) => {
  if (!file) {
    return { file: null, resized: false, error: null };
  }

  if (isImageFile(file)) {
    return resizeImageFile(file, options);
  }

  if (isVideoFile(file)) {
    const maxVideoBytes = options.maxVideoBytes ?? DEFAULT_MAX_VIDEO_BYTES;
    if (maxVideoBytes && file.size > maxVideoBytes) {
      return {
        file: null,
        resized: false,
        error: `Video too large. Max ${formatBytes(maxVideoBytes)}.`,
      };
    }
    return { file, resized: false, error: null };
  }

  return { file: null, resized: false, error: 'Unsupported file type.' };
};

export const MEDIA_LIMITS = {
  maxImageDimension: DEFAULT_MAX_IMAGE_DIMENSION,
  maxImageBytes: DEFAULT_MAX_IMAGE_BYTES,
  maxVideoBytes: DEFAULT_MAX_VIDEO_BYTES,
};
