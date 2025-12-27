<template>
    <!-- Datepicker with Floating Label and Click-Outside Detection -->
    <div ref="containerRef" class="relative w-full">
      <!-- Read-only input used as the peer element for floating label -->
      <input
        :id="inputId"
        type="text"
        readonly
        :value="selectedDate || ''"
        @click="togglePicker"
        placeholder=" "
        class="peer p-4 block w-full border border-stone-300 rounded-sm text-sm text-stone-700 bg-white shadow-sm
               focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500
               dark:bg-neutral-800 dark:border-neutral-700 dark:text-white dark:focus:ring-green-500
               placeholder-transparent
               focus:pt-6 focus:pb-2
               [&:not(:placeholder-shown)]:pt-6 [&:not(:placeholder-shown)]:pb-2"
      />
      <!-- Floating label -->
      <label
        :for="inputId"
        class="absolute top-0 left-0 p-4 h-full text-sm truncate pointer-events-none transition ease-in-out duration-100 origin-[0_0] dark:text-white peer-disabled:opacity-50 peer-disabled:pointer-events-none
               scale-90
               translate-x-0.5
               -translate-y-1.5
               text-stone-500 dark:text-neutral-500
               peer-placeholder-shown:scale-100
               peer-placeholder-shown:translate-x-0
               peer-placeholder-shown:translate-y-0
               peer-placeholder-shown:text-stone-500 dark:peer-placeholder-shown:text-neutral-500
               peer-focus:scale-90
               peer-focus:translate-x-0.5
               peer-focus:-translate-y-1.5
               peer-focus:text-stone-500 dark:peer-focus:text-neutral-500"
      >
        <span>{{ label }}</span>
        <span v-if="required" class="text-red-500 dark:text-red-400"> *</span>
      </label>

      <!-- Calendar Dropdown -->
      <div
        v-if="showPicker"
        class="absolute left-0 mt-2 w-80 bg-white border border-stone-200 rounded-sm shadow-lg dark:bg-neutral-900 dark:border-neutral-700 z-50"
      >
        <!-- Calendar Header with Month Navigation -->
        <div class="flex justify-between items-center p-3 border-b border-stone-200 dark:border-neutral-700">
          <button
            @click="prevMonth"
            type="button"
            class="p-2 text-stone-700 hover:bg-stone-100 rounded-full dark:text-neutral-200 dark:hover:bg-neutral-800"
            aria-label="Previous Month"
          >
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-stone-700 dark:text-neutral-200">
              {{ monthNames[currentMonth] }} {{ currentYear }}
            </span>
          </div>
          <button
            @click="nextMonth"
            type="button"
            class="p-2 text-stone-700 hover:bg-stone-100 rounded-full dark:text-neutral-200 dark:hover:bg-neutral-800"
            aria-label="Next Month"
          >
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>

        <!-- Days of the Week Header -->
        <div class="grid grid-cols-7 gap-1 p-3 text-center text-xs text-stone-500 dark:text-neutral-400">
          <div>Mo</div>
          <div>Tu</div>
          <div>We</div>
          <div>Th</div>
          <div>Fr</div>
          <div>Sa</div>
          <div>Su</div>
        </div>

        <!-- Calendar Days Grid -->
        <div class="grid grid-cols-7 gap-1 p-3">
          <template v-for="(day, index) in daysArray" :key="index">
            <div>
              <!-- Render a clickable day if valid; otherwise render an empty cell -->
              <button
                v-if="day"
                @click="selectDay(day)"
                type="button"
                :class="[
                  'w-full h-8 rounded-sm text-sm focus:outline-none',
                  formatDate(new Date(currentYear, currentMonth, day)) === selectedDate
                    ? 'bg-green-600 text-white'
                    : 'text-stone-800 hover:bg-green-100 dark:text-neutral-200 dark:hover:bg-neutral-800'
                ]"
              >
                {{ day }}
              </button>
              <div v-else class="w-full h-8"></div>
            </div>
          </template>
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
      required: false,
      default: ''
    },
    required: {
      type: Boolean,
      default: false
    },
    label: {
      type: String,
      required: true
    },
    placeholder: {
      type: String,
      default: 'Select a date'
    }
  });

  // Define emits for v-model binding
  const emit = defineEmits(['update:modelValue']);

  // Container ref for click-outside detection
  const containerRef = ref(null);
  const inputId = `date-picker-${Math.random().toString(36).slice(2, 10)}`;

  // Reactive state to toggle the calendar dropdown visibility
  const showPicker = ref(false);

  // Reactive state for the currently displayed date (used for month/year view)
  // Defaults to today's date or the provided modelValue (if valid)
  const currentDate = ref(new Date());
  if (props.modelValue) {
    const dateFromModel = new Date(props.modelValue);
    if (!isNaN(dateFromModel)) {
      currentDate.value = dateFromModel;
    }
  }

  // Computed property for the selected date (v-model binding)
  const selectedDate = computed({
    get() {
      return props.modelValue;
    },
    set(newValue) {
      emit('update:modelValue', newValue);
    }
  });

  // Array of month names for display
  const monthNames = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"
  ];

  // Computed property for the current month (0-indexed)
  const currentMonth = computed(() => currentDate.value.getMonth());

  // Computed property for the current year
  const currentYear = computed(() => currentDate.value.getFullYear());

  // Helper function to determine the number of days in a given month
  const getDaysInMonth = (year, month) => {
    return new Date(year, month + 1, 0).getDate();
  };

  // Computed property to generate the calendar grid (days array)
  const daysArray = computed(() => {
    const year = currentYear.value;
    const month = currentMonth.value;
    const daysInMonth = getDaysInMonth(year, month);

    // Determine the day of the week for the 1st of the month
    // JavaScript's getDay() returns 0 (Sunday) to 6 (Saturday)
    // Adjust so that Monday is considered the first day (offset = 0)
    const firstDayOfWeek = new Date(year, month, 1).getDay();
    const offset = (firstDayOfWeek + 6) % 7;

    // Calculate total grid slots (fill extra cells to complete full weeks)
    const totalSlots = offset + daysInMonth;
    const rows = Math.ceil(totalSlots / 7);
    const totalCells = rows * 7;

    const days = [];
    // Fill initial empty cells
    for (let i = 0; i < offset; i++) {
      days.push(null);
    }
    // Fill in the days of the month
    for (let day = 1; day <= daysInMonth; day++) {
      days.push(day);
    }
    // Fill remaining cells with null values
    while (days.length < totalCells) {
      days.push(null);
    }
    return days;
  });

  // Helper function to format a date as "YYYY-MM-DD"
  const formatDate = (date) => {
    const year = date.getFullYear();
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const day = date.getDate().toString().padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  // Toggle the visibility of the calendar dropdown
  const togglePicker = () => {
    showPicker.value = !showPicker.value;
  };

  // Navigate to the previous month
  const prevMonth = () => {
    const date = new Date(currentYear.value, currentMonth.value - 1, 1);
    currentDate.value = date;
  };

  // Navigate to the next month
  const nextMonth = () => {
    const date = new Date(currentYear.value, currentMonth.value + 1, 1);
    currentDate.value = date;
  };

  // Handle the selection of a day from the calendar
  const selectDay = (day) => {
    if (!day) return;
    const date = new Date(currentYear.value, currentMonth.value, day);
    const formatted = formatDate(date);
    selectedDate.value = formatted;
    // Update currentDate so the calendar reflects the chosen date
    currentDate.value = date;
    // Hide the dropdown after selection
    showPicker.value = false;
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
  </script>
