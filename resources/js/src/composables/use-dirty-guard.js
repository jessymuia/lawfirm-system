/**
 * UI-15: forms inside CrudModal used to close silently on the X button,
 * backdrop click, Esc, or a route change, discarding unsaved edits with
 * no warning. This is the one shared "snapshot on open, compare on
 * close, confirm discard" implementation — CrudModal wires it in once
 * (see crud-modal.vue) so every module inherits it just by passing a
 * `form-snapshot` getter.
 *
 * A snapshot is taken when the modal opens (`snapshot()`), and `isDirty()`
 * compares the current form state against it via a JSON string diff —
 * simple, dependency-free, and fine for the plain-object form models
 * every module already uses.
 */
export function useDirtyGuard(getFormState) {
    let baseline = null;

    const snapshot = () => {
        baseline = getFormState ? JSON.stringify(getFormState()) : null;
    };

    const clear = () => {
        baseline = null;
    };

    const isDirty = () => {
        if (baseline === null || !getFormState) return false;
        return JSON.stringify(getFormState()) !== baseline;
    };

    const confirmDiscard = () =>
        new Promise((resolve) => {
            if (!isDirty()) {
                resolve(true);
                return;
            }

            window.Swal.fire({
                title: 'Discard unsaved changes?',
                text: 'You have unsaved edits. Closing now will lose them.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Discard',
                cancelButtonText: 'Keep editing',
                confirmButtonColor: 'var(--color-danger)',
                reverseButtons: true,
            }).then((result) => resolve(result.isConfirmed));
        });

    return { snapshot, clear, isDirty, confirmDiscard };
}
