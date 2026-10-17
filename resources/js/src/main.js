import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import { pinia } from './stores';
const app = createApp(App);

// UI-13: the firm's colour, name and logo
import { loadBranding } from './branding';
loadBranding();

// bootstrap
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// modals
import '@/assets/sass/components/custom-modal.scss';
import { installModalPolish } from '@/utils/modal-polish';
import { wireRouter } from '@/utils/activity';

// perfect scrollbar
import PerfectScrollbar from 'vue3-perfect-scrollbar';
import 'vue3-perfect-scrollbar/dist/vue3-perfect-scrollbar.css';

//vue-meta
import { createHead } from '@vueuse/head';
const head = createHead();

//Sweetalert
import Swal from 'sweetalert2';
window.Swal = Swal;

// vue input mask
import Maska from 'maska';

// smooth scroll
import { registerScrollSpy } from 'vue3-scroll-spy/dist/index';
registerScrollSpy(app, { offset: 118 });

// datatables
import { ClientTable } from 'v-tables-3';

// set default settings
import appSetting from './app-setting';
window.$appSetting = appSetting;
window.$appSetting.init();

// FUN-19: the service worker (installable app, hearing-day views offline); the install prompt is kept for the Today page
if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || window.location.hostname === 'localhost')) {
    window.addEventListener('load', () => { navigator.serviceWorker.register('/sw.js').catch(() => {}); });
}
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); window.$installPrompt = e; window.dispatchEvent(new Event('pwa-installable')); });

wireRouter(router);
installModalPolish(router);
app.use(pinia).use(router).use(PerfectScrollbar).use(Maska).use(ClientTable).use(head).mount('#app');
