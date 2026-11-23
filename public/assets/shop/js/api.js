// The Shop's calls to its own JSON API (same origin only). It sends the CSRF header on changes, unwraps the API's
// envelope ({"ok": true, "data": ...} or {"ok": false, "error": {code, message, fields}}) and turns every failure into
// one ApiError, so a page handles "the server said no" and "the network failed" in one place. No DOM here.

export class ApiError extends Error {
  /**
   * @param {{status: number, code: string, message: string, fields?: Record<string, string>, retryAfter?: number|null}} details
   */
  constructor({ status, code, message, fields = {}, retryAfter = null }) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
    this.retryAfter = retryAfter;
  }

  /** The server refused the values (422), with a message per field. */
  get isValidation() {
    return this.status === 422;
  }

  /** The request never got an answer (offline, blocked, timed out). */
  get isNetwork() {
    return this.code === 'network';
  }
}

const NETWORK_MESSAGE = 'We could not reach the shop. Check your connection and try again.';
const UNEXPECTED_MESSAGE = 'Something went wrong. Please try again.';

/**
 * Field errors as {field: message}. Only plain text values are kept; anything else is dropped.
 */
export function fieldErrors(error) {
  const result = {};
  if (error instanceof ApiError) {
    for (const [name, message] of Object.entries(error.fields)) {
      if (typeof message === 'string' && message !== '') {
        result[name] = message;
      }
    }
  }
  return result;
}

/**
 * Turns a fetch response into the envelope's data, or throws an ApiError.
 */
export async function readEnvelope(response) {
  let body = null;
  try {
    body = await response.json();
  } catch {
    body = null;
  }

  if (body !== null && typeof body === 'object' && body.ok === true && response.ok) {
    return body.data;
  }

  const retry = Number.parseInt(response.headers?.get?.('Retry-After') ?? '', 10);
  const error = body !== null && typeof body === 'object' && body.error !== null && typeof body.error === 'object' ? body.error : {};
  const fields = error.fields !== null && typeof error.fields === 'object' && !Array.isArray(error.fields) ? error.fields : {};
  throw new ApiError({
    status: response.status,
    code: typeof error.code === 'string' ? error.code : 'unexpected',
    message: typeof error.message === 'string' && error.message !== '' ? error.message : UNEXPECTED_MESSAGE,
    fields,
    retryAfter: Number.isFinite(retry) ? retry : null,
  });
}

/**
 * @param {{baseUrl: string, csrfToken?: string, fetchImpl?: typeof fetch}} options
 *   baseUrl is the API root such as "/demo/api/v1" (same site); csrfToken is the session's current token.
 */
export function createApi({ baseUrl, csrfToken = '', fetchImpl = (...args) => globalThis.fetch(...args) }) {
  let token = csrfToken;

  async function send(method, path, body) {
    const headers = { Accept: 'application/json' };
    const init = { method, headers, credentials: 'same-origin' };
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = token;
      init.body = JSON.stringify(body ?? {});
    }

    let response;
    try {
      response = await fetchImpl(baseUrl + path, init);
    } catch {
      throw new ApiError({ status: 0, code: 'network', message: NETWORK_MESSAGE });
    }
    return readEnvelope(response);
  }

  async function refreshToken() {
    const data = await send('GET', '/auth/customer/csrf');
    if (typeof data?.csrf_token === 'string' && data.csrf_token !== '') {
      token = data.csrf_token;
      return true;
    }
    return false;
  }

  /** A change (POST). If the token went stale (signing in from another tab rotates it), fetch a new one and try once more. */
  async function post(path, body) {
    try {
      return await send('POST', path, body);
    } catch (error) {
      if (error instanceof ApiError && error.status === 403 && error.code === 'csrf_mismatch' && (await refreshToken().catch(() => false))) {
        return send('POST', path, body);
      }
      throw error;
    }
  }

  return {
    get: (path) => send('GET', path),
    post,
    refreshToken,
  };
}
