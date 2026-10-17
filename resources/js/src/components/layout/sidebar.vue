<template>
    <div class="modern-sidebar-wrapper" :class="{ 'sidebar-collapsed': !app.is_show_sidebar }">
        <nav class="modern-sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <router-link to="/" class="brand-link">
                        <img :src="branding.logo_url || defaultLogo" class="brand-logo" alt="" />
                        <span class="brand-text" data-testid="brand-name">{{ branding.name }}</span>
                    </router-link>
                </div>
            </div>

            <!-- Sidebar Menu -->
            <!-- UI-15: the scroller adds its own rails (divs), so the list sits inside it rather than being it -->
            <perfect-scrollbar
                class="sidebar-menu"
                :options="{ wheelSpeed: 0.5, swipeEasing: true, minScrollbarLength: 40, maxScrollbarLength: 300, suppressScrollX: true }"
            >
              <ul class="sidebar-menu-list">
                <!-- Dashboard -->
                <li v-if="hasPermission('view-dashboard')" class="menu-item">
                    <router-link to="/" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                        </svg>
                        <span class="menu-text">Dashboard</span>
                    </router-link>
                </li>

                <!-- Menu Divider -->
                <li class="menu-divider">
                    <span class="divider-text">Management</span>
                </li>

                <!-- Users -->
                <li v-if="hasPermission('list-users')" class="menu-item">
                    <router-link to="/users" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <span class="menu-text">Users</span>
                    </router-link>
                </li>

                <!-- Clients -->
                <li v-if="hasPermission('list-clients')" class="menu-item">
                    <router-link to="/clients" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="8.5" cy="7" r="4"></circle>
                            <line x1="20" y1="8" x2="20" y2="14"></line>
                            <line x1="23" y1="11" x2="17" y2="11"></line>
                        </svg>
                        <span class="menu-text">Clients</span>
                    </router-link>
                </li>

                <!-- Cases -->
                <li v-if="hasPermission('list-cases')" class="menu-item">
                    <router-link to="/cases" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                        <span class="menu-text">Cases</span>
                    </router-link>
                </li>

                <!-- Menu Divider -->
                <li class="menu-divider">
                    <span class="divider-text">Legal Operations</span>
                </li>

                <!-- Court Types -->
                <li v-if="hasPermission('list-court')" class="menu-item">
                    <router-link to="/courttypes" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                        </svg>
                        <span class="menu-text">Court Types</span>
                    </router-link>
                </li>

                <!-- Courts -->
                <li v-if="hasPermission('list-court')" class="menu-item">
                    <router-link to="/courts" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 21h18"></path>
                            <path d="M5 21V7l8-4v18"></path>
                            <path d="M19 21V11l-6-4"></path>
                            <path d="M9 9v.01"></path>
                            <path d="M9 12v.01"></path>
                            <path d="M9 15v.01"></path>
                        </svg>
                        <span class="menu-text">Courts</span>
                    </router-link>
                </li>

                <!-- FUN-19: Today (hearing day, on a phone) -->
                <li class="menu-item">
                    <router-link to="/today" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>
                        </svg>
                        <span class="menu-text">Today</span>
                    </router-link>
                </li>

                <!-- Hearings -->
                <li v-if="hasPermission('list-hearings')" class="menu-item">
                    <router-link to="/hearings" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <span class="menu-text">Hearings</span>
                    </router-link>
                </li>

                <!-- Hearings Calendar -->
                <li v-if="hasPermission('list-hearings')" class="menu-item">
                    <router-link to="/hearings/calendar" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                            <path d="M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"></path>
                        </svg>
                        <span class="menu-text">Calendar</span>
                    </router-link>
                </li>

                <!-- FUN-16: Court diary and calendar sync -->
                <li v-if="hasPermission('list-hearings')" class="menu-item">
                    <router-link to="/court-diary" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                        </svg>
                        <span class="menu-text">Court Diary</span>
                    </router-link>
                </li>
                <li class="menu-item">
                    <router-link to="/calendar-sync" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                        <span class="menu-text">Calendar Sync</span>
                    </router-link>
                </li>

                <!-- Hearing Types -->
                <li v-if="hasPermission('list-hearings')" class="menu-item">
                    <router-link to="/hearingtypes" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                        </svg>
                        <span class="menu-text">Hearing Types</span>
                    </router-link>
                </li>

                <!-- Menu Divider -->
                <li class="menu-divider">
                    <span class="divider-text">Finance</span>
                </li>

                <!-- Invoices -->
                <li v-if="hasPermission('list-invoice')" class="menu-item">
                    <router-link to="/invoices" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                        <span class="menu-text">Invoices</span>
                    </router-link>
                </li>

                <!-- Expenses -->
                <li v-if="hasPermission('list-expenses')" class="menu-item">
                    <router-link to="/expenses" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="1" x2="12" y2="23"></line>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                        <span class="menu-text">Expenses</span>
                    </router-link>
                </li>

                <!-- Expense Categories -->
                <li v-if="hasPermission('list-expenses')" class="menu-item">
                    <router-link to="/expense-categories" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="2" y1="12" x2="22" y2="12"></line>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                        </svg>
                        <span class="menu-text">Expense Categories</span>
                    </router-link>
                </li>

                <!-- Menu Divider -->
                <li class="menu-divider">
                    <span class="divider-text">Settings</span>
                </li>

                <!-- Tasks -->
                <li v-if="hasPermission('list-task')" class="menu-item">
                    <router-link to="/tasks" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                            <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                            <path d="M9 14l2 2 4-4"></path>
                        </svg>
                        <span class="menu-text">Tasks</span>
                    </router-link>
                </li>

                <!-- Roles -->
                <li v-if="hasPermission('list-roles')" class="menu-item">
                    <router-link to="/roles" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12 1v6m0 6v6"></path>
                            <path d="m4.93 4.93 4.24 4.24m5.66 5.66 4.24 4.24"></path>
                            <path d="m19.07 4.93-4.24 4.24m-5.66 5.66-4.24 4.24"></path>
                        </svg>
                        <span class="menu-text">Roles</span>
                    </router-link>
                </li>

                <!-- FUN-14: Document templates -->
                <li v-if="hasPermission('list-documents')" class="menu-item">
                    <router-link to="/document-templates" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M9 13h6M9 17h3"></path>
                        </svg>
                        <span class="menu-text">Templates</span>
                    </router-link>
                </li>

                <!-- FUN-17: Partner reports -->
                <li v-if="hasPermission('view-partner-reports')" class="menu-item">
                    <router-link to="/reports" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>
                        </svg>
                        <span class="menu-text">Partner Reports</span>
                    </router-link>
                </li>

                <!-- FUN-12: Trust accounting -->
                <li v-if="(hasPermission('reconcile-trust-accounts') || hasPermission('list-trust-transactions')) && features.entitled('trust_accounting')" class="menu-item">
                    <router-link to="/trust" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 21h18"></path><path d="M5 21V10l7-5 7 5v11"></path><path d="M9 21v-6h6v6"></path>
                        </svg>
                        <span class="menu-text">Trust Accounting</span>
                    </router-link>
                </li>

                <!-- FUN-18: Offices & practice groups -->
                <li v-if="hasPermission('manage-organisation')" class="menu-item">
                    <router-link to="/settings/organisation" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="2" width="16" height="20" rx="2"></rect>
                            <line x1="9" y1="6" x2="9" y2="6.01"></line><line x1="15" y1="6" x2="15" y2="6.01"></line>
                            <line x1="9" y1="10" x2="9" y2="10.01"></line><line x1="15" y1="10" x2="15" y2="10.01"></line>
                            <path d="M10 22v-4h4v4"></path>
                        </svg>
                        <span class="menu-text">Offices &amp; Groups</span>
                    </router-link>
                </li>

                <!-- FUN-9: Conflicts -->
                <li v-if="hasPermission('run-conflict-checks')" class="menu-item">
                    <router-link to="/conflicts" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        <span class="menu-text">Conflicts</span>
                    </router-link>
                </li>

                <!-- PLAT-13: Subscription (plan, trial) -->
                <li v-if="firmBilling && hasPermission('manage-subscription')" class="menu-item">
                    <router-link to="/settings/subscription" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                        <span class="menu-text">Subscription</span>
                    </router-link>
                </li>

                <!-- UI-13: Branding (logo, colour, letterhead) -->
                <li v-if="hasPermission('manage-branding')" class="menu-item">
                    <router-link to="/settings/branding" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="13.5" cy="6.5" r="2.5"></circle><circle cx="17.5" cy="10.5" r="2.5"></circle><circle cx="8.5" cy="7.5" r="2.5"></circle><circle cx="6.5" cy="12.5" r="2.5"></circle>
                            <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.7-.7 1.7-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7h2c3.1 0 5.6-2.5 5.6-5.6C22 6 17.5 2 12 2z"></path>
                        </svg>
                        <span class="menu-text">Branding</span>
                    </router-link>
                </li>

                <!-- DATA-14: Currencies and tax -->
                <li v-if="hasPermission('manage-finance-settings')" class="menu-item">
                    <router-link to="/settings/finance" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                        <span class="menu-text">Currencies and tax</span>
                    </router-link>
                </li>

                <!-- SEC-13: Compliance (legal holds, retention, data-subject requests) -->
                <li v-if="hasPermission('manage-compliance')" class="menu-item">
                    <router-link to="/settings/compliance" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                        <span class="menu-text">Compliance</span>
                    </router-link>
                </li>

                <li v-if="hasPermission('manage-ethical-screens')" class="menu-item">
                    <router-link to="/settings/ethical-walls" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><line x1="5.6" y1="5.6" x2="18.4" y2="18.4"></line></svg>
                        <span class="menu-text">Ethical walls</span>
                    </router-link>
                </li>

                <li v-if="hasPermission('manage-api-access') && features.entitled('api_access')" class="menu-item">
                    <router-link to="/settings/api-access" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="8 9 4 12 8 15"></polyline><polyline points="16 9 20 12 16 15"></polyline><line x1="14" y1="5" x2="10" y2="19"></line></svg>
                        <span class="menu-text">API access</span>
                    </router-link>
                </li>

                <!-- PLAT-8: Support access (approve, limit and review platform-staff sessions) -->
                <li v-if="hasPermission('manage-support-access')" class="menu-item">
                    <router-link to="/settings/support-access" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
                        </svg>
                        <span class="menu-text">Support access</span>
                    </router-link>
                </li>

                <!-- SEC-12: Security (two-factor policy, single sign-on) -->
                <li v-if="hasPermission('manage-security-settings')" class="menu-item">
                    <router-link to="/settings/security" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                        <span class="menu-text">Security</span>
                    </router-link>
                </li>
              </ul>
            </perfect-scrollbar>
        </nav>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useAppStore } from '@/stores/app';
import { useFeatures, loadFeatures } from '@/composables/use-features';
import { useRoute } from 'vue-router';
import { branding } from '@/branding';   // UI-13
import defaultLogo from '@/assets/images/logo.svg';

const app = useAppStore();
const features = useFeatures();
const featRoute = useRoute();
watch(() => featRoute.path, () => loadFeatures());   // PLAT-1/3: hide what the plan does not include
// plans and payments are the platform operator's job; the firm only sees them when self-service billing is switched on
const firmBilling = document.getElementById('app')?.dataset.firmBilling === '1';
const userPermissions = JSON.parse(localStorage.getItem("permissions") || "[]");

onMounted(() => {
    const selector = document.querySelector('.modern-sidebar a[href="' + window.location.pathname + '"]');
    if (selector) {
        selector.classList.add('active');
    }
});

const hasPermission = (permission) => {
    return userPermissions.includes(permission);
};

const toggleMobileMenu = () => {
    if (window.innerWidth < 992) {
        app.toggleSideBar(!app.is_show_sidebar);
    }
};
</script>

<style lang="scss" scoped>
.modern-sidebar-wrapper {
    position: fixed;
    top: 70px;
    left: 0;
    bottom: 0;
    width: 260px;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border-color);
    transition: transform var(--transition-base);
    z-index: 100;
    overflow: hidden;

    @media (max-width: 576px) {
        top: 60px;
    }

    @media (max-width: 991px) {
        transform: translateX(-100%);
    }
}

.modern-sidebar {
    height: 100%;
    display: flex;
    flex-direction: column;
}

// Sidebar Header
.sidebar-header {
    padding: 1.5rem 1.25rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);

    .sidebar-brand {
        .brand-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;

            .brand-logo {
                height: 32px;
                width: auto;
            }

            .brand-text {
                font-size: var(--font-size-lg);
                font-weight: var(--font-weight-bold);
                color: white;
            }
        }
    }
}

// Sidebar Menu
.sidebar-menu {
    flex: 1;
    padding: 1rem 0;
}

.sidebar-menu-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.menu-item {
    margin: 0.25rem 0.75rem;

    .menu-link {
        display: flex;
        align-items: center;
        gap: 0.875rem;
        padding: 0.75rem 1rem;
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        border-radius: var(--radius-lg);
        font-size: var(--font-size-sm);
        font-weight: var(--font-weight-medium);
        transition: all var(--transition-fast);
        position: relative;

        svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            opacity: 0.8;
            transition: all var(--transition-fast);
        }

        .menu-text {
            flex: 1;
        }

        &:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;

            svg {
                opacity: 1;
                transform: translateX(2px);
            }
        }

        &.router-link-active,
        &.active {
            background: linear-gradient(135deg, var(--primary-600) 0%, var(--primary-700) 100%);
            color: white;
            box-shadow: var(--shadow-md);

            svg {
                opacity: 1;
            }

            &::before {
                content: '';
                position: absolute;
                left: -0.75rem;
                top: 50%;
                transform: translateY(-50%);
                width: 4px;
                height: 60%;
                background: white;
                border-radius: 0 4px 4px 0;
            }
        }
    }
}

.menu-divider {
    margin: 1.25rem 1.25rem 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);

    .divider-text {
        font-size: var(--font-size-xs);
        font-weight: var(--font-weight-semibold);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: rgba(255, 255, 255, 0.5);
        padding: 0 0.5rem;
    }
}

// Dark mode adjustments
body.dark {
    .modern-sidebar-wrapper {
        background: var(--sidebar-bg);
        border-color: var(--border-color);
    }
}

// Scrollbar styling
:deep(.ps__rail-y) {
    background: transparent !important;
    opacity: 0;
    transition: opacity 0.3s;

    &:hover,
    &.ps--clicking {
        opacity: 1;
    }

    .ps__thumb-y {
        background: rgba(255, 255, 255, 0.3) !important;
        width: 4px;
        border-radius: 4px;

        &:hover {
            background: rgba(255, 255, 255, 0.5) !important;
        }
    }
}
</style>
