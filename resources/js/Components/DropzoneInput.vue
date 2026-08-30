<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  formatBytes,
  prepareMediaFile,
  resizeImageFile,
  resolveMediaType,
  takeFilesWithinTotalBytes,
  MEDIA_LIMITS,
} from '@/utils/media';

const { t } = useI18n();

// Props
const props = defineProps({
  modelValue: {
    type: [File, String, Array],
    default: null, // Par défaut, aucune image n'est sélectionnée
  },
  label: {
    type: String,
    default: '',
  },
  allowedExtensions: {
    type: Array,
    default: () => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'],
  },
  mode: {
    type: String,
    default: 'image',
    validator: (value) => ['image', 'media'].includes(value),
  },
  multiple: Boolean,
  maxFiles: {
    type: Number,
    default: 20,
  },
  existingItems: {
    type: Array,
    default: () => ([]),
  },
  disabled: Boolean,
});

// Événements
const emit = defineEmits(['update:modelValue', 'remove-existing']); // Événement pour mettre à jour v-model

const file = computed({
  get: () => props.modelValue, // Obtenir le fichier depuis v-model
  set: (value) => !props.disabled && emit('update:modelValue', value),
});
const isMediaMode = computed(() => props.mode === 'media');
const selectedFiles = computed(() => (
  props.multiple && Array.isArray(props.modelValue)
    ? props.modelValue.filter((value) => value instanceof File)
    : []
));
const existingMediaItems = computed(() => (
  props.multiple && Array.isArray(props.existingItems) ? props.existingItems : []
));
const selectedFileCount = computed(() => selectedFiles.value.length + existingMediaItems.value.length);
const selectedFileBytes = computed(() => selectedFiles.value.reduce(
  (total, selectedFile) => total + Math.max(0, Number(selectedFile?.size) || 0),
  0,
));
const hasReachedFileLimit = computed(() => selectedFileCount.value >= Math.max(1, props.maxFiles));
const hasReachedTotalMediaLimit = computed(() => (
  isMediaMode.value && selectedFileBytes.value >= MEDIA_LIMITS.maxTotalMediaBytes
));
const canAddFiles = computed(() => !hasReachedFileLimit.value && !hasReachedTotalMediaLimit.value);
const limitReachedMessage = computed(() => (
  hasReachedTotalMediaLimit.value
    ? t('dropzone.errors.total_too_large', { size: formatBytes(MEDIA_LIMITS.maxTotalMediaBytes) })
    : t('dropzone.limit_reached', { count: props.maxFiles })
));

const input = ref(null); // Référence pour l'élément input de fichier
const preview = ref(null); // Référence pour l'aperçu de l'image
const progress = ref(0); // Progression fictive (par exemple pour l'upload)
const errorMessage = ref('');
const isDragging = ref(false);
const showProgress = computed(() => file.value instanceof File);
const normalizedAllowedExtensions = computed(() => (
  props.allowedExtensions.map((extension) => String(extension).replace(/^\./, '').toLowerCase())
));
const acceptedFileTypes = computed(() => (
  normalizedAllowedExtensions.value.map((extension) => `.${extension}`).join(',')
));
const resolvedLabel = computed(() => props.label || t(
  isMediaMode.value ? 'dropzone.upload_media' : 'dropzone.upload_image',
));
const resolvedHint = computed(() => t(
  isMediaMode.value ? 'dropzone.media_hint' : 'dropzone.optimization_hint',
));

const previewName = computed(() => {
  if (file.value instanceof File) {
    return file.value.name;
  }

  return resolvedLabel.value || t('dropzone.image_preview');
});

const previewMeta = computed(() => {
  if (file.value instanceof File) {
    return `${(file.value.size / 1024).toFixed(2)} KB`;
  }

  if (typeof props.modelValue === 'string' && props.modelValue.trim() !== '') {
    return t('dropzone.current_image');
  }

  return '';
});

const mediaTypeLabel = (value) => t(`dropzone.media_types.${value || 'file'}`);
const selectedFileType = (value) => resolveMediaType(value) || 'file';
const existingItemType = (value) => {
  const type = String(value?.type || '').toLowerCase();

  return ['image', 'video', 'document'].includes(type) ? type : 'file';
};
const existingItemName = (value) => String(
  value?.name || value?.title || value?.url || resolvedLabel.value,
).trim();

const localizedResizeError = (message) => {
  if (message === 'Image processing failed.') {
    return t('dropzone.errors.processing_failed');
  }

  const tooLargeMatch = String(message || '').match(/^(Image|Video|Document) too large\. Max (.+)\.$/);
  if (tooLargeMatch) {
    const translationKey = {
      Image: 'too_large',
      Video: 'video_too_large',
      Document: 'document_too_large',
    }[tooLargeMatch[1]];

    return t(`dropzone.errors.${translationKey}`, { size: tooLargeMatch[2] });
  }

  return message;
};

const updatePreview = (value) => {
  if (value instanceof File) {
    const reader = new FileReader();
    reader.onload = (e) => {
      preview.value = e.target.result;
    };
    reader.readAsDataURL(value);
    return;
  }
  if (typeof value === 'string' && value.trim() !== '') {
    preview.value = value;
    progress.value = 100;
    return;
  }
  preview.value = null;
  progress.value = 0;
};

const processSelectedFiles = async (incomingFiles, resetInput = null) => {
  const candidates = Array.from(incomingFiles || []).filter((value) => value instanceof File);
  if (props.disabled || candidates.length === 0) {
    return;
  }

  errorMessage.value = '';
  progress.value = 0;
  const availableSlots = props.multiple
    ? Math.max(0, Math.max(1, props.maxFiles) - selectedFileCount.value)
    : 1;
  const filesToProcess = candidates.slice(0, availableSlots);

  if (filesToProcess.length === 0) {
    errorMessage.value = t('dropzone.errors.too_many_files', { count: props.maxFiles });
    return;
  }

  const processedFiles = [];

  for (const selectedFile of filesToProcess) {
    const mime = selectedFile.type?.toLowerCase() || '';
    const extension = selectedFile.name?.split('.').pop()?.toLowerCase() || '';
    if (extension === 'svg' || mime === 'image/svg' || mime === 'image/svg+xml') {
      errorMessage.value = t('dropzone.errors.svg_not_allowed');
      continue;
    }

    if (!normalizedAllowedExtensions.value.includes(extension)) {
      errorMessage.value = t(
        isMediaMode.value
          ? 'dropzone.errors.unsupported_media_format'
          : 'dropzone.errors.unsupported_format',
        { formats: normalizedAllowedExtensions.value.join(', ') },
      );
      continue;
    }

    if (isMediaMode.value && !resolveMediaType(selectedFile)) {
      errorMessage.value = t('dropzone.errors.unsupported_media_format', {
        formats: normalizedAllowedExtensions.value.join(', '),
      });
      continue;
    }

    const result = isMediaMode.value
      ? await prepareMediaFile(selectedFile, {
        maxDimension: MEDIA_LIMITS.maxImageDimension,
        maxBytes: MEDIA_LIMITS.maxImageBytes,
        maxVideoBytes: MEDIA_LIMITS.maxVideoBytes,
        maxDocumentBytes: MEDIA_LIMITS.maxDocumentBytes,
      })
      : await resizeImageFile(selectedFile, {
        maxDimension: MEDIA_LIMITS.maxImageDimension,
        maxBytes: MEDIA_LIMITS.maxImageBytes,
      });
    if (props.disabled) {
      return;
    }

    if (result.error) {
      errorMessage.value = localizedResizeError(result.error);
      continue;
    }

    if (result.file) {
      processedFiles.push(result.file);
    }
  }

  if (resetInput) {
    resetInput.value = '';
  }

  if (processedFiles.length === 0) {
    return;
  }

  if (props.multiple) {
    const latestSelectedFiles = selectedFiles.value;
    const knownFiles = new Set(latestSelectedFiles.map((value) => (
      `${value.name}:${value.size}:${value.lastModified}`
    )));
    const uniqueFiles = processedFiles.filter((value) => {
      const key = `${value.name}:${value.size}:${value.lastModified}`;
      if (knownFiles.has(key)) {
        return false;
      }

      knownFiles.add(key);

      return true;
    });
    const remainingSlots = Math.max(
      0,
      Math.max(1, props.maxFiles) - existingMediaItems.value.length - latestSelectedFiles.length,
    );
    const filesWithinSlotLimit = uniqueFiles.slice(0, remainingSlots);
    const latestSelectedBytes = latestSelectedFiles.reduce(
      (total, selectedFile) => total + Math.max(0, Number(selectedFile?.size) || 0),
      0,
    );
    const {
      acceptedFiles,
      rejectedFiles: filesOverTotalLimit,
    } = isMediaMode.value
      ? takeFilesWithinTotalBytes(
        filesWithinSlotLimit,
        MEDIA_LIMITS.maxTotalMediaBytes,
        latestSelectedBytes,
      )
      : { acceptedFiles: filesWithinSlotLimit, rejectedFiles: [] };

    if (filesWithinSlotLimit.length < uniqueFiles.length) {
      errorMessage.value = t('dropzone.errors.too_many_files', { count: props.maxFiles });
    }
    if (filesOverTotalLimit.length > 0) {
      errorMessage.value = t('dropzone.errors.total_too_large', {
        size: formatBytes(MEDIA_LIMITS.maxTotalMediaBytes),
      });
    }

    if (acceptedFiles.length === 0) {
      return;
    }

    file.value = [...latestSelectedFiles, ...acceptedFiles];
    progress.value = 100;

    return;
  }

  const [processedFile] = processedFiles;
  file.value = processedFile;
  updatePreview(processedFile);

  const interval = setInterval(() => {
    if (progress.value >= 100) {
      clearInterval(interval);
    } else {
      progress.value += 10;
    }
  }, 100);
};

// Fonction pour gérer le changement de fichier
const handleFileChange = (event) => {
  processSelectedFiles(event.target.files, event.target);
};

// Fonction pour déclencher l'ouverture du champ <input>
const triggerFileInput = () => {
  if (props.disabled) {
    return;
  }

  if (input.value) {
    input.value.click();
  }
};

// Fonction pour supprimer le fichier
const removeFile = () => {
  if (props.disabled) {
    return;
  }

  file.value = null; // Supprimer le fichier
  preview.value = null; // Supprimer l'aper‡u
  progress.value = 0; // R‚initialiser la progression
  errorMessage.value = '';
  isDragging.value = false;
  if (input.value) {
    input.value.value = '';
  }
};

const removeSelectedFile = (index) => {
  if (props.disabled) {
    return;
  }

  file.value = selectedFiles.value.filter((_, fileIndex) => fileIndex !== index);
  errorMessage.value = '';
};

const removeExistingItem = (item) => {
  if (props.disabled) {
    return;
  }

  emit('remove-existing', item);
};

const handleDragOver = (event) => {
  event.preventDefault();

  if (props.disabled) {
    return;
  }

  isDragging.value = true;
};

const handleDragLeave = () => {
  isDragging.value = false;
};

const handleDrop = (event) => {
  event.preventDefault();
  isDragging.value = false;

  processSelectedFiles(event.dataTransfer?.files);
};


watch(() => props.modelValue, updatePreview, { immediate: true });
</script>

<template>
  <div>
    <template v-if="multiple">
      <div v-if="selectedFileCount" class="mb-3 space-y-2">
        <div
          v-for="item in existingMediaItems"
          :key="item.id || item.url"
          class="flex items-center justify-between gap-3 rounded-sm border border-stone-300 bg-white p-3 dark:border-neutral-600 dark:bg-neutral-800"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="rounded-sm bg-stone-100 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                {{ mediaTypeLabel(existingItemType(item)) }}
              </span>
              <p class="truncate text-sm font-medium text-stone-800 dark:text-white">
                {{ existingItemName(item) }}
              </p>
            </div>
          </div>
          <button
            type="button"
            :disabled="disabled"
            class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
            @click="removeExistingItem(item)"
          >
            {{ t('dropzone.remove') }}
          </button>
        </div>

        <div
          v-for="(selectedFile, index) in selectedFiles"
          :key="`${selectedFile.name}-${selectedFile.size}-${selectedFile.lastModified}`"
          class="flex items-center justify-between gap-3 rounded-sm border border-stone-300 bg-white p-3 dark:border-neutral-600 dark:bg-neutral-800"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="rounded-sm bg-stone-100 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                {{ mediaTypeLabel(selectedFileType(selectedFile)) }}
              </span>
              <p class="truncate text-sm font-medium text-stone-800 dark:text-white">
                {{ selectedFile.name }}
              </p>
            </div>
            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
              {{ formatBytes(selectedFile.size) }}
            </p>
          </div>
          <button
            type="button"
            :disabled="disabled"
            class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
            @click="removeSelectedFile(index)"
          >
            {{ t('dropzone.remove') }}
          </button>
        </div>
      </div>

      <div
        v-if="canAddFiles"
        class="flex cursor-pointer justify-center rounded-sm border border-dashed p-8 transition"
        :class="[
          isDragging
            ? 'border-stone-500 bg-stone-100 dark:border-neutral-400 dark:bg-neutral-700'
            : 'border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-800',
          disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        ]"
        :aria-disabled="disabled"
        @click="triggerFileInput"
        @dragover="handleDragOver"
        @dragleave="handleDragLeave"
        @drop="handleDrop"
      >
        <div class="text-center">
          <span class="inline-flex size-12 items-center justify-center rounded-full bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200">
            <svg
              class="size-5"
              xmlns="http://www.w3.org/2000/svg"
              width="24"
              height="24"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="17 8 12 3 7 8"></polyline>
              <line x1="12" x2="12" y1="3" y2="15"></line>
            </svg>
          </span>
          <p class="mt-3 text-sm font-medium text-stone-800 dark:text-neutral-200">
            {{ resolvedLabel }}
          </p>
          <div class="mt-1 flex flex-wrap justify-center text-sm leading-6 text-stone-600 dark:text-neutral-300">
            <span class="pe-1">{{ t('dropzone.drop_here') }}</span>
            <span class="font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-500">
              {{ t('dropzone.browse') }}
            </span>
          </div>
          <p class="mt-1 text-xs text-stone-400 dark:text-neutral-400">
            {{ resolvedHint }}
          </p>
        </div>
      </div>

      <p v-else class="text-xs text-stone-500 dark:text-neutral-400">
        {{ limitReachedMessage }}
      </p>
    </template>

    <template v-else>
    <!-- Prévisualisation de l'image -->
    <template v-if="preview">
      <div
        class="p-3 bg-white border border-solid border-stone-300 rounded-sm dark:bg-neutral-800 dark:border-neutral-600"
      >
        <div class="mb-1 flex justify-between items-center">
          <div class="flex items-center gap-x-3">
            <img
              :src="preview"
              :alt="t('dropzone.preview_alt')"
              class="size-10 rounded-sm border border-stone-200 dark:border-neutral-700"
            />
            <div>
              <p class="text-sm font-medium text-stone-800 dark:text-white">
                {{ previewName }}
              </p>
              <p v-if="previewMeta" class="text-xs text-stone-500 dark:text-neutral-500">
                {{ previewMeta }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              type="button"
              :disabled="disabled"
              @click="triggerFileInput"
              class="rounded-sm border border-stone-300 bg-white px-2 py-1 text-xs font-semibold text-stone-700 transition hover:bg-stone-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
            >
              {{ t('dropzone.replace') }}
            </button>
            <button
              type="button"
              :disabled="disabled"
              @click="removeFile"
              class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300 dark:hover:bg-rose-950/50"
            >
              {{ t('dropzone.remove') }}
            </button>
          </div>
        </div>

        <!-- Barre de progression -->
        <div v-if="showProgress" class="flex items-center gap-x-3">
          <div
            class="flex w-full h-2 bg-stone-200 rounded-full overflow-hidden dark:bg-neutral-700"
          >
            <div
              class="flex flex-col justify-center rounded-full bg-blue-600 text-xs text-white text-center transition-all duration-500"
              :style="{ width: progress + '%' }"
            ></div>
          </div>
          <div class="w-10 text-end">
            <span class="text-sm text-stone-800 dark:text-white">
              {{ progress }}%
            </span>
          </div>
        </div>
        <p v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
          {{ t('dropzone.attached_hint') }}
        </p>
      </div>
    </template>

    <!-- Bouton pour ajouter un fichier -->
    <div
      v-else
      class="p-12 flex justify-center border border-dashed rounded-sm transition"
      :class="[
        isDragging
          ? 'border-stone-500 bg-stone-100 dark:border-neutral-400 dark:bg-neutral-700'
          : 'border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-800',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
      ]"
      @click="triggerFileInput"
      @dragover="handleDragOver"
      @dragleave="handleDragLeave"
      @drop="handleDrop"
      :aria-disabled="disabled"
    >
      <div class="text-center">
        <span
          class="inline-flex justify-center items-center size-16 bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200"
        >
          <svg
            class="shrink-0 size-6"
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="17 8 12 3 7 8"></polyline>
            <line x1="12" x2="12" y1="3" y2="15"></line>
          </svg>
        </span>
        <div class="mt-4 flex flex-wrap justify-center text-sm leading-6 text-stone-600">
          <span class="pe-1 font-medium text-stone-800 dark:text-neutral-200">
            {{ t('dropzone.drop_here') }}
          </span>
          <span class="bg-white font-semibold text-blue-600 hover:text-blue-700 dark:bg-neutral-800 dark:text-blue-500">
            {{ t('dropzone.browse') }}
          </span>
        </div>
        <p class="mt-1 text-xs text-stone-400 dark:text-neutral-400">
          {{ resolvedHint }}
        </p>
      </div>
    </div>
    </template>

    <!-- Champ caché pour sélectionner le fichier -->
    <input
      type="file"
      :accept="acceptedFileTypes"
      :multiple="multiple"
      :disabled="disabled || (multiple && !canAddFiles)"
      :aria-disabled="disabled || (multiple && !canAddFiles)"
      class="sr-only"
      @change="handleFileChange"
      ref="input"
    />
    <p v-if="errorMessage" class="mt-2 text-xs text-rose-600">
      {{ errorMessage }}
    </p>
  </div>
</template>
