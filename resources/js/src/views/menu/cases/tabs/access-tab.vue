<template>
    <div class="elevated-card" data-testid="access-tab">
        <div class="card-header"><h2 class="card-title">Who can see this matter</h2></div>
        <div class="card-body">
            <p class="hint">The assigned team always has access. Grant anyone else access here, for as long as you choose. Every grant and removal is recorded.</p>

            <h3 class="sub">Matter team</h3>
            <ul v-if="team.length" class="people">
                <li v-for="p in team" :key="p.user_id"><strong>{{ p.name }}</strong><span class="role">{{ p.role || 'Team member' }}</span></li>
            </ul>
            <p v-else class="hint">No one is assigned yet.</p>

            <h3 class="sub">Extra access</h3>
            <form class="grant-form" @submit.prevent="grant">
                <div class="field grow"><span>Person</span>
                    <SearchSelect fetch-url="/api/v1/advocates" v-model="form.user_id" placeholder="Search people..." />
                </div>
                <label class="field">Until (optional)<input v-model="form.expires_at" type="date" class="form-control" :min="today" /></label>
                <label class="field grow">Reason (optional)<input v-model="form.reason" class="form-control" maxlength="255" placeholder="Why do they need access?" /></label>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="!form.user_id || saving">Grant access</button>
            </form>

            <div class="table-wrap">
                <table v-if="grants.length" class="table table-sm">
                    <thead><tr><th scope="col">Person</th><th scope="col">Reason</th><th scope="col">Until</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="g in grants" :key="g.id">
                            <td>{{ g.name }}</td><td>{{ g.reason || '—' }}</td><td>{{ g.expires_at ? day(g.expires_at) : 'No end date' }}</td>
                            <td><button type="button" class="btn btn-link btn-sm" @click="revoke(g)">Remove</button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="hint">No extra access has been granted.</p>
            </div>
        </div>
    </div>
</template>

<script setup>
/** SEC-10: who can see one matter. Needs the manage-matter-access permission. */
import { onMounted, ref } from 'vue';
import axios from '@/api';
import SearchSelect from '@/components/ui/search-select.vue';
import { confirmAction } from '@/composables/use-confirm';
import { useToast } from '@/composables/use-toast';

const props = defineProps({ caseId: { type: [Number, String], required: true } });
const { showMessage } = useToast();
const team = ref([]);
const grants = ref([]);
const saving = ref(false);
const form = ref({ user_id: null, expires_at: '', reason: '' });
const today = new Date().toISOString().slice(0, 10);
const day = (v) => new Date(v).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

const load = async () => {
    try {
        const { data } = await axios.get(`/api/v1/cases/${props.caseId}/access`);
        team.value = data.data.team; grants.value = data.data.grants;
    } catch (e) { showMessage(e.response?.data?.message || 'Could not load access.', 'error'); }
};
const grant = async () => {
    saving.value = true;
    try {
        await axios.post(`/api/v1/cases/${props.caseId}/access`, { user_id: form.value.user_id, expires_at: form.value.expires_at || null, reason: form.value.reason || null });
        showMessage('Access granted.');
        form.value = { user_id: null, expires_at: '', reason: '' };
        await load();
    } catch (e) { showMessage(e.response?.data?.message || 'Could not grant access.', 'error'); }
    saving.value = false;
};
const revoke = async (g) => {
    if (!(await confirmAction({ title: 'Remove access', message: `Remove ${g.name}'s access to this matter?`, confirmText: 'Remove' }))) return;
    try { await axios.delete(`/api/v1/cases/${props.caseId}/access/${g.id}`); showMessage('Access removed.'); await load(); }
    catch (e) { showMessage(e.response?.data?.message || 'Could not remove access.', 'error'); }
};
onMounted(load);
</script>

<style lang="scss" scoped>
.hint { font-size: 13px; color: var(--text-muted); margin: 0 0 12px; }
.sub { font-size: 14px; margin: 18px 0 8px; }
.people { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px; }
.people li { background: var(--neutral-100); border-radius: 999px; padding: 4px 12px; font-size: 13px; }
.role { color: var(--text-muted); margin-left: 8px; font-size: 12px; }
.grant-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 14px; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; min-width: 150px; }
.grow { flex: 1; min-width: 200px; }
.table-wrap { overflow-x: auto; }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.btn-link { box-shadow: none; background: transparent; }
</style>
