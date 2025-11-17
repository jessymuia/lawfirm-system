<template>
    <div class="modern-sidebar-wrapper" :class="{ 'sidebar-collapsed': !$store.state.is_show_sidebar }">
        <nav class="modern-sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <router-link to="/" class="brand-link">
                        <img src="@/assets/images/logo.svg" class="brand-logo" alt="logo" />
                        <span class="brand-text">Ouma Njoga</span>
                    </router-link>
                </div>
            </div>

            <!-- Sidebar Menu -->
            <perfect-scrollbar 
                class="sidebar-menu" 
                tag="ul" 
                :options="{ wheelSpeed: 0.5, swipeEasing: true, minScrollbarLength: 40, maxScrollbarLength: 300, suppressScrollX: true }"
            >
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

                <!-- Hearings -->
                <li v-if="hasPermission('list-clients')" class="menu-item">
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

                <!-- Hearing Types -->
                <li v-if="hasPermission('list-clients')" class="menu-item">
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
                <li v-if="hasPermission('list-invoice')" class="menu-item">
                    <router-link to="/expenses" class="menu-link" @click="toggleMobileMenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="1" x2="12" y2="23"></line>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                        <span class="menu-text">Expenses</span>
                    </router-link>
                </li>

                <!-- Expense Categories -->
                <li v-if="hasPermission('list-invoice')" class="menu-item">
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
            </perfect-scrollbar>
        </nav>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useStore } from 'vuex';

const store = useStore();
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
        store.commit('toggleSideBar', !store.state.is_show_sidebar);
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
                filter: brightness(0) invert(1);
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
    list-style: none;
    margin: 0;
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
