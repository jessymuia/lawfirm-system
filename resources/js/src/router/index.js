import { createRouter, createWebHistory } from 'vue-router';

import Home from '../views/dashboard.vue';
import { pinia } from '../stores';
import { useAppStore } from '../stores/app';

// UI-1: template purge. This router previously carried ~83 routes for
// demo apps (chat, mailbox, scrumboard, notes, todo, a duplicate demo
// invoice module), component/element/form/table style-guide pages, and
// three alternate dashboards (index.vue, index1.0.vue, index2.vue) — none
// of them linked from the actual sidebar/header nav (verified against
// components/layout/sidebar.vue and header.vue). Only the firm's real
// modules and the two routes actually linked from the header dropdown
// (profile, account settings) remain below.

const routes = [
    // dashboard
    { path: '/', name: 'Home', component: Home, meta: { authRequired: true } },

    // users
    {
        path: '/users',
        name: 'users',
        component: () => import(/* webpackChunkName: "users" */ '../views/menu/users/index.vue'),
    },

    // hearing types
    {
        path: '/hearingtypes',
        name: 'hearing-types',
        component: () => import(/* webpackChunkName: "hearings" */ '../views/menu/hearings/types.vue'),
    },

    // expense categories
    {
        path: '/expense-categories',
        name: 'expense-categories',
        component: () => import(/* webpackChunkName: "expenses" */ '../views/menu/expenses/categories.vue'),
    },

    // courts / court types
    {
        path: '/courts',
        name: 'courts',
        component: () => import(/* webpackChunkName: "courts" */ '../views/menu/court/index.vue'),
    },
    {
        path: '/courttypes',
        name: 'court-types',
        component: () => import(/* webpackChunkName: "courts" */ '../views/menu/court/types.vue'),
    },

    // expenses
    {
        path: '/expenses',
        name: 'expenses',
        component: () => import(/* webpackChunkName: "expenses" */ '../views/menu/expenses/index.vue'),
    },

    // time entries
    {
        path: '/time-entries',
        name: 'time-entries',
        component: () => import(/* webpackChunkName: "time-entries" */ '../views/menu/time-entries/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // hearings
    {
        path: '/hearings',
        name: 'hearings',
        component: () => import(/* webpackChunkName: "hearings" */ '../views/menu/hearings/index.vue'),
    },
    {
        path: '/hearings/calendar',
        name: 'hearings-calendar',
        component: () => import(/* webpackChunkName: "hearings" */ '../views/menu/hearings/calendar.vue'),
        meta: {
            authRequired: true,
        },
    },

    // tasks
    {
        path: '/tasks',
        name: 'tasks',
        component: () => import(/* webpackChunkName: "tasks" */ '../views/menu/tasks/index.vue'),
    },

    // clients
    {
        path: '/clients',
        name: 'clients',
        component: () => import(/* webpackChunkName: "clients" */ '../views/menu/clients/list-clients.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Clients' }],
        },
    },
    {
        path: '/clients/:id',
        name: 'client-details',
        component: () => import(/* webpackChunkName: "clients" */ '../views/menu/clients/view-client.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Clients', name: 'clients' }, { label: 'Client' }],
        },
    },

    // cases
    {
        path: '/cases',
        name: 'cases',
        component: () => import(/* webpackChunkName: "cases" */ '../views/menu/cases/list-cases.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Cases' }],
        },
    },
    {
        path: '/view-case',
        name: 'case-details',
        component: () => import(/* webpackChunkName: "cases" */ '../views/menu/cases/view-case.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Cases', name: 'cases' }, { label: 'Case' }],
        },
    },
    {
        path: '/cases/create',
        name: 'create-case',
        component: () => import(/* webpackChunkName: "cases" */ '../views/menu/cases/create-case.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Cases', name: 'cases' }, { label: 'New case' }],
        },
    },

    // invoices
    {
        path: '/invoices',
        name: 'invoices',
        component: () => import(/* webpackChunkName: "invoices" */ '../views/menu/invoices/index.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Invoices' }],
        },
    },
    {
        path: '/view-invoice',
        name: 'invoice-details',
        component: () => import(/* webpackChunkName: "invoices" */ '../views/menu/invoices/items/index.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Invoices', name: 'invoices' }, { label: 'Invoice items' }],
        },
    },
    {
        path: '/invoice-preview',
        name: 'invoice-preview-d',
        component: () => import(/* webpackChunkName: "invoices" */ '../views/menu/invoices/preview.vue'),
        meta: {
            authRequired: true,
            breadcrumb: [{ label: 'Invoices', name: 'invoices' }, { label: 'Preview' }],
        },
    },

    // roles
    {
        path: '/roles',
        name: 'roles',
        component: () => import(/* webpackChunkName: "roles" */ '../views/menu/roles/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // FUN-19: the hearing-day view (the installed app opens here).
    {
        path: '/today',
        name: 'today',
        component: () => import(/* webpackChunkName: "today" */ '../views/menu/today/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // FUN-14: document templates.
    {
        path: '/document-templates',
        name: 'document-templates',
        component: () => import(/* webpackChunkName: "templates" */ '../views/menu/templates/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // FUN-16: the court diary and a person's calendar sync.
    {
        path: '/court-diary',
        name: 'court-diary',
        component: () => import(/* webpackChunkName: "calendar" */ '../views/menu/calendar/court-diary.vue'),
        meta: {
            authRequired: true,
        },
    },
    {
        path: '/calendar-sync',
        name: 'calendar-sync',
        component: () => import(/* webpackChunkName: "calendar" */ '../views/menu/calendar/sync.vue'),
        meta: {
            authRequired: true,
        },
    },

    // FUN-17: partner reports (view-partner-reports).
    {
        path: '/reports',
        name: 'reports',
        component: () => import(/* webpackChunkName: "reports" */ '../views/menu/reports/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // FUN-12: trust three-way reconciliation and retainers.
    {
        path: '/trust',
        name: 'trust',
        component: () => import(/* webpackChunkName: "trust" */ '../views/menu/trust/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // FUN-18: offices and practice groups (manage-organisation).
    {
        path: '/settings/organisation',
        name: 'organisation',
        component: () => import(/* webpackChunkName: "organisation" */ '../views/menu/organisation/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // FUN-9: conflict-of-interest checks (run-conflict-checks; register and policy need manage-conflicts).
    {
        path: '/conflicts',
        name: 'conflicts',
        component: () => import(/* webpackChunkName: "conflicts" */ '../views/menu/conflicts/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // PLAT-13: the firm's plan and trial (manage-subscription).
    {
        path: '/settings/subscription',
        name: 'subscription',
        component: () => import(/* webpackChunkName: "subscription" */ '../views/menu/subscription/index.vue'),
        meta: {
            authRequired: true,
        },
        // plans and payments are managed by the platform operator unless self-service billing is switched on
        beforeEnter: (to, from, next) => (document.getElementById('app')?.dataset.firmBilling === '1' ? next() : next('/')),
    },

    // UI-13: the firm's logo, colour and letterhead (manage-branding).
    {
        path: '/settings/branding',
        name: 'branding-settings',
        component: () => import(/* webpackChunkName: "branding" */ '../views/menu/branding/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // DATA-14: base currency, currencies, exchange rates and tax rates (manage-finance-settings).
    {
        path: '/settings/finance',
        name: 'finance-settings',
        component: () => import(/* webpackChunkName: "finance" */ '../views/menu/finance/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // SEC-13: legal holds, retention schedule and data-subject requests (manage-compliance).
    {
        path: '/settings/compliance',
        name: 'compliance-settings',
        component: () => import(/* webpackChunkName: "compliance" */ '../views/menu/compliance/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // PLAT-8: the firm approves, limits and reviews platform-support access (manage-support-access).
    {
        path: '/settings/support-access',
        name: 'support-access-settings',
        component: () => import(/* webpackChunkName: "support-access" */ '../views/menu/support-access/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // SEC-11: ethical walls (manage-ethical-screens).
    {
        path: '/settings/ethical-walls',
        name: 'ethical-walls-settings',
        component: () => import(/* webpackChunkName: "ethical-walls" */ '../views/menu/ethical-walls/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // PLAT-11: API keys and webhooks (manage-api-access).
    {
        path: '/settings/api-access',
        name: 'api-access-settings',
        component: () => import(/* webpackChunkName: "api-access" */ '../views/menu/integrations/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // SEC-12: the firm's two-factor policy and single sign-on (manage-security-settings).
    {
        path: '/settings/security',
        name: 'security-settings',
        component: () => import(/* webpackChunkName: "security-settings" */ '../views/menu/security/index.vue'),
        meta: {
            authRequired: true,
        },
    },

    // account settings (linked from the header dropdown) — the dead
    // '/users/profile' route (static demo content: "Skills", "Bio",
    // "GitHub Contributor" — leftover admin-template bloat unrelated to
    // this app, with no real data behind it at all) has been removed;
    // account_setting.vue now covers real profile + password settings.
    {
        path: '/users/account-setting',
        name: 'account-setting',
        component: () => import(/* webpackChunkName: "profile" */ '../views/users/account_setting.vue'),
        meta: {
            authRequired: true,
        },
    },

    // auth
    {
        path: '/login',
        name: 'login',
        component: () => import(/* webpackChunkName: "auth-login" */ '../views/auth/login.vue'),
        meta: { layout: 'auth' },
    },
    {
        path: '/auth/reset/:token',
        name: 'reset-password-token',
        component: () => import(/* webpackChunkName: "auth-reset" */ '../views/auth/resetPassword.vue'),
        meta: { layout: 'auth' },
    },
    {
        path: '/auth/pass-recovery',
        name: 'pass-recovery',
        component: () => import(/* webpackChunkName: "auth-pass-recovery" */ '../views/auth/pass_recovery.vue'),
        meta: { layout: 'auth' },
    },
    {
        // SEC-12: two-factor enrolment the firm's policy requires before anything else.
        path: '/auth/two-factor',
        name: 'two-factor',
        component: () => import(/* webpackChunkName: "auth-two-factor" */ '../views/auth/two_factor.vue'),
        meta: { layout: 'auth', authRequired: true },
    },

    // error pages (UI-2: dedicated 403 alongside the existing 404/500/503)
    {
        path: '/pages/error403',
        name: 'error403',
        component: () => import(/* webpackChunkName: "pages-error403" */ '../views/pages/error403.vue'),
        meta: { layout: 'auth' },
    },
    {
        path: '/pages/error404',
        name: 'error404',
        component: () => import(/* webpackChunkName: "pages-error404" */ '../views/pages/error404.vue'),
        meta: { layout: 'auth' },
    },
    {
        path: '/pages/error500',
        name: 'error500',
        component: () => import(/* webpackChunkName: "pages-error500" */ '../views/pages/error500.vue'),
        meta: { layout: 'auth' },
    },
    {
        path: '/pages/error503',
        name: 'error503',
        component: () => import(/* webpackChunkName: "pages-error503" */ '../views/pages/error503.vue'),
        meta: { layout: 'auth' },
    },

    // catch-all: any unmatched path lands on the 404 page rather than a
    // blank screen.
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import(/* webpackChunkName: "pages-error404" */ '../views/pages/error404.vue'),
        meta: { layout: 'auth' },
    },
];

const router = new createRouter({
    history: createWebHistory(),
    linkExactActiveClass: 'active',
    routes,
    scrollBehavior(to, from, savedPosition) {
        if (savedPosition) {
            return savedPosition;
        } else {
            return { left: 0, top: 0 };
        }
    },
});

router.beforeEach((to, from, next) => {
    if (to.meta && to.meta.layout && to.meta.layout == 'auth') {
        useAppStore(pinia).setLayout('auth');
    } else {
        useAppStore(pinia).setLayout('app');
    }
    const authRequired = to.matched.some((route) => route.meta.authRequired);
    if (!authRequired) return next();

    // Client-side check only: a UX shortcut to avoid an unnecessary round
    // trip when we already know there's no session. It is not a security
    // boundary — every actual permission/authorization check happens
    // server-side (see SEC-3/SEC-8), and this flag holds no session
    // secret, just a login/display marker, since the real session lives
    // in an httpOnly cookie.
    if (localStorage.getItem('user')) {
        next();
    } else {
        next({ name: 'login', query: { redirectFrom: to.fullPath } });
    }
});

export default router;
