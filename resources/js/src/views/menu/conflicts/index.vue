<template>
    <div class="conflicts-page">
        <PageHeader title="Conflict checks" subtitle="Search names across clients, matters and parties before taking on work." icon="M12 9v4M12 17h.01M10.3 3.9L2 18a2 2 0 001.7 3h16.6A2 2 0 0022 18L13.7 3.9a2 2 0 00-3.4 0z" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item active" aria-current="page"><span>Conflicts</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="elevated-card" data-testid="conflict-search">
            <div class="card-header"><h2 class="card-title">Check for conflicts</h2></div>
            <div class="card-body">
                <p class="hint">
                    Search every client and every matter's parties, closed matters included. Clients and matters opened
                    through the forms are checked automatically; use this for a quick look before a meeting.
                </p>
                <form @submit.prevent="search">
                    <div v-for="(n, i) in names" :key="i" class="name-row">
                        <input v-model="n.name" class="form-control" placeholder="Name of person or company" :aria-label="'Name ' + (i + 1)" />
                        <select v-model="n.role" class="form-control" :aria-label="'Role ' + (i + 1)">
                            <option value="client">Would be our client</option>
                            <option value="adverse">Would be against us</option>
                            <option value="related">Related / co-party</option>
                        </select>
                        <button v-if="names.length > 1" type="button" class="btn btn-sm btn-outline-secondary" @click="names.splice(i, 1)">Remove</button>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-outline-primary" @click="names.push({ name: '', role: 'adverse' })">Add a name</button>
                        <button type="submit" class="btn btn-primary" :disabled="busy || !names.some((n) => n.name.trim().length >= 2)">Search</button>
                    </div>
                </form>
                <div v-if="result" class="result" data-testid="conflict-result">
                    <p :class="result.outcome === 'clear' ? 'ok' : 'warn'">
                        {{ result.outcome === 'clear' ? 'No conflicts found.' : 'Possible conflicts found.' }} Check #{{ result.id }} is on the record.
                    </p>
                    <div v-if="result.hits.length" v-html="hitsHtml(result.hits)"></div>
                </div>
            </div>
        </div>

        <template v-if="canManage">
            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Policy</h2></div>
                <div class="card-body">
                    <label class="toggle-row">
                        <input type="checkbox" :checked="policy.required" :disabled="savingPolicy" @change="setPolicy($event.target.checked)" />
                        <span>Require a cleared conflict check before a client or matter can be opened (also through the API)</span>
                    </label>
                </div>
            </div>

            <div class="elevated-card" data-testid="conflict-register">
                <div class="card-header">
                    <h2 class="card-title">Register</h2>
                    <label class="toggle-row inline"><input v-model="undecidedOnly" type="checkbox" @change="loadRegister(1)" /> <span>Waiting for a decision</span></label>
                </div>
                <div class="card-body">
                    <table v-if="register.data && register.data.length" class="table">
                        <thead><tr><th>#</th><th>When</th><th>By</th><th>Names</th><th>Outcome</th><th>Decision</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="c in register.data" :key="c.id">
                                <td>{{ c.id }}</td>
                                <td>{{ new Date(c.created_at).toLocaleString() }}</td>
                                <td>{{ c.created_by_name }}</td>
                                <td>{{ (c.query.names || []).map((n) => n.name).join(', ') }}</td>
                                <td :class="c.outcome === 'clear' ? 'ok' : 'warn'">{{ c.outcome }}</td>
                                <td>
                                    <span v-if="c.decision">{{ c.decision }} by {{ c.decided_by_name }}<br><small>{{ c.decision_note }}</small></span>
                                    <span v-else-if="c.outcome === 'potential'" class="warn">undecided</span>
                                </td>
                                <td>
                                    <button v-if="c.outcome === 'potential' && !c.decision && !c.subject_id" type="button" class="btn btn-sm btn-primary" @click="decide(c)">Review</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="hint">Nothing here.</p>
                    <div v-if="register.last_page > 1" class="table-pagination">
                        <button type="button" :disabled="register.current_page <= 1" @click="loadRegister(register.current_page - 1)">Prev</button>
                        <span>Page {{ register.current_page }} of {{ register.last_page }}</span>
                        <button type="button" :disabled="register.current_page >= register.last_page" @click="loadRegister(register.current_page + 1)">Next</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import PageHeader from '@/components/ui/page-header.vue';
/** FUN-9: ad-hoc conflict search; for conflicts administrators also the register, decisions and the intake policy. */
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { hitsHtml } from '@/composables/use-conflict-gate';

useMeta({ title: 'Conflicts' });
const { showMessage } = useToast();
const canManage = JSON.parse(localStorage.getItem('permissions') || '[]').includes('manage-conflicts');

const names = ref([{ name: '', role: 'client' }, { name: '', role: 'adverse' }]);
const busy = ref(false);
const result = ref(null);

const search = async () => {
    busy.value = true;
    try {
        const { data } = await axios.post('/api/v1/conflicts/check', { purpose: 'search', names: names.value.filter((n) => n.name.trim().length >= 2) });
        result.value = data.data;
        if (canManage) loadRegister(1);
    } catch (e) {
        showMessage(e.response?.data?.message || 'The search did not work.', 'error');
    } finally {
        busy.value = false;
    }
};

const policy = ref({ required: false });
const savingPolicy = ref(false);
const setPolicy = (required) => {
    savingPolicy.value = true;
    axios.put('/api/v1/conflicts/policy', { required })
        .then(({ data }) => { policy.value = data.data; showMessage(required ? 'Conflict checks are now required at intake.' : 'Conflict checks are now advisory.'); })
        .catch(() => showMessage('Could not change the policy.', 'error'))
        .finally(() => { savingPolicy.value = false; });
};

const register = ref({});
const undecidedOnly = ref(false);
const loadRegister = (page = 1) => axios.get('/api/v1/conflicts', { params: { page, undecided: undecidedOnly.value ? 1 : undefined } })
    .then(({ data }) => { register.value = data.data; });

const decide = async (c) => {
    const r = await window.Swal.fire({
        title: `Conflict check #${c.id}`, width: 680, icon: 'warning',
        html: hitsHtml(c.hits) + '<p>Record the decision and why.</p>',
        input: 'textarea', inputPlaceholder: 'Who was consulted, what consent or screen is in place…',
        inputValidator: (v) => (!v || v.trim().length < 10 ? 'Record why in a sentence or two.' : undefined),
        showDenyButton: true, showCancelButton: true,
        confirmButtonText: 'Proceed', denyButtonText: 'Decline',
    });
    if (r.isDismissed) return;
    const decision = r.isConfirmed ? 'proceed' : 'declined';
    const note = (r.value || window.Swal.getInput()?.value || '').trim();
    if (note.length < 10) { showMessage('Record why in a sentence or two.', 'error'); return; }
    axios.put(`/api/v1/conflicts/${c.id}/decision`, { decision, note })
        .then(({ data }) => { showMessage(data.message); loadRegister(register.value.current_page || 1); })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not record the decision.', 'error'));
};

onMounted(() => {
    axios.get('/api/v1/conflicts/policy').then(({ data }) => { policy.value = data.data; }).catch(() => {});
    if (canManage) loadRegister(1);
});
</script>

<style lang="scss" scoped>
.hint {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
}

.name-row {
    display: grid;
    grid-template-columns: 1fr 200px auto;
    gap: 8px;
    margin-bottom: 8px;

    @media (max-width: 720px) {
        grid-template-columns: 1fr;
    }
}

.actions {
    display: flex;
    gap: 8px;
}

.result {
    margin-top: 16px;
}

.ok {
    color: #15803d;
}

.warn {
    color: #b45309;
}

.toggle-row {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    font-size: 14px;

    &.inline {
        margin-left: auto;
        font-size: 13px;
    }
}

.card-header {
    display: flex;
    align-items: center;
}

:deep(.conflict-hits) {
    padding-left: 18px;
    font-size: 14px;

    li {
        margin-bottom: 6px;
    }

    .kind {
        font-weight: 700;
    }

    .kind-conflict {
        color: #b42318;
    }

    .kind-related {
        color: #b45309;
    }
}
</style>
