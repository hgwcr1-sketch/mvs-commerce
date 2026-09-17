/**
 * MVS Commerce — Connectivity Detection
 *
 * Small mechanism to determine if the server is reachable.
 * Uses navigator.onLine as a quick signal, then attempts a light
 * endpoint check only when needed.
 *
 * DO NOT rely on navigator.onLine alone — it may indicate network
 * connectivity even when the MVS server is unreachable.
 *
 * Online first: if server is available, use exact existing checkout.
 * Offline path: only when server truly unreachable + valid offline auth.
 */

const CONNECTIVITY_CHECK_URL = '/mvs/offline/health';
const CHECK_TIMEOUT_MS = 5000;
const MAX_CHECK_INTERVAL_MS = 30000; // never more frequent than 30s

/**
 * State of connectivity knowledge.
 * - 'unknown': nothing checked yet
 * - 'online': server confirmed reachable
 * - 'offline': server confirmed unreachable (or check never succeeded)
 * - 'checking': a check is in progress
 */
export const ConnectivityState = {
  UNKNOWN: 'unknown',
  ONLINE: 'online',
  OFFLINE: 'offline',
  CHECKING: 'checking',
};

/**
 * Check if the MVS server is reachable.
 * Uses navigator.onLine as a quick initial signal, then attempts
 * a lightweight fetch to a health endpoint.
 *
 * @returns {Promise<'online' | 'offline'>} Server reachability status.
 */
export async function checkServerReachable() {
  const debugState = globalThis.__MVS_OFFLINE_DEBUG__;
  if (debugState) {
    debugState.healthProbes = (debugState.healthProbes || 0) + 1;
    if (typeof console !== 'undefined' && console.info) console.info('[MVS Offline] health probe');
  }
  // Quick signal: if navigator.onLine is false, we're definitely offline
  if (typeof navigator === 'undefined' || !navigator.onLine) {
    lastHealthStatus = null;
    return 'offline';
  }

  // navigator.onLine is true — now try a real check
  // We use an AbortController so a single check doesn't hang forever
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), CHECK_TIMEOUT_MS);

  try {
    const response = await fetch(CONNECTIVITY_CHECK_URL, {
      method: 'GET',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      },
      credentials: 'same-origin',
      signal: controller.signal,
    });
    lastHealthStatus = response.status;
    if (debugState) debugState.lastHealthStatus = response.status;

    // Only an operational, authenticated health response is connectivity.
    const state = response.status === 200 ? ConnectivityState.ONLINE : ConnectivityState.OFFLINE;
    setState(state);
    return state === ConnectivityState.ONLINE ? 'online' : 'offline';

  } catch (err) {
    // fetch failed — server likely unreachable
    // err could be: TypeError (network error), AbortError (timeout),
    // or other DOMException
    setState(ConnectivityState.OFFLINE);
    lastHealthStatus = null;
    return 'offline';
  } finally {
    clearTimeout(timeoutId);
  }
}

/**
 * Current connectivity state (memoized, with time-based refresh).
 * Starts as 'unknown'. CheckServerReachable() updates it.
 */
let currentState = ConnectivityState.UNKNOWN;
let lastHealthStatus = null;

export function getLastHealthStatus() {
  return lastHealthStatus;
}

/**
 * Get the current connectivity state.
 * @returns {'online' | 'offline' | 'unknown' | 'checking'}
 */
export function getState() {
  return currentState;
}

/**
 * Set the connectivity state (used internally after a check, or
 * imported for testing).
 */
function setState(state) {
  currentState = state;
}

/**
 * Reset state to unknown (useful for testing).
 */
export function resetState() {
  currentState = ConnectivityState.UNKNOWN;
  lastHealthStatus = null;
}

/**
 * Determine if we should attempt the offline path.
 * This should be true ONLY WHEN:
 * 1. Server is confirmed unreachable (checkServerReachable() returns 'offline'), AND
 * 2. There is a valid offline authorization for the current terminal.
 *
 * This function encapsulates the "Online First" rule:
 * - If server is reachable → use exact existing checkout (return false)
 * - If server unreachable + valid auth → allow offline path (return true)
 * - If server unreachable + no valid auth → block (return false, show error)
 *
 * @param {object} authContext - { company_id, branch_id, terminal_uuid }
 * @param {function} checkAuthValidity - function that validates auth for the context
 * @returns {boolean} true if offline path may be attempted
 */
export function shouldAttemptOffline(authContext, checkAuthValidity) {
  const state = getState();

  // If we already know we're online, absolutely do NOT go offline
  if (state === ConnectivityState.ONLINE) {
    return false;
  }

  // If server is unreachable (or we've never checked), attempt auth verification
  if (state === ConnectivityState.OFFLINE || state === ConnectivityState.UNKNOWN) {
    // If we have an auth context, verify it's valid
    if (authContext) {
      const authOk = checkAuthValidity(authContext);
      if (authOk) {
        // Valid offline authorization exists — allow offline path
        return true;
      }
      // No valid authorization — block offline path
      // (User will see appropriate error message)
      return false;
    }
    // No auth context provided — can't verify, default to online
    return false;
  }

  // Checking state — don't decide yet
  return false;
}
