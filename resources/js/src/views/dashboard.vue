<template>
    <div class="enhanced-dashboard">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Dashboard</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Overview</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h1>Welcome back! 👋</h1>
                    <p>Here's what's happening with your law firm today</p>
                </div>
                <div class="quick-actions">
                    <router-link to="/clients" class="quick-action-btn primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2"/>
                            <circle cx="8.5" cy="7" r="4" stroke-width="2"/>
                            <path d="M20 8v6M23 11h-6" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        New Client
                    </router-link>
                    <router-link to="/cases" class="quick-action-btn secondary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2"/>
                            <polyline points="14 2 14 8 20 8" stroke-width="2"/>
                        </svg>
                        New Case
                    </router-link>
                </div>
            </div>
        </div>

        <!-- Enhanced Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card clients-gradient">
                <div class="stat-header">
                    <div class="stat-icon-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2"/>
                            <circle cx="9" cy="7" r="4" stroke-width="2"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="stat-trend-badge positive">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" stroke-width="2"/>
                        </svg>
                        +12%
                    </div>
                </div>
                <div class="stat-body">
                    <h3 class="stat-value">{{ dashboard.clients }}</h3>
                    <p class="stat-label">Total Clients</p>
                    <p class="stat-description">Active client base</p>
                </div>
                <div class="stat-footer">
                    <apex-chart v-if="total_visit_options" height="50" type="line" :options="total_visit_options" :series="total_visit_series"></apex-chart>
                </div>
            </div>

            <div class="stat-card cases-gradient">
                <div class="stat-header">
                    <div class="stat-icon-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2"/>
                            <polyline points="14 2 14 8 20 8" stroke-width="2"/>
                            <line x1="16" y1="13" x2="8" y2="13" stroke-width="2"/>
                            <line x1="16" y1="17" x2="8" y2="17" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="stat-trend-badge positive">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" stroke-width="2"/>
                        </svg>
                        +8%
                    </div>
                </div>
                <div class="stat-body">
                    <h3 class="stat-value">{{ dashboard.cases }}</h3>
                    <p class="stat-label">Active Cases</p>
                    <p class="stat-description">Cases in progress</p>
                </div>
                <div class="stat-footer">
                    <apex-chart v-if="paid_visit_options" height="50" type="line" :options="paid_visit_options" :series="paid_visit_series"></apex-chart>
                </div>
            </div>

            <div class="stat-card invoices-gradient">
                <div class="stat-header">
                    <div class="stat-icon-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2"/>
                            <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="stat-trend-badge positive">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" stroke-width="2"/>
                        </svg>
                        +15%
                    </div>
                </div>
                <div class="stat-body">
                    <h3 class="stat-value">KES {{ formatNumber(dashboard.invoices) }}</h3>
                    <p class="stat-label">Total Revenue</p>
                    <p class="stat-description">This month</p>
                </div>
                <div class="stat-footer">
                    <div class="mini-progress">
                        <div class="progress-bar" style="width: 75%"></div>
                    </div>
                    <span class="progress-text">75% of target</span>
                </div>
            </div>

            <div class="stat-card expenses-gradient">
                <div class="stat-header">
                    <div class="stat-icon-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <line x1="12" y1="1" x2="12" y2="23" stroke-width="2"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="stat-trend-badge negative">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="23 18 13.5 8.5 8.5 13.5 1 6" stroke-width="2"/>
                        </svg>
                        -5%
                    </div>
                </div>
                <div class="stat-body">
                    <h3 class="stat-value">KES {{ formatNumber(dashboard.expenses) }}</h3>
                    <p class="stat-label">Weekly Expenses</p>
                    <p class="stat-description">Last 7 days</p>
                </div>
                <div class="stat-footer">
                    <div class="mini-progress expenses">
                        <div class="progress-bar" style="width: 45%"></div>
                    </div>
                    <span class="progress-text">45% of budget</span>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-section">
            <!-- Main Performance Chart -->
            <div class="main-chart-card">
                <div class="chart-header">
                    <div class="chart-title-section">
                        <h3>Performance Overview</h3>
                        <p>Monthly clients and cases trends</p>
                    </div>
                    <div class="chart-controls">
                        <div class="chart-legend-modern">
                            <button class="legend-btn active clients">
                                <span class="legend-dot"></span>
                                <span>Clients</span>
                            </button>
                            <button class="legend-btn active cases">
                                <span class="legend-dot"></span>
                                <span>Cases</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="chart-body">
                    <apex-chart v-if="unique_visitor_options" height="380" type="bar" :options="unique_visitor_options" :series="unique_visitor_series"></apex-chart>
                </div>
            </div>

            <!-- Quick Stats Sidebar -->
            <div class="quick-stats-card">
                <div class="stats-header">
                    <h3>Quick Insights</h3>
                    <button class="view-all-btn">View All</button>
                </div>
                <div class="stats-list">
                    <div class="stat-list-item success">
                        <div class="stat-icon-wrapper">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <polyline points="20 6 9 17 4 12" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <p class="stat-title">Won Cases</p>
                            <h4 class="stat-number">127</h4>
                        </div>
                        <div class="stat-badge success">+12%</div>
                    </div>

                    <div class="stat-list-item warning">
                        <div class="stat-icon-wrapper">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <circle cx="12" cy="12" r="10" stroke-width="2"/>
                                <polyline points="12 6 12 12 16 14" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <p class="stat-title">Pending Cases</p>
                            <h4 class="stat-number">{{ dashboard.cases }}</h4>
                        </div>
                        <div class="stat-badge neutral">--</div>
                    </div>

                    <div class="stat-list-item primary">
                        <div class="stat-icon-wrapper">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke-width="2"/>
                                <circle cx="12" cy="7" r="4" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <p class="stat-title">New Clients</p>
                            <h4 class="stat-number">{{ Math.ceil(dashboard.clients * 0.15) }}</h4>
                        </div>
                        <div class="stat-badge success">+8%</div>
                    </div>

                    <div class="stat-list-item accent">
                        <div class="stat-icon-wrapper">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <p class="stat-title">Hearings This Week</p>
                            <h4 class="stat-number">34</h4>
                        </div>
                        <div class="stat-badge success">+5%</div>
                    </div>

                    <div class="stat-list-item info">
                        <div class="stat-icon-wrapper">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" stroke-width="2"/>
                                <rect x="9" y="3" width="6" height="4" rx="1" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <p class="stat-title">Active Tasks</p>
                            <h4 class="stat-number">89</h4>
                        </div>
                        <div class="stat-badge warning">Due soon</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useStore } from 'vuex';
import ApexChart from 'vue3-apexcharts';
import axios from '../api';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Dashboard - Ouma Njoga & Co Advocates' });

const store = useStore();
const dashboard = ref({ clients: 45, cases: 3, invoices: 0, expenses: 0 });

const formatNumber = (num) => {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
};

// Statistics Chart - Clients
const total_visit_series = ref([{ data: [21, 9, 36, 12, 44, 25, 59, 41, 66, 25] }]);
const total_visit_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    return {
        chart: {
            sparkline: { enabled: true },
            dropShadow: { enabled: true, top: 1, left: 1, blur: 3, color: '#4f46e5', opacity: 0.2 }
        },
        stroke: { curve: 'smooth', width: 2 },
        markers: { size: 0 },
        colors: ['rgba(255, 255, 255, 0.9)'],
        grid: { padding: { top: 0, bottom: 0, left: 0 } },
        tooltip: {
            theme: is_dark ? 'dark' : 'light',
            x: { show: false },
            y: { title: { formatter: () => '' } }
        }
    };
});

// Statistics Chart - Cases
const paid_visit_series = ref([{ data: [22, 19, 30, 47, 32, 44, 34, 55, 41, 69] }]);
const paid_visit_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    return {
        chart: {
            sparkline: { enabled: true },
            dropShadow: { enabled: true, top: 1, left: 1, blur: 3, color: '#10b981', opacity: 0.2 }
        },
        stroke: { curve: 'smooth', width: 2 },
        markers: { size: 0 },
        colors: ['rgba(255, 255, 255, 0.9)'],
        grid: { padding: { top: 0, bottom: 0, left: 0 } },
        tooltip: {
            theme: is_dark ? 'dark' : 'light',
            x: { show: false },
            y: { title: { formatter: () => '' } }
        }
    };
});

// Main Chart
const unique_visitor_series = ref([
    { name: 'Clients', data: [58, 44, 55, 57, 56, 61, 58, 63, 60, 66, 56, 63] },
    { name: 'Cases', data: [91, 76, 85, 101, 98, 87, 105, 91, 114, 94, 66, 70] }
]);

const unique_visitor_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    return {
        chart: {
            toolbar: { show: false },
            fontFamily: 'Inter, sans-serif',
        },
        dataLabels: { enabled: false },
        stroke: { show: true, width: 2, colors: ['transparent'] },
        colors: ['#4f46e5', '#10b981'],
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '65%',
                borderRadius: 10,
                borderRadiusApplication: 'end'
            }
        },
        legend: { show: false },
        grid: {
            borderColor: is_dark ? '#334155' : '#e2e8f0',
            strokeDashArray: 3,
            xaxis: { lines: { show: false } }
        },
        xaxis: {
            categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: {
                style: {
                    colors: is_dark ? '#94a3b8' : '#64748b',
                    fontSize: '13px',
                    fontWeight: 500
                }
            }
        },
        yaxis: {
            tickAmount: 5,
            labels: {
                style: {
                    colors: is_dark ? '#94a3b8' : '#64748b',
                    fontSize: '13px',
                    fontWeight: 500
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: is_dark ? 'dark' : 'light',
                type: 'vertical',
                shadeIntensity: 0.1,
                inverseColors: false,
                opacityFrom: 1,
                opacityTo: 0.9,
                stops: [0, 100]
            }
        },
        tooltip: {
            theme: is_dark ? 'dark' : 'light',
            y: { formatter: (val) => val },
            style: { fontSize: '14px' }
        }
    };
});

onMounted(() => {
    fetchData();
});

const fetchData = () => {
    axios.get("/api/dashboard")
        .then(response => {
            dashboard.value.cases = response.data.data[1];
            dashboard.value.clients = response.data.data[0];
            dashboard.value.invoices = response.data.data[2];
            dashboard.value.expenses = response.data.data[3];

            unique_visitor_series.value[0].data = response.data.data[4];
            unique_visitor_series.value[1].data = response.data.data[5];
        }).catch(error => {
            console.error('Error fetching dashboard data:', error);
        });
};
</script>

<style lang="scss" scoped>
.enhanced-dashboard {
    padding: 24px;
    max-width: 1600px;
    margin: 0 auto;
}

.welcome-banner {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 32px;
    box-shadow: 0 8px 24px rgba(102, 126, 234, 0.25);
    position: relative;
    overflow: hidden;

    &::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        border-radius: 50%;
    }

    .welcome-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 24px;
    }

    .welcome-text {
        color: white;

        h1 {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 8px 0;
        }

        p {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
    }

    .quick-actions {
        display: flex;
        gap: 12px;
    }

    .quick-action-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 14px;
        text-decoration: none;
        transition: all 0.3s ease;
        border: none;
        cursor: pointer;

        svg {
            width: 20px;
            height: 20px;
            stroke-width: 2;
        }

        &.primary {
            background: white;
            color: #667eea;

            &:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 16px rgba(255,255,255,0.3);
            }
        }

        &.secondary {
            background: rgba(255,255,255,0.2);
            color: white;
            backdrop-filter: blur(10px);

            &:hover {
                background: rgba(255,255,255,0.3);
                transform: translateY(-2px);
            }
        }
    }
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;

    &::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }

    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.12);
    }

    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
    }

    .stat-icon-lg {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;

        svg {
            width: 28px;
            height: 28px;
            stroke-width: 2;
        }
    }

    .stat-trend-badge {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;

        svg {
            width: 14px;
            height: 14px;
            stroke-width: 2.5;
        }

        &.positive {
            background: #d1fae5;
            color: #065f46;
        }

        &.negative {
            background: #fee2e2;
            color: #991b1b;
        }
    }

    .stat-body {
        margin-bottom: 16px;

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: #1f2937;
        }

        .stat-label {
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            margin: 0 0 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-description {
            font-size: 13px;
            color: #9ca3af;
            margin: 0;
        }
    }

    .stat-footer {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #f3f4f6;

        .mini-progress {
            height: 6px;
            background: #f3f4f6;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 8px;

            .progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #4f46e5, #6366f1);
                border-radius: 3px;
                transition: width 0.5s ease;
            }

            &.expenses .progress-bar {
                background: linear-gradient(90deg, #f97316, #fb923c);
            }
        }

        .progress-text {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
    }

    &.clients-gradient {
        &::before {
            background: linear-gradient(90deg, #4f46e5, #6366f1);
        }

        .stat-icon-lg {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
        }
    }

    &.cases-gradient {
        &::before {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .stat-icon-lg {
            background: linear-gradient(135deg, #10b981, #059669);
        }
    }

    &.invoices-gradient {
        &::before {
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
        }

        .stat-icon-lg {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
        }
    }

    &.expenses-gradient {
        &::before {
            background: linear-gradient(90deg, #f97316, #fb923c);
        }

        .stat-icon-lg {
            background: linear-gradient(135deg, #f97316, #fb923c);
        }
    }
}

.charts-section {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 24px;
}

.main-chart-card {
    background: white;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);

    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .chart-title-section {
        h3 {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin: 0 0 4px 0;
        }

        p {
            font-size: 14px;
            color: #6b7280;
            margin: 0;
        }
    }

    .chart-legend-modern {
        display: flex;
        gap: 12px;

        .legend-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s ease;

            .legend-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
            }

            &.active {
                border-color: currentColor;

                &.clients {
                    color: #4f46e5;
                    .legend-dot { background: #4f46e5; }
                }

                &.cases {
                    color: #10b981;
                    .legend-dot { background: #10b981; }
                }
            }
        }
    }
}

.quick-stats-card {
    background: white;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);

    .stats-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;

        h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }

        .view-all-btn {
            font-size: 13px;
            font-weight: 600;
            color: #6366f1;
            background: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;

            &:hover {
                color: #4f46e5;
                text-decoration: underline;
            }
        }
    }

    .stats-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .stat-list-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        background: #f9fafb;
        border-radius: 12px;
        border: 2px solid #f3f4f6;
        transition: all 0.3s ease;

        &:hover {
            background: #f3f4f6;
            transform: translateX(4px);
        }

        .stat-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: white;

            svg {
                width: 24px;
                height: 24px;
                stroke-width: 2;
            }
        }

        .stat-content {
            flex: 1;

            .stat-title {
                font-size: 13px;
                font-weight: 500;
                color: #6b7280;
                margin: 0 0 4px 0;
            }

            .stat-number {
                font-size: 24px;
                font-weight: 700;
                color: #1f2937;
                margin: 0;
            }
        }

        .stat-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;

            &.success {
                background: #d1fae5;
                color: #065f46;
            }

            &.warning {
                background: #fef3c7;
                color: #92400e;
            }

            &.neutral {
                background: #f3f4f6;
                color: #6b7280;
            }
        }

        &.success .stat-icon-wrapper {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        &.warning .stat-icon-wrapper {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        &.primary .stat-icon-wrapper {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        &.accent .stat-icon-wrapper {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        &.info .stat-icon-wrapper {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
        }
    }
}

@media (max-width: 1200px) {
    .charts-section {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    }
}

@media (max-width: 768px) {
    .enhanced-dashboard {
        padding: 16px;
    }

    .welcome-banner {
        padding: 24px;

        .welcome-text h1 {
            font-size: 24px;
        }

        .welcome-content {
            flex-direction: column;
            align-items: flex-start;
        }

        .quick-actions {
            width: 100%;

            .quick-action-btn {
                flex: 1;
                justify-content: center;
            }
        }
    }

    .stats-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .charts-section {
        gap: 16px;
    }

    .main-chart-card,
    .quick-stats-card {
        padding: 20px;
    }

    .stat-card {
        padding: 20px;

        .stat-body .stat-value {
            font-size: 28px;
        }
    }
}

// Dark mode support
body.dark {
    .welcome-banner {
        background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
    }

    .stat-card,
    .main-chart-card,
    .quick-stats-card {
        background: #1e293b;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);

        .stat-value,
        .stat-number,
        h3 {
            color: #f1f5f9;
        }

        .stat-label,
        .stat-title,
        .stat-description,
        p {
            color: #94a3b8;
        }

        .stat-footer {
            border-color: #334155;
        }

        .mini-progress {
            background: #334155;
        }
    }

    .stat-list-item {
        background: #0f172a;
        border-color: #334155;

        &:hover {
            background: #1e293b;
        }

        .stat-badge {
            &.neutral {
                background: #334155;
                color: #cbd5e1;
            }
        }
    }

    .chart-legend-modern .legend-btn {
        background: #0f172a;
        border-color: #334155;
    }
}
</style>