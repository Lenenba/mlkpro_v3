<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { resizeImageFile, MEDIA_LIMITS } from '@/utils/media';

// Props
const props = defineProps({
  modelValue: {
    type: [File, String],
    default: null, // Par défaut, aucune image n'est sélectionnée
  },
  label: {
    type: String,
    default: 'Upload an image',
  },
});

// Événements
const emit = defineEmits(['update:modelValue']); // Événement pour mettre à jour v-model

const file = computed({
  get: () => props.modelValue, // Obtenir le fichier depuis v-model
  set: (value) => emit('update:modelValue', value), // Mettre à jour le fichier dans le parent
});

const input = ref(null); // Référence pour l'élément input de fichier
const preview = ref(null); // Référence pour l'aperçu de l'image
const progress = ref(0); // Progression fictive (par exemple pour l'upload)
const errorMessage = ref('');
const isDragging = ref(false);
const showProgress = computed(() => file.value instanceof File);

const previewName = computed(() => {
  if (file.value instanceof File) {
    return file.value.name;
  }

  return props.label || 'Image preview';
});

const previewMeta = computed(() => {
  if (file.value instanceof File) {
    return `${(file.value.size / 1024).toFixed(2)} KB`;
  }

  if (typeof props.modelValue === 'string' && props.modelValue.trim() !== '') {
    return 'Current image';
  }

  return '';
});

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

const processSelectedFile = async (selectedFile, resetInput = null) => {
  if (!selectedFile) {
    return;
  }

  errorMessage.value = '';
  progress.value = 0;

  const result = await resizeImageFile(selectedFile, {
    maxDimension: MEDIA_LIMITS.maxImageDimension,
    maxBytes: MEDIA_LIMITS.maxImageBytes,
  });
  if (result.error) {
    errorMessage.value = result.error;
    if (resetInput) {
      resetInput.value = '';
    }
    return;
  }

  const processedFile = result.file;
  file.value = processedFile;

  const reader = new FileReader();
  reader.onload = (e) => {
    preview.value = e.target.result;
  };
  reader.readAsDataURL(processedFile);

  const interval = setInterval(() => {
    if (progress.value >= 100) {
      clearInterval(interval);
    } else {
      progress.value += 10;
    }
  }, 100);
};

// Fonction pour gérer le changement de fichier
const handleFileChange = async (event) => {
  await processSelectedFile(event.target.files[0], event.target);
};

// Fonction pour déclencher l'ouverture du champ <input>
const triggerFileInput = () => {
  if (input.value) {
    input.value.click(); // Déclenche l'événement "click" sur l'élément input
  }
};

// Fonction pour supprimer le fichier
const removeFile = () => {
  file.value = null; // Supprimer le fichier
  preview.value = null; // Supprimer l'aper‡u
  progress.value = 0; // R‚initialiser la progression
  errorMessage.value = '';
  isDragging.value = false;
  if (input.value) {
    input.value.value = '';
  }
};

const handleDragOver = (event) => {
  event.preventDefault();
  isDragging.value = true;
};

const handleDragLeave = () => {
  isDragging.value = false;
};

const handleDrop = async (event) => {
  event.preventDefault();
  isDragging.value = false;

  const droppedFile = event.dataTransfer?.files?.[0];
  await processSelectedFile(droppedFile);
};


// Initialiser l'aperçu si une image est déjà définie dans le modèle
onMounted(() => {
  if (typeof props.modelValue === 'string' && props.modelValue.trim() !== '') {
    preview.value = props.modelValue; // Affiche l'image existante
  }
});

watch(
  () => props.modelValue,
  (value) => {
    updatePreview(value);
  }
);
</script>

<template>
  <div>
    <!-- Prévisualisation de l'image -->
    <template v-if="preview">
      <div
        class="p-3 bg-white border border-solid border-stone-300 rounded-sm dark:bg-neutral-800 dark:border-neutral-600"
      >
        <div class="mb-1 flex justify-between items-center">
          <div class="flex items-center gap-x-3">
            <img
              :src="preview"
              alt="Preview"
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
              @click="triggerFileInput"
              class="rounded-sm border border-stone-300 bg-white px-2 py-1 text-xs font-semibold text-stone-700 transition hover:bg-stone-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
            >
              Replace
            </button>
            <button
              type="button"
              @click="removeFile"
              class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300 dark:hover:bg-rose-950/50"
            >
              Remove
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
          This image is already attached. You can replace it or remove it.
        </p>
      </div>
    </template>

    <!-- Bouton pour ajouter un fichier -->
    <div
      v-else
      class="cursor-pointer p-12 flex justify-center border border-dashed rounded-sm transition"
      :class="isDragging
        ? 'border-stone-500 bg-stone-100 dark:border-neutral-400 dark:bg-neutral-700'
        : 'border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-800'"
      @click="triggerFileInput"
      @dragover="handleDragOver"
      @dragleave="handleDragLeave"
      @drop="handleDrop"
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
            Drop your file here or
          </span>
          <span class="bg-white font-semibold text-blue-600 hover:text-blue-700 dark:bg-neutral-800 dark:text-blue-500">
            browse
          </span>
        </div>
        <p class="mt-1 text-xs text-stone-400 dark:text-neutral-400">
          Large images are optimized automatically.
        </p>
      </div>
    </div>

    <!-- Champ caché pour sélectionner le fichier -->
    <input
      type="file"
      accept="image/*"
      class="sr-only"
      @change="handleFileChange"
      ref="input"
    />
    <p v-if="errorMessage" class="mt-2 text-xs text-rose-600">
      {{ errorMessage }}
    </p>
  </div>
</template>
