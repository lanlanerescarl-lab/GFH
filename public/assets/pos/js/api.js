// Every call the POS makes to the server goes through here: the CSRF header, the JSON envelope, and error mapping.
//
// Session timeout: when a call is refused because the session ended, the app's sign-in dialog opens, the person signs
// in again here (POST /auth/staff/login with a fresh CSRF token), and the same call is sent again. The ticket lives in
// memory in the app and is never touched by any of this, so it is never lost.
//
// A server that has lost the session answers a change with 403 csrf_mismatch (the CSRF check runs before the
// sign-in check), so csrf_mismatch is handled first: fetch a fresh token and send once more. If that comes back 401
// the person really is signed out, and the sign-in dialog opens.
//
// Nothing about authentication is kept in browser storage: the CSRF token lives in this closure only.

export class ApiError extends Error {
  constructor({ status = 0, code = 'server_error', message = 'Something went wrong. Please try again.', fields = {}, network = false } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
    /** True when the server could not be reached at all (offline, timeout, DNS), as opposed to an answer saying no. */
    this.network = network;
  }
}

const NETWORK_MESSAGE = 'Cannot reach the server. Check the connection, then try again.';

/**
 * @param {object} options
 * @param {string} options.base the API base path, for example "/demo/api/v1"
 * @param {string} options.csrf the session's CSRF token
 * @param {typeof fetch} [options.fetchImpl] the fetch to use (tests pass a fake)
 * @param {(api: object) => Promise<void>} [options.requestSignIn] opens the sign-in dialog; resolves after api.login() succeeded, rejects if the person gives up
 * @param {(session: {user: object, csrf: string}) => void} [options.onSession] told when someone signs in again
 * @param {(online: boolean) => void} [options.onNetwork] told after every request whether the server could be reached
 */
export function createApi({ base, csrf, fetchImpl = (...args) => globalThis.fetch(...args), requestSignIn = null, onSession = () => {}, onNetwork = () => {} }) {
  let token = csrf;
  let signingIn = null;

  async function send(method, path, body) {
    const headers = { Accept: 'application/json' };
    const init = { method, headers, credentials: 'same-origin' };
    if (method !== 'GET') {
      headers['X-CSRF-Token'] = token;
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body ?? {});
    }

    let response;
    try {
      response = await fetchImpl(base + path, init);
    } catch {
      onNetwork(false);
      throw new ApiError({ network: true, message: NETWORK_MESSAGE });
    }
    onNetwork(true);

    let envelope = null;
    try {
      envelope = await response.json();
    } catch {
      envelope = null;
    }

    if (envelope !== null && typeof envelope === 'object' && envelope.ok === true) return envelope.data;

    const error = envelope?.error ?? {};
    throw new ApiError({
      status: response.status,
      code: error.code ?? 'server_error',
      message: error.message ?? 'The server sent an answer the POS cannot read. Please try again.',
      fields: error.fields ?? {},
    });
  }

  async function refreshCsrf() {
    const data = await send('GET', '/auth/staff/csrf');
    token = data.csrf_token;
    return token;
  }

  async function reauthenticate(original) {
    if (requestSignIn === null) throw original;
    signingIn ??= requestSignIn(api).finally(() => { signingIn = null; });
    try {
      await signingIn;
    } catch {
      throw original;
    }
  }

  async function call(method, path, body) {
    let refreshed = false;
    let signedIn = false;

    for (;;) {
      try {
        return await send(method, path, body);
      } catch (error) {
        if (!(error instanceof ApiError) || error.network) throw error;

        if (method !== 'GET' && error.status === 403 && error.code === 'csrf_mismatch' && !refreshed) {
          refreshed = true;
          await refreshCsrf();
          continue;
        }
        if (error.status === 401 && !signedIn) {
          signedIn = true;
          await reauthenticate(error);
          continue;
        }
        throw error;
      }
    }
  }

  const api = {
    get: (path) => call('GET', path),
    post: (path, body) => call('POST', path, body),
    csrfToken: () => token,

    /**
     * A page loaded from the service worker's saved copy has no CSRF token (the copy never keeps one). This fetches one
     * when the token is empty, for the forms that need it; it is not called otherwise.
     */
    async ensureCsrf() {
      if (token === '') await refreshCsrf();
      return token;
    },

    /**
     * A light check that the server can be reached: GET of the CSRF endpoint, with no sign-in dialog and no retry.
     * Any answer below 500 means yes (a 401 still proves the server is there). Used by the heartbeat.
     */
    async ping(timeoutMs = 8000) {
      const controller = typeof AbortController === 'function' ? new AbortController() : null;
      const timer = controller === null ? null : setTimeout(() => controller.abort(), timeoutMs);
      try {
        const response = await fetchImpl(`${base}/auth/staff/csrf`, {
          method: 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: controller?.signal,
        });
        return response.status < 500;
      } catch {
        return false;
      } finally {
        if (timer !== null) clearTimeout(timer);
      }
    },

    /** Signs in again (the sign-in dialog calls this). Throws an ApiError with the server's message on a wrong password or a lockout. */
    async login(email, password) {
      await refreshCsrf();
      const data = await send('POST', '/auth/staff/login', { email, password });
      token = data.csrf_token;
      onSession({ user: data.user, csrf: token });
      return data;
    },

    async logout() {
      return send('POST', '/auth/staff/logout', {});
    },

    // The POS endpoints, so every path is written once.
    terminals: async () => (await call('GET', '/pos/terminals')).items,
    snapshot: (terminalId) => call('GET', `/pos/terminals/${encodeURIComponent(terminalId)}/snapshot`),
    currentShift: (terminalId) => call('GET', `/pos/terminals/${encodeURIComponent(terminalId)}/shift`),
    openShift: (terminalId, openingCash) => call('POST', '/pos/shifts', { terminal_id: terminalId, opening_cash: openingCash }),
    closeShift: (shiftId, countedCash) => call('POST', `/pos/shifts/${encodeURIComponent(shiftId)}/close`, { counted_cash: countedCash }),
    postSale: (sale) => call('POST', '/pos/sales', sale),
    getSale: (saleId) => call('GET', `/pos/sales/${encodeURIComponent(saleId)}`),
  };

  return api;
}
