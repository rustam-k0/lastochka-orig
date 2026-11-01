<template>
  <LineChartGenerator
    :chart-data="chartData"
    :chart-id="chartId"
    :chart-options="chartOptions"
    :css-classes="cssClasses"
    :dataset-id-key="datasetIdKey"
    :height="height"
    :plugins="plugins"
    :styles="styles"
    :width="width"
  />
</template>

<script>
import { Line as LineChartGenerator } from "vue-chartjs";

import {
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Title,
  Tooltip,
} from "chart.js";

ChartJS.register(Title, Tooltip, Legend, LineElement, LinearScale, CategoryScale, PointElement, Filler);

export default {
  name: "LineChart",
  components: {
    LineChartGenerator,
  },
  props: {
    data: Array,
    chartId: {
      type: String,
      default: "line-chart",
    },
    datasetIdKey: {
      type: String,
      default: "label",
    },
    width: {
      type: Number,
      default: 400,
    },
    height: {
      type: Number,
      default: 400,
    },
    cssClasses: {
      default: "",
      type: String,
    },
    styles: {
      type: Object,
      default: () => {},
    },
    plugins: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {
      chartData: {
        labels: ["01.02", "08.02", "14.02", "21.02", "28.02"],
        datasets: [
          {
            // label: 200,
            data: [0.9, 1.08, 1.03, 1.08, 1.01],
            borderColor: "#00A3FF",
            backgroundColor: [
              "rgba(0, 128, 255, 0.1)", // первый цвет
            ],
            // backgroundColor: function(context) {
            //   const chart = context.chart;
            //   const ctx = chart.ctx;
            //   const gradient = ctx.createLinearGradient(0, 0, 0, chart.height);
            //   gradient.addColorStop(0, 'rgba(0, 128, 255, 0.1)');
            //   gradient.addColorStop(1, 'rgba(0, 128, 255, 0)');
            //   return gradient;
            // }
            fill: "start",
            pointRadius: 0,
          },
        ],
      },
      chartOptions: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          filler: {
            propagate: true,
          },
          legend: false,
        },
        scales: {
          x: {
            ticks: {
              color: "#616161",
            },
            grid: {
              drawBorder: false,
              display: false,
              tickBorderDashOffset: 0.9,
            },
          },
          y: {
            max: 1.2,
            title: {
              display: true,
              color: "#616161",
              font: {
                size: 14,
                style: "normal",
                lineHeight: 2,
                family: "Inter",
              },
              padding: { top: 0, left: 0, right: 0, bottom: 0 },
            },
            ticks: {
              color: "#616161",
              stepSize: 0.05,
            },
          },
        },
      },
    };
  },
};
</script>
