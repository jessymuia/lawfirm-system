import axios from '@/api';

/**
 * FUN-9: run a conflict check before taking on a client or opening a matter.
 *
 * Resolves with `{ proceed, checkId }`: send `checkId` as `conflict_check_id` (the API links the new record to it).
 * `proceed` is false when the person cancels or the intake must wait for a conflicts administrator. A clear check
 * proceeds without a dialog.
 * People without `run-conflict-checks` skip it here; the API still refuses them when the firm requires a check.
 */
const permissions = () => JSON.parse(localStorage.getItem('permissions') || '[]');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const KIND = {
    conflict: 'Possible conflict',
    related: 'Connected elsewhere',
    existing_client: 'Existing client',
    same_side: 'Already opposed on another matter',
};
const ROLE = { client: 'client', adverse: 'adverse party', co_party: 'co-party', related: 'related party', opposing_counsel: 'opposing counsel' };

export const describeHit = (h) => {
    const where = h.accessible
        ? (h.matter ? `matter ${esc(h.matter.case_number)}${h.matter.closed ? ' (closed)' : ''}` : 'client record')
        : 'a matter you do not have access to';
    const who = h.found.name ? `<b>${esc(h.found.name)}</b>` : 'a matching name';
    const how = h.reason === 'name' ? `${Math.round(h.score * 100)}% name match` : `same ${h.reason}`;

    return `<li><span class="kind kind-${h.kind}">${KIND[h.kind] || h.kind}</span> ${who}, ${ROLE[h.found.role] || h.found.role} on ${where} `
        + `<small>(${how} with “${esc(h.searched.name)}” as ${ROLE[h.searched.role] || h.searched.role})</small></li>`;
};

export const hitsHtml = (hits) => `<ul class="conflict-hits">${hits.map(describeHit).join('')}</ul>`;

export async function conflictGate(purpose, names, { phone = null, identifier = null } = {}) {
    const perms = permissions();
    if (!perms.includes('run-conflict-checks')) return { proceed: true, checkId: null };

    const { data } = await axios.post('/api/v1/conflicts/check', { purpose, names, phone, identifier });
    const check = data.data;
    if (check.outcome === 'clear') return { proceed: true, checkId: check.id };

    const Swal = window.Swal;
    const canDecide = perms.includes('manage-conflicts');
    const intro = `<p>The conflict check found ${check.hits.filter((h) => ['conflict', 'related'].includes(h.kind)).length} possible conflict(s). `
        + `Check #${check.id} is on the record.</p>`;

    if (!canDecide) {
        await Swal.fire({
            icon: 'warning', title: 'Possible conflict of interest', width: 640,
            html: `${intro}${hitsHtml(check.hits)}<p>A conflicts administrator must review check #${check.id} and record a decision before you can continue. `
                + 'Your form is kept; save it again once they have.</p>',
            confirmButtonText: 'Understood',
        });
        return { proceed: false, checkId: check.id };
    }

    const r = await Swal.fire({
        icon: 'warning', title: 'Possible conflict of interest', width: 640,
        html: `${intro}${hitsHtml(check.hits)}<p>To proceed, record why (consent obtained, screen in place, not the same person…).</p>`,
        input: 'textarea', inputPlaceholder: 'Reason for proceeding',
        inputValidator: (v) => (!v || v.trim().length < 10 ? 'Record why in a sentence or two.' : undefined),
        showCancelButton: true, confirmButtonText: 'Record decision and proceed', cancelButtonText: 'Cancel',
    });
    if (!r.isConfirmed) return { proceed: false, checkId: check.id };
    await axios.put(`/api/v1/conflicts/${check.id}/decision`, { decision: 'proceed', note: r.value.trim() });

    return { proceed: true, checkId: check.id };
}

/** Rejects with `{ cancelled: true }` when the intake should stop (so callers can skip their error toast). */
export const CANCELLED = Object.freeze({ cancelled: true });
