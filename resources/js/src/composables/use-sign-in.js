import axios, { ensureCsrfCookie } from '@/api';

/**
 * SEC-12: the steps of signing in, shared by the login page and the session-expired modal.
 *
 * A password is only the first step for someone with two-factor on: /login then answers
 * `{ mfa_required: true, challenge }` and sets no session until /login/mfa redeems the challenge with a code
 * (or a recovery code). Every successful step answers with the same positional payload
 * `[user, isAllowed, roles, permissions, mustEnrolMfa]`, which `remember()` turns into the display marker the
 * router checks. The session itself lives in an httpOnly cookie; nothing here is a credential.
 */

export const remember = (payload) => {
    const [user, isAllowed, roles, permissions, mustEnrolMfa = false] = payload;
    localStorage.setItem('user', JSON.stringify({
        login: true,
        user_id: user.id,
        name: user.name,
        email: user.email,
        isAllowed,
        roles,
    }));
    localStorage.setItem('permissions', JSON.stringify(permissions));
    // FUN-19: nothing a previous person left for offline use survives a new sign-in on this device
    navigator.serviceWorker?.controller?.postMessage('signed-in');

    return { isAllowed, mustEnrolMfa };
};

/** Where to go once signed in: enrolment first when the firm requires it, then where they were headed. */
export const landingFor = ({ isAllowed, mustEnrolMfa }, redirectFrom = null) => {
    if (mustEnrolMfa) return '/auth/two-factor';
    // Only same-app paths: a redirect parameter must never send someone off-site.
    if (typeof redirectFrom === 'string' && redirectFrom.startsWith('/') && !redirectFrom.startsWith('//')) return redirectFrom;

    return isAllowed ? '/' : '/tasks';
};

/** @returns {Promise<{ challenge: string } | { isAllowed: boolean, mustEnrolMfa: boolean }>} */
export const signInWithPassword = async (email, password) => {
    await ensureCsrfCookie();
    const { data } = await axios.post('/api/v1/login', { email, password });
    if (data.data && data.data.mfa_required) return { challenge: data.data.challenge };

    return remember(data.data);
};

export const redeemChallenge = async (challenge, code) => {
    const { data } = await axios.post('/api/v1/login/mfa', { challenge, code: code.replace(/\s+/g, '') });

    return remember(data.data);
};

/** After the identity provider sends the browser back, the session cookie is already set; load who it belongs to. */
export const finishSso = async () => {
    const { data } = await axios.get('/api/v1/session');

    return remember(data.data);
};

/** `{ available, enforce }` for this firm, or nothing offered when the lookup fails. */
export const ssoConfig = async () => {
    try {
        const { data } = await axios.get('/api/v1/sso/config');

        return { available: !!data.data?.available, enforce: !!data.data?.enforce };
    } catch {
        return { available: false, enforce: false };
    }
};

export const SSO_START_URL = '/api/v1/sso/start';
