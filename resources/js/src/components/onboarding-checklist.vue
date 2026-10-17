<template>
    <section v-if="state && state.show" class="onboarding" data-testid="onboarding" aria-labelledby="onboarding-title">
        <header class="ob-head">
            <div class="ob-ring" :style="{ '--p': percent }" role="img" :aria-label="`${percent}% complete`">
                <span>{{ percent }}<small>%</small></span>
            </div>
            <div class="ob-intro">
                <h2 id="onboarding-title" class="ob-title">Getting started</h2>
                <p class="ob-sub">{{ doneCount }} of {{ steps.length }} steps done. {{ nextStep ? 'Next up: ' + nextStep.title.toLowerCase() + '.' : 'You are all set.' }}</p>
            </div>
            <button type="button" class="ob-hide" aria-label="Hide getting started" @click="dismiss">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
            </button>
        </header>

        <ol class="ob-steps">
            <li v-for="(s, i) in steps" :key="s.key" class="ob-step" :class="{ done: s.done, next: s.key === nextKey }" :data-testid="'onboarding-' + s.key">
                <component :is="s.done ? 'div' : 'router-link'" :to="s.done ? undefined : s.link" class="ob-card">
                    <span class="ob-icon" aria-hidden="true">
                        <svg v-if="s.done" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="icons[s.key] || icons.client"></svg>
                    </span>
                    <span class="ob-text">
                        <span class="ob-step-no">Step {{ i + 1 }}</span>
                        <span class="ob-step-title">{{ s.title }}</span>
                        <span class="ob-step-desc">{{ s.done ? 'Done' : (hints[s.key] || '') }}</span>
                    </span>
                    <span v-if="!s.done" class="ob-go" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                    </span>
                </component>
            </li>
        </ol>
    </section>
</template>

<script setup>
/**
 * PLAT-13: a new firm's first steps, in the order that leads to a first matter (client → matter → colleague →
 * two-factor). Progress comes from the firm's own data; steps the person may not do are left out. Hidden once
 * everything is done, or when the person hides it.
 */
import { computed, onMounted, ref } from 'vue';
import axios from '@/api';

const state = ref(null);
const steps = computed(() => (state.value ? state.value.steps.filter((s) => s.can) : []));
const doneCount = computed(() => steps.value.filter((s) => s.done).length);
const percent = computed(() => (steps.value.length ? Math.round((doneCount.value / steps.value.length) * 100) : 0));
const nextStep = computed(() => steps.value.find((s) => !s.done));
const nextKey = computed(() => nextStep.value?.key);

const hints = {
    client: 'Save who you act for, once.',
    matter: 'Open the case and link the client.',
    colleague: 'Give your team their own sign-in.',
    two_factor: 'Protect your account with a code.',
};
const icons = {
    client: '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    matter: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    colleague: '<path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    two_factor: '<path d="M12 3 4 6v6c0 4.5 3.2 8 8 9 4.8-1 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
};

onMounted(() => {
    axios.get('/api/v1/onboarding').then(({ data }) => {
        state.value = data.data;
    }).catch(() => {
        state.value = null;
    });
});

const dismiss = () => {
    state.value = { ...state.value, show: false };
    axios.post('/api/v1/onboarding/dismiss').catch(() => {});
};
</script>

<style lang="scss" scoped>
.onboarding {
    --ob-accent: var(--primary-600);
    position: relative;
    margin-bottom: 20px;
    padding: 22px 24px 24px;
    border: 1px solid color-mix(in srgb, var(--ob-accent) 14%, var(--border-light));
    border-radius: 18px;
    background:
        radial-gradient(520px 180px at 0% 0%, color-mix(in srgb, var(--ob-accent) 10%, transparent), transparent 70%),
        var(--card-bg);
    box-shadow: 0 1px 2px rgba(16, 24, 20, 0.04), 0 10px 30px rgba(16, 24, 20, 0.05);
}

.ob-head {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
}

.ob-ring {
    flex-shrink: 0;
    width: 58px;
    height: 58px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background:
        radial-gradient(closest-side, var(--card-bg) 76%, transparent 78% 100%),
        conic-gradient(var(--ob-accent) calc(var(--p) * 1%), color-mix(in srgb, var(--ob-accent) 12%, var(--neutral-200)) 0);
    transition: background 0.4s ease;

    span {
        font-size: 15px;
        font-weight: 800;
        color: var(--ob-accent);
        letter-spacing: -0.02em;

        small {
            font-size: 10px;
            font-weight: 700;
            margin-left: 1px;
        }
    }
}

.ob-intro { flex: 1; min-width: 0; }

.ob-title {
    margin: 0;
    font-size: 19px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text-primary);
}

.ob-sub {
    margin: 3px 0 0;
    font-size: 13.5px;
    color: var(--text-secondary);
}

.ob-hide {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;

    svg { width: 17px; height: 17px; }

    &:hover { background: var(--neutral-100); color: var(--text-secondary); }
}

.ob-steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 12px;
}

.ob-card {
    height: 100%;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 15px 15px 16px;
    border: 1px solid var(--border-light);
    border-radius: 14px;
    background: var(--card-bg);
    color: inherit;
    text-decoration: none;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}

a.ob-card:hover {
    transform: translateY(-2px);
    border-color: color-mix(in srgb, var(--ob-accent) 40%, var(--border-light));
    box-shadow: 0 8px 20px rgba(16, 24, 20, 0.08);
}

.ob-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: color-mix(in srgb, var(--ob-accent) 9%, var(--card-bg));
    color: var(--ob-accent);

    svg { width: 21px; height: 21px; }
}

.ob-text { display: flex; flex-direction: column; min-width: 0; flex: 1; }

.ob-step-no {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
}

.ob-step-title {
    margin-top: 2px;
    font-size: 14.5px;
    font-weight: 700;
    line-height: 1.3;
    color: var(--text-primary);
}

.ob-step-desc {
    margin-top: 4px;
    font-size: 12.5px;
    line-height: 1.4;
    color: var(--text-secondary);
}

.ob-go {
    align-self: center;
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--neutral-100);
    color: var(--text-tertiary);
    transition: background 0.15s, color 0.15s;

    svg { width: 15px; height: 15px; }
}

a.ob-card:hover .ob-go { background: var(--ob-accent); color: var(--text-inverse); }

.ob-step.next .ob-card {
    border-color: color-mix(in srgb, var(--ob-accent) 55%, var(--card-bg));
    background: linear-gradient(180deg, color-mix(in srgb, var(--ob-accent) 7%, var(--card-bg)), var(--card-bg));
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--ob-accent) 11%, transparent);

    .ob-icon { background: var(--ob-accent); color: var(--text-inverse); }
    .ob-step-no { color: var(--ob-accent); }
}

.ob-step.done .ob-card { background: var(--neutral-50); border-color: var(--border-light); }
.ob-step.done .ob-icon { background: var(--success-100); color: var(--success-700); }
.ob-step.done .ob-step-title { color: var(--text-muted); }
.ob-step.done .ob-step-desc { color: var(--success-700); font-weight: 600; }

@media (max-width: 560px) {
    .onboarding { padding: 18px 16px 18px; }
    .ob-steps { grid-template-columns: 1fr; }
}
</style>
