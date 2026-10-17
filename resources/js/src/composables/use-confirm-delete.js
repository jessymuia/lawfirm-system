import axios from '../api';
import { useToast } from './use-toast';
import { confirmAction } from './use-confirm';

/**
 * UI-8: delete buttons that silently did nothing (see DATA-7) eroded user
 * trust. This composable is the one shared implementation of
 * "confirm dialog -> DELETE request -> success/failure toast -> update
 * the list from the server's response" — every module's delete button
 * should call this rather than re-implementing the flow (and rather than
 * the plain browser confirm() some modules used before).
 *
 * Usage:
 *   const { confirmDelete } = useConfirmDelete();
 *   confirmDelete({
 *     url: `/api/v1/clients/${item.id}`,
 *     itemLabel: item.name,
 *     onSuccess: (data) => { rows.value = data; },
 *     // UI-24: optional — pass these two to get an "Undo" action on the
 *     // success toast. Omit both and behaviour is unchanged.
 *     restoreUrl: `/api/v1/clients/${item.id}/restore`,
 *     onRestore: () => refreshList(),
 *   });
 */
export function useConfirmDelete() {
    const { showMessage } = useToast();

    const confirmDelete = ({ url, itemLabel = 'this item', onSuccess, onError, restoreUrl, onRestore, warning = '' }) => {
        confirmAction({
            title: `Delete ${itemLabel}?`,
            text: restoreUrl
                ? `${itemLabel[0].toUpperCase()}${itemLabel.slice(1)} will be removed from your lists. You can undo this straight afterwards.`
                : `${itemLabel[0].toUpperCase()}${itemLabel.slice(1)} will be removed from your lists. An administrator can restore it if needed.`,
            ...(warning ? { text: `${warning}` } : {}),
            confirmText: 'Yes, delete',
            danger: true,
        }).then((ok) => {
            if (!ok) return;

            axios.delete(url)
                .then((response) => {
                    if (restoreUrl) {
                        // UI-24: soft-delete is already reversible server-side
                        // (see the module controller's restore()), so give the
                        // person a direct way back for the next 10 seconds
                        // instead of relying on an administrator later.
                        showMessage(`Deleted ${itemLabel}.`, 'success', {
                            actionLabel: 'Undo',
                            duration: 10000,
                            onAction: () => {
                                axios.post(restoreUrl)
                                    .then((restoreResponse) => {
                                        showMessage('Restored.');
                                        if (onRestore) onRestore(restoreResponse.data.data);
                                    })
                                    .catch(() => {
                                        showMessage('Could not restore. Please try again.', 'error');
                                    });
                            },
                        });
                    } else {
                        showMessage('Deleted successfully.');
                    }
                    if (onSuccess) onSuccess(response.data.data);
                })
                .catch((error) => {
                    const message = error.response?.data?.message || 'Error deleting. Please try again.';
                    showMessage(message, 'error');
                    if (onError) onError(error);
                });
        });
    };

    return { confirmDelete };
}
