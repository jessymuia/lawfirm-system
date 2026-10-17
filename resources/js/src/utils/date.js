/**
 * UI-7: previously every module juggled 'd/m/Y' strings by hand — masked
 * text inputs, manual Carbon::createFromFormat calls on the backend, no
 * shared parsing/validation. This is the one place date conversion
 * happens on the frontend.
 *
 * The wire format stays 'd/m/Y' for now (matching every existing backend
 * validation rule and Carbon::createFromFormat call) rather than
 * switching to ISO — that's a larger, riskier change better done with
 * test coverage in place (see the migration plan's own §3 testing
 * strategy) than as a blind find-and-replace across every controller.
 * What this fixes today: one real date-picker component, one date utility,
 * used everywhere instead of N slightly-different maska-masked text inputs.
 */

const WIRE_FORMAT = 'd/m/Y';

/** Parse a 'd/m/Y' string into a JS Date, or null if invalid/empty. */
export function parseWireDate(value) {
    if (!value) return null;
    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
    if (!match) return null;

    const [, day, month, year] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day));

    // Reject e.g. 31/02/2026 — JS Date silently rolls invalid days into
    // the next month, so we verify the round-trip matches.
    if (date.getFullYear() !== Number(year) || date.getMonth() !== Number(month) - 1 || date.getDate() !== Number(day)) {
        return null;
    }

    return date;
}

/** Format a JS Date (or flatpickr's array-of-one-date) as 'd/m/Y'. */
export function formatWireDate(date) {
    const d = Array.isArray(date) ? date[0] : date;
    if (!(d instanceof Date) || isNaN(d)) return '';

    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${day}/${month}/${year}`;
}

/** Human-friendly display, e.g. "3 Jun 2026", from a 'd/m/Y' wire string. */
export function displayDate(wireValue) {
    const date = parseWireDate(wireValue);
    if (!date) return wireValue || '';
    return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

export const wireFormat = WIRE_FORMAT;
