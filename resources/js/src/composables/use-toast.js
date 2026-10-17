/**
 * UI-4: every module previously duplicated its own showMessage() Swal-toast
 * helper. This is the single shared version — same behaviour, one place
 * to change it.
 */
export function useToast() {
    /**
     * UI-24: `options.actionLabel` (e.g. "Undo") adds a button to the
     * toast; `options.onAction` runs if it's clicked before the toast's
     * `options.duration` (default 3000ms, use a longer one — 10000ms —
     * for anything undoable) auto-dismisses. Existing calls with no
     * options are unaffected — this is purely additive.
     */
    const showMessage = (msg = '', type = 'success', options = {}) => {
        const { actionLabel = '', onAction = null, duration = 3000 } = options;

        const toast = window.Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: !!actionLabel,
            confirmButtonText: actionLabel,
            timer: duration,
            timerProgressBar: true,
        });
        toast.fire({
            icon: type,
            title: msg,
            padding: '12px 20px',
        }).then((result) => {
            if (result.isConfirmed && onAction) onAction();
        });
    };

    return { showMessage };
}
