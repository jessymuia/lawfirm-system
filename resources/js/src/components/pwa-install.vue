<template>
    <transition name="pwa">
        <div v-if="visible" class="pwa-fab" role="dialog" aria-label="Install app" data-testid="pwa-install">
            <template v-if="!expanded">
                <button type="button" class="pwa-main" @click="onInstall">
                    <span class="pwa-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M5 21h14" /></svg>
                    </span>
                    <span class="pwa-text"><strong>Install app</strong><small>Faster access, works offline</small></span>
                </button>
                <button type="button" class="pwa-close" aria-label="Dismiss" @click="dismiss">&times;</button>
            </template>
            <div v-else class="pwa-help">
                <p><strong>Install on iPhone / iPad</strong></p>
                <p>Tap <span class="pwa-share" aria-label="Share">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3" /><path d="m8 7 4-4 4 4" /><path d="M5 11v9h14v-9" /></svg>
                </span> Share, then <strong>Add to Home Screen</strong>.</p>
                <button type="button" class="pwa-ok" @click="dismiss">Got it</button>
            </div>
        </div>
    </transition>
</template>

<script setup>
/** Floating "Install app" button (PWA). Shown on every page until installed or dismissed (remembered for 7 days). */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAppStore } from '@/stores/app';

const app = useAppStore();

const KEY = 'pwa-install-dismissed';
const DAYS = 7;
const deferred = ref(window.$installPrompt || null);
const installed = ref(false);
const dismissed = ref(false);
const expanded = ref(false);

const standalone = () => window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

try {
    const at = Number(localStorage.getItem(KEY) || 0);
    dismissed.value = at > 0 && Date.now() - at < DAYS * 86400000;
} catch { /* storage unavailable: show every time */ }

const drawerOpen = () => app.is_show_sidebar && window.innerWidth < 992;
const visible = computed(() => !drawerOpen() && !installed.value && !dismissed.value && !standalone() && (deferred.value || isIos));

const onReady = () => { deferred.value = window.$installPrompt || null; };
const onInstalled = () => { installed.value = true; window.$installPrompt = null; };

const onInstall = async () => {
    if (deferred.value) {
        deferred.value.prompt();
        try { await deferred.value.userChoice; } catch { /* ignore */ }
        deferred.value = null;
        window.$installPrompt = null;
    } else {
        expanded.value = true;
    }
};
const dismiss = () => {
    dismissed.value = true;
    expanded.value = false;
    try { localStorage.setItem(KEY, String(Date.now())); } catch { /* ignore */ }
};

onMounted(() => {
    window.addEventListener('pwa-installable', onReady);
    window.addEventListener('appinstalled', onInstalled);
});
onBeforeUnmount(() => {
    window.removeEventListener('pwa-installable', onReady);
    window.removeEventListener('appinstalled', onInstalled);
});
</script>

<style lang="scss" scoped>
.pwa-fab {
    position: fixed;
    right: 16px;
    bottom: calc(16px + env(safe-area-inset-bottom, 0px));
    z-index: 1080;
    display: flex;
    align-items: center;
    max-width: calc(100vw - 32px);
    background: var(--primary-800);
    color: var(--text-inverse);
    border-radius: 999px;
    box-shadow: 0 10px 30px rgba(15, 52, 96, .35), 0 2px 6px rgba(0, 0, 0, .15);
}
.pwa-main {
    display: flex; align-items: center; gap: 10px;
    background: none; border: 0; color: inherit; cursor: pointer;
    padding: 10px 6px 10px 12px; text-align: left; min-width: 0;
}
.pwa-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 50%; background: rgba(255, 255, 255, .16); flex: none;
}
.pwa-text { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
.pwa-text strong { font-size: 14px; font-weight: 600; }
.pwa-text small { font-size: 11.5px; opacity: .8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pwa-close {
    background: none; border: 0; color: inherit; font-size: 22px; line-height: 1; cursor: pointer;
    width: 40px; height: 44px; opacity: .75; flex: none;
}
.pwa-close:hover { opacity: 1; }
.pwa-help { padding: 14px 18px; border-radius: 18px; max-width: 280px; }
.pwa-help p { margin: 0 0 6px; font-size: 13.5px; line-height: 1.4; }
.pwa-share { display: inline-flex; vertical-align: middle; }
.pwa-ok { margin-top: 6px; background: var(--card-bg); color: var(--primary-800); border: 0; border-radius: 999px; padding: 6px 16px; font-weight: 600; font-size: 13px; cursor: pointer; }
.pwa-enter-active, .pwa-leave-active { transition: opacity .25s ease, transform .25s ease; }
.pwa-enter-from, .pwa-leave-to { opacity: 0; transform: translateY(16px); }
@media (max-width: 480px) {
    .pwa-fab { right: 12px; left: 12px; bottom: calc(12px + env(safe-area-inset-bottom, 0px)); max-width: none; justify-content: space-between; border-radius: 16px; }
    .pwa-main { flex: 1; }
}
@media print { .pwa-fab { display: none; } }
</style>
