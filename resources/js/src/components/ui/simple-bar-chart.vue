<template>
    <div class="simple-bar-chart">
        <div class="chart-legend">
            <span class="legend-item"><span class="legend-swatch series-a"></span>{{ seriesALabel }}</span>
            <span class="legend-item"><span class="legend-swatch series-b"></span>{{ seriesBLabel }}</span>
        </div>
        <svg :viewBox="`0 0 ${width} ${height}`" class="chart-svg" role="img" :aria-label="ariaLabel">
            <g v-for="(point, index) in points" :key="index">
                <rect
                    :x="index * groupWidth + groupPadding"
                    :y="height - axisHeight - barHeight(point.a)"
                    :width="barWidth"
                    :height="barHeight(point.a)"
                    class="bar series-a"
                />
                <rect
                    :x="index * groupWidth + groupPadding + barWidth + barGap"
                    :y="height - axisHeight - barHeight(point.b)"
                    :width="barWidth"
                    :height="barHeight(point.b)"
                    class="bar series-b"
                />
                <text :x="index * groupWidth + groupWidth / 2" :y="height - 6" class="axis-label" text-anchor="middle">
                    {{ point.label }}
                </text>
            </g>
        </svg>
    </div>
</template>

<script setup>
/**
 * UI-29: dependency-free grouped bar chart — no charting library was
 * already in this project, and pulling one in for two small dashboard
 * charts felt like more new surface area (webpack config, bundle size,
 * an unfamiliar API to maintain) than this actually needs. Plain SVG,
 * driven entirely by server-aggregated points (see
 * DashboardController::index's cases_trend/billing_trend) — this
 * component does no math over raw rows, only lays out numbers it's
 * already been given.
 */
import { computed } from 'vue';

const props = defineProps({
    points: { type: Array, required: true }, // [{ label, a, b }]
    seriesALabel: { type: String, required: true },
    seriesBLabel: { type: String, required: true },
    ariaLabel: { type: String, default: 'Chart' },
});

const width = 480;
const height = 220;
const axisHeight = 24;
const groupPadding = 8;
const barGap = 4;
const barWidth = 16;

const groupWidth = computed(() => width / Math.max(props.points.length, 1));

const maxValue = computed(() =>
    Math.max(1, ...props.points.map((p) => Math.max(p.a, p.b)))
);

const barHeight = (value) => {
    const usable = height - axisHeight - 10;
    return Math.max(1, (value / maxValue.value) * usable);
};
</script>

<style scoped>
.simple-bar-chart {
    width: 100%;
}

.chart-legend {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: var(--text-secondary, #64748b);
    margin-bottom: 8px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.legend-swatch {
    width: 10px;
    height: 10px;
    border-radius: 2px;

    &.series-a {
        background: var(--primary-600);
    }

    &.series-b {
        background: #7c3aed;
    }
}

.chart-svg {
    width: 100%;
    height: 220px;
}

.bar.series-a {
    fill: var(--primary-600);
}

.bar.series-b {
    fill: #7c3aed;
}

.axis-label {
    font-size: 9px;
    fill: var(--text-tertiary, #94a3b8);
}
</style>
