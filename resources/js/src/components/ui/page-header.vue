<template>
    <header class="lms-page-header">
        <span class="lms-ph-icon" aria-hidden="true"><slot name="icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="icon" /></svg></slot></span>
        <div class="lms-ph-text">
            <h1>{{ title }}</h1>
            <p v-if="subtitle">{{ subtitle }}</p>
        </div>
        <div class="lms-ph-actions"><slot /></div>
    </header>
</template>

<script setup>
/** The title block every settings-style page starts with: icon, title, one line of help, and optional actions. */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: 'M4 6h16M4 12h16M4 18h10' },   // an SVG path
});
</script>

<style lang="scss">
.lms-page-header { display: flex; align-items: center; gap: 16px; background: #fff; border: 1px solid var(--border-light, #e3ebe6); border-radius: 16px; padding: 18px 22px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(16, 33, 27, .04); }
.lms-ph-icon { width: 46px; height: 46px; flex-shrink: 0; border-radius: 13px; display: grid; place-items: center; color: #fff; background: linear-gradient(145deg, var(--primary-500, #148a67), var(--primary-700, #0a5a43)); box-shadow: 0 6px 16px rgba(14, 107, 80, .25); }
.lms-ph-text { min-width: 0; flex: 1; }
.lms-ph-text h1 { font-family: inherit !important; margin: 0; font-size: 1.35rem; font-weight: 700; letter-spacing: -.01em; color: var(--text-primary, #16211d); }
.lms-ph-text p { margin: 2px 0 0; color: #5b6b64; font-size: .92rem; }
.lms-ph-actions { display: flex; gap: 8px; flex-wrap: wrap; }
@media (max-width: 600px) { .lms-page-header { flex-wrap: wrap; } }
</style>
