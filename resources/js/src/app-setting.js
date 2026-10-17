import { pinia } from './stores';
import { useAppStore } from './stores/app';
import { $themeConfig } from '@themeConfig';

/**
 * UI-12: this previously also initialized an i18n locale (dead code —
 * it imported a '../i18n' module that didn't exist anywhere in the repo,
 * so any code path that actually invoked toggleLocale() would have
 * broken the production build with an unresolved-module error). No view
 * in the app ever rendered a language switcher against it, so per the
 * doc's "commit to i18n or remove it" decision: removed. Re-introduce a
 * real vue-i18n setup with actual translation dictionaries if/when
 * EN+SW support is prioritized, rather than resurrecting this scaffold.
 */
export default {
    init() {
        // set default styles
        let val = localStorage.getItem('dark_mode'); // light, dark, system
        if (!val) {
            val = $themeConfig.theme;
        }
        useAppStore(pinia).toggleDarkMode(val);

        val = localStorage.getItem('menu_style'); // vertical, collapsible-vertical, horizontal
        if (!val) {
            val = $themeConfig.navigation;
        }
        useAppStore(pinia).toggleMenuStyle(val);

        val = localStorage.getItem('layout_style'); // full, boxed-layout, large-boxed-layout
        if (!val) {
            val = $themeConfig.layout;
        }
        useAppStore(pinia).toggleLayoutStyle(val);
    },

    toggleMode(mode) {
        if (!mode) {
            let val = localStorage.getItem('dark_mode'); //light|dark|system
            mode = val;
            if (!val) {
                mode = 'light';
            }
        }
        useAppStore(pinia).toggleDarkMode(mode || 'light');
        return mode;
    },
};
