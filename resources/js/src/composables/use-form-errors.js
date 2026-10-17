import { reactive } from 'vue';

/**
 * UI-6: form errors were surfaced via a single generic toast ("Error
 * adding client. Please try again.") with no indication of which field
 * was wrong. Laravel already returns a structured error bag on 422
 * responses — { message, errors: { field: ['message', ...] } } — this
 * composable is the one place that gets parsed into per-field messages.
 *
 * Usage:
 *   const { errors, hasError, fieldError, setErrorsFromResponse, clearErrors } = useFormErrors();
 *   axios.post(...).catch(error => setErrorsFromResponse(error));
 *   <input :class="{ 'is-invalid': hasError('name') }" @input="clearErrors('name')" />
 *   <div class="invalid-feedback" v-if="hasError('name')">{{ fieldError('name') }}</div>
 */
export function useFormErrors() {
    const errors = reactive({});

    const hasError = (field) => Boolean(errors[field]?.length);
    const fieldError = (field) => errors[field]?.[0] ?? '';

    const clearErrors = (field) => {
        if (field) {
            delete errors[field];
        } else {
            Object.keys(errors).forEach((key) => delete errors[key]);
        }
    };

    /**
     * Populate `errors` from an axios error response. Returns the
     * top-level message (for a fallback toast) whether or not a field
     * error bag was present, so callers can always show *something*.
     */
    const setErrorsFromResponse = (error, fallbackMessage = 'Something went wrong. Please try again.') => {
        clearErrors();

        const responseData = error?.response?.data;
        if (responseData?.errors && typeof responseData.errors === 'object') {
            Object.entries(responseData.errors).forEach(([field, messages]) => {
                errors[field] = Array.isArray(messages) ? messages : [messages];
            });
        }

        return responseData?.message || fallbackMessage;
    };

    return { errors, hasError, fieldError, clearErrors, setErrorsFromResponse };
}
