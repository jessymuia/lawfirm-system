<template>
    <div class="app-wrapper">
        <!-- Header -->
        <Header></Header>

        <!-- Main Container -->
        <div
            class="main-container"
            id="container"
            :class="[!$store.state.is_show_sidebar ? 'sidebar-closed' : '', $store.state.menu_style === 'collapsible-vertical' ? 'collapsible-vertical-mobile' : '']"
        >
            <!-- Overlay for mobile -->
            <div 
                class="sidebar-overlay" 
                :class="{ show: !$store.state.is_show_sidebar }" 
                @click="$store.commit('toggleSideBar', !$store.state.is_show_sidebar)"
            ></div>

            <!-- Sidebar -->
            <Sidebar></Sidebar>

            <!-- Content Area -->
            <div id="content" class="main-content">
                <div class="content-wrapper">
                    <router-view />
                </div>

                <!-- Footer -->
                <Footer></Footer>
            </div>

            <!-- App Settings -->
            <app-settings />
        </div>
    </div>
</template>

<script setup>
import Header from '@/components/layout/header.vue';
import Sidebar from '@/components/layout/sidebar.vue';
import Footer from '@/components/layout/footer.vue';
import appSettings from '@/components/app-settings.vue';
</script>

<style lang="scss" scoped>
.app-wrapper {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--background);
}

.main-container {
    display: flex;
    flex: 1;
    margin-top: 140px; // Header (70px) + Breadcrumb (70px)
    position: relative;
    
    @media (max-width: 576px) {
        margin-top: 120px; // Header (60px) + Breadcrumb (60px)
    }
}

// Sidebar overlay for mobile
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 99;
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

// Main content area
#content {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 140px);
    transition: margin-left var(--transition-base);
    
    // Desktop: push content when sidebar is visible
    @media (min-width: 992px) {
        margin-left: 260px;
        
        .sidebar-closed & {
            margin-left: 0;
        }
    }
    
    // Mobile: no margin, sidebar overlays
    @media (max-width: 991px) {
        margin-left: 0;
    }

    @media (max-width: 576px) {
        min-height: calc(100vh - 120px);
    }
}

.content-wrapper {
    flex: 1;
    padding: 0;
}

// Ensure proper z-index layering
:deep(.modern-header-container) {
    z-index: 1000;
}

:deep(.breadcrumb-header) {
    z-index: 999;
}

:deep(.modern-sidebar-wrapper) {
    z-index: 100;
}

// Fix for collapsible vertical mode
.collapsible-vertical-mobile {
    @media (max-width: 991px) {
        #content {
            margin-left: 0;
            width: 100%;
        }
    }
}
</style>

<style lang="scss">
// Global styles for layout
body {
    margin: 0;
    padding: 0;
    overflow-x: hidden;
}

// Ensure sidebar transitions work properly
.modern-sidebar-wrapper {
    position: fixed;
    top: 70px;
    left: 0;
    bottom: 0;
    width: 260px;
    z-index: 100;
    transition: transform var(--transition-base);

    // Desktop: always visible unless closed
    @media (min-width: 992px) {
        transform: translateX(0);
        
        .sidebar-closed & {
            transform: translateX(-260px);
        }
    }

    // Mobile: hidden by default, show when toggled
    @media (max-width: 991px) {
        transform: translateX(-260px);
        
        .main-container:not(.sidebar-closed) & {
            transform: translateX(0);
        }
    }

    @media (max-width: 576px) {
        top: 60px;
    }
}

// Remove old overlay styles if they exist
.overlay,
.search-overlay {
    display: none !important;
}
</style>
<style lang="scss">
/* Force full width - no centering */
body {
    margin: 0 !important;
    padding: 0 !important;
}

#content,
.main-content,
.content-wrapper {
    max-width: none !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}

/* Only the main-content should respect sidebar */
#content {
    @media (min-width: 992px) {
        margin-left: 260px !important;
    }
}
</style>
