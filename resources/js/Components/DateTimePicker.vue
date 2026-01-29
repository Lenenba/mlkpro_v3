<template>
    <div ref="containerRef" class="relative w-full">
        <input
            :id="inputId"
            type="text"
            readonly
            :disabled="disabled"
            :value="displayValue || ''"
            @click="togglePicker"
            placeholder=" "
            :class="inputClasses"
        />
        <button
            v-if="showClear"
            type="button"
            @click.stop="clearDateTime"
            class="absolute inset-y-0 end-0 flex items-center pe-3 text-stone-400 hover:text-stone-600 dark:text-neutral-400 dark:hover:text-neutral-200"
            aria-label="Clear date"
        >
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
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

        <div
            v-if="showPicker"
            class="absolute left-0 mt-2 w-80 rounded-sm border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900 z-50"
        >
            <div class="flex items-center justify-between border-b border-stone-200 p-3 dark:border-neutral-700">
                <button
                    @click="prevMonth"
                    type="button"
                    class="rounded-full p-2 text-stone-700 hover:bg-stone-100 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    aria-label="Previous Month"
                >
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <span class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                    {{ monthNames[currentMonth] }} {{ currentYear }}
                </span>
                <button
                    @click="nextMonth"
                    type="button"
                    class="rounded-full p-2 text-stone-700 hover:bg-stone-100 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    aria-label="Next Month"
                >
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>

            <div class="grid grid-cols-7 gap-1 p-3 text-center text-xs text-stone-500 dark:text-neutral-400">
                <div v-for="label in weekdayLabels" :key="label">{{ label }}</div>
            </div>

            <div class="grid grid-cols-7 gap-1 p-3">
                <template v-for="(day, index) in daysArray" :key="index">
                    <div>
                        <button
                            v-if="day"
                            @click="selectDay(day)"
                            type="button"
                            :class="[
                                'h-8 w-full rounded-sm text-sm focus:outline-none',
                                formatDate(new Date(currentYear, currentMonth, day)) === dateValue
                                    ? 'bg-green-600 text-white'
                                    : 'text-stone-800 hover:bg-green-100 dark:text-neutral-200 dark:hover:bg-neutral-800'
                            ]"
                        >
                            {{ day }}
                        </button>
                        <div v-else class="h-8 w-full"></div>
                    </div>
                </template>
            </div>

            <div class="border-t border-stone-200 p-3 dark:border-neutral-700">
                <div class="text-xs font-semibold text-stone-500 dark:text-neutral-400">{{ timeLabel }}</div>
                <select
                    v-model="timeValue"
                    :disabled="disabled || !dateValue"
                    class="mt-2 w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                    <option v-if="!timeValue" value="">--:--</option>
                    <option v-for="option in timeOptions" :key="option" :value="option">{{ option }}</option>
                </select>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        required: true,
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    minuteStep: {
        type: Number,
        default: 15,
    },
    defaultTime: {
        type: String,
        default: '09:00',
    },
});

const emit = defineEmits(['update:modelValue']);

const containerRef = ref(null);
const showPicker = ref(false);
const inputId = `datetime-picker-${Math.random().toString(36).slice(2, 10)}`;
const dateValue = ref('');
const timeValue = ref('');

const locale = (() => {
    if (typeof document !== 'undefined') {
        const lang = document.documentElement?.lang;
        if (lang) {
            return lang;
        }
    }
    if (typeof navigator !== 'undefined' && navigator.language) {
        return navigator.language;
    }
    return 'fr';
})();

const formatDate = (date) => {
    const year = date.getFullYear();
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const day = date.getDate().toString().padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const formatTime = (date) => {
    const hour = date.getHours().toString().padStart(2, '0');
    const minute = date.getMinutes().toString().padStart(2, '0');
    return `${hour}:${minute}`;
};

const parseModelValue = (value) => {
    if (!value) {
        return { date: '', time: '' };
    }
    const stringValue = String(value).trim();
    const dateMatch = stringValue.match(/\d{4}-\d{2}-\d{2}/);
    const timeMatch = stringValue.match(/\d{2}:\d{2}/);
    if (dateMatch) {
        return {
            date: dateMatch[0],
            time: timeMatch ? timeMatch[0] : '',
        };
    }
    const parsed = new Date(stringValue);
    if (Number.isNaN(parsed.getTime())) {
        return { date: '', time: '' };
    }
    return {
        date: formatDate(parsed),
        time: formatTime(parsed),
    };
};

const setFromModel = (value) => {
    const parsed = parseModelValue(value);
    dateValue.value = parsed.date;
    timeValue.value = parsed.time || (parsed.date ? props.defaultTime : '');
};

setFromModel(props.modelValue);

watch(
    () => props.modelValue,
    (value) => {
        setFromModel(value);
    },
);

const minuteStep = computed(() => {
    const step = Number(props.minuteStep);
    return Number.isFinite(step) && step > 0 ? step : 15;
});

const timeOptions = computed(() => {
    const options = [];
    for (let hour = 0; hour < 24; hour += 1) {
        for (let minute = 0; minute < 60; minute += minuteStep.value) {
            options.push(`${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`);
        }
    }
    if (timeValue.value && !options.includes(timeValue.value)) {
        options.unshift(timeValue.value);
    }
    return options;
});

const timeLabel = computed(() => (locale.startsWith('fr') ? 'Heure' : 'Time'));
const showClear = computed(() => !props.required && Boolean(dateValue.value || timeValue.value));

const inputClasses = computed(() => ([
    'peer p-4 block w-full border border-stone-300 rounded-sm text-sm text-stone-700 bg-white shadow-sm',
    'focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500',
    'dark:bg-neutral-800 dark:border-neutral-700 dark:text-white dark:focus:ring-green-500',
    'placeholder-transparent',
    'focus:pt-6 focus:pb-2',
    '[&:not(:placeholder-shown)]:pt-6 [&:not(:placeholder-shown)]:pb-2',
    props.disabled ? 'opacity-60 pointer-events-none' : '',
    showClear.value ? 'pe-10' : '',
]));

const displayValue = computed(() => {
    if (!dateValue.value) {
        return '';
    }
    const time = timeValue.value || props.defaultTime;
    const [year, month, day] = dateValue.value.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);
    const date = new Date(year, (month || 1) - 1, day || 1, hour || 0, minute || 0);
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
});

const currentDate = ref(new Date());
const initialParsed = parseModelValue(props.modelValue);
if (initialParsed.date) {
    const [year, month, day] = initialParsed.date.split('-').map(Number);
    currentDate.value = new Date(year, (month || 1) - 1, day || 1);
}

const monthNames = computed(() => {
    const formatter = new Intl.DateTimeFormat(locale, { month: 'long' });
    return Array.from({ length: 12 }, (_, index) => formatter.format(new Date(2020, index, 1)));
});

const weekdayLabels = computed(() => {
    const formatter = new Intl.DateTimeFormat(locale, { weekday: 'short' });
    const monday = new Date(2023, 0, 2);
    return Array.from({ length: 7 }, (_, index) => formatter.format(new Date(2023, 0, monday.getDate() + index)));
});

const currentMonth = computed(() => currentDate.value.getMonth());
const currentYear = computed(() => currentDate.value.getFullYear());

const getDaysInMonth = (year, month) => new Date(year, month + 1, 0).getDate();

const daysArray = computed(() => {
    const year = currentYear.value;
    const month = currentMonth.value;
    const daysInMonth = getDaysInMonth(year, month);
    const firstDayOfWeek = new Date(year, month, 1).getDay();
    const offset = (firstDayOfWeek + 6) % 7;
    const totalSlots = offset + daysInMonth;
    const rows = Math.ceil(totalSlots / 7);
    const totalCells = rows * 7;
    const days = [];
    for (let i = 0; i < offset; i += 1) {
        days.push(null);
    }
    for (let day = 1; day <= daysInMonth; day += 1) {
        days.push(day);
    }
    while (days.length < totalCells) {
        days.push(null);
    }
    return days;
});

const togglePicker = () => {
    if (props.disabled) {
        return;
    }
    showPicker.value = !showPicker.value;
};

const prevMonth = () => {
    currentDate.value = new Date(currentYear.value, currentMonth.value - 1, 1);
};

const nextMonth = () => {
    currentDate.value = new Date(currentYear.value, currentMonth.value + 1, 1);
};

const selectDay = (day) => {
    if (!day || props.disabled) {
        return;
    }
    const date = new Date(currentYear.value, currentMonth.value, day);
    dateValue.value = formatDate(date);
    if (!timeValue.value) {
        timeValue.value = props.defaultTime;
    }
    currentDate.value = date;
};

const clearDateTime = () => {
    dateValue.value = '';
    timeValue.value = '';
    showPicker.value = false;
    emit('update:modelValue', '');
};

const emitValue = () => {
    if (!dateValue.value) {
        emit('update:modelValue', '');
        return;
    }
    const time = timeValue.value || props.defaultTime;
    const next = `${dateValue.value}T${time}`;
    if (next !== (props.modelValue || '')) {
        emit('update:modelValue', next);
    }
};

watch([dateValue, timeValue], emitValue);

const handleClickOutside = (event) => {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
        showPicker.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>
