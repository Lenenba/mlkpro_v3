<template>
    <!-- Timepicker with Floating Label and Click-Outside Detection -->
    <div ref="containerRef" class="relative w-full min-w-0">
      <!-- Read-only input used as the peer element for the floating label -->
      <input
        :id="inputId"
        type="text"
        readonly
        :disabled="disabled"
        :required="required"
        :value="timeValue"
        @click="togglePicker"
        @keydown.enter.prevent="togglePicker"
        @keydown.space.prevent="togglePicker"
        @keydown.esc.prevent="showPicker = false"
        placeholder=" "
        class="app-field-control peer cursor-pointer truncate"
        aria-haspopup="dialog"
        :aria-expanded="showPicker ? 'true' : 'false'"
        :aria-required="required ? 'true' : undefined"
      />
      <!-- Floating label -->
      <label
            :for="inputId"
            :title="label"
            class="app-floating-label
                scale-90
                translate-x-0.5
                -translate-y-1.5
                peer-placeholder-shown:scale-100
                peer-placeholder-shown:translate-x-0
                peer-placeholder-shown:translate-y-0
                peer-focus:scale-90
                peer-focus:translate-x-0.5
                peer-focus:-translate-y-1.5">
            <span class="app-floating-label-content">
              {{ label }}<span v-if="required" class="text-red-500 dark:text-red-400"> *</span>
            </span>
        </label>

      <!-- Time Picker Dropdown -->
      <div
        v-if="showPicker"
        class="absolute start-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-sm border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
      >
        <div class="p-3">
          <!-- Hours Section -->
          <div>
            <span class="block text-sm font-medium text-stone-700 dark:text-neutral-200 mb-2">Hour</span>
            <div class="grid grid-cols-6 gap-2">
              <button
                v-for="h in hours"
                :key="h"
                @click="selectHour(h)"
                type="button"
                :class="[
                  'w-full py-2 rounded-sm text-sm focus:outline-none',
                  selectedHour === h ? 'bg-green-600 text-white' : 'text-stone-800 hover:bg-green-100 dark:text-neutral-200 dark:hover:bg-neutral-800'
                ]"
              >
                {{ h }}
              </button>
            </div>
          </div>
          <!-- Minutes Section -->
          <div class="mt-4">
            <span class="block text-sm font-medium text-stone-700 dark:text-neutral-200 mb-2">Minute</span>
            <div class="grid grid-cols-6 gap-2">
              <button
                v-for="m in minutes"
                :key="m"
                @click="selectMinute(m)"
                type="button"
                :class="[
                  'w-full py-2 rounded-sm text-sm focus:outline-none',
                  selectedMinute === m ? 'bg-green-600 text-white' : 'text-stone-800 hover:bg-green-100 dark:text-neutral-200 dark:hover:bg-neutral-800'
                ]"
              >
                {{ m }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>

  <script setup>
  // Import necessary functions from Vue
  import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

  // Define component props
  const props = defineProps({
    modelValue: {
      type: String,
      default: ''
    },
    label: {
      type: String,
      required: true
    },
    required: {
      type: Boolean,
      default: false
    },
    disabled: {
      type: Boolean,
      default: false
    },
    placeholder: {
      type: String,
      default: 'Select a time'
    }
  });

  // Define emits for v-model binding
  const emit = defineEmits(['update:modelValue']);

  // Reactive state to toggle the time picker dropdown visibility
  const showPicker = ref(false);

  // Reference for the component container to detect outside clicks
  const containerRef = ref(null);
  const inputId = `time-picker-${Math.random().toString(36).slice(2, 10)}`;

  // Reactive state for the selected hour and minute
  const selectedHour = ref(null);
  const selectedMinute = ref(null);

  // Parse the initial modelValue (expected format "HH:mm") if provided
  if (props.modelValue) {
    const parts = props.modelValue.split(':');
    if (parts.length === 2) {
      selectedHour.value = parts[0];
      selectedMinute.value = parts[1];
    }
  }

  // Computed property to display the selected time in "HH:mm" format
  const timeValue = computed(() => {
    if (selectedHour.value !== null && selectedMinute.value !== null) {
      return `${selectedHour.value}:${selectedMinute.value}`;
    }
    return '';
  });

  // Computed array for hours (00 to 23)
  const hours = computed(() => {
    const arr = [];
    for (let i = 0; i < 24; i++) {
      arr.push(i.toString().padStart(2, '0'));
    }
    return arr;
  });

  // Computed array for minutes (00 to 55 in 5-minute increments)
  const minutes = computed(() => {
    const arr = [];
    for (let i = 0; i < 60; i += 5) {
      arr.push(i.toString().padStart(2, '0'));
    }
    return arr;
  });

  // Function to handle hour selection
  const selectHour = (h) => {
    selectedHour.value = h;
    // If minute is already selected, update the model value and close the picker
    if (selectedMinute.value !== null) {
      updateTime();
    }
  };

  // Function to handle minute selection
  const selectMinute = (m) => {
    selectedMinute.value = m;
    // If hour is already selected, update the model value and close the picker
    if (selectedHour.value !== null) {
      updateTime();
    }
  };

  // Update the modelValue and close the picker
  const updateTime = () => {
    const formatted = `${selectedHour.value}:${selectedMinute.value}`;
    emit('update:modelValue', formatted);
    showPicker.value = false;
  };

  // Toggle the visibility of the time picker dropdown
  const togglePicker = () => {
    if (props.disabled) {
      return;
    }
    showPicker.value = !showPicker.value;
  };

  // Handle clicks outside the component to close the dropdown
  const handleClickOutside = (event) => {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
      showPicker.value = false;
    }
  };

  // Attach the document click listener when component mounts
  onMounted(() => {
    document.addEventListener('click', handleClickOutside);
  });

  // Remove the listener before component unmounts
  onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
  });

  // Watch for external changes to modelValue and update selected time accordingly
  watch(
    () => props.modelValue,
    (newVal) => {
      if (newVal) {
        const parts = newVal.split(':');
        if (parts.length === 2) {
          selectedHour.value = parts[0];
          selectedMinute.value = parts[1];
        }
      } else {
        selectedHour.value = null;
        selectedMinute.value = null;
      }
    }
  );
  </script>
