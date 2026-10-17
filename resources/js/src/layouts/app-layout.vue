 <template>
    <div class="app-wrapper">
        <!-- UI-33: skip-link — first focusable element on the page,
             visually hidden until focused, jumps keyboard/screen-reader
             users straight past the header/sidebar to the actual page
             content. -->
        <a href="#content" class="skip-link">Skip to main content</a>

        <!-- Header -->
        <Header></Header>

        <!-- Main Container -->
        <div
            class="main-container"
            id="container"
            :class="[!app.is_show_sidebar ? 'sidebar-closed' : '', app.menu_style === 'collapsible-vertical' ? 'collapsible-vertical-mobile' : '']"
        >
            <!-- Overlay for mobile -->
            <div 
                class="sidebar-overlay" 
                :class="{ show: app.is_show_sidebar }" 
                @click="app.toggleSideBar(!app.is_show_sidebar)"
            ></div>

            <!-- Sidebar -->
            <Sidebar></Sidebar>

            <!-- Content Area -->
            <main id="content" class="main-content" tabindex="-1">
                <div class="content-wrapper">
                    <AppBreadcrumbs />
                    <AccountNotice />
                    <router-view />
                </div>

                <!-- Footer -->
                <Footer></Footer>
            </main>
        </div>

        <!-- UI-9: global search palette, Ctrl/Cmd-K -->
        <global-search ref="globalSearchRef" />
    </div>
</template>

<script setup>
import { useAppStore } from '@/stores/app';

const app = useAppStore();   // ENG-14: layout state (Pinia)
import { ref, provide, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import AccountNotice from '@/components/account-notice.vue';
import Header from '@/components/layout/header.vue';
import Sidebar from '@/components/layout/sidebar.vue';
import Footer from '@/components/layout/footer.vue';
import GlobalSearch from '@/components/global-search.vue';
import AppBreadcrumbs from '@/components/app-breadcrumbs.vue';

// Phones and tablets (< 992px): the menu is a drawer. It starts closed, closes when a page is opened, and closes when the
// window grows back to desktop width so the desktop sidebar is shown again.
const route = useRoute();
const narrow = window.matchMedia('(max-width: 991px)');
const closeOnNarrow = () => { if (narrow.matches) app.toggleSideBar(false); };
const onBreakpoint = (e) => app.toggleSideBar(!e.matches);
onMounted(() => { closeOnNarrow(); narrow.addEventListener('change', onBreakpoint); });
onBeforeUnmount(() => narrow.removeEventListener('change', onBreakpoint));
watch(() => route.fullPath, closeOnNarrow);

const globalSearchRef = ref(null);
provide('openGlobalSearch', () => globalSearchRef.value?.open());
</script>

<style lang="scss" scoped>
.skip-link {
    position: absolute;
    top: -60px;
    left: 8px;
    z-index: 2000;
    padding: 10px 16px;
    background: var(--primary-600);
    color: var(--text-inverse);
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: top 0.15s ease;

    &:focus {
        top: 8px;
    }
}

.app-wrapper {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--background);
}

.main-container {
    display: flex;
    flex: 1;
    position: relative;
    
    @media (max-width: 576px) {
        margin-top: 10px;
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
    min-height: calc(100vh - 70px);
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
        min-height: calc(100vh - 60px);
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
