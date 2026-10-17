<template>
    <div class="layout-px-spacing">
        <div class="page-header-modern">
            <h1 class="page-title">Calendar sync</h1>
            <p class="page-subtitle">Put your hearings and tasks in Google Calendar, Outlook or Apple Calendar.</p>
        </div>

        <p v-if="notice" class="alert" :class="notice.ok ? 'alert-success' : 'alert-warning'" role="status">{{ notice.text }}</p>

        <div class="elevated-card" data-testid="calendar-connections">
            <div class="card-header"><h2 class="card-title">Connected calendars</h2></div>
            <div class="card-body">
                <p class="hint">
                    Your hearings and open tasks are kept in step automatically. Move a task in your calendar and its due date
                    follows. Hearing dates come from the court: if one is moved in the calendar it is put back.
                </p>
                <div v-for="c in connections" :key="c.id" class="row-line">
                    <div>
                        <strong>{{ c.provider === 'google' ? 'Google Calendar' : 'Microsoft 365 / Outlook' }}</strong>
                        <span class="muted"> {{ c.account_email }}</span>
                        <div class="hint">
                            <template v-if="c.state === 'error'"><span class="text-danger">{{ c.error }}</span></template>
                            <template v-else-if="c.last_synced_at">Last synced {{ when(c.last_synced_at) }}<span v-if="c.error" class="text-warning"> · {{ c.error }}</span></template>
                            <template v-else>First sync in progress…</template>
                        </div>
                    </div>
                    <div class="actions">
                        <button v-if="c.state === 'error'" type="button" class="btn btn-sm btn-primary" @click="connect(c.provider)">Reconnect</button>
                        <button v-else type="button" class="btn btn-sm btn-outline-secondary" @click="syncNow(c)">Sync now</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" @click="disconnect(c)">Disconnect</button>
                    </div>
                </div>
                <div class="connect">
                    <button v-if="available.google && !has('google')" type="button" class="btn btn-primary" @click="connect('google')">Connect Google Calendar</button>
                    <button v-if="available.microsoft && !has('microsoft')" type="button" class="btn btn-primary" @click="connect('microsoft')">Connect Outlook / Microsoft 365</button>
                    <p v-if="!available.google && !available.microsoft" class="hint">
                        Direct sync is not set up for the firm yet (an administrator adds the Google or Microsoft app under Integrations). The subscription links below work with every calendar.
                    </p>
                </div>
            </div>
        </div>

        <div class="elevated-card" data-testid="calendar-feeds">
            <div class="card-header"><h2 class="card-title">Subscription links</h2></div>
            <div class="card-body">
                <p class="hint">
                    Paste a link into your calendar's "subscribe by URL" (Google: Other calendars → From URL; Outlook: Add calendar → Subscribe from web).
                    It refreshes on the calendar's own schedule, usually every few hours. Anyone with the link sees what it shows, so keep it private.
                </p>
                <div v-if="newFeed" class="new-feed">
                    <label for="feed-url">Your new link (shown once):</label>
                    <div class="copy">
                        <input id="feed-url" :value="newFeed" class="form-control" readonly @focus="$event.target.select()" />
                        <button type="button" class="btn btn-outline-secondary" @click="copy">Copy</button>
                    </div>
                </div>
                <div v-for="f in feeds" :key="f.id" class="row-line">
                    <div>
                        <strong>{{ f.scope === 'diary' ? 'Court diary (all hearings you can see)' : 'My hearings and tasks' }}</strong>
                        <div class="hint">Created {{ when(f.created_at) }}<template v-if="f.last_used_at"> · last read {{ when(f.last_used_at) }}</template></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" @click="revoke(f)">Turn off</button>
                </div>
                <div class="connect">
                    <button type="button" class="btn btn-outline-primary" @click="createFeed('mine')">New link: my hearings and tasks</button>
                    <button v-if="canDiary" type="button" class="btn btn-outline-primary" @click="createFeed('diary')">New link: court diary</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
/** FUN-16: a person's calendar sync (Google / Microsoft) and iCalendar subscription links. */
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'Calendar sync' });
const { showMessage } = useToast();
const route = useRoute();

const connections = ref([]);
const available = ref({ google: false, microsoft: false });
const feeds = ref([]);
const newFeed = ref('');
const canDiary = JSON.parse(localStorage.getItem('permissions') || '[]').includes('list-hearings');
const NOTICES = {
    connected: { ok: true, text: 'Calendar connected. Your hearings and tasks will appear in a minute.' },
    declined: { ok: false, text: 'Access was not allowed, so nothing was connected.' },
    expired: { ok: false, text: 'That connection attempt expired. Please try again.' },
    failed: { ok: false, text: 'The calendar provider did not accept the connection. Please try again.' },
};
const notice = ref(NOTICES[route.query.calendar] || null);

const when = (d) => (d ? new Date(String(d).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const has = (p) => connections.value.some((c) => c.provider === p);
const fail = (e, text) => showMessage(e.response?.data?.message || text, 'error');

const load = () => Promise.all([
    axios.get('/api/v1/calendar/connections').then(({ data }) => { connections.value = data.data.connections; available.value = data.data.available; }),
    axios.get('/api/v1/calendar/feeds').then(({ data }) => { feeds.value = data.data; }),
]).catch((e) => fail(e, 'Could not load your calendar settings.'));

const connect = (provider) => axios.post(`/api/v1/calendar/connect/${provider}`)
    .then(({ data }) => { window.location.assign(data.data.url); })
    .catch((e) => fail(e, 'Could not start the connection.'));
const syncNow = (c) => axios.post(`/api/v1/calendar/connections/${c.id}/sync`)
    .then(({ data }) => { showMessage(data.message); setTimeout(load, 3000); })
    .catch((e) => fail(e, 'Could not sync.'));
const disconnect = async (c) => {
    if (!(await confirmAction({ title: 'Disconnect this calendar?', text: 'Hearings will stop syncing to it. Events already created there are left in place.', confirmText: 'Disconnect', danger: true }))) return;
    return axios.delete(`/api/v1/calendar/connections/${c.id}`)
    .then(({ data }) => { showMessage(data.message); load(); })
    .catch((e) => fail(e, 'Could not disconnect.'));
};
const createFeed = (scope) => axios.post('/api/v1/calendar/feeds', { scope })
    .then(({ data }) => { newFeed.value = data.data.url; load(); })
    .catch((e) => fail(e, 'Could not create the link.'));
const revoke = async (f) => {
    if (!(await confirmAction({ title: 'Turn this calendar link off?', text: 'Anyone subscribed through it will stop receiving updates. You can create a new link afterwards.', confirmText: 'Turn link off', danger: true }))) return;
    return axios.delete(`/api/v1/calendar/feeds/${f.id}`)
    .then(() => { newFeed.value = ''; load(); })
    .catch((e) => fail(e, 'Could not turn the link off.'));
};
const copy = () => navigator.clipboard?.writeText(newFeed.value).then(() => showMessage('Link copied.'));

onMounted(load);
</script>

<style lang="scss" scoped>
.hint, .muted { font-size: 13px; color: var(--text-secondary, #64748b); }
.row-line { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-color, #e2e8f0); flex-wrap: wrap; }
.actions, .connect { display: flex; gap: 8px; flex-wrap: wrap; }
.connect { margin-top: 12px; }
.new-feed { margin: 12px 0; }
.copy { display: flex; gap: 8px; }
</style>
