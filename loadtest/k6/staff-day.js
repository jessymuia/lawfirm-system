// ENG-27: a firm's staff at work, as HTTP load against one deployment (docs/ops/CAPACITY.md).
//
//   BASE_URL=https://firm.example.com EMAIL=... PASSWORD=... k6 run loadtest/k6/staff-day.js
//   VUS=50 DURATION=5m k6 run ...                  (concurrent people, how long)
//   HOSTS=a.example.com,b.example.com k6 run ...   (multi-tenant: spread the people over several firms' hosts)
//   EMAIL_PATTERN=load-{n}@firm.test USERS=50 ...   (one person per virtual user: the API allows 300 requests a minute
//                                                    per person, so one shared login measures the rate limiter)
//
// Each virtual user signs in once (Sanctum cookie session, like the web app), then loops through what people do all
// day: the dashboard, today's hearings, lists, a search, a matter, a report now and then. Think time between steps.
// The thresholds are the service objective the ceilings in CAPACITY.md are measured against.
import http from 'k6/http';
import { check, group, sleep } from 'k6';

const HOSTS = (__ENV.HOSTS || '').split(',').filter(Boolean);
const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const EMAIL = __ENV.EMAIL || 'e2e-admin@lawfirm.test';
const PATTERN = __ENV.EMAIL_PATTERN || '';
const USERS = Number(__ENV.USERS || 1);
const PASSWORD = __ENV.PASSWORD || 'E2ePassword!123';
const THINK = Number(__ENV.THINK || 1);

export const options = {
    noCookiesReset: true,   // keep the session between loops, as a browser does
    scenarios: {
        staff: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [
                { duration: __ENV.RAMP || '30s', target: Number(__ENV.VUS || 20) },
                { duration: __ENV.DURATION || '2m', target: Number(__ENV.VUS || 20) },
                { duration: '10s', target: 0 },
            ],
            gracefulRampDown: '10s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],                       // under 1 % errors
        'http_req_duration{kind:read}': ['p(95)<500'],        // a screen's data in half a second
        'http_req_duration{kind:report}': ['p(95)<2000'],     // a partner report in two
    },
};

function base() {
    if (!HOSTS.length) return BASE;
    const scheme = BASE.startsWith('https') ? 'https' : 'http';
    return `${scheme}://${HOSTS[(__VU - 1) % HOSTS.length]}`;
}

let signedIn = false;

function signIn(url) {
    http.get(`${url}/sanctum/csrf-cookie`);
    const xsrf = decodeURIComponent((http.cookieJar().cookiesForURL(url)['XSRF-TOKEN'] || [''])[0]);
    const res = http.post(`${url}/api/v1/login`, JSON.stringify({ email: PATTERN ? PATTERN.replace('{n}', String(((__VU - 1) % USERS) + 1)) : EMAIL, password: PASSWORD }),
        { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, Referer: url }, tags: { kind: 'login' } });
    check(res, { 'signed in': (r) => r.status === 200 });
    signedIn = res.status === 200;
}

function get(url, path, kind = 'read') {
    const res = http.get(`${url}${path}`, { headers: { Accept: 'application/json', Referer: url }, tags: { kind, name: path.split('?')[0] } });
    check(res, { [`${path.split('?')[0]} 200`]: (r) => r.status === 200 });
    return res;
}

export default function () {
    const url = base();
    if (!signedIn) signIn(url);

    group('morning', () => {
        get(url, '/api/v1/dashboard');
        get(url, '/api/v1/today');
        get(url, '/api/v1/court-diary');
    });
    sleep(THINK);
    group('work a matter', () => {
        const cases = get(url, '/api/v1/cases?page=1&per_page=25');
        const first = (cases.json('data.data') || [])[0];
        if (first) {
            get(url, `/api/v1/cases/${first.id}`);
            get(url, `/api/v1/hearings?case_id=${first.id}&page=1`);
        }
        get(url, '/api/v1/clients?page=1&per_page=25');
    });
    sleep(THINK);
    group('find something', () => {
        get(url, '/api/v1/search?q=case');
        get(url, '/api/v1/invoices?page=1&per_page=25');
        get(url, '/api/v1/time-entries?page=1');
    });
    if (Math.random() < 0.1) {
        const today = new Date().toISOString().slice(0, 10);
        get(url, `/api/v1/reports/summary?from=${today.slice(0, 8)}01&to=${today}`, 'report');
    }
    sleep(THINK);
}
