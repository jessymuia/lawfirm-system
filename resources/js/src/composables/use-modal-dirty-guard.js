import { onMounted, onBeforeUnmount } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { useDirtyGuard } from './use-dirty-guard';

/**
 * UI-15: attaches the dirty-form guard to any Bootstrap modal by id —
 * used by crud-modal.vue for modals built on the shared shell, and
 * called directly by modules that still use their own hand-rolled
 * `<div class="modal">` markup (the majority of this app's add/edit
 * modals today — see clients/cases/hearings/tasks/expenses/invoices
 * list views). Either way the behavior is identical: snapshot the form
 * when the modal opens, and confirm before discarding on close (X,
 * backdrop, Esc) or on a route change while it's open and dirty.
 *
 * Usage (in a module's <script setup>, no CrudModal required):
 *   useModalDirtyGuard('client_modal', () => ({ ...client.value }));
 */
export function useModalDirtyGuard(modalId, getFormState, onDiscard) {
    const { snapshot, clear, confirmDiscard } = useDirtyGuard(getFormState);

    let modalEl = null;
    let bypassNextHide = false;

    const onShown = () => snapshot();

    const onHide = (event) => {
        if (!getFormState || bypassNextHide) {
            bypassNextHide = false;
            return;
        }

        // Bootstrap's hide.bs.modal isn't natively cancelable in a way we
        // can await, so we stop this hide, ask, and re-trigger it
        // ourselves if the person confirms discarding their edits.
        event.preventDefault();
        confirmDiscard().then((confirmed) => {
            if (!confirmed) return;
            bypassNextHide = true;
            clear();
            if (onDiscard) onDiscard();
            window.bootstrap?.Modal.getOrCreateInstance(modalEl).hide();
        });
    };

    onMounted(() => {
        modalEl = document.getElementById(modalId);
        if (!modalEl) return;
        modalEl.addEventListener('shown.bs.modal', onShown);
        modalEl.addEventListener('hide.bs.modal', onHide);
    });

    onBeforeUnmount(() => {
        if (!modalEl) return;
        modalEl.removeEventListener('shown.bs.modal', onShown);
        modalEl.removeEventListener('hide.bs.modal', onHide);
    });

    onBeforeRouteLeave(() => {
        if (!modalEl?.classList.contains('show')) return true;

        return confirmDiscard().then((confirmed) => {
            if (confirmed) clear();
            return confirmed;
        });
    });

    return { clear };
}
