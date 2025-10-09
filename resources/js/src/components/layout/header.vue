<template>
    <div>
        <!-- MODERN TOP HEADER -->
        <div class="modern-header-container fixed-top">
            <header class="modern-header">
                <!-- Logo Section -->
                <div class="header-brand">
                    <button 
                        class="mobile-menu-toggle"
                        @click="$store.commit('toggleSideBar', !$store.state.is_show_sidebar)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                    
                    <router-link to="/" class="logo-link">
                        <div class="logo-wrapper">
                            <img src="@/assets/images/logo.svg" class="logo-img" alt="logo" />
                            <span class="logo-text">Ouma Njoga & Co.</span>
                        </div>
                    </router-link>
                </div>

                <!-- Right Section -->
                <div class="header-actions">
                    <!-- Theme Toggle -->
                    <div class="action-item theme-toggle">
                        <button 
                            v-if="$store.state.dark_mode == 'light'" 
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
                            v-if="$store.state.dark_mode == 'dark'" 
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
                            v-if="$store.state.dark_mode == 'system'" 
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
                            >
                                <img src="@/assets/images/profile-16.jpeg" alt="User Avatar" class="user-avatar" />
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="chevron">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>
                            
                            <ul class="dropdown-menu dropdown-menu-end modern-dropdown" aria-labelledby="userDropdown">
                                <li class="dropdown-header">
                                    <div class="user-info">
                                        <div class="user-name">Admin User</div>
                                        <div class="user-email">admin@oumanjoga.com</div>
                                    </div>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <router-link to="/users/profile" class="dropdown-item">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="12" cy="7" r="4"></circle>
                                        </svg>
                                        <span>Profile</span>
                                    </router-link>
                                </li>
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
                                    <router-link to="/login" class="dropdown-item logout-item" @click="logout">
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

        <!-- BREADCRUMB HEADER -->
        <div class="breadcrumb-header">
            <div class="breadcrumb-content">
                <button 
                    class="sidebar-toggle-btn"
                    @click="$store.commit('toggleSideBar', !$store.state.is_show_sidebar)"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
                
                <div id="breadcrumb" class="breadcrumb-portal"></div>
            </div>
        </div>

        <!-- OVERLAY FOR MOBILE -->
        <div 
            class="header-overlay" 
            :class="{ show: !$store.state.is_show_sidebar }" 
            @click="$store.commit('toggleSideBar', !$store.state.is_show_sidebar)"
        ></div>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';

onMounted(() => {
    toggleMode();
});

const toggleMode = (mode) => {
    window.$appSetting.toggleMode(mode);
};

const logout = () => {
    localStorage.clear();
};
</script>

<style lang="scss" scoped>
// Modern Header Styles
.modern-header-container {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: var(--z-fixed);
    background: var(--header-bg);
    border-bottom: 1px solid var(--border-light);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-base);
}

.modern-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1.5rem;
    height: 70px;
    max-width: 100%;
    margin: 0 auto;
}

// Logo Section
.header-brand {
    display: flex;
    align-items: center;
    gap: 1rem;

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

// Header Actions
.header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.action-item {
    display: flex;
    align-items: center;
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

// Breadcrumb Header
.breadcrumb-header {
    position: fixed;
    top: 70px;
    left: 0;
    right: 0;
    z-index: calc(var(--z-fixed) - 1);
    background: var(--background);
    border-bottom: 1px solid var(--border-light);
    padding: 0.875rem 1.5rem;
    transition: all var(--transition-base);
}

.breadcrumb-content {
    display: flex;
    align-items: center;
    gap: 1rem;
    max-width: 100%;
    margin: 0 auto;
}

.sidebar-toggle-btn {
    display: flex;
    align-items: center;
    justify-content: center;
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
        display: none;
    }
}

.breadcrumb-portal {
    flex: 1;
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

// Adjust main content for fixed headers
:global(#content) {
    padding-top: 140px; // 70px top header + 70px breadcrumb header
}

// Dark mode adjustments
body.dark {
    .modern-header-container,
    .breadcrumb-header {
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

    .breadcrumb-header {
        padding: 0.75rem 1rem;
    }
}

@media (max-width: 576px) {
    .modern-header {
        height: 60px;
    }

    .breadcrumb-header {
        top: 60px;
    }

    :global(#content) {
        padding-top: 120px;
    }
}
</style>