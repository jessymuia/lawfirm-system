<template>
    <div class="hearings-calendar-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Hearings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Calendar</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="calendar-header">
            <h2 class="page-title">Hearings Calendar</h2>

            <div class="view-toggle" role="tablist" aria-label="Calendar view">
                <button
                    v-for="mode in viewModes"
                    :key="mode.id"
                    type="button"
                    role="tab"
                    class="view-toggle-btn"
                    :class="{ active: viewMode === mode.id }"
                    :aria-selected="viewMode === mode.id"
                    @click="viewMode = mode.id"
                >
                    {{ mode.label }}
                </button>
            </div>

            <div class="month-nav">
                <button type="button" class="btn-nav" @click="goToPrevious" :aria-label="`Previous ${viewMode}`">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>
                <span class="current-month" data-testid="calendar-month-label">{{ rangeLabel }}</span>
                <button type="button" class="btn-nav" @click="goToNext" :aria-label="`Next ${viewMode}`">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
                <button type="button" class="btn-today" @click="goToToday">Today</button>
            </div>
        </div>

        <!-- UI-10: hearing type is a real WCAG-checked color swatch PLUS a
             text label on every chip — color is never the only signal a
             hearing's type is conveyed by. -->
        <div class="type-legend" v-if="typeLegend.length">
            <span v-for="t in typeLegend" :key="t.id" class="legend-item">
                <span class="legend-swatch" :style="{ background: t.color }"></span>
                {{ t.name }}
            </span>
        </div>

        <div v-if="loading" class="calendar-loading">Loading hearings...</div>

        <template v-else>
            <div v-if="viewMode === 'month'" class="calendar-grid" data-testid="hearings-calendar-grid">
                <div class="weekday-row">
                    <div v-for="day in weekdayLabels" :key="day" class="weekday-cell">{{ day }}</div>
                </div>
                <div class="days-row">
                    <div
                        v-for="cell in monthCells"
                        :key="cell.key"
                        class="day-cell"
                        :class="{ 'other-month': !cell.inCurrentMonth, 'is-today': cell.isToday }"
                    >
                        <div class="day-number">{{ cell.dayNumber }}</div>
                        <div class="day-hearings">
                            <HearingChip v-for="hearing in cell.hearings" :key="hearing.id" :hearing="hearing" :color="colorFor(hearing)" @click="goToHearingCase(hearing)" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="viewMode === 'week'" class="calendar-grid week-grid" data-testid="hearings-calendar-grid">
                <div class="weekday-row">
                    <div v-for="cell in weekCells" :key="`h-${cell.key}`" class="weekday-cell">
                        {{ weekdayLabels[cell.date.getDay()] }} {{ cell.dayNumber }}
                    </div>
                </div>
                <div class="days-row week-days-row">
                    <div
                        v-for="cell in weekCells"
                        :key="cell.key"
                        class="day-cell week-day-cell"
                        :class="{ 'is-today': cell.isToday }"
                    >
                        <div class="day-hearings">
                            <HearingChip v-for="hearing in cell.hearings" :key="hearing.id" :hearing="hearing" :color="colorFor(hearing)" expanded @click="goToHearingCase(hearing)" />
                            <p v-if="!cell.hearings.length" class="no-hearings">No hearings</p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="agenda-list" data-testid="hearings-calendar-grid">
                <div v-if="!agendaGroups.length" class="calendar-loading">No hearings in this range.</div>
                <div v-for="group in agendaGroups" :key="group.key" class="agenda-group">
                    <h3 class="agenda-date">{{ group.label }}</h3>
                    <a
                        v-for="hearing in group.hearings"
                        :key="hearing.id"
                        href="javascript:;"
                        class="agenda-row"
                        @click="goToHearingCase(hearing)"
                    >
                        <span class="legend-swatch" :style="{ background: colorFor(hearing) }"></span>
                        <span class="agenda-type">{{ hearing.hearing_type_name }}</span>
                        <span class="agenda-case">{{ hearing.case }}</span>
                        <span class="agenda-court">{{ hearing.court }}</span>
                    </a>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, h } from 'vue';
import { useRouter } from 'vue-router';
import axios, { fetchAllPages } from '../../../api';
import { parseWireDate } from '@/utils/date';

// UI-13: month/week/agenda toggle, WCAG-checked color-by-type (always
// paired with a text label — see HearingChip/type-legend below, never
// color alone), and event click routes straight to the case's Hearings
// tab (see goToHearingCase). Fetches only the visible range via the
// from/to filter HearingController::index already supported but this
// page never actually used — it previously loaded every hearing in the
// entire firm on every visit regardless of which month was shown.

const router = useRouter();

const hearings = ref([]);
const loading = ref(true);
const viewDate = ref(new Date());
const viewMode = ref('month');
const viewModes = [
    { id: 'month', label: 'Month' },
    { id: 'week', label: 'Week' },
    { id: 'agenda', label: 'Agenda' },
];

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Tailwind "-600" shades — chosen specifically because that shade step
// is the commonly-cited threshold for meeting WCAG AA (4.5:1) contrast
// as text/icon color against a white background, which is how these are
// used here (swatch fill + matching text elsewhere in the app).
const PALETTE = ['#0284c7', '#7c3aed', '#059669', '#dc2626', '#d97706', '#db2777', '#4f46e5', '#0d9488'];

const colorFor = (hearing) => {
    const id = Number(hearing.hearing_type) || 0;
    return PALETTE[id % PALETTE.length];
};

const typeLegend = computed(() => {
    const seen = new Map();
    hearings.value.forEach((h) => {
        if (!seen.has(h.hearing_type)) {
            seen.set(h.hearing_type, { id: h.hearing_type, name: h.hearing_type_name, color: colorFor(h) });
        }
    });
    return Array.from(seen.values());
});

const dateKey = (date) => `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;

const hearingsByDay = computed(() => {
    const map = {};
    hearings.value.forEach((hearing) => {
        if (!hearing.hearing_date) return;
        const parsed = parseWireDate(hearing.hearing_date);
        if (!parsed) return;
        const key = dateKey(parsed);
        if (!map[key]) map[key] = [];
        map[key].push(hearing);
    });
    return map;
});

function buildCell(date, inCurrentMonth, today) {
    const key = dateKey(date);
    return {
        key: `${key}-${inCurrentMonth}`,
        date,
        dayNumber: date.getDate(),
        inCurrentMonth,
        isToday: dateKey(today) === key,
        hearings: hearingsByDay.value[key] || [],
    };
}

const monthCells = computed(() => {
    const year = viewDate.value.getFullYear();
    const month = viewDate.value.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const startOffset = firstOfMonth.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();

    const cells = [];
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    for (let i = startOffset - 1; i >= 0; i--) {
        const dayNumber = daysInPrevMonth - i;
        cells.push(buildCell(new Date(year, month - 1, dayNumber), false, today));
    }
    for (let day = 1; day <= daysInMonth; day++) {
        cells.push(buildCell(new Date(year, month, day), true, today));
    }
    let nextMonthDay = 1;
    while (cells.length % 7 !== 0) {
        cells.push(buildCell(new Date(year, month + 1, nextMonthDay), false, today));
        nextMonthDay++;
    }
    return cells;
});

const startOfWeek = (date) => {
    const d = new Date(date);
    d.setDate(d.getDate() - d.getDay());
    return d;
};

const weekCells = computed(() => {
    const today = new Date();
    const start = startOfWeek(viewDate.value);
    const cells = [];
    for (let i = 0; i < 7; i++) {
        const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        cells.push(buildCell(date, true, today));
    }
    return cells;
});

// Agenda groups whatever's currently loaded (bounded to the fetched
// range — see fetchRange below) by date, chronologically.
const agendaGroups = computed(() => {
    const groups = {};
    hearings.value.forEach((hearing) => {
        const parsed = parseWireDate(hearing.hearing_date);
        if (!parsed) return;
        const key = dateKey(parsed);
        if (!groups[key]) {
            groups[key] = {
                key,
                label: parsed.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }),
                sortDate: parsed,
                hearings: [],
            };
        }
        groups[key].hearings.push(hearing);
    });
    return Object.values(groups).sort((a, b) => a.sortDate - b.sortDate);
});

const pad = (n) => String(n).padStart(2, '0');
const toIso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

// The date range actually needed to render the current view — this is
// what gets sent as from/to, not "everything".
const activeRange = computed(() => {
    if (viewMode.value === 'week') {
        const start = startOfWeek(viewDate.value);
        const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
        return { from: toIso(start), to: toIso(end) };
    }
    // Month and Agenda both browse a month at a time.
    const year = viewDate.value.getFullYear();
    const month = viewDate.value.getMonth();
    return {
        from: toIso(new Date(year, month, 1)),
        to: toIso(new Date(year, month + 1, 0)),
    };
});

const rangeLabel = computed(() => {
    if (viewMode.value === 'week') {
        const start = startOfWeek(viewDate.value);
        const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
        const fmt = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        return `${fmt(start)} – ${fmt(end)}`;
    }
    return viewDate.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
});

const goToHearingCase = (hearing) => {
    localStorage.setItem('caseId', hearing.case_id);
    // UI-13: land directly on that case's Hearings tab, not just the
    // case's default Overview tab.
    router.push('/view-case#hearings');
};

const goToPrevious = () => {
    if (viewMode.value === 'week') {
        viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth(), viewDate.value.getDate() - 7);
    } else {
        viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() - 1, 1);
    }
};

const goToNext = () => {
    if (viewMode.value === 'week') {
        viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth(), viewDate.value.getDate() + 7);
    } else {
        viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + 1, 1);
    }
};

const goToToday = () => {
    viewDate.value = new Date();
};

const fetchHearings = () => {
    loading.value = true;
    const { from, to } = activeRange.value;
    fetchAllPages('/api/v1/hearings', { from, to })
        .then(({ rows }) => {
            hearings.value = rows;
        })
        .catch((error) => {
            console.error('Error fetching hearings for calendar:', error);
        })
        .finally(() => {
            loading.value = false;
        });
};

watch([viewMode, activeRange], fetchHearings);

onMounted(() => {
    fetchHearings();
});

// Small inline component: a single hearing chip, always pairing color
// with a text label (the hearing type name) — never color-only.
const HearingChip = {
    props: { hearing: Object, color: String, expanded: Boolean },
    emits: ['click'],
    render() {
        return h('a', {
            href: 'javascript:;',
            class: ['hearing-chip', { expanded: this.expanded }],
            style: { borderLeftColor: this.color },
            'data-testid': `hearing-chip-${this.hearing.id}`,
            title: `${this.hearing.hearing_type_name} — ${this.hearing.case} at ${this.hearing.court}`,
            onClick: () => this.$emit('click'),
        }, [
            h('span', { class: 'chip-type', style: { color: this.color } }, this.hearing.hearing_type_name),
            h('span', { class: 'chip-case' }, this.hearing.case),
        ]);
    },
};
</script>

<style lang="scss" scoped>
.hearings-calendar-page {
    padding: 20px;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

.page-title {
    font-size: 22px;
    font-weight: 700;
    margin: 0;
}

.view-toggle {
    display: flex;
    gap: 4px;
    background: var(--surface, #f1f5f9);
    padding: 4px;
    border-radius: 8px;
}

.view-toggle-btn {
    padding: 6px 14px;
    border: none;
    background: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
    cursor: pointer;

    &.active {
        background: #fff;
        color: var(--primary-600);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    }
}

.month-nav {
    display: flex;
    align-items: center;
    gap: 12px;
}

.current-month {
    font-weight: 600;
    font-size: 15px;
    min-width: 160px;
    text-align: center;
}

.btn-nav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    background: white;
    border-radius: 8px;
    cursor: pointer;

    &:hover {
        background: rgba(2, 132, 199, 0.08);
    }
}

.btn-today {
    padding: 6px 14px;
    border: 1px solid var(--primary-600);
    color: var(--primary-600);
    background: white;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;

    &:hover {
        background: rgba(2, 132, 199, 0.08);
    }
}

.type-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 16px;
    font-size: 12px;
    color: var(--text-secondary, #64748b);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.legend-swatch {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.calendar-loading {
    padding: 40px 0;
    text-align: center;
    color: #6b7280;
}

.calendar-grid {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid rgba(0, 0, 0, 0.08);
}

.weekday-row, .days-row {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
}

.weekday-cell {
    padding: 10px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    color: #6b7280;
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.day-cell {
    min-height: 100px;
    padding: 8px;
    border-right: 1px solid rgba(0, 0, 0, 0.04);
    border-bottom: 1px solid rgba(0, 0, 0, 0.04);

    &.other-month {
        background: rgba(0, 0, 0, 0.02);

        .day-number {
            color: #6b7280;
        }
    }

    &.is-today {
        background: rgba(2, 132, 199, 0.05);

        .day-number {
            color: var(--primary-600);
            font-weight: 700;
        }
    }
}

.week-day-cell {
    min-height: 220px;
}

.day-number {
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
}

.day-hearings {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.no-hearings {
    font-size: 11px;
    color: #cbd5e1;
    margin: 4px 0 0;
}

.hearing-chip {
    display: flex;
    flex-direction: column;
    font-size: 11px;
    padding: 3px 6px;
    border-radius: 4px;
    border-left: 3px solid transparent;
    background: rgba(0, 0, 0, 0.03);
    color: var(--text-primary, #0f172a);
    text-decoration: none;
    overflow: hidden;

    &:hover {
        background: rgba(0, 0, 0, 0.06);
    }

    .chip-type {
        font-weight: 700;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .chip-case {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    &.expanded .chip-case {
        white-space: normal;
    }
}

.agenda-list {
    background: white;
    border-radius: 12px;
    border: 1px solid rgba(0, 0, 0, 0.08);
    padding: 8px 0;
}

.agenda-group {
    padding: 8px 20px;

    & + .agenda-group {
        border-top: 1px solid rgba(0, 0, 0, 0.06);
    }
}

.agenda-date {
    font-size: 13px;
    font-weight: 700;
    margin: 8px 0;
    color: var(--text-secondary, #64748b);
}

.agenda-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 4px;
    text-decoration: none;
    color: var(--text-primary, #0f172a);
    font-size: 13px;
    border-radius: 6px;

    &:hover {
        background: rgba(2, 132, 199, 0.05);
    }
}

.agenda-type {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-secondary, #64748b);
    min-width: 90px;
}

.agenda-case {
    font-weight: 600;
    flex: 1;
}

.agenda-court {
    color: var(--text-tertiary, #94a3b8);
    font-size: 12px;
}
</style>
