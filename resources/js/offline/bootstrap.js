import { openDB } from './db.js';
import { getAuthorization, isAuthorizationValid, saveAuthorization } from './authorization.js';
import { fetchAndStoreSnapshot } from './snapshot-client.js';
import { checkPublicKeyReady, storePublicKeyLocally } from './offline-ready.js';
import { getSnapshotMetadata } from './snapshot.js';
import { checkServerReachable, getLastHealthStatus } from './connectivity.js';
import { syncPendingOperations } from './sync-worker.js';

const TERMINAL_KEY = 'mvs-offline-terminal';
const RECOVERY_CHECK_INTERVAL_MS = 30000;
let syncTimer = null;
let recoveryTimer = null;
let lastFocusSync = 0;

const debugState = globalThis.__MVS_OFFLINE_DEBUG__ || {
  moduleLoaded: true,
  domReady: false,
  contextAvailable: false,
  runtimeStarted: false,
  healthProbes: 0,
  lastHealthStatus: null,
  lastResult: null,
};
globalThis.__MVS_OFFLINE_DEBUG__ = debugState;
if (typeof console !== 'undefined' && console.info) console.info('[MVS Offline] bootstrap module loaded');

function logFailure(error) {
  // Offline setup must never make the online POS unavailable.
  if (typeof console !== 'undefined' && console.warn) {
    console.warn('[MVS Offline] provisioning skipped:', error?.message || error);
  }
}

function readContext() {
  const context = globalThis.__MVS_OFFLINE_CONTEXT__;
  if (!context?.company_id || !context?.branch_id || !context?.user_id) return null;
  return {
    company_id: Number(context.company_id),
    branch_id: Number(context.branch_id),
    user_id: Number(context.user_id),
  };
}

function terminalUuid(context) {
  const key = `${TERMINAL_KEY}:${context.company_id}:${context.branch_id}`;
  const current = localStorage.getItem(key);
  if (current) return current;
  const uuid = crypto.randomUUID();
  localStorage.setItem(key, uuid);
  return uuid;
}

async function fetchAuthorization(context) {
  const provision = await fetch('/mvs/offline/provision', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    },
    body: JSON.stringify({ terminal_uuid: context.terminal_uuid }),
  });
  if (!provision.ok) throw new Error(`terminal provisioning HTTP ${provision.status}`);
  const response = await fetch('/mvs/offline/authorize', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    },
    body: JSON.stringify({ terminal_uuid: context.terminal_uuid }),
  });
  if (!response.ok) throw new Error(`authorization HTTP ${response.status}`);
  const data = await response.json();
  const token = data.authorization || data.token;
  if (!token) throw new Error('authorization response has no token');
  return {
    ...data,
    authorization: token,
    token,
    company_id: context.company_id,
    branch_id: context.branch_id,
    terminal_uuid: context.terminal_uuid,
  };
}

async function fetchPublicKey() {
  const existing = await checkPublicKeyReady();
  if (existing.ready) return;
  const response = await fetch('/mvs/offline/public-key', { credentials: 'same-origin' });
  if (!response.ok) throw new Error(`public key HTTP ${response.status}`);
  const data = await response.json();
  const result = await storePublicKeyLocally(data.public_key);
  if (!result.success) throw new Error(result.reason);
}

async function provision(context) {
  context.terminal_uuid = terminalUuid(context);
  globalThis.__MVS_OFFLINE_CONTEXT__ = context;
  // Local persistence must not depend on Service Worker installation or server
  // provisioning succeeding first.
  await openDB();
  if ('serviceWorker' in navigator) {
    try {
      await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    } catch (error) {
      logFailure(error);
    }
  }
  await fetchPublicKey();

  let auth = await getAuthorization(context);
  if (!auth || !(await isAuthorizationValid(context))) {
    const reachable = await checkServerReachable();
    if (reachable !== 'online') {
      throw new Error(getLastHealthStatus() ? `health HTTP ${getLastHealthStatus()}` : 'server health did not confirm availability');
    }
    auth = await fetchAuthorization(context);
    await saveAuthorization(auth, context);
  }

  const health = await checkServerReachable();
  if (health === 'online') {
    const snapshotResult = await fetchAndStoreSnapshot({
      authorizationToken: auth.token,
      terminalUuid: context.terminal_uuid,
      companyId: context.company_id,
      branchId: context.branch_id,
      userId: context.user_id,
    });
    if (!snapshotResult.success) throw new Error(snapshotResult.error);
  } else if (getLastHealthStatus() || !await getSnapshotMetadata(context)) {
    throw new Error('snapshot unavailable while server is unreachable');
  }
  return context;
}

function scheduleSync(context, delay = 0) {
  if (syncTimer) {
    if (delay === 0) return;
    clearTimeout(syncTimer);
  }
  syncTimer = setTimeout(async () => {
    syncTimer = null;
    try {
      const health = await checkServerReachable();
      if (health !== 'online') return;
      const auth = await getAuthorization(context);
      const summary = await syncPendingOperations(context, { authorization: auth?.token });
      if (summary.reason === 'transient') scheduleSync(context, summary.backoffMs);
    } catch (error) {
      logFailure(error);
    }
  }, delay);
}

function scheduleBackendRecovery(context) {
  if (recoveryTimer) clearTimeout(recoveryTimer);
  recoveryTimer = setTimeout(async () => {
    recoveryTimer = null;
    try {
      if (await checkServerReachable() === 'online') scheduleSync(context);
    } catch (error) {
      logFailure(error);
    } finally {
      scheduleBackendRecovery(context);
    }
  }, RECOVERY_CHECK_INTERVAL_MS);
}

export async function bootstrapOffline() {
  const context = readContext();
  debugState.domReady = document.readyState !== 'loading';
  debugState.contextAvailable = Boolean(context);
  if (typeof console !== 'undefined' && console.info) {
    console.info('[MVS Offline] DOM ready', document.readyState);
    console.info('[MVS Offline] POS context found', Boolean(context));
  }
  if (!context || typeof indexedDB === 'undefined') {
    debugState.lastResult = 'no POS context';
    return { ready: false, reason: 'no POS context' };
  }
  try {
    debugState.runtimeStarted = true;
    if (typeof console !== 'undefined' && console.info) console.info('[MVS Offline] runtime started');
    context.terminal_uuid = terminalUuid(context);
    globalThis.__MVS_OFFLINE_CONTEXT__ = context;
    await openDB();

    const reconnect = () => scheduleSync(context);
    window.addEventListener('online', reconnect, { passive: true });
    window.addEventListener('focus', () => {
      if (Date.now() - lastFocusSync < 15000) return;
      lastFocusSync = Date.now();
      scheduleSync(context);
    }, { passive: true });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') scheduleSync(context);
    }, { passive: true });
    scheduleBackendRecovery(context);

    const provisioned = await provision(context);
    if (typeof console !== 'undefined' && console.info) console.info('[MVS Offline] pending drain requested');
    scheduleSync(provisioned);
    debugState.lastResult = 'ready';
    return { ready: true, context: provisioned };
  } catch (error) {
    debugState.lastResult = error?.message || 'offline bootstrap failed';
    logFailure(error);
    return { ready: false, reason: error?.message || 'offline bootstrap failed' };
  }
}

export function startOfflineBootstrap() {
  return bootstrapOffline().catch((error) => {
    logFailure(error);
    return { ready: false, reason: error?.message || 'offline bootstrap failed' };
  });
}

if (typeof window !== 'undefined') {
  const start = () => startOfflineBootstrap();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
}
