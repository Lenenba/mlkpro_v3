<script setup>

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import ApexCharts from 'apexcharts';


// Props pour personnaliser les données et options du graphique
const props = defineProps({
  series: {
    type: Array,
    required: true,
  },
  categories: {
    type: Array,
    required: true,
  },
  height: {
    type: Number,
    default: 300,
  },
  colors: {
    type: Array,
    default: () => ['#2563eb', '#9333ea'],
  },
});

// Référence pour le conteneur du graphique
const chartRef = ref(null);
// Variable pour stocker l'instance du graphique
let chartInstance = null;

// Options du graphique
const chartOptions = {
  chart: {
    height: 300,
    type: 'area',
    toolbar: {
      show: false,
    },
    zoom: {
      enabled: false,
    },
  },
  series: props.series,
  legend: {
    show: true,
    position: 'top',
    horizontalAlign: 'center',
  },
  dataLabels: {
    enabled: false,
  },
  stroke: {
    curve: 'straight',
    width: 2,
  },
  grid: {
    strokeDashArray: 2,
    borderColor: '#e5e7eb',
  },
  fill: {
    type: 'gradient',
    gradient: {
      type: 'vertical',
      shadeIntensity: 1,
      opacityFrom: 0.1,
      opacityTo: 0.8,
    },
  },
  xaxis: {
    type: 'category',
    categories: props.categories,
    axisBorder: {
      show: false,
    },
    axisTicks: {
      show: false,
    },
    labels: {
      style: {
        colors: '#9ca3af',
        fontSize: '13px',
        fontFamily: 'Inter, ui-sans-serif',
        fontWeight: 400,
      },
      formatter: (title) => {
        if (title) {
          const parts = title.split(' ');
          return `${parts[0]} ${parts[1].slice(0, 3)}`;
        }
        return title;
      },
    },
  },
  yaxis: {
    labels: {
      align: 'left',
      style: {
        colors: '#9ca3af',
        fontSize: '13px',
        fontFamily: 'Inter, ui-sans-serif',
        fontWeight: 400,
      },
      formatter: (value) => (value >= 1000 ? `${value / 1000}k` : value),
    },
  },
  tooltip: {
    x: {
      format: 'MMMM yyyy',
    },
    y: {
      formatter: (value) => `$${value >= 1000 ? `${value / 1000}k` : value}`,
    },
  },
  responsive: [
    {
      breakpoint: 568,
      options: {
        chart: {
          height: 300,
        },
        xaxis: {
          labels: {
            style: {
              fontSize: '11px',
              colors: '#9ca3af',
            },
          },
        },
        yaxis: {
          labels: {
            style: {
              fontSize: '11px',
              colors: '#9ca3af',
            },
          },
        },
      },
    },
  ],
  colors: ['#2563eb', '#9333ea'],
};

// Fonction pour initialiser le graphique
const initializeChart = () => {
  if (chartRef.value) {
    chartInstance = new ApexCharts(chartRef.value, chartOptions);
    chartInstance.render();
  } else {
    console.error('chartRef.value is null. The chart container element might not be rendered yet.');
  }
};

// Monte le composant
onMounted(() => {
  nextTick(() => {
    initializeChart();
  });
});

// Démonte le composant et nettoie l'instance du graphique
onBeforeUnmount(() => {
  if (chartInstance) {
    chartInstance.destroy();
    chartInstance = null;
  }
});
</script>

<template>
  <div ref="chartRef"></div>
</template>
