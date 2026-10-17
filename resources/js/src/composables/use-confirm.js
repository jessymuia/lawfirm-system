/**
 * One confirmation dialog for the whole app, so every risky action is asked the same clear way:
 * what will happen, to what, whether it can be undone, and a button that names the action.
 *
 *   if (!(await confirmAction({ title: 'Remove this party?', text: 'Kamau Ltd will be removed from the matter.', confirmText: 'Remove', danger: true }))) return;
 *   const reason = await confirmAction({ title: 'Release the hold?', input: 'Reason', confirmText: 'Release hold' });   // resolves to the typed text
 *   if (reason === false) return;
 *
 * Resolves to `true` (or the typed text when `input` is set), or `false` when cancelled.
 */
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/**
 * @param {{ title?: string, text?: string, confirmText?: string, cancelText?: string, danger?: boolean, icon?: string|null,
 *           input?: string|null, inputPlaceholder?: string, inputType?: string, typeToConfirm?: string|null, irreversible?: boolean }} [options]
 * @returns {Promise<boolean|string>}
 */
export async function confirmAction({
    title = 'Are you sure?',
    text = '',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    danger = false,
    icon = null,                 // 'warning' | 'question' | 'info' | 'success' | 'error'
    input = null,                // a label: asks for a typed answer (required)
    inputPlaceholder = '',
    inputType = 'textarea',
    typeToConfirm = null,        // a word the person must type exactly (for irreversible actions)
    irreversible = false,
} = {}) {
    const Swal = window.Swal;
    if (!Swal) return window.confirm(`${title}\n\n${text}`);

    const note = irreversible ? '<p class="confirm-note">This cannot be undone.</p>' : '';
    const wantsInput = input || typeToConfirm;
    const result = await Swal.fire({
        title,
        html: `${text ? `<p class="confirm-text">${esc(text)}</p>` : ''}${note}`,
        icon: icon || (danger ? 'warning' : 'question'),
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: danger,
        buttonsStyling: false,
        customClass: {
            popup: 'confirm-popup',
            confirmButton: danger ? 'confirm-btn confirm-danger' : 'confirm-btn confirm-primary',
            cancelButton: 'confirm-btn confirm-cancel',
            input: 'confirm-input',
        },
        ...(wantsInput ? {
            input: typeToConfirm ? 'text' : inputType,
            inputLabel: typeToConfirm ? `Type "${typeToConfirm}" to confirm` : input,
            inputPlaceholder,
            inputValidator: (v) => {
                const value = String(v || '').trim();
                if (typeToConfirm) return value === typeToConfirm ? null : `Type ${typeToConfirm} exactly to continue.`;
                return value.length >= 3 ? null : 'Please give a short reason (at least 3 characters).';
            },
        } : {}),
    });
    if (!result.isConfirmed) return false;
    return wantsInput ? String(result.value || '').trim() : true;
}
