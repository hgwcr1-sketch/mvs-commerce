/**
 * MVS Commerce — Offline Readiness Check
 *
 * Determines if the POS can operate in offline mode.
 * All conditions must be met:
 * - Service Worker registered and active
 * - Valid snapshot in IndexedDB
 * - Valid authorization in IndexedDB (not expired, max 48h)
 * - Public key available for signature verification
 * - Company/branch/terminal context matches
 */

import { getSnapshot, getSnapshotMetadata } from './snapshot.js';
import { getAuthorization, isAuthorizationValid } from './authorization.js';
import { openDB, STORES } from './db.js';

const PUBLIC_KEY_STORAGE_KEY = 'mvs_offline_public_key';
const PUBLIC_KEY_META_NAME = 'mvsoffline-public-key';

/**
 * Check if Service Worker is registered and active for POS scope.
 */
export async function checkServiceWorkerReady() {
  if (!('serviceWorker' in navigator)) {
    return { ready: false, reason: 'Service Worker not supported' };
  }

  try {
    const registration = await navigator.serviceWorker.getRegistration('/');
    if (!registration) {
      return { ready: false, reason: 'Service Worker not registered' };
    }

    if (!registration.active) {
      return { ready: false, reason: 'Service Worker not active' };
    }

    // Check if it's our cache version
    const version = await getServiceWorkerVersion(registration);
    if (!version) {
      return { ready: false, reason: 'Service Worker version unknown' };
    }

    return { ready: true, version };
  } catch (error) {
    return { ready: false, reason: `Service Worker check failed: ${error.message}` };
  }
}

/**
 * Get Service Worker cache version via message channel.
 */
function getServiceWorkerVersion(registration) {
  return new Promise((resolve) => {
    const channel = new MessageChannel();
    channel.port1.onmessage = (event) => {
      resolve(event.data?.version || null);
    };
    registration.active.postMessage('getVersion', [channel.port2]);
    // Timeout after 2 seconds
    setTimeout(() => resolve(null), 2000);
  });
}

/**
 * Check if a valid snapshot exists in IndexedDB for the current context.
 */
export async function checkSnapshotReady(context) {
  if (!context?.company_id || !context?.branch_id || !context?.terminal_uuid) {
    return { ready: false, reason: 'Missing context for snapshot check' };
  }

  try {
    const metadata = await getSnapshotMetadata(context);
    if (!metadata) {
      return { ready: false, reason: 'No snapshot found in IndexedDB' };
    }

    // Validate snapshot structure
    const snapshot = await getSnapshot(context);
    if (!snapshot) {
      return { ready: false, reason: 'Snapshot data corrupted or missing' };
    }

    // Check required fields
    const required = ['schema_version', 'generated_at', 'company', 'branch', 'terminal', 'products', 'customers', 'payment_methods'];
    for (const field of required) {
      if (!snapshot[field]) {
        return { ready: false, reason: `Snapshot missing required field: ${field}` };
      }
    }

    // Context match
    if (snapshot.company?.id !== context.company_id) {
      return { ready: false, reason: 'Snapshot company mismatch' };
    }
    if (snapshot.branch?.id !== context.branch_id) {
      return { ready: false, reason: 'Snapshot branch mismatch' };
    }
    if (snapshot.terminal?.terminal_uuid !== context.terminal_uuid) {
      return { ready: false, reason: 'Snapshot terminal mismatch' };
    }

    return { ready: true, metadata };
  } catch (error) {
    return { ready: false, reason: `Snapshot check failed: ${error.message}` };
  }
}

/**
 * Check if a valid authorization exists in IndexedDB for the current context.
 */
export async function checkAuthorizationReady(context) {
  if (!context?.company_id || !context?.branch_id || !context?.terminal_uuid) {
    return { ready: false, reason: 'Missing context for authorization check' };
  }

  try {
    const isValid = await isAuthorizationValid(context);
    if (!isValid) {
      return { ready: false, reason: 'Authorization missing or expired' };
    }

    const auth = await getAuthorization(context);
    if (!auth) {
      return { ready: false, reason: 'Authorization data missing' };
    }

    // Check 48-hour max window from issued_at
    const issuedAt = new Date(auth.issued_at).getTime();
    const now = Date.now();
    const maxWindowMs = 48 * 60 * 60 * 1000;
    if (now - issuedAt > maxWindowMs) {
      return { ready: false, reason: 'Authorization exceeds 48-hour maximum window' };
    }

    return { ready: true, auth };
  } catch (error) {
    return { ready: false, reason: `Authorization check failed: ${error.message}` };
  }
}

/**
 * Check if public key is available (stored locally or in meta tag).
 */
export async function checkPublicKeyReady() {
  // Try localStorage first (persisted from online fetch)
  const stored = localStorage.getItem(PUBLIC_KEY_STORAGE_KEY);
  if (stored) {
    try {
      const parsed = JSON.parse(stored);
      if (parsed?.key && parsed?.fetched_at) {
        // Verify it's a valid PEM format
        if (parsed.key.includes('BEGIN PUBLIC KEY') || parsed.key.includes('BEGIN RSA PUBLIC KEY')) {
          return { ready: true, key: parsed.key, source: 'localStorage' };
        }
      }
    } catch {
      // Invalid JSON, continue to other sources
    }
  }

  // Try meta tag (delivered by server in POS page)
  const meta = document.querySelector(`meta[name="${PUBLIC_KEY_META_NAME}"]`);
  if (meta?.content) {
    const key = meta.content;
    if (key.includes('BEGIN PUBLIC KEY') || key.includes('BEGIN RSA PUBLIC KEY')) {
      // Store for future offline use
      try {
        localStorage.setItem(PUBLIC_KEY_STORAGE_KEY, JSON.stringify({ key, fetched_at: Date.now() }));
      } catch {
        // Ignore storage errors
      }
      return { ready: true, key, source: 'meta' };
    }
  }

  // Try to fetch from server (only works online)
  try {
    const response = await fetch('/mvs/offline/public-key', { credentials: 'same-origin' });
    if (response.ok) {
      const data = await response.json();
      if (data?.public_key) {
        const key = data.public_key;
        if (key.includes('BEGIN PUBLIC KEY') || key.includes('BEGIN RSA PUBLIC KEY')) {
          try {
            localStorage.setItem(PUBLIC_KEY_STORAGE_KEY, JSON.stringify({ key, fetched_at: Date.now() }));
          } catch {
            // Ignore
          }
          return { ready: true, key, source: 'server' };
        }
      }
    }
  } catch {
    // Offline or server error
  }

  return { ready: false, reason: 'Public key not available' };
}

/**
 * Get the public key for offline signature verification.
 * Returns the key or null if not available.
 */
export async function getPublicKey() {
  const result = await checkPublicKeyReady();
  return result.ready ? result.key : null;
}

/**
 * Store public key locally for offline use.
 * Called when online to prepare for offline operation.
 */
export async function storePublicKeyLocally(key) {
  if (!key || typeof key !== 'string') {
    return { success: false, reason: 'Invalid key' };
  }
  if (!key.includes('BEGIN PUBLIC KEY') && !key.includes('BEGIN RSA PUBLIC KEY')) {
    return { success: false, reason: 'Key must be in PEM format' };
  }
  try {
    localStorage.setItem(PUBLIC_KEY_STORAGE_KEY, JSON.stringify({ key, fetched_at: Date.now() }));
    return { success: true };
  } catch (error) {
    return { success: false, reason: error.message };
  }
}

/**
 * Clear stored public key (for testing or key rotation).
 */
export function clearStoredPublicKey() {
  localStorage.removeItem(PUBLIC_KEY_STORAGE_KEY);
}

/**
 * Main offline readiness check.
 * Returns { ready: boolean, checks: object, blockers: string[] }
 */
export async function checkOfflineReady(context) {
  const checks = {};
  const blockers = [];

  // 1. Service Worker
  checks.serviceWorker = await checkServiceWorkerReady();
  if (!checks.serviceWorker.ready) blockers.push(`Service Worker: ${checks.serviceWorker.reason}`);

  // 2. Snapshot
  checks.snapshot = await checkSnapshotReady(context);
  if (!checks.snapshot.ready) blockers.push(`Snapshot: ${checks.snapshot.reason}`);

  // 3. Authorization
  checks.authorization = await checkAuthorizationReady(context);
  if (!checks.authorization.ready) blockers.push(`Authorization: ${checks.authorization.reason}`);

  // 4. Public Key
  checks.publicKey = await checkPublicKeyReady();
  if (!checks.publicKey.ready) blockers.push(`Public Key: ${checks.publicKey.reason}`);

  // 5. Context match (already validated in snapshot/authorization checks)

  const ready = blockers.length === 0;

  return {
    ready,
    checks,
    blockers,
    context: context ? {
      company_id: context.company_id,
      branch_id: context.branch_id,
      terminal_uuid: context.terminal_uuid,
    } : null,
  };
}

/**
 * Convenience: check if offline sale can proceed.
 * Requires offlineReady + valid public key for signature verification.
 */
export async function canMakeOfflineSale(context, authToken) {
  const readyCheck = await checkOfflineReady(context);
  if (!readyCheck.ready) {
    return { allowed: false, reason: `Offline not ready: ${readyCheck.blockers.join(', ')}` };
  }

  // Verify we have a public key
  const publicKey = await getPublicKey();
  if (!publicKey) {
    return { allowed: false, reason: 'Public key not available for signature verification' };
  }

  // Verify the auth token signature (imported from auth-verification)
  const { verifyAuthSignature } = await import('./auth-verification.js');
  const verifyResult = await verifyAuthSignature(authToken, publicKey, context);
  if (!verifyResult.valid) {
    return { allowed: false, reason: verifyResult.error };
  }

  return { allowed: true, auth: verifyResult.auth };
}