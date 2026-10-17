import axios from '../api';
import { useToast } from './use-toast';

/**
 * FUN-4: server-side exports need the session cookie (SEC-8's Sanctum SPA
 * auth), so a plain `<a href="/api/v1/export/...">` won't authenticate —
 * the browser navigating directly to that URL doesn't carry the same
 * credentials axios attaches. This composable fetches the file via axios
 * (blob response) and then triggers a client-side download from the blob.
 */
export function useFileDownload() {
    const { showMessage } = useToast();

    const downloadFile = (url, filename, params = {}) => {
        return axios.get(url, { params, responseType: 'blob' })
            .then(async (response) => {
                const type = response.headers?.['content-type'] || response.data?.type || '';
                // a JSON body here is an error the server dressed as success; and a download must not be empty
                if (!response.data || response.data.size === 0 || /json/i.test(type)) {
                    let msg = 'The file came back empty. Please try again.';
                    try { msg = JSON.parse(await response.data.text()).message || msg; } catch (e) { /* keep default */ }
                    showMessage(msg, 'error');
                    return;
                }
                const blob = new Blob([response.data], { type: type.split(';')[0] || undefined });
                const blobUrl = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = blobUrl;
                link.setAttribute('download', filename);
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(blobUrl);
            })
            .catch(async (error) => {
                // Error responses are JSON, but since we requested a blob,
                // axios hands back a Blob even for the error body — parse
                // it back to text/JSON to surface a real message.
                let message = 'Error generating the export. Please try again.';
                if (error.response?.data instanceof Blob) {
                    try {
                        const text = await error.response.data.text();
                        const parsed = JSON.parse(text);
                        message = parsed.message || message;
                    } catch (e) {
                        // fall through to the default message
                    }
                }
                showMessage(message, 'error');
            });
    };

    return { downloadFile };
}
