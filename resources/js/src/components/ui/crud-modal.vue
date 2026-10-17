<template>
    <div class="modal fade" :id="modalId" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" :class="sizeClass" role="document">
            <div class="modal-content crud-modal-content">
                <div class="modal-header">
                    <slot name="header">
                        <h4 class="modal-title">{{ title }}</h4>
                    </slot>
                    <button
                        :id="`${modalId}-closebtn`"
                        type="button"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                        class="crud-close"
                    >&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="$emit('submit')">
                        <slot></slot>
                        <div class="crud-modal-actions">
                            <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn-submit" :disabled="submitting">
                                {{ submitting ? 'Saving...' : submitLabel }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useModalDirtyGuard } from '@/composables/use-modal-dirty-guard';

/**
 * §4 point 2 / UI-4: the shared "modal chrome" every create/edit form in
 * this app re-implements by hand (header, close button, form wrapper,
 * cancel/submit footer) — this doesn't replace each module's own fields
 * (those stay module-specific, passed in via the default slot), only the
 * repeated shell around them.
 *
 * Programmatic open/close still goes through Bootstrap's native data
 * attributes (data-bs-toggle="modal" data-bs-target="#<modalId>"), same
 * as every existing modal in the app, so this is a drop-in replacement
 * for a module's own <div class="modal">...</div> markup, not a new
 * interaction pattern to learn.
 *
 * Usage:
 *   <CrudModal modal-id="client_modal" :title="modal.title" :submit-label="modal.button"
 *              :submitting="submitting" @submit="submitForm">
 *     <div class="form-group">...</div>
 *   </CrudModal>
 */
const props = defineProps({
    modalId: { type: String, required: true },
    title: { type: String, default: '' },
    submitLabel: { type: String, default: 'Save' },
    submitting: { type: Boolean, default: false },
    size: { type: String, default: 'default' }, // 'default' | 'lg' | 'xl'
    // UI-15: pass a function returning a plain-object snapshot of the form
    // model (e.g. `() => ({ ...form.value })`). Omit it and the dirty-guard
    // simply stays inactive for that modal — a safe default for any modal
    // that isn't backing a form yet.
    formSnapshot: { type: Function, default: null },
});

const emit = defineEmits(['submit', 'discard']);

const sizeClass = computed(() => {
    if (props.size === 'lg') return 'modal-lg';
    if (props.size === 'xl') return 'modal-xl';
    return '';
});

// UI-15: same guard logic every other modal in the app uses (see
// use-modal-dirty-guard.js) — CrudModal doesn't duplicate the DOM
// wiring, it just adds an emit('discard') hook on top for callers that
// want to know when a discard happened.
const { clear: clearDirtyGuard } = useModalDirtyGuard(props.modalId, props.formSnapshot, () => emit('discard'));

// Bug fix (found via a real E2E run): a successful save needs to mark
// this modal's guard clean before it auto-closes — see
// list-clients.vue's identical raw-modal fix for the full explanation.
// Exposed so parent components (the ones passing form-snapshot) can
// call it from their own @submit success handler.
defineExpose({ clearDirtyGuard });
</script>

<style lang="scss" scoped>
.crud-modal-content {
    border-radius: var(--radius-lg);
    border: none;
    overflow: hidden;
    background: var(--card-bg);
}

.modal-header {
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: #fff;
    border-bottom: none;
    align-items: center;

    .modal-title {
        color: #fff;
        font-weight: var(--font-weight-bold);
        font-size: var(--font-size-base);
        // UI-31: at 360px a long title ("Update Case", plus whatever a
        // module passes via the #header slot) can otherwise push the
        // close button out of the viewport instead of truncating.
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }

    .crud-close {
        flex-shrink: 0;
        margin-left: auto;
        width: 36px;
        height: 36px;
        border: 1px solid rgba(255, 255, 255, .55);
        border-radius: 8px;
        background: rgba(255, 255, 255, .14);
        color: #fff;
        font-size: 24px;
        line-height: 1;
        cursor: pointer;
        &:hover { background: rgba(255, 255, 255, .28); }
    }
}

.crud-modal-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-lg);
    padding-top: var(--spacing-md);
    border-top: 1px solid var(--border-color);
}

.btn-cancel {
    padding: 10px 20px;
    background: var(--surface);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-weight: var(--font-weight-semibold);
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: background var(--transition-fast);

    &:hover {
        background: var(--neutral-100);
    }
}

.btn-submit {
    padding: 10px 24px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: var(--text-inverse);
    border: none;
    border-radius: var(--radius-md);
    font-weight: var(--font-weight-semibold);
    font-size: var(--font-size-sm);
    cursor: pointer;

    &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
}
</style>
