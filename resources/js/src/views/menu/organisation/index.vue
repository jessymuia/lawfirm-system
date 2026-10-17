<template>
    <div class="organisation-page">
        <PageHeader title="Offices & practice groups" subtitle="Organise people and matters into offices and practice groups." icon="M4 21V8l8-5 8 5v13M9 21v-6h6v6" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Offices &amp; Practice Groups</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <p class="hint">
            Offices and practice groups organise people and matters inside the firm. Matters default to their responsible
            lawyer's office and group, and lists and reports can be filtered by them.
        </p>

        <div class="grid">
            <div class="elevated-card" data-testid="offices">
                <div class="card-header"><h2 class="card-title">Offices</h2></div>
                <div class="card-body">
                    <table v-if="org.offices.length" class="table">
                        <thead><tr><th>Name</th><th>City</th><th>People</th><th>Open matters</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="o in org.offices" :key="o.id">
                                <td>{{ o.name }} <span v-if="o.is_head_office" class="badge">Head office</span></td>
                                <td>{{ o.city || '—' }}</td>
                                <td>{{ o.people }}</td>
                                <td>{{ o.open_matters }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary" @click="editOffice(o)">Edit</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" @click="remove('offices', o)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="hint">No offices yet.</p>
                    <form class="inline-form" @submit.prevent="saveOffice">
                        <input v-model="office.name" class="form-control" placeholder="Office name" aria-label="Office name" required />
                        <input v-model="office.city" class="form-control" placeholder="City" aria-label="City" />
                        <label class="check"><input v-model="office.is_head_office" type="checkbox" /> Head office</label>
                        <button type="submit" class="btn btn-primary">{{ office.id ? 'Save' : 'Add office' }}</button>
                        <button v-if="office.id" type="button" class="btn btn-outline-secondary" @click="office = blankOffice()">Cancel</button>
                    </form>
                </div>
            </div>

            <div class="elevated-card" data-testid="practice-groups">
                <div class="card-header"><h2 class="card-title">Practice groups</h2></div>
                <div class="card-body">
                    <table v-if="org.practice_groups.length" class="table">
                        <thead><tr><th>Name</th><th>Head</th><th>People</th><th>Open matters</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="g in org.practice_groups" :key="g.id">
                                <td>{{ g.name }}</td>
                                <td>{{ g.head || '—' }}</td>
                                <td>{{ g.people }}</td>
                                <td>{{ g.open_matters }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary" @click="editGroup(g)">Edit</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" @click="remove('practice-groups', g)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="hint">No practice groups yet.</p>
                    <form class="inline-form" @submit.prevent="saveGroup">
                        <input v-model="group.name" class="form-control" placeholder="Practice group name" aria-label="Practice group name" required />
                        <select v-model="group.head_user_id" class="form-control" aria-label="Head of group">
                            <option :value="null">No head</option>
                            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                        <button type="submit" class="btn btn-primary">{{ group.id ? 'Save' : 'Add group' }}</button>
                        <button v-if="group.id" type="button" class="btn btn-outline-secondary" @click="group = blankGroup()">Cancel</button>
                    </form>
                </div>
            </div>
        </div>

        <div v-if="org.offices.length || org.practice_groups.length" class="elevated-card" data-testid="assign">
            <div class="card-header"><h2 class="card-title">Move people</h2></div>
            <div class="card-body">
                <form class="assign-form" @submit.prevent="assign">
                    <select v-model="move.user_ids" class="form-control people" multiple aria-label="People to move">
                        <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <div class="targets">
                        <select v-model="move.office_id" class="form-control" aria-label="To office">
                            <option value="keep">Office: leave as is</option>
                            <option :value="null">Office: none</option>
                            <option v-for="o in org.offices" :key="o.id" :value="o.id">Office: {{ o.name }}</option>
                        </select>
                        <select v-model="move.practice_group_id" class="form-control" aria-label="To practice group">
                            <option value="keep">Group: leave as is</option>
                            <option :value="null">Group: none</option>
                            <option v-for="g in org.practice_groups" :key="g.id" :value="g.id">Group: {{ g.name }}</option>
                        </select>
                        <label class="check"><input v-model="move.apply_to_open_matters" type="checkbox" /> Their open matters move too</label>
                        <button type="submit" class="btn btn-primary" :disabled="!move.user_ids.length">Move</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- FUN-15: who may file email to matters by sending it to their addresses -->
        <div class="elevated-card" data-testid="email-filing">
            <div class="card-header"><h2 class="card-title">Email filing</h2></div>
            <div class="card-body">
                <p class="hint">Each matter has its own email address. Choose whose mail sent to those addresses is filed; anything else is ignored.</p>
                <p v-if="!emailFiling.enabled" class="hint">Filing by address is not set up on this installation yet (inbound domain and webhook secret); uploads of saved emails still work.</p>
                <form class="assign-form" @submit.prevent="saveEmailFiling">
                    <select v-model="emailFiling.senders" class="form-control" aria-label="Who may file email">
                        <option value="staff">Only our people (their sign-in addresses)</option>
                        <option value="firm_domains">Anyone at our email domains</option>
                        <option value="anyone">Anyone with the address</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>

        <!-- FUN-13: identifiers on LEDES e-bills -->
        <div class="elevated-card" data-testid="ebilling">
            <div class="card-header"><h2 class="card-title">E-billing (LEDES)</h2></div>
            <div class="card-body">
                <p class="hint">The firm's ID on LEDES e-bills, usually its tax PIN. Clients' and matters' own references are set on each client and matter.</p>
                <form class="assign-form" @submit.prevent="saveLedes">
                    <input v-model="lawFirmId" class="form-control" maxlength="64" placeholder="Law firm ID (e.g. P051234567X)" aria-label="LEDES law firm ID" />
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import PageHeader from '@/components/ui/page-header.vue';
import { confirmAction } from '@/composables/use-confirm';
/** FUN-18: the firm's offices and practice groups (manage-organisation). */
import { onMounted, ref } from 'vue';
import axios, { fetchAllPages } from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useOrganisation } from '@/composables/use-organisation';

useMeta({ title: 'Offices & Practice Groups' });
const { showMessage } = useToast();
const { reload } = useOrganisation();

const org = ref({ offices: [], practice_groups: [] });
const people = ref([]);
const blankOffice = () => ({ id: null, name: '', city: '', is_head_office: false });
const blankGroup = () => ({ id: null, name: '', head_user_id: null });
const office = ref(blankOffice());
const group = ref(blankGroup());
const move = ref({ user_ids: [], office_id: 'keep', practice_group_id: 'keep', apply_to_open_matters: true });

const load = () => axios.get('/api/v1/organisation').then(({ data }) => {
    org.value = data.data;
    reload();
});
const emailFiling = ref({ senders: 'staff', enabled: false });
const saveEmailFiling = () => {
    axios.put('/api/v1/email-filing/settings', { senders: emailFiling.value.senders })
        .then(({ data }) => showMessage(data.message))
        .catch((e) => fail(e, 'Could not save.'));
};
const lawFirmId = ref('');
const saveLedes = () => {
    axios.put('/api/v1/ebilling/settings', { law_firm_id: lawFirmId.value || null })
        .then(({ data }) => showMessage(data.message))
        .catch((e) => fail(e, 'Could not save the law firm ID.'));
};
const fail = (e, fallback) => showMessage(e.response?.data?.message || fallback, 'error');

const editOffice = (o) => { office.value = { id: o.id, name: o.name, city: o.city || '', is_head_office: o.is_head_office }; };
const editGroup = (g) => { group.value = { id: g.id, name: g.name, head_user_id: g.head_user_id }; };

const saveOffice = () => {
    const { id, ...body } = office.value;
    (id ? axios.put(`/api/v1/organisation/offices/${id}`, body) : axios.post('/api/v1/organisation/offices', body))
        .then(() => { office.value = blankOffice(); load(); })
        .catch((e) => fail(e, 'Could not save the office.'));
};
const saveGroup = () => {
    const { id, ...body } = group.value;
    (id ? axios.put(`/api/v1/organisation/practice-groups/${id}`, body) : axios.post('/api/v1/organisation/practice-groups', body))
        .then(() => { group.value = blankGroup(); load(); })
        .catch((e) => fail(e, 'Could not save the practice group.'));
};
const remove = async (kind, row) => {
    const what = kind === 'offices' ? 'office' : 'practice group';
    if (!(await confirmAction({ title: `Remove the ${what} "${row.name}"?`, text: `People and matters in this ${what} will be left without one until you move them.`, confirmText: `Remove ${what}`, danger: true }))) return;
    axios.delete(`/api/v1/organisation/${kind}/${row.id}`)
        .then(load)
        .catch((e) => fail(e, 'Could not remove it.'));
};
const assign = async () => {
    if (!(await confirmAction({ title: `Move ${move.value.user_ids.length} ${move.value.user_ids.length === 1 ? 'person' : 'people'}?`, text: move.value.apply_to_open_matters ? 'Their open matters will move with them.' : 'Their open matters will stay where they are.', confirmText: 'Yes, move' }))) return;
    const body = { user_ids: move.value.user_ids, apply_to_open_matters: move.value.apply_to_open_matters };
    if (move.value.office_id !== 'keep') body.office_id = move.value.office_id;
    if (move.value.practice_group_id !== 'keep') body.practice_group_id = move.value.practice_group_id;
    axios.post('/api/v1/organisation/assign', body)
        .then(({ data }) => { showMessage(data.message); move.value.user_ids = []; load(); })
        .catch((e) => fail(e, 'Could not move them.'));
};

onMounted(() => {
    load();
    axios.get('/api/v1/email-filing/settings').then(({ data }) => { emailFiling.value = data.data; }).catch(() => {});
    axios.get('/api/v1/ebilling/settings').then(({ data }) => { lawFirmId.value = data.data.law_firm_id || ''; }).catch(() => {});
    fetchAllPages('/api/v1/users').then((rows) => { people.value = rows; }).catch(() => {});
});
</script>

<style lang="scss" scoped>
.hint {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 16px;

    @media (max-width: 720px) {
        grid-template-columns: 1fr;
    }
}

.inline-form, .targets {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-top: 12px;

    .form-control {
        flex: 1 1 160px;
    }
}

.assign-form .people {
    min-height: 140px;
}

.check {
    display: flex;
    gap: 6px;
    align-items: center;
    font-size: 13px;
}

.row-actions {
    display: flex;
    gap: 6px;
}

.badge {
    background: var(--primary-50);
    color: var(--primary-700);
    font-size: 11px;
}
</style>
