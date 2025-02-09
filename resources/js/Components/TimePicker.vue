<template>
    <!-- Timepicker with Floating Label and Click-Outside Detection -->
    <div ref="containerRef" class="relative w-full">
      <!-- Read-only input used as the peer element for the floating label -->
      <input
        type="text"
        readonly
        :value="timeValue"
        @click="togglePicker"
        placeholder=" "
        class="peer p-4 block w-full border border-gray-300 rounded-sm text-sm text-gray-700 bg-white shadow-sm
               focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500
               dark:bg-neutral-800 dark:border-neutral-700 dark:text-white dark:focus:ring-green-500
               placeholder-transparent
               focus:pt-6 focus:pb-2
               [&:not(:placeholder-shown)]:pt-6 [&:not(:placeholder-shown)]:pb-2"
      />
      <!-- Floating label -->
      <label
            for="floating-input"
            class="absolute top-0 left-0 p-4 h-full text-sm truncate pointer-events-none transition ease-in-out duration-100 origin-[0_0] dark:text-white peer-disabled:opacity-50 peer-disabled:pointer-events-none
                scale-90
                translate-x-0.5
                -translate-y-1.5
                text-gray-500 dark:peer-focus:text-neutral-500
                peer-[not(:placeholder-shown)]:scale-90
                peer-[not(:placeholder-shown)]:translate-x-0.5
                peer-[not(:placeholder-shown)]:-translate-y-1.5
                peer-[not(:placeholder-shown)]:text-gray-500 dark:peer-[not(:placeholder-shown)]:text-neutral-500 dark:text-neutral-500">
            {{ label }}
        </label>

      <!-- Time Picker Dropdown -->
      <div
        v-if="showPicker"
        class="absolute left-0 mt-2 w-80 bg-white border border-gray-200 rounded-sm shadow-lg dark:bg-neutral-900 dark:border-neutral-700 z-50"
      >
        <div class="p-3">
          <!-- Hours Section -->
          <div>
            <span class="block text-sm font-medium text-gray-700 dark:text-neutral-200 mb-2">Hour</span>
            <div class="grid grid-cols-6 gap-2">
              <button
                v-for="h in hours"
                :key="h"
                @click="selectHour(h)"
                type="button"
                :class="[
                  'w-full py-2 rounded-sm text-sm focus:outline-none',
                  selectedHour === h ? 'bg-green-600 text-white' : 'text-gray-800 hover:bg-green-100 dark:text-neutral-200 dark:hover:bg-neutral-800'
                ]"
              >
                {{ h }}
              </button>
            </div>
          </div>
          <!-- Minutes Section -->
          <div class="mt-4">
            <span class="block text-sm font-medium text-gray-700 dark:text-neutral-200 mb-2">Minute</span>
            <div class="grid grid-cols-6 gap-2">
              <button
                v-for="m in minutes"
                :key="m"
                @click="selectMinute(m)"
                type="button"
                :class="[
                  'w-full py-2 rounded-sm text-sm focus:outline-none',
                  selectedMinute === m ? 'bg-green-600 text-white' : 'text-gray-800 hover:bg-green-100 dark:text-neutral-200 dark:hover:bg-neutral-800'
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
      }
    }
  );
  </script>
