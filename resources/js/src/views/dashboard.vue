<template>
    <div class="layout-px-spacing dash_2">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Dashboard</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Sales</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>

            <ul class="navbar-nav flex-row ms-auto">
                <li class="nav-item more-dropdown">
                    <div class="dropdown custom-dropdown-icon">
                        <a href="javascript:;" class="nav-link dropdown-toggle" id="ddlSettings" data-bs-toggle="dropdown" aria-expanded="false">
                            <span>Settings</span>
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-chevron-down"
                            >
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="ddlSettings">
                            <li><a class="dropdown-item" data-value="Settings" href="javascript:void(0);">Settings</a></li>
                            <li><a class="dropdown-item" data-value="Mail" href="javascript:void(0);">Mail</a></li>
                            <li><a class="dropdown-item" data-value="Print" href="javascript:void(0);">Print</a></li>
                            <li><a class="dropdown-item" data-value="Download" href="javascript:void(0);">Download</a></li>
                            <li><a class="dropdown-item" data-value="Share" href="javascript:void(0);">Share</a></li>
                        </ul>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="row layout-top-spacing">
            <div class="col-xl-4 col-lg-12 col-md-12 col-sm-12 col-12 layout-spacing">
                <div class="widget widget-statistics">
                    <div class="widget-heading">
                        <h5>Statistics</h5>
                        <div class="task-action">
                            <div class="dropdown btn-group">
                                <a href="javascript:;" id="ddlStatistics" class="btn dropdown-toggle btn-icon-only" data-bs-toggle="dropdown" aria-expanded="false">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="feather feather-more-horizontal"
                                    >
                                        <circle cx="12" cy="12" r="1"></circle>
                                        <circle cx="19" cy="12" r="1"></circle>
                                        <circle cx="5" cy="12" r="1"></circle>
                                    </svg>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="ddlStatistics">
                                    <li><a href="javascript:;" class="dropdown-item">View</a></li>
                                    <li><a href="javascript:;" class="dropdown-item">Download</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="widget-content">
                        <div class="row">
                            <div class="col-6">
                                <div class="w-detail">
                                    <p class="w-title">Total Clients</p>
                                    <p class="w-stats">{{dashboard.clients}}</p>
                                </div>
                                <apex-chart v-if="total_visit_options" height="58" type="line" :options="total_visit_options" :series="total_visit_series"></apex-chart>
                            </div>
                            <div class="col-6">
                                <div class="w-detail">
                                    <p class="w-title">Total Cases</p>
                                    <p class="w-stats">{{dashboard.cases}}</p>
                                </div>
                                <apex-chart v-if="paid_visit_options" height="58" type="line" :options="paid_visit_options" :series="paid_visit_series"></apex-chart>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12 col-12 layout-spacing">
                <div class="widget widget-expenses">
                    <div class="widget-heading">
                        <h5>Expenses</h5>
                        <div class="task-action">
                            <div class="dropdown btn-group">
                                <a href="javascript:;" id="ddlExpenses" class="btn dropdown-toggle btn-icon-only" data-bs-toggle="dropdown" aria-expanded="false">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="feather feather-more-horizontal"
                                    >
                                        <circle cx="12" cy="12" r="1"></circle>
                                        <circle cx="19" cy="12" r="1"></circle>
                                        <circle cx="5" cy="12" r="1"></circle>
                                    </svg>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="ddlExpenses">
                                    <li><a href="javascript:;" class="dropdown-item">This Week</a></li>
                                    <li><a href="javascript:;" class="dropdown-item">Last Week</a></li>
                                    <li><a href="javascript:;" class="dropdown-item">Last Month</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="widget-content">
                        <p class="value">
                            $ 45,141
                            <span>this week </span>
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-trending-up"
                            >
                                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                                <polyline points="17 6 23 6 23 12"></polyline>
                            </svg>
                        </p>
                        <div class="w-progress-stats">
                            <div class="progress">
                                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="57" class="progress-bar bg-gradient-secondary" style="width: 57%"></div>
                            </div>
                            <div class="w-icon">57%</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12 col-12 layout-spacing">
                <div class="widget widget-total-balance">
                    <div class="widget-content">
                        <div class="account-box">
                            <div class="info">
                                <div class="inv-title">
                                    <h5>Total Balance</h5>
                                </div>
                                <div class="inv-balance-info">
                                    <p class="inv-balance">$ 41,741.42</p>
                                    <span class="inv-stats balance-credited">+ 2453</span>
                                </div>
                            </div>
                            <div class="acc-action">
                                <div>
                                    <a href="javascript:void(0);"
                                    ><svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="feather feather-plus"
                                    >
                                        <line x1="12" y1="5" x2="12" y2="19"></line>
                                        <line x1="5" y1="12" x2="19" y2="12"></line></svg
                                    ></a>
                                    <a href="javascript:void(0);"
                                    ><svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="feather feather-credit-card"
                                    >
                                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                        <line x1="1" y1="10" x2="23" y2="10"></line></svg
                                    ></a>
                                </div>
                                <a href="javascript:void(0);">Upgrade</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-9 col-lg-12 col-md-12 col-sm-12 col-12 layout-spacing">
                <div class="widget widget-unique-visitors">
                    <div class="widget-heading">
                        <h5>Unique Visitors</h5>
                        <div class="task-action">
                            <div class="dropdown btn-group">
                                <a href="javascript:;" id="ddlVisitors" class="btn dropdown-toggle btn-icon-only" data-bs-toggle="dropdown" aria-expanded="false">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="feather feather-more-horizontal"
                                    >
                                        <circle cx="12" cy="12" r="1"></circle>
                                        <circle cx="19" cy="12" r="1"></circle>
                                        <circle cx="5" cy="12" r="1"></circle>
                                    </svg>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="ddlVisitors">
                                    <li><a href="javascript:;" class="dropdown-item">View</a></li>
                                    <li><a href="javascript:;" class="dropdown-item">Update</a></li>
                                    <li><a href="javascript:;" class="dropdown-item">Download</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="widget-content">
                        <apex-chart v-if="unique_visitor_options" height="350" type="bar" :options="unique_visitor_options" :series="unique_visitor_series"></apex-chart>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>
<script setup>
import '@/assets/sass/widgets/widgets.scss';
import { computed, ref } from 'vue';
import { useStore } from 'vuex';
import ApexChart from 'vue3-apexcharts';

import { useMeta } from '@/composables/use-meta';
useMeta({ title: 'Widgets' });

const store = useStore();

//Statistics
const total_visit_series = ref([{ data: [21, 9, 36, 12, 44, 25, 59, 41, 66, 25] }]);
const total_visit_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    return {
        chart: { sparkline: { enabled: true }, dropShadow: { enabled: true, top: 3, left: 1, blur: 3, color: '#009688', opacity: 0.7 } },
        stroke: { curve: 'smooth', width: 2 },
        markers: { size: 0 },
        colors: ['#009688'],
        grid: { padding: { top: 0, bottom: 0, left: 0 } },
        tooltip: {
            theme: is_dark ? 'dark' : 'light',
            x: { show: false },
            y: {
                title: {
                    formatter: function formatter() {
                        return '';
                    },
                },
            },
        },
        responsive: [{ breakPoint: 576, options: { chart: { height: 95 }, grid: { padding: { top: 45, bottom: 0, left: 0 } } } }],
    };
});

const paid_visit_series = ref([{ data: [22, 19, 30, 47, 32, 44, 34, 55, 41, 69] }]);
const paid_visit_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    return {
        chart: { sparkline: { enabled: true }, dropShadow: { enabled: true, top: 1, left: 1, blur: 2, color: '#e2a03f', opacity: 0.7 } },
        stroke: { curve: 'smooth', width: 2 },
        markers: { size: 0 },
        colors: ['#e2a03f'],
        grid: { padding: { top: 0, bottom: 0, left: 0 } },
        tooltip: {
            theme: is_dark ? 'dark' : 'light',
            x: { show: false },
            y: {
                title: {
                    formatter: function formatter() {
                        return '';
                    },
                },
            },
        },
        responsive: [{ breakPoint: 576, options: { chart: { height: 95 }, grid: { padding: { top: 35, bottom: 0, left: 0 } } } }],
    };
});

//unique visitors
const unique_visitor_series = ref([
    { name: 'Direct', data: [58, 44, 55, 57, 56, 61, 58, 63, 60, 66, 56, 63] },
    { name: 'Organic', data: [91, 76, 85, 101, 98, 87, 105, 91, 114, 94, 66, 70] },
]);
const unique_visitor_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    return {
        chart: { toolbar: { show: false } },
        dataLabels: { enabled: false },
        stroke: { show: true, width: 2, colors: ['transparent'] },
        colors: ['#5c1ac3', '#ffbb44'],
        dropShadow: { enabled: true, opacity: 0.3, blur: 1, left: 1, top: 1, color: '#515365' },
        plotOptions: { bar: { horizontal: false, columnWidth: '55%', borderRadius: 10 } },
        legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '14px', markers: { width: 12, height: 12 }, itemMargin: { horizontal: 0, vertical: 8 } },
        grid: { borderColor: is_dark ? '#191e3a' : '#e0e6ed' },
        xaxis: {
            categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            axisBorder: { show: true, color: is_dark ? '#3b3f5c' : '#e0e6ed' },
        },
        yaxis: {
            tickAmount: 6,
        },
        fill: {
            type: 'gradient',
            gradient: { shade: is_dark ? 'dark' : 'light', type: 'vertical', shadeIntensity: 0.3, inverseColors: false, opacityFrom: 1, opacityTo: 0.8, stops: [0, 100] },
        },
        tooltip: {
            theme: is_dark ? 'dark' : 'light',
            y: {
                formatter: function (val) {
                    return val;
                },
            },
        },
    };
});

//Followers
const followers_series = ref([{ name: 'Sales', data: [38, 60, 38, 52, 36, 40, 28] }]);
const followers_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    let option = {
        chart: { sparkline: { enabled: true } },
        stroke: { curve: 'smooth', width: 2 },
        colors: ['#4361ee'],
        yaxis: { min: 0, show: false },
        tooltip: { theme: is_dark ? 'dark' : 'light', x: { show: false } },
    };
    if (is_dark) {
        option['fill'] = { type: 'gradient', gradient: { type: 'vertical', shadeIntensity: 1, inverseColors: !1, opacityFrom: 0.3, opacityTo: 0.05, stops: [100, 100] } };
    }
    return option;
});

//Referral
const referral_series = ref([{ name: 'Sales', data: [60, 28, 52, 38, 40, 36, 38] }]);
const referral_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    let option = {
        chart: { sparkline: { enabled: true } },
        stroke: { curve: 'smooth', width: 2 },
        colors: ['#e7515a'],
        yaxis: { min: 0, show: false },
        tooltip: { theme: is_dark ? 'dark' : 'light', x: { show: false } },
    };
    if (is_dark) {
        option['fill'] = { type: 'gradient', gradient: { type: 'vertical', shadeIntensity: 1, inverseColors: !1, opacityFrom: 0.3, opacityTo: 0.05, stops: [100, 100] } };
    }
    return option;
});

//Engagement
const engagement_series = ref([{ name: 'Sales', data: [28, 50, 36, 60, 38, 52, 38] }]);
const engagement_options = computed(() => {
    const is_dark = store.state.is_dark_mode;
    let option = {
        chart: { sparkline: { enabled: true } },
        stroke: { curve: 'smooth', width: 2 },
        colors: ['#1abc9c'],
        yaxis: { min: 0, show: false },
        tooltip: { theme: is_dark ? 'dark' : 'light', x: { show: false } },
    };
    if (is_dark) {
        option['fill'] = { type: 'gradient', gradient: { type: 'vertical', shadeIntensity: 1, inverseColors: !1, opacityFrom: 0.3, opacityTo: 0.05, stops: [100, 100] } };
    }
    return option;
});
</script>
