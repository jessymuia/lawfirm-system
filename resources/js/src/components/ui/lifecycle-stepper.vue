<template>
    <div class="lifecycle-stepper">
        <div class="stepper-track">
            <span
                v-for="state in allStates"
                :key="state"
                class="stepper-node"
                :class="{ current: state === currentStatus, past: isPast(state) }"
            >
                {{ label(state) }}
            </span>
        </div>

        <div v-if="nextStates.length" class="stepper-actions">
            <span class="stepper-actions-label">Move to:</span>
            <button
                v-for="next in nextStates"
                :key="next"
                type="button"
                class="btn-transition"
                :disabled="!canTransition || transitioning"
                :title="!canTransition ? 'You do not have permission to change this case\'s status' : ''"
                @click="$emit('transition', next)"
            >
                {{ label(next) }}
            </button>
        </div>
        <p v-else class="stepper-terminal">This is a terminal status — no further transitions.</p>
    </div>
</template>

<script setup>
/**
 * UI-10/UI-12: renders the case lifecycle as a stepper (current state,
 * plus every state it's allowed to move to next) driven entirely by the
 * backend's own transitions (see App\Enums\MatterStatus::next(), served as lifecycle_transitions) —
 * the frontend never hardcodes which transitions are legal, it only
 * renders whatever `transitions` it's given and lets the server have
 * the final say (CasesController::transitionStatus re-validates
 * canTransitionTo() regardless of what button was clickable here).
 *
 * `canTransition` gates the buttons on UPDATE_CASES permission — a user
 * without it still sees the stepper (useful context), just can't act on
 * it, with a title tooltip explaining why.
 */
const props = defineProps({
    currentStatus: { type: String, required: true },
    // The full transitions map, e.g. { open: ['closed','appeal','settled'], ... } —
    // passed down so this component has zero business-rule knowledge of
    // its own; it only walks whatever graph it's given.
    transitions: { type: Object, required: true },
    canTransition: { type: Boolean, default: true },
    transitioning: { type: Boolean, default: false },
});

defineEmits(['transition']);

const allStates = Object.keys(props.transitions);
const nextStates = props.transitions[props.currentStatus] ?? [];

const isPast = (state) => allStates.indexOf(state) < allStates.indexOf(props.currentStatus);

const label = (state) => state.charAt(0).toUpperCase() + state.slice(1);
</script>

<style scoped>
.lifecycle-stepper {
    margin-top: 8px;
}

.stepper-track {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.stepper-node {
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: var(--surface, #f1f5f9);
    color: var(--text-tertiary, #94a3b8);
    border: 1px solid var(--border-color, #e2e8f0);
}

.stepper-node.past {
    color: var(--text-secondary, #64748b);
}

.stepper-node.current {
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: #fff;
    border-color: transparent;
}

.stepper-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.stepper-actions-label {
    font-size: 12px;
    color: var(--text-tertiary, #94a3b8);
}

.btn-transition {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--border-color, #e2e8f0);
    background: var(--card-bg, #fff);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;

    &:hover:not(:disabled) {
        border-color: var(--primary-600);
        color: var(--primary-600);
    }

    &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
}

.stepper-terminal {
    font-size: 12px;
    color: var(--text-tertiary, #94a3b8);
    margin: 0;
}
</style>
