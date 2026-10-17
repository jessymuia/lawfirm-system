<template>
    <div>
        <!-- MODERN TOP HEADER -->
        <div class="modern-header-container fixed-top">
            <header class="modern-header">
                <!-- Logo Section -->
                <div class="header-brand">
                    <button 
                        class="mobile-menu-toggle"
                        @click="app.toggleSideBar(!app.is_show_sidebar)"
                        aria-label="Toggle sidebar navigation"
                        :aria-expanded="app.is_show_sidebar ? 'true' : 'false'"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                    
                    <!-- The sidebar carries its own brand mark and is
                         visible by default above 991px, so this duplicate
                         only needs to show up where the sidebar is
                         off-screen until toggled open (see the matching
                         @media rule in this component's own <style>, and
                         .modern-sidebar-wrapper's breakpoint in
                         sidebar.vue) -- otherwise both the header and the
                         sidebar showed the same logo and name at once. -->
                    <router-link to="/" class="logo-link mobile-only-brand">
                        <div class="logo-wrapper">
                            <img :src="branding.logo_url || defaultLogo" class="logo-img" alt="" />
                            <span class="logo-text">{{ branding.name }}</span>
                        </div>
                    </router-link>
                </div>

                <!-- Breadcrumb in Header (Compact) -->
                <div class="header-breadcrumb">
                    <div id="breadcrumb" class="breadcrumb-portal"></div>
                </div>

                <!-- Right Section -->
                <div class="header-actions">
                    <!-- Global search trigger (UI-9) -->
                    <div class="action-item search-trigger">
                        <button
                            type="button"
                            class="search-trigger-btn"
                            @click="openGlobalSearch && openGlobalSearch()"
                            title="Search (Ctrl/Cmd-K)"
                            aria-label="Open global search"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <span class="search-trigger-label">Search</span>
                            <kbd class="search-trigger-kbd">Ctrl K</kbd>
                        </button>
                    </div>

                    <!-- Theme Toggle -->
                    <div class="action-item theme-toggle">
                        <button 
                            v-if="app.dark_mode == 'light'" 
                            @click="toggleMode('dark')"
                            class="theme-btn"
                            title="Switch to Dark Mode"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="5"></circle>
                                <line x1="12" y1="1" x2="12" y2="3"></line>
                                <line x1="12" y1="21" x2="12" y2="23"></line>
                                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                                <line x1="1" y1="12" x2="3" y2="12"></line>
                                <line x1="21" y1="12" x2="23" y2="12"></line>
                                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                            </svg>
                            <span class="btn-text">Light</span>
                        </button>
                        
                        <button 
                            v-if="app.dark_mode == 'dark'" 
                            @click="toggleMode('system')"
                            class="theme-btn"
                            title="Switch to System Mode"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                            </svg>
                            <span class="btn-text">Dark</span>
                        </button>
                        
                        <button 
                            v-if="app.dark_mode == 'system'" 
                            @click="toggleMode('light')"
                            class="theme-btn"
                            title="Switch to Light Mode"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path>
                                <polygon points="12 15 17 21 7 21 12 15"></polygon>
                            </svg>
                            <span class="btn-text">System</span>
                        </button>
                    </div>

                    <!-- User Profile Dropdown -->
                    <div class="action-item user-menu">
                        <div class="dropdown">
                            <button 
                                class="user-btn" 
                                id="userDropdown" 
                                data-bs-toggle="dropdown" 
                                aria-expanded="false"
                                aria-label="Open account menu"
                            >
                                <img src="@/assets/images/profile-16.jpeg" alt="User Avatar" class="user-avatar" />
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="chevron">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>
                            
                            <ul class="dropdown-menu dropdown-menu-end modern-dropdown" aria-labelledby="userDropdown">
                                <li class="dropdown-header">
                                    <div class="user-info">
                                        <div class="user-name" data-testid="header-user-name">{{ currentUser.name || 'User' }}</div>
                                        <div class="user-email" data-testid="header-user-email">{{ currentUser.email || '' }}</div>
                                    </div>
                                </li>
                                <!-- UI-16: the person's other firms (shown when they belong to more than one) -->
                                <template v-if="firms.length > 1">
                                    <li><hr class="dropdown-divider"></li>
                                    <li class="dropdown-header firm-switch-label">Switch firm</li>
                                    <li v-for="firm in firms" :key="firm.slug">
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            :class="{ 'current-firm': firm.current }"
                                            :aria-current="firm.current ? 'true' : undefined"
                                            :disabled="firm.current || switching"
                                            data-testid="firm-switch-item"
                                            @click="switchTo(firm)"
                                        >
                                            <span>{{ firm.name }}</span>
                                            <span v-if="firm.current" class="firm-current-tag">current</span>
                                        </button>
                                    </li>
                                </template>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <router-link to="/users/account-setting" class="dropdown-item">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="3"></circle>
                                            <path d="M12 1v6m0 6v6m8.66-9l-5.2 3M8.54 14l-5.2 3m13.32-3l-5.2-3M8.54 10l-5.2-3"></path>
                                        </svg>
                                        <span>Settings</span>
                                    </router-link>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <router-link to="/login" class="dropdown-item logout-item" @click.prevent="logout">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                            <polyline points="16 17 21 12 16 7"></polyline>
                                            <line x1="21" y1="12" x2="9" y2="12"></line>
                                        </svg>
                                        <span>Sign Out</span>
                                    </router-link>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>
        </div>

        <!-- OVERLAY FOR MOBILE -->
        <div 
            class="header-overlay" 
            :class="{ show: false }"
            @click="app.toggleSideBar(!app.is_show_sidebar)"
        ></div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
import { branding } from '@/branding';   // UI-13
import defaultLogo from '@/assets/images/logo.svg';
import { useAppStore } from '@/stores/app';

const app = useAppStore();   // ENG-14: layout state (Pinia)
import { onMounted, inject, ref } from 'vue';
import axios from '@/api';

const openGlobalSearch = inject('openGlobalSearch', null);

// Bug fix: this dropdown previously showed hardcoded "Admin User" /
// "admin@oumanjoga.com" text regardless of who was actually logged in.
const currentUser = ref({ name: '', email: '' });
try {
    const stored = JSON.parse(localStorage.getItem('user') || 'null');
    if (stored) {
        currentUser.value = { name: stored.name || '', email: stored.email || '' };
    }
} catch (e) {
    // leave the defaults if localStorage has anything unexpected in it
}

// UI-16: firm switcher. Moving to another firm goes through that firm's own host with a one-minute, single-use link.
const firms = ref([]);
const switching = ref(false);
const switchTo = async (firm) => {
    if (!(await confirmAction({ title: `Switch to ${firm.name || firm.slug}?`, text: 'You will be taken to that firm and signed in there. Anything unsaved here will be lost.', confirmText: 'Switch firm' }))) return;
    switching.value = true;
    try {
        const { data } = await axios.post(`/api/v1/firms/${firm.slug}/switch`);
        window.location.href = data.data.url;
    } catch (e) {
        switching.value = false;
    }
};

onMounted(() => {
    toggleMode();
    // only once signed in: the header is mounted on the sign-in page too, where a 401 would open the session-expired dialog
    if (currentUser.value.email) {
        axios.get('/api/v1/firms').then(({ data }) => { firms.value = data.data || []; }).catch(() => {});
    }
});

const toggleMode = (mode) => {
    window.$appSetting.toggleMode(mode);
};

const logout = async () => {
    if (!(await confirmAction({ title: 'Sign out?', text: 'You will need to sign in again to continue. Anything unsaved will be lost.', confirmText: 'Sign out' }))) return;
    // Invalidate the server-side session (see APIController::logout, SEC-4/
    // SEC-8) before clearing the local display flags — otherwise the
    // httpOnly session cookie stays valid and the user isn't really logged
    // out server-side.
    axios.post('/api/v1/logout').finally(() => {
        localStorage.clear();
        navigator.serviceWorker?.controller?.postMessage('signed-out');   // FUN-19: offline copies go with the session
        window.location.assign('/login');
    });
};
</script>

<style lang="scss" scoped>
// UI-16: firm switcher
.firm-switch-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
.current-firm { font-weight: 600; }
.firm-current-tag { margin-left: auto; padding-left: 8px; font-size: 12px; opacity: 0.7; }

// Modern Header Styles
.modern-header-container {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: var(--z-fixed);
    background: var(--header-bg);
    border-bottom: 1px solid var(--border-light);
    box-shadow: none;
    transition: all var(--transition-base);
    margin: 0;
    padding: 0;
}

.modern-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1.5rem;
    height: 70px;
    max-width: 100%;
    margin: 0;
    gap: 1.5rem;
}

// Logo Section
.header-brand {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-shrink: 0;

    .mobile-menu-toggle {
        display: none;
        background: transparent;
        border: none;
        padding: 0.5rem;
        color: var(--text-secondary);
        cursor: pointer;
        border-radius: var(--radius-md);
        transition: all var(--transition-fast);

        &:hover {
            background: var(--neutral-100);
            color: var(--primary-600);
        }

        @media (max-width: 991px) {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }

    .logo-link {
        text-decoration: none;

        // Hidden on desktop -- the sidebar (visible by default above
        // 991px) already carries the brand mark there. Shown only where
        // the sidebar is off-screen until the hamburger opens it, so
        // exactly one brand mark is ever on screen at a time, never both.
        &.mobile-only-brand {
            display: none;

            @media (max-width: 991px) {
                display: block;
            }
        }
        
        .logo-wrapper {
            display: flex;
            align-items: center;
            gap: 0.75rem;

            .logo-img {
                height: 36px;
                width: auto;
            }

            .logo-text {
                font-size: var(--font-size-lg);
                font-weight: var(--font-weight-bold);
                color: var(--text-primary);
                white-space: nowrap;

                @media (max-width: 768px) {
                    display: none;
                }
            }
        }
    }
}

// Breadcrumb in Header (Compact)
.header-breadcrumb {
    flex: 1;
    min-width: 0;
    
    @media (max-width: 768px) {
        display: none;
    }
}

.breadcrumb-portal {
    :deep(.breadcrumb) {
        margin: 0;
        padding: 0;
        background: transparent;
        font-size: var(--font-size-sm);
    }
    
    :deep(.breadcrumb-item) {
        color: var(--text-secondary);
        
        &.active {
            color: var(--text-primary);
            font-weight: var(--font-weight-semibold);
        }
        
        a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color var(--transition-fast);
            
            &:hover {
                color: var(--primary-600);
            }
        }
    }
}

// Header Actions
.header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-shrink: 0;
}

.action-item {
    display: flex;
    align-items: center;
}

// Global search trigger (UI-9)
.search-trigger {
    .search-trigger-btn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 0.75rem;
        background: var(--neutral-100);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        font-size: var(--font-size-sm);
        font-weight: var(--font-weight-medium);
        cursor: pointer;
        transition: all var(--transition-fast);

        &:hover {
            background: var(--neutral-200);
            color: var(--primary-600);
            border-color: var(--primary-200);
        }

        svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .search-trigger-label {
            @media (max-width: 768px) {
                display: none;
            }
        }

        .search-trigger-kbd {
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 4px;
            background: rgba(0, 0, 0, 0.08);
            color: var(--text-secondary);

            @media (max-width: 576px) {
                display: none;
            }
        }
    }
}

// Theme Toggle
.theme-toggle {
    .theme-btn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: var(--neutral-100);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        font-size: var(--font-size-sm);
        font-weight: var(--font-weight-medium);
        cursor: pointer;
        transition: all var(--transition-fast);

        &:hover {
            background: var(--neutral-200);
            color: var(--primary-600);
            border-color: var(--primary-200);
        }

        .btn-text {
            @media (max-width: 576px) {
                display: none;
            }
        }

        svg {
            width: 18px;
            height: 18px;
        }
    }
}

// User Menu
.user-menu {
    .user-btn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.375rem 0.75rem 0.375rem 0.375rem;
        background: var(--neutral-100);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-full);
        cursor: pointer;
        transition: all var(--transition-fast);

        &:hover {
            background: var(--neutral-200);
            border-color: var(--primary-200);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
        }

        .chevron {
            color: var(--text-secondary);
            width: 16px;
            height: 16px;

            @media (max-width: 576px) {
                display: none;
            }
        }
    }
}

// Modern Dropdown
.modern-dropdown {
    min-width: 240px;
    padding: 0.5rem;
    border: 1px solid var(--border-light);
    box-shadow: var(--shadow-lg);
    border-radius: var(--radius-lg);
    background: var(--card-bg);
    margin-top: 0.5rem;

    .dropdown-header {
        padding: 0.75rem 1rem;
        
        .user-info {
            .user-name {
                font-size: var(--font-size-sm);
                font-weight: var(--font-weight-semibold);
                color: var(--text-primary);
                margin-bottom: 0.25rem;
            }

            .user-email {
                font-size: var(--font-size-xs);
                color: var(--text-secondary);
            }
        }
    }

    .dropdown-divider {
        border-color: var(--border-light);
        margin: 0.5rem 0;
    }

    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.625rem 1rem;
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        font-size: var(--font-size-sm);
        text-decoration: none;
        transition: all var(--transition-fast);

        svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        &:hover {
            background: var(--neutral-100);
            color: var(--primary-600);
        }

        &.logout-item {
            color: var(--error-600);

            &:hover {
                background: var(--error-50);
                color: var(--error-700);
            }
        }
    }
}

// Header Overlay
.header-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: calc(var(--z-fixed) - 2);
    opacity: 0;
    visibility: hidden;
    transition: all var(--transition-base);

    &.show {
        opacity: 1;
        visibility: visible;
    }

    @media (min-width: 992px) {
        display: none;
    }
}

// Dark mode adjustments
body.dark {
    .modern-header-container {
        background: var(--surface);
        border-color: var(--border-color);
    }

    .user-avatar {
        border-color: var(--neutral-700) !important;
    }
}

// Responsive
@media (max-width: 991px) {
    .modern-header {
        padding: 0 1rem;
    }
}

@media (max-width: 576px) {
    .modern-header {
        height: 60px;
    }
}
</style>
