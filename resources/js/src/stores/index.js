import { createPinia } from 'pinia';

/**
 * ENG-14: the app's Pinia instance. Exported so code that runs outside components (router guards, app-setting at
 * start-up, before the app is mounted) can use a store: `useAppStore(pinia)`.
 */
export const pinia = createPinia();
