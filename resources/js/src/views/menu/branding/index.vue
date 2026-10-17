<template>
    <div class="branding-page">
        <PageHeader title="Branding" subtitle="Your firm name, colour and logo on the app, invoices and letters." icon="M12 3a9 9 0 100 18 4 4 0 004-4 2 2 0 00-2-2h-1a2 2 0 01-2-2 6 6 0 002-1 9 9 0 00-1-9z" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Branding</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <p v-if="!form" class="hint">Loading…</p>
        <form v-else class="branding-grid" @submit.prevent="save">
            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Name and colour</h2></div>
                <div class="card-body">
                    <label class="field">Firm name as shown
                        <input v-model="form.name" class="form-control" maxlength="120" required data-testid="branding-name" />
                    </label>
                    <label class="field">Primary colour
                        <span class="color-row">
                            <input v-model="form.color" type="color" class="color-input" aria-label="Pick the primary colour" />
                            <input v-model="form.color" class="form-control" pattern="#[0-9a-fA-F]{6}" maxlength="7" aria-label="Primary colour as hex" data-testid="branding-color" />
                        </span>
                    </label>
                    <div class="swatches" aria-hidden="true">
                        <span v-for="(value, key) in preview" :key="key" class="swatch" :style="{ background: value }" />
                    </div>
                </div>
            </div>

            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Logo</h2></div>
                <div class="card-body">
                    <img v-if="logoUrl" :src="logoUrl" alt="Current logo" class="logo-preview" />
                    <p v-else class="hint">No logo yet: the firm's initials are shown.</p>
                    <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" class="form-control" aria-label="Logo file" @change="uploadLogo" />
                    <p class="hint">PNG, JPEG or WebP, up to 1 MB. A wide logo on a transparent background works best.</p>
                    <button v-if="logoUrl" type="button" class="btn btn-outline-danger btn-sm" @click="removeLogo">Remove logo</button>
                </div>
            </div>

            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Letterhead</h2></div>
                <div class="card-body">
                    <p class="hint">Printed under the logo on invoices and signature certificates.</p>
                    <label class="field">Address <input v-model="form.letterhead.address" class="form-control" maxlength="300" /></label>
                    <label class="field">Phone <input v-model="form.letterhead.phone" class="form-control" maxlength="60" /></label>
                    <label class="field">Email <input v-model="form.letterhead.email" type="email" class="form-control" maxlength="120" /></label>
                    <label class="field">Website <input v-model="form.letterhead.website" class="form-control" maxlength="120" /></label>
                    <label class="field">Tax PIN <input v-model="form.letterhead.tax_pin" class="form-control" maxlength="40" /></label>
                    <label class="field">Invoice footer
                        <textarea v-model="form.invoice_footer" class="form-control" rows="3" maxlength="1000" placeholder="e.g. bank details, payment terms" />
                    </label>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary" :disabled="saving" data-testid="branding-save">Save branding</button>
            </div>
        </form>
    </div>
</template>

<script setup lang="ts">
import PageHeader from '@/components/ui/page-header.vue';
import { confirmAction } from '@/composables/use-confirm';
import { computed, onMounted, ref } from 'vue';
import axios from '@/api';
import type { ApiResponse } from '@/api';
import { applyBranding, palette, type PublicBranding } from '@/branding';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'Branding' });
const { showMessage } = useToast();

interface Letterhead { address: string | null; phone: string | null; email: string | null; website: string | null; tax_pin: string | null }
interface Settings extends PublicBranding { letterhead: Letterhead; invoice_footer: string | null }

const form = ref<Settings | null>(null);
const logoUrl = ref<string | null>(null);
const saving = ref(false);
const logoInput = ref<HTMLInputElement | null>(null);
const preview = computed(() => (form.value && /^#[0-9a-fA-F]{6}$/.test(form.value.color) ? palette(form.value.color) : {}));

const message = (e: unknown, fallback: string) =>
    (e as { response?: { data?: { message?: string } } }).response?.data?.message ?? fallback;

const load = async () => {
    const { data } = await axios.get<ApiResponse<Settings>>('/api/v1/branding/settings');
    form.value = data.data;
    logoUrl.value = data.data.logo_url;
};

const save = async () => {
    if (!form.value) return;
    saving.value = true;
    try {
        const { name, color, letterhead, invoice_footer } = form.value;
        const { data } = await axios.put<ApiResponse<Settings>>('/api/v1/branding', { name, color, letterhead, invoice_footer });
        applyBranding({ name: data.data.name, color: data.data.color, logo_url: data.data.logo_url });
        showMessage('Branding saved.');
    } catch (e) {
        showMessage(message(e, 'Branding could not be saved.'), 'error');
    } finally {
        saving.value = false;
    }
};

const uploadLogo = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('logo', file);
    try {
        const { data } = await axios.post<ApiResponse<PublicBranding>>('/api/v1/branding/logo', body, { headers: { 'Content-Type': 'multipart/form-data' } });
        logoUrl.value = data.data.logo_url;
        applyBranding(data.data);
        showMessage('Logo saved.');
    } catch (e) {
        showMessage(message(e, 'The logo could not be saved.'), 'error');
    } finally {
        if (logoInput.value) logoInput.value.value = '';
    }
};

const removeLogo = async () => {
    if (!(await confirmAction({ title: 'Remove the firm logo?', text: 'Invoices, letters and the sign-in page will go back to showing the firm name only.', confirmText: 'Remove logo', danger: true }))) return;
    const { data } = await axios.delete<ApiResponse<PublicBranding>>('/api/v1/branding/logo');
    logoUrl.value = null;
    applyBranding(data.data);
    showMessage('Logo removed.');
};

onMounted(load);
</script>

<style lang="scss" scoped>
.branding-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
.field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; font-size: 13px; }
.color-row { display: flex; gap: 8px; align-items: center; }
.color-input { width: 44px; height: 38px; padding: 2px; border: 1px solid var(--border-color, #e2e8f0); border-radius: 6px; }
.swatches { display: flex; gap: 4px; }
.swatch { flex: 1; height: 20px; border-radius: 4px; }
.logo-preview { max-height: 64px; max-width: 240px; display: block; margin-bottom: 12px; }
.hint { font-size: 12px; color: var(--text-secondary, #64748b); }
.actions { grid-column: 1 / -1; }
</style>
