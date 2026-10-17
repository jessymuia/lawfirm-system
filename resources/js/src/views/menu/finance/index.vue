<template>
    <div class="finance-page">
        <PageHeader title="Currencies and tax" subtitle="Base currency, exchange rates and tax rates used on invoices." icon="M12 2v20M17 6H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Currencies and tax</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <p v-if="!settings" class="hint">Loading…</p>
        <div v-else class="finance-grid">
            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Base currency</h2></div>
                <div class="card-body">
                    <p class="hint">The currency the firm keeps its books and reports in. Time rates and expenses are in it.</p>
                    <form class="inline-form" @submit.prevent="saveBase">
                        <label class="field">Base currency
                            <input v-model="base" class="form-control" maxlength="3" :disabled="settings.base_currency_locked" data-testid="base-currency" />
                        </label>
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="settings.base_currency_locked">Save</button>
                    </form>
                    <p v-if="settings.base_currency_locked" class="hint">Invoices have been issued in {{ settings.base_currency }}, so it cannot change.</p>
                </div>
            </div>

            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Currencies</h2></div>
                <div class="card-body">
                    <p class="hint">Invoices can be in any enabled currency.</p>
                    <ul class="plain-list">
                        <li v-for="c in settings.currencies" :key="c.code">
                            <label class="toggle"><input type="checkbox" :checked="c.enabled" @change="toggleCurrency(c)" /> {{ c.code }} · {{ c.name }}</label>
                        </li>
                    </ul>
                    <form class="inline-form" @submit.prevent="addCurrency">
                        <label class="field">Code <input v-model="newCurrency.code" class="form-control" maxlength="3" required /></label>
                        <label class="field">Name <input v-model="newCurrency.name" class="form-control" maxlength="100" required /></label>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Add</button>
                    </form>
                </div>
            </div>

            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Exchange rates</h2></div>
                <div class="card-body">
                    <p class="hint">How many {{ settings.base_currency }} one unit is worth, from a date. An invoice fixes the latest rate on or before its date when it is issued; later rates never change it.</p>
                    <table class="table table-sm">
                        <thead><tr><th scope="col">Currency</th><th scope="col">Rate</th><th scope="col">From</th><th scope="col"><span class="sr-only-text">Actions</span></th></tr></thead>
                        <tbody>
                            <tr v-for="r in settings.exchange_rates" :key="r.id">
                                <td>{{ r.currency }}</td><td>{{ r.rate }}</td><td>{{ r.effective_on }}</td>
                                <td><button type="button" class="btn btn-link btn-sm" @click="deleteRate(r)">Delete</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <form class="inline-form" @submit.prevent="addRate">
                        <label class="field">Currency
                            <select v-model="newRate.currency" class="form-control" required>
                                <option v-for="c in foreign" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </label>
                        <label class="field">Rate <input v-model="newRate.rate" type="number" step="0.00000001" min="0" class="form-control" required /></label>
                        <label class="field">From <input v-model="newRate.effective_on" type="date" class="form-control" required /></label>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Save rate</button>
                    </form>
                </div>
            </div>

            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Document numbering</h2></div>
                <div class="card-body">
                    <p class="hint">How invoice, receipt and trust-ledger numbers look. Numbers stay in one unbroken run whatever you change here.</p>
                    <form class="inline-form" @submit.prevent="saveNumbering">
                        <label v-for="n in numberKinds" :key="n.key" class="field">{{ n.label }} prefix
                            <input v-model="numbering.prefixes[n.key]" class="form-control" maxlength="8" pattern="[A-Za-z0-9]{1,8}" required :data-testid="'prefix-' + n.key" />
                            <span class="hint">e.g. {{ example(n.key) }}</span>
                        </label>
                        <label class="toggle"><input v-model="numbering.per_year" type="checkbox" /> Include the year</label>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Save numbering</button>
                    </form>
                </div>
            </div>

            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Tax rates</h2></div>
                <div class="card-body">
                    <p class="hint">An invoice line can use a tax rate: its tax is worked out from it. The default is preselected on new lines.</p>
                    <ul class="plain-list">
                        <li v-for="t in settings.tax_rates" :key="t.id">
                            {{ t.name }} · {{ t.rate }}%<span v-if="t.jurisdiction"> · {{ t.jurisdiction }}</span><strong v-if="t.is_default"> · default</strong>
                            <span v-if="t.is_system" class="tag" title="Kept current by the platform when the law changes">system</span>
                            <button type="button" class="btn btn-link btn-sm" @click="archiveTax(t)">Archive</button>
                        </li>
                    </ul>
                    <form class="inline-form" @submit.prevent="addTax">
                        <label class="field">Name <input v-model="newTax.name" class="form-control" maxlength="100" required /></label>
                        <label class="field">Rate (%) <input v-model="newTax.rate" type="number" step="0.0001" min="0" max="100" class="form-control" required /></label>
                        <label class="field">Jurisdiction <input v-model="newTax.jurisdiction" class="form-control" maxlength="60" /></label>
                        <label class="toggle"><input v-model="newTax.is_default" type="checkbox" /> Default</label>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Add</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import PageHeader from '@/components/ui/page-header.vue';
import { computed, onMounted, ref } from 'vue';
import type { ApiResponse } from '@/api';
import {
    financeSettingsShow, financeSettingsBaseCurrency, financeSettingsCurrency, financeSettingsExchangeRate,
    financeSettingsDeleteExchangeRate, financeSettingsTaxRate, financeSettingsArchiveTaxRate, financeSettingsNumbering,
} from '@/api/client.generated';   // ENG-12: the typed client generated from docs/openapi.json
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

// DATA-14: the firm's base currency, currencies, exchange rates and tax rates (docs/MONEY.md).
useMeta({ title: 'Currencies and tax' });
const { showMessage } = useToast();

interface CurrencyRow { code: string; name: string; minor_units: number; symbol: string | null; enabled: boolean; is_system: boolean }
interface RateRow { id: number; currency: string; rate: number; effective_on: string; source: string }
interface TaxRow { id: number; name: string; rate: number; jurisdiction: string | null; is_default: boolean; is_system: boolean }
interface Settings {
    base_currency: string; base_currency_locked: boolean; currencies: CurrencyRow[]; invoice_currencies: string[];
    exchange_rates: RateRow[]; tax_rates: TaxRow[];
    numbering: { per_year: boolean; prefixes: Record<string, string>; examples: Record<string, string> };
}

const settings = ref<Settings | null>(null);
const base = ref('');
const numbering = ref<{ per_year: boolean; prefixes: Record<string, string> }>({ per_year: false, prefixes: { invoice: 'INV', receipt: 'RCT', trust: 'TRT' } });
const numberKinds = [{ key: 'invoice', label: 'Invoice' }, { key: 'receipt', label: 'Receipt' }, { key: 'trust', label: 'Trust ledger' }];
const example = (k: string) => `${numbering.value.prefixes[k] || '…'}-${numbering.value.per_year ? new Date().getFullYear() + '-' : ''}000123`;
const newCurrency = ref({ code: '', name: '' });
const newRate = ref({ currency: '', rate: '', effective_on: new Date().toISOString().slice(0, 10) });
const newTax = ref({ name: '', rate: '', jurisdiction: '', is_default: false });
const foreign = computed(() => (settings.value?.invoice_currencies ?? []).filter(c => c !== settings.value?.base_currency));

const message = (e: unknown, fallback: string) =>
    (e as { response?: { data?: { message?: string } } }).response?.data?.message ?? fallback;

const show = (data: ApiResponse<Settings>) => {
    settings.value = data.data;
    base.value = data.data.base_currency;
    if (data.data.numbering) numbering.value = { per_year: data.data.numbering.per_year, prefixes: { ...data.data.numbering.prefixes } };
};

const call = async (request: Promise<ApiResponse<unknown>>, done: string) => {
    try {
        show((await request) as ApiResponse<Settings>);
        showMessage(done);
        return true;
    } catch (e) {
        showMessage(message(e, 'That could not be saved.'), 'error');
        return false;
    }
};

const saveBase = () => call(financeSettingsBaseCurrency({ base_currency: base.value }), 'Base currency saved.');
const toggleCurrency = (c: CurrencyRow) => call(financeSettingsCurrency({ ...c, enabled: !c.enabled }), `${c.code} ${c.enabled ? 'disabled' : 'enabled'}.`);
const saveNumbering = () => call(financeSettingsNumbering({ per_year: numbering.value.per_year, prefixes: numbering.value.prefixes }), 'Numbering saved.');
const addCurrency = async () => {
    if (await call(financeSettingsCurrency(newCurrency.value), 'Currency added.')) newCurrency.value = { code: '', name: '' };
};
const addRate = () => call(financeSettingsExchangeRate({ ...newRate.value, rate: Number(newRate.value.rate) }), 'Exchange rate saved.');
const deleteRate = (r: RateRow) => call(financeSettingsDeleteExchangeRate(r.id), 'Exchange rate deleted.');
const addTax = async () => {
    if (await call(financeSettingsTaxRate({ ...newTax.value, rate: Number(newTax.value.rate) }), 'Tax rate added.')) newTax.value = { name: '', rate: '', jurisdiction: '', is_default: false };
};
const archiveTax = (t: TaxRow) => call(financeSettingsArchiveTaxRate(t.id), `${t.name} archived.`);

onMounted(async () => show((await financeSettingsShow()) as ApiResponse<Settings>));
</script>

<style lang="scss" scoped>
.finance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.inline-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-top: 12px; }
.plain-list { list-style: none; padding: 0; margin: 0; }
.plain-list li { padding: 4px 0; }
.toggle { display: flex; gap: 6px; align-items: center; font-size: 13px; }
.hint { font-size: 12px; color: var(--text-muted); }
.tag { font-size: 11px; padding: 1px 6px; border-radius: 999px; background: var(--primary-50); color: var(--primary-700); }
.sr-only-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
