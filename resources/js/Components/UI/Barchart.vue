<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import ApexCharts from 'apexcharts';

// Référence pour le conteneur du graphique
const chartRef = ref(null);
let chartInstance = null;

// Options par défaut du graphique
const chartOptions = {
    chart: {
        type: 'bar',
        height: 250,
        toolbar: {
            show: false,
        },
        zoom: {
            enabled: false,
        },
    },
    series: [
        {
            name: 'In-store',
            data: [200, 300, 290, 350, 150, 350, 300, 100, 125, 220, 200, 300],
        },
        {
            name: 'Online',
            data: [150, 230, 382, 204, 169, 290, 300, 100, 300, 225, 120, 150],
        },
    ],
    colors: ['#8b5cf6', '#d4d4d4'],
    plotOptions: {
        bar: {
            horizontal: false,
            columnWidth: '14px',
            borderRadius: 0,
        },
    },
    legend: {
        show: false,
    },
    dataLabels: {
        enabled: false,
    },
    stroke: {
        show: true,
        width: 2,
        colors: ['transparent'],
    },
    xaxis: {
        categories: [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December',
        ],
        axisBorder: {
            show: false,
        },
        axisTicks: {
            show: false,
        },
        labels: {
            style: {
                colors: '#a8a29e',
                fontSize: '13px',
                fontFamily: 'Inter, ui-sans-serif',
                fontWeight: 400,
            },
            formatter: (title) => title.slice(0, 3),
        },
    },
    yaxis: {
        labels: {
            align: 'left',
            style: {
                colors: '#a8a29e',
                fontSize: '13px',
                fontFamily: 'Inter, ui-sans-serif',
                fontWeight: 400,
            },
            formatter: (value) => (value >= 1000 ? `${value / 1000}k` : value),
        },
    },
    tooltip: {
        y: {
            formatter: (value) => `${value}`,
        },
    },
    grid: {
        borderColor: '#e5e5e5',
    },
};

// Configurations supplémentaires
const lightModeConfig = {
    colors: ['#16a34a', '#d4d4d4'],
    grid: {
        borderColor: '#e5e5e5',
    },
    xaxis: {
        labels: {
            style: {
                colors: '#a8a29e',
            },
        },
    },
    yaxis: {
        labels: {
            style: {
                colors: '#a8a29e',
            },
        },
    },
};

const darkModeConfig = {
    colors: ['#22c55e', '#737373'],
    grid: {
        borderColor: '#404040',
    },
    xaxis: {
        labels: {
            style: {
                colors: '#a3a3a3',
            },
        },
    },
    yaxis: {
        labels: {
            style: {
                colors: '#a3a3a3',
            },
        },
    },
};

// État pour suivre le mode actuel
const isDarkMode = ref(false);

// Fonction pour initialiser le graphique
const initializeChart = () => {
    if (chartRef.value) {
        const mergedOptions = Object.assign(
            {},
            chartOptions,
            isDarkMode.value ? darkModeConfig : lightModeConfig
        );

        chartInstance = new ApexCharts(chartRef.value, mergedOptions);
        chartInstance.render();
    }
};

// Fonction pour basculer entre les styles
const toggleTheme = () => {
    isDarkMode.value = !isDarkMode.value;

    if (chartInstance) {
        const mergedOptions = Object.assign(
            {},
            chartOptions,
            isDarkMode.value ? darkModeConfig : lightModeConfig
        );

        chartInstance.updateOptions(mergedOptions);
    }
};

// Gestion du montage et du démontage
onMounted(() => {
    initializeChart();
});

onBeforeUnmount(() => {
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }
});
</script>


<template>
    <div
        class="xl:col-span-4 flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <!-- Header -->
        <div
            class="py-3 px-5 flex flex-wrap justify-between items-center gap-2 border-b border-stone-200 dark:border-neutral-700">
            <h2 class="inline-block font-semibold text-stone-800 dark:text-neutral-200">
                Orders
            </h2>

            <div class="flex justify-end items-center gap-x-2">
                <!-- Calendar Dropdown -->
                <div class="hs-dropdown [--auto-close:inside] [--placement:top-right] inline-flex">
                    <button id="hs-pro-dnic" type="button"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                        25 Jul - 25 Aug
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-[318px] sm:w-[636px] transition-[opacity,margin] duration opacity-0 hidden z-50 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                        role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-dnic">
                        <!-- Calendar -->
                        <div class="sm:flex">
                            <!-- Calendar -->
                            <div class="p-3 space-y-0.5">
                                <!-- Months -->
                                <div class="grid grid-cols-5 items-center gap-x-3 mx-1.5 pb-3">
                                    <!-- Prev Button -->
                                    <div class="col-span-1">
                                        <button type="button"
                                            class="size-8 flex justify-center items-center text-stone-800 hover:bg-stone-100 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            aria-label="Previous">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m15 18-6-6 6-6" />
                                            </svg>
                                        </button>
                                    </div>
                                    <!-- End Prev Button -->

                                    <!-- Month / Year -->
                                    <div class="col-span-3 flex justify-center items-center gap-x-1">
                                        <div class="relative">
                                            <select data-hs-select='{
                        "placeholder": "Select month",
                        "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                        "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative flex text-nowrap w-full cursor-pointer text-start font-medium text-stone-800 hover:text-stone-600 focus:outline-none focus:text-stone-600 before:absolute before:inset-0 before:z-[1] dark:text-neutral-200 dark:hover:text-neutral-300 dark:focus:text-neutral-300",
                        "dropdownClasses": "mt-2 z-50 w-32 max-h-72 p-1 space-y-0.5 bg-white border border-stone-200 rounded-lg shadow-lg overflow-hidden overflow-y-auto [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500 dark:bg-neutral-900 dark:border-neutral-700",
                        "optionClasses": "p-2 w-full text-sm text-stone-800 cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:hover:bg-neutral-800 dark:text-neutral-200 dark:focus:bg-neutral-800",
                        "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>"
                      }' class="hidden">
                                                <option value="0">January</option>
                                                <option value="1">February</option>
                                                <option value="2">March</option>
                                                <option value="3">April</option>
                                                <option value="4">May</option>
                                                <option value="5">June</option>
                                                <option value="6" selected>July</option>
                                                <option value="7">August</option>
                                                <option value="8">September</option>
                                                <option value="9">October</option>
                                                <option value="10">November</option>
                                                <option value="11">December</option>
                                            </select>
                                        </div>

                                        <span class="text-stone-800 dark:text-neutral-200">/</span>

                                        <div class="relative">
                                            <select data-hs-select='{
                        "placeholder": "Select year",
                        "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                        "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative flex text-nowrap w-full cursor-pointer text-start font-medium text-stone-800 hover:text-stone-600 focus:outline-none focus:text-stone-600 before:absolute before:inset-0 before:z-[1] dark:text-neutral-200 dark:hover:text-neutral-300 dark:focus:text-neutral-300",
                        "dropdownClasses": "mt-2 z-50 w-20 max-h-72 p-1 space-y-0.5 bg-white border border-stone-200 rounded-lg shadow-lg overflow-hidden overflow-y-auto [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500 dark:bg-neutral-900 dark:border-neutral-700",
                        "optionClasses": "p-2 w-full text-sm text-stone-800 cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:hover:bg-neutral-800 dark:text-neutral-200 dark:focus:bg-neutral-800",
                        "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>"
                      }' class="hidden">
                                                <option selected>2023</option>
                                                <option>2024</option>
                                                <option>2025</option>
                                                <option>2026</option>
                                                <option>2027</option>
                                            </select>
                                        </div>
                                    </div>
                                    <!-- End Month / Year -->

                                    <!-- Next Button -->
                                    <div class="col-span-1 flex justify-end">
                                        <button type="button"
                                            class="opacity-0 pointer-events-none size-8 flex justify-center items-center text-stone-800 hover:bg-stone-100 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            aria-label="Next">
                                            <svg class="shrink-0 size-4" width="24" height="24" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m9 18 6-6-6-6" />
                                            </svg>
                                        </button>
                                    </div>
                                    <!-- End Next Button -->
                                </div>
                                <!-- Months -->

                                <!-- Weeks -->
                                <div class="flex pb-1.5">
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Mo
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Tu
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        We
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Th
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Fr
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Sa
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Su
                                    </span>
                                </div>
                                <!-- Weeks -->

                                <!-- Days -->
                                <div class="flex">
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200"
                                            disabled>
                                            26
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200"
                                            disabled>
                                            27
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200"
                                            disabled>
                                            28
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200"
                                            disabled>
                                            29
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200"
                                            disabled>
                                            30
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            1
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            2
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            3
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            4
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            5
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            6
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            7
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            8
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            9
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            10
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            11
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            12
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            13
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            14
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            15
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            16
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            17
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            18
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            19
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            20
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            21
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            22
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            23
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            24
                                        </button>
                                    </div>
                                    <div class="bg-stone-100 rounded-s-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center bg-green-600 border border-transparent text-sm font-medium text-white hover:border-green-600 rounded-full dark:bg-green-500 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:hover:border-neutral-700">
                                            25
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            26
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            27
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            28
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            29
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            30
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            31
                                        </button>
                                    </div>
                                    <div class="bg-gradient-to-r from-stone-100 dark:from-stone-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            1
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            2
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            3
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            4
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            5
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            6
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->
                            </div>
                            <!-- End Calendar -->

                            <!-- Calendar -->
                            <div class="p-3 space-y-0.5">
                                <!-- Months -->
                                <div class="grid grid-cols-5 items-center gap-x-3 mx-1.5 pb-3">
                                    <!-- Prev Button -->
                                    <div class="col-span-1">
                                        <button type="button"
                                            class="opacity-0 pointer-events-none size-8 flex justify-center items-center text-stone-800 hover:bg-stone-100 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            aria-label="Previous">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m15 18-6-6 6-6" />
                                            </svg>
                                        </button>
                                    </div>
                                    <!-- End Prev Button -->

                                    <!-- Month / Year -->
                                    <div class="col-span-3 flex justify-center items-center gap-x-1">
                                        <div class="relative">
                                            <select data-hs-select='{
                        "placeholder": "Select month",
                        "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                        "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative flex text-nowrap w-full cursor-pointer text-start font-medium text-stone-800 hover:text-stone-600 focus:outline-none focus:text-stone-600 before:absolute before:inset-0 before:z-[1] dark:text-neutral-200 dark:hover:text-neutral-300 dark:focus:text-neutral-300",
                        "dropdownClasses": "mt-2 z-50 w-32 max-h-72 p-1 space-y-0.5 bg-white border border-stone-200 rounded-lg shadow-lg overflow-hidden overflow-y-auto [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500 dark:bg-neutral-900 dark:border-neutral-700",
                        "optionClasses": "p-2 w-full text-sm text-stone-800 cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:hover:bg-neutral-800 dark:text-neutral-200 dark:focus:bg-neutral-800",
                        "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>"
                      }' class="hidden">
                                                <option value="0">January</option>
                                                <option value="1">February</option>
                                                <option value="2">March</option>
                                                <option value="3">April</option>
                                                <option value="4">May</option>
                                                <option value="5">June</option>
                                                <option value="6" selected>July</option>
                                                <option value="7">August</option>
                                                <option value="8">September</option>
                                                <option value="9">October</option>
                                                <option value="10">November</option>
                                                <option value="11">December</option>
                                            </select>
                                        </div>

                                        <span class="text-stone-800 dark:text-neutral-200">/</span>

                                        <div class="relative">
                                            <select data-hs-select='{
                        "placeholder": "Select year",
                        "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                        "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative flex text-nowrap w-full cursor-pointer text-start font-medium text-stone-800 hover:text-stone-600 focus:outline-none focus:text-stone-600 before:absolute before:inset-0 before:z-[1] dark:text-neutral-200 dark:hover:text-neutral-300 dark:focus:text-neutral-300",
                        "dropdownClasses": "mt-2 z-50 w-20 max-h-72 p-1 space-y-0.5 bg-white border border-stone-200 rounded-lg shadow-lg overflow-hidden overflow-y-auto [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500 dark:bg-neutral-900 dark:border-neutral-700",
                        "optionClasses": "p-2 w-full text-sm text-stone-800 cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:hover:bg-neutral-800 dark:text-neutral-200 dark:focus:bg-neutral-800",
                        "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>"
                      }' class="hidden">
                                                <option selected>2023</option>
                                                <option>2024</option>
                                                <option>2025</option>
                                                <option>2026</option>
                                                <option>2027</option>
                                            </select>
                                        </div>
                                    </div>
                                    <!-- End Month / Year -->

                                    <!-- Next Button -->
                                    <div class="col-span-1 flex justify-end">
                                        <button type="button"
                                            class="size-8 flex justify-center items-center text-stone-800 hover:bg-stone-100 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            aria-label="Next">
                                            <svg class="shrink-0 size-4" width="24" height="24" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m9 18 6-6-6-6" />
                                            </svg>
                                        </button>
                                    </div>
                                    <!-- End Next Button -->
                                </div>
                                <!-- Months -->

                                <!-- Weeks -->
                                <div class="flex pb-1.5">
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Mo
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Tu
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        We
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Th
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Fr
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Sa
                                    </span>
                                    <span
                                        class="m-px w-10 block text-center text-sm text-stone-500 dark:text-neutral-500">
                                        Su
                                    </span>
                                </div>
                                <!-- Weeks -->

                                <!-- Days -->
                                <div class="flex">
                                    <div class="bg-gradient-to-l from-stone-100 dark:from-stone-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            31
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700">
                                            1
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700">
                                            2
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700">
                                            3
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700">
                                            4
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            5
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            6
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            7
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            8
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            9
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            10
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            11
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            12
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            13
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            14
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            15
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            16
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            17
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            18
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            19
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            20
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            21
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            22
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            23
                                        </button>
                                    </div>
                                    <div
                                        class="bg-stone-100 first:rounded-s-full last:rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            24
                                        </button>
                                    </div>
                                    <div class="bg-stone-100 rounded-e-full dark:bg-neutral-800">
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center bg-green-600 border border-transparent text-sm font-medium text-white hover:border-green-600 rounded-full dark:bg-green-500 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:hover:border-neutral-700">
                                            25
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            26
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            27
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            28
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            29
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            30
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 rounded-full hover:border-green-600 hover:text-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:border-green-600 focus:text-green-600 dark:text-neutral-200">
                                            31
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            1
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            2
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            3
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Days -->
                                <div class="flex">
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            4
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            5
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            6
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            7
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            8
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            9
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                            class="m-px size-10 flex justify-center items-center border border-transparent text-sm text-stone-800 hover:border-green-600 hover:text-green-600 rounded-full disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:border-neutral-500 dark:focus:bg-neutral-700"
                                            disabled>
                                            10
                                        </button>
                                    </div>
                                </div>
                                <!-- Days -->

                                <!-- Button Group -->
                                <div class="pt-4 flex justify-end gap-x-2">
                                    <button type="button"
                                        class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        data-hs-overlay="#hs-pro-edmad">
                                        Cancel
                                    </button>
                                    <button type="button"
                                        class="py-2 px-3  inline-flex justify-center items-center gap-x-2 text-xs font-medium rounded-lg border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500"
                                        data-hs-overlay="#hs-pro-edmad">
                                        Apply
                                    </button>
                                </div>
                                <!-- End Button Group -->
                            </div>
                            <!-- End Calendar -->
                        </div>
                        <!-- End Calendar -->
                    </div>
                </div>
                <!-- End Calendar Dropdown -->

                <!-- Add Activity Dropdown -->
                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                    <!-- Button -->
                    <button id="hs-pro-daaad" type="button"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                        <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14" />
                            <path d="M12 5v14" />
                        </svg>
                        Add activity
                    </button>
                    <!-- End Button -->

                    <!-- Add Activity Dropdown -->
                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                        role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-daaad">
                        <div class="p-1">
                            <div
                                class="flex justify-between items-center py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <label for="hs-pro-dachdds1"
                                    class="flex justify-between items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="22 7 13.5 15.5 8.5 10.5 2 17" />
                                        <polyline points="16 7 22 7 22 13" />
                                    </svg>
                                    Revenue
                                </label>
                                <input type="checkbox"
                                    class="shrink-0 size-3.5 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dachdds1" checked>
                            </div>

                            <div
                                class="flex justify-between items-center py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <label for="hs-pro-dachdds2"
                                    class="flex justify-between items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14.5 22H18a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v4"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <path
                                            d="M2.97 13.12c-.6.36-.97 1.02-.97 1.74v3.28c0 .72.37 1.38.97 1.74l3 1.83c.63.39 1.43.39 2.06 0l3-1.83c.6-.36.97-1.02.97-1.74v-3.28c0-.72-.37-1.38-.97-1.74l-3-1.83a1.97 1.97 0 0 0-2.06 0l-3 1.83Z">
                                        </path>
                                        <path d="m7 17-4.74-2.85"></path>
                                        <path d="m7 17 4.74-2.85"></path>
                                        <path d="M7 17v5"></path>
                                    </svg>
                                    Orders
                                </label>
                                <input type="checkbox"
                                    class="shrink-0 size-3.5 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dachdds2" checked>
                            </div>

                            <div
                                class="flex justify-between items-center py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <label for="hs-pro-dachdds3"
                                    class="flex justify-between items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="9 10 4 15 9 20" />
                                        <path d="M20 4v7a4 4 0 0 1-4 4H4" />
                                    </svg>
                                    Refunds
                                </label>
                                <input type="checkbox"
                                    class="shrink-0 size-3.5 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dachdds3">
                            </div>
                        </div>
                    </div>
                    <!-- End Add Activity Dropdown -->
                </div>
                <!-- End Add Activity Dropdown -->
            </div>
        </div>
        <!-- End Header -->

        <!-- Body -->
        <div class="grid md:grid-cols-8 divide-x divide-stone-200 dark:divide-neutral-600">
            <div class="md:col-span-5 lg:col-span-6 p-5">
                <!-- Apex Line Chart -->
                <div id="hs-orders-bar-chart" class="min-h-[265px] -mx-2" ref="chartRef"></div>

                <!-- Legen Indicator -->
                <div class="flex justify-center items-center gap-x-4">
                    <div class="inline-flex items-center">
                        <span class="size-2.5 inline-block bg-green-500 rounded-sm me-2 dark:bg-green-500"></span>
                        <span class="text-[13px] text-stone-600 dark:text-neutral-400">
                            In-store
                        </span>
                    </div>
                    <div class="inline-flex items-center">
                        <span class="size-2.5 inline-block bg-stone-300 rounded-sm me-2 dark:bg-neutral-700"></span>
                        <span class="text-[13px] text-stone-600 dark:text-neutral-400">
                            Online
                        </span>
                    </div>
                </div>
                <!-- End Legen Indicator -->
            </div>
            <!-- End Col -->

            <div class="md:col-span-3 lg:col-span-2">
                <div class="p-2">
                    <!-- Card -->
                    <div class="p-2 bg-white dark:bg-neutral-800 dark:border-neutral-800">
                        <!-- Nav Tab -->
                        <nav class="relative flex gap-x-1 after:absolute after:bottom-0 after:inset-x-0 after:border-b after:border-stone-200 dark:after:border-neutral-700"
                            aria-label="Tabs" role="tablist" aria-orientation="horizontal">
                            <button type="button"
                                class="hs-tab-active:after:bg-stone-800 hs-tab-active:text-stone-800 px-2.5 py-1.5 mb-2 relative inline-flex items-center gap-x-2 hover:bg-stone-100 text-stone-500 hover:text-stone-800 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 after:absolute after:-bottom-2 after:inset-x-0 after:z-10 after:h-0.5 after:pointer-events-none dark:hs-tab-active:text-neutral-200 dark:hs-tab-active:after:bg-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700 active"
                                id="hs-pro-tabs-dtsch-item-revenue" aria-selected="true"
                                data-hs-tab="#hs-pro-tabs-dtsch-revenue" aria-controls="hs-pro-tabs-dtsch-revenue"
                                role="tab">
                                Orders
                            </button>
                            <button type="button"
                                class="hs-tab-active:after:bg-stone-800 hs-tab-active:text-stone-800 px-2.5 py-1.5 mb-2 relative inline-flex items-center gap-x-2 hover:bg-stone-100 text-stone-500 hover:text-stone-800 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 after:absolute after:-bottom-2 after:inset-x-0 after:z-10 after:h-0.5 after:pointer-events-none dark:hs-tab-active:text-neutral-200 dark:hs-tab-active:after:bg-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                id="hs-pro-tabs-dtsch-item-orders" aria-selected="false"
                                data-hs-tab="#hs-pro-tabs-dtsch-orders" aria-controls="hs-pro-tabs-dtsch-orders"
                                role="tab">
                                Sales
                            </button>
                        </nav>
                        <!-- End Nav Tab -->

                        <div>
                            <!-- Tab Content -->
                            <div id="hs-pro-tabs-dtsch-revenue" role="tabpanel"
                                aria-labelledby="hs-pro-tabs-dtsch-item-revenue">
                                <div class="py-4">
                                    <h4 class="font-semibold text-xl md:text-2xl text-stone-800 dark:text-white">
                                        125,090
                                    </h4>

                                    <!-- Progress -->
                                    <div class="relative mt-3">
                                        <div class="flex w-full h-2 bg-stone-200 rounded-sm overflow-hidden dark:bg-neutral-700"
                                            role="progressbar" aria-valuenow="72" aria-valuemin="0" aria-valuemax="100">
                                            <div class="flex flex-col justify-center rounded-sm overflow-hidden bg-green-600 text-xs text-white text-center whitespace-nowrap transition duration-500"
                                                style="width: 72%"></div>
                                        </div>
                                        <div
                                            class="absolute top-1/2 start-[71%] w-2 h-5 bg-green-600 border-2 border-white rounded-sm transform -translate-y-1/2 dark:border-neutral-800">
                                        </div>
                                    </div>
                                    <!-- End Progress -->

                                    <!-- Progress Status -->
                                    <div class="mt-3 flex justify-between items-center">
                                        <span class="text-xs text-stone-800 dark:text-white">
                                            0.00
                                        </span>
                                        <span class="text-xs text-stone-800 dark:text-white">
                                            200,000
                                        </span>
                                    </div>
                                    <!-- End Progress Status -->

                                    <p class="mt-4 text-sm text-stone-600 dark:text-neutral-400">
                                        A project-wise breakdown of total orders complemented by detailed insights.
                                    </p>
                                </div>
                            </div>
                            <!-- End Tab Content -->

                            <!-- Tab Content -->
                            <div id="hs-pro-tabs-dtsch-orders" class="hidden" role="tabpanel"
                                aria-labelledby="hs-pro-tabs-dtsch-item-orders">
                                <div class="py-4">
                                    <h4 class="font-semibold text-xl md:text-2xl text-stone-800 dark:text-white">
                                        $993,758.20
                                    </h4>

                                    <!-- Progress -->
                                    <div class="relative mt-3">
                                        <div class="flex w-full h-2 bg-stone-200 rounded-sm overflow-hidden dark:bg-neutral-700"
                                            role="progressbar" aria-valuenow="47" aria-valuemin="0" aria-valuemax="100">
                                            <div class="flex flex-col justify-center rounded-sm overflow-hidden bg-green-600 text-xs text-white text-center whitespace-nowrap transition duration-500"
                                                style="width: 47%"></div>
                                        </div>
                                        <div
                                            class="absolute top-1/2 start-[46%] w-2 h-5 bg-green-600 border-2 border-white rounded-sm transform -translate-y-1/2 dark:border-neutral-800">
                                        </div>
                                    </div>
                                    <!-- End Progress -->

                                    <!-- Progress Status -->
                                    <div class="mt-3 flex justify-between items-center">
                                        <span class="text-xs text-stone-800 dark:text-white">
                                            0.00
                                        </span>
                                        <span class="text-xs text-stone-800 dark:text-white">
                                            $2mln
                                        </span>
                                    </div>
                                    <!-- End Progress Status -->

                                    <p class="mt-4 text-sm text-stone-600 dark:text-neutral-400">
                                        A project-wise breakdown of total orders complemented by detailed insights.
                                    </p>
                                </div>
                            </div>
                            <!-- End Tab Content -->
                        </div>
                    </div>
                    <!-- End Card -->

                    <div>
                        <!-- Link -->
                        <a class="p-2 flex items-center gap-x-2 text-sm font-medium text-stone-800 rounded-lg hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700 dark:hover:text-green-500 dark:focus:bg-neutral-700"
                            href="#">
                            <span
                                class="flex shrink-0 justify-center items-center size-7 bg-white border border-stone-200 rounded-lg dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300">
                                <svg class="shrink-0 size-3.5 text-green-600 dark:text-green-500"
                                    xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                    viewBox="0 0 16 16">
                                    <path
                                        d="M7.657 6.247c.11-.33.576-.33.686 0l.645 1.937a2.89 2.89 0 0 0 1.829 1.828l1.936.645c.33.11.33.576 0 .686l-1.937.645a2.89 2.89 0 0 0-1.828 1.829l-.645 1.936a.361.361 0 0 1-.686 0l-.645-1.937a2.89 2.89 0 0 0-1.828-1.828l-1.937-.645a.361.361 0 0 1 0-.686l1.937-.645a2.89 2.89 0 0 0 1.828-1.828l.645-1.937zM3.794 1.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387A1.734 1.734 0 0 0 4.593 5.69l-.387 1.162a.217.217 0 0 1-.412 0L3.407 5.69A1.734 1.734 0 0 0 2.31 4.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387A1.734 1.734 0 0 0 3.407 2.31l.387-1.162zM10.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.156 1.156 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.156 1.156 0 0 0-.732-.732L9.1 2.137a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732L10.863.1z" />
                                </svg>
                            </span>
                            <div class="grow">
                                <p>
                                    Show all highlights
                                </p>
                            </div>
                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6"></path>
                            </svg>
                        </a>
                        <!-- End Link -->

                        <!-- Link -->
                        <a class="p-2 flex items-center gap-x-2 text-sm font-medium text-stone-800 rounded-lg hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700 dark:hover:text-green-500 dark:focus:bg-neutral-700"
                            href="#">
                            <span
                                class="flex shrink-0 justify-center items-center size-7 bg-white border border-stone-200 rounded-lg dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300">
                                <svg class="shrink-0 size-3.5 text-green-600 dark:text-green-500"
                                    xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                    viewBox="0 0 16 16">
                                    <path
                                        d="M1 11a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1v-3zm5-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7zm5-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1V2z" />
                                </svg>
                            </span>
                            <div class="grow">
                                <p>
                                    Show all sales data
                                </p>
                            </div>
                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6"></path>
                            </svg>
                        </a>
                        <!-- End Link -->
                    </div>
                </div>
            </div>
            <!-- End Col -->
        </div>
        <!-- End Body -->
    </div>
</template>
<link rel="stylesheet" href="../../assets/vendor/apexcharts/dist/apexcharts.css">
</link>
<style>
/* Tooltip styling */
.apexcharts-tooltip.apexcharts-theme-light {
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
}
</style>
