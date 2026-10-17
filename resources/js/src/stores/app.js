import { defineStore } from 'pinia';

/**
 * ENG-14 (was the Vuex store): layout and theme state only. Server data lives with the views that load it, not here.
 */
export const useAppStore = defineStore('app', {
    state: () => ({
        layout: 'app',
        is_show_sidebar: true,
        is_show_search: false,
        is_dark_mode: false,
        dark_mode: 'light',
        menu_style: 'vertical',
        layout_style: 'full',
    }),
    actions: {
        setLayout(value) {
            this.layout = value;
        },
        toggleSideBar(value) {
            this.is_show_sidebar = value;
        },
        toggleSearch(value) {
            this.is_show_search = value;
        },
        /** light | dark | system */
        toggleDarkMode(value) {
            const mode = value || 'light';
            localStorage.setItem('dark_mode', mode);
            this.dark_mode = mode;
            if (mode === 'system') {
                this.is_dark_mode = Boolean(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            } else {
                this.is_dark_mode = mode === 'dark';
            }
            document.querySelector('body').classList.toggle('dark', this.is_dark_mode);
        },
        /** horizontal | vertical | collapsible-vertical */
        toggleMenuStyle(value) {
            const style = value || '';
            localStorage.setItem('menu_style', style);
            this.menu_style = style;
            if (!style || style === 'vertical') {
                this.is_show_sidebar = true;
            } else if (style === 'collapsible-vertical') {
                this.is_show_sidebar = false;
            }
        },
        /** boxed-layout | large-boxed-layout | full */
        toggleLayoutStyle(value) {
            const style = value || '';
            localStorage.setItem('layout_style', style);
            this.layout_style = style;
        },
    },
});
