// UI-13: the firm's branding in the web app. The firm's primary colour becomes the whole --primary-* scale
// (design-tokens.scss), so every component that uses the tokens follows it; the name and logo show in the sidebar and
// on the sign-in page. The last branding seen is kept in this browser so the next visit paints in the right colour at
// once; the API is the source of truth.
import { reactive } from 'vue';
import axios from '@/api';
import type { ApiResponse } from '@/api';

export interface PublicBranding {
    name: string;
    color: string;
    logo_url: string | null;
}

const CACHE_KEY = 'branding';
const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as const;
// how far each shade moves from the firm's colour (600) toward white (negative) or black (positive)
const MIX: Record<(typeof SHADES)[number], number> = { 50: -0.95, 100: -0.88, 200: -0.75, 300: -0.55, 400: -0.35, 500: -0.15, 600: 0, 700: 0.18, 800: 0.35, 900: 0.5 };

export const branding = reactive<PublicBranding>({ name: '', color: '#0e6b50', logo_url: null });

const toRgb = (hex: string): [number, number, number] => {
    const n = parseInt(hex.replace('#', ''), 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
};
const toHex = (rgb: number[]) => '#' + rgb.map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');

const luminance = (rgb: number[]) => {
    const [r, g, b] = rgb.map((v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
/** WCAG contrast ratio of a colour against white. */
export const contrastOnWhite = (hex: string) => 1.05 / (luminance(toRgb(hex)) + 0.05);

/**
 * UI-15: the colour darkened just enough to be readable as text on white and on the light tints (and to carry white
 * text as a button), WCAG AA for normal text with a margin for the tints: 4.9:1 on white. A firm's dark colour is kept.
 */
export const readable = (hex: string): string => {
    const rgb = toRgb(hex);
    for (let t = 0; t <= 1; t += 0.02) {
        const candidate = toHex(rgb.map((c) => c * (1 - t)));
        if (contrastOnWhite(candidate) >= 4.9) return candidate;
    }
    return '#000000';
};

/** The --primary-50…900 scale for a colour, its readable form (see readable()) at 600. */
export const palette = (hex: string): Record<string, string> => {
    const rgb = toRgb(readable(hex));
    return Object.fromEntries(SHADES.map((shade) => {
        const t = MIX[shade];
        const target = t < 0 ? 255 : 0;
        return [`--primary-${shade}`, toHex(rgb.map((c) => c + (target - c) * Math.abs(t)))];
    }));
};

const apply = (b: PublicBranding) => {
    Object.assign(branding, b);
    if (/^#[0-9a-fA-F]{6}$/.test(b.color)) {
        const root = document.documentElement.style;
        Object.entries(palette(b.color)).forEach(([k, v]) => root.setProperty(k, v));
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', b.color);
    }
};

/** Paint from the last known branding, then fetch the current one. */
export const loadBranding = (): Promise<void> => {
    try {
        const cached = JSON.parse(localStorage.getItem(CACHE_KEY) || 'null');
        if (cached) apply(cached);
    } catch {
        // storage unavailable or unreadable: the API answer below is enough
    }
    return axios.get<ApiResponse<PublicBranding>>('/api/v1/branding').then(({ data }) => {
        apply(data.data);
        try {
            localStorage.setItem(CACHE_KEY, JSON.stringify(data.data));
        } catch {
            // private mode: nothing to remember
        }
    }).catch(() => {});
};

/** After the settings page saved new branding. */
export const applyBranding = (b: PublicBranding) => {
    apply(b);
    try {
        localStorage.setItem(CACHE_KEY, JSON.stringify(b));
    } catch {
        // as above
    }
};
