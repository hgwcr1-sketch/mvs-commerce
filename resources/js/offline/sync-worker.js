/**
 * MVS Commerce — Offline Sync Worker
 *
 * Synchronizes pending operations (IndexedDB) to the official MVS server
 * ONE operation at a time (FIFO). Strict MAX_CONCURRENT = 1: no Promise.all,
 * no batches. Each operation: markSyncing → POST → wait for server ACK →
 * markSynced → small pause → next.
 *
 * Backpressure: a configurable pause separates consecutive operations so a
 * bulk recovery (e.g. 100 offline tickets) never floods the server. This same
 * philosophy will be reused later for Factura Electrónica (Hacienda) queues,
 * which will be server-side.
 *
 * Retry policy:
 * - transient (network / timeout / 408 / 5xx) → markTransientError → backoff
 * - permanent (409 conflict / 422 validation) → markFailed, op is preserved
 *   (never deleted), worker continues with the next FIFO operation
 * - auth revoked (401/403) → markFailed and STOP the whole sync
 * - already_processed (idempotent ACK) → markSynced with the same sale
 *
 * Sync lock: module-level running flag (simple and reliable). Prevents two
 * worker calls (online event, focus event, manual trigger) from running at
 * once. No heavy dependency.
 */

import {
  getPendingOperations,
  markSyncing,
  markSynced,
  markTransientError,
  markFailed,
} from './pending-operations.js';

import { checkServerReachable } from './connectivity.js';

const SYNC_URL = '/mvs/offline/sync';
const MAX_CONCURRENT = 1;
const PAUSE_BETWEEN_OPERATIONS_MS = 300;
const BASE_BACKOFF_MS = 2000;
const MAX_BACKOFF_MS = 60000;
const MAX_SINGLE_RUN_MS = 120000;

let running = false;
let currentBackoffMs = BASE_BACKOFF_MS;
let abortRequested = false;

/**
 * Whether a sync run is currently in progress.
 */
export function isSyncRunning() {
  return running;
}

/**
 * Current backoff delay configured after the last transient error.
 */
export function getBackoffMs() {
  return currentBackoffMs;
}

/**
 * Reset the backoff sequence to its base value.
 */
export function resetBackoff() {
  currentBackoffMs = BASE_BACKOFF_MS;
}

/**
 * Ask an in-progress sync run to stop after the current operation.
 */
export function stopSync() {
  abortRequested = true;
}

function getCsrfToken() {
  if (typeof document !== 'undefined' && document.querySelector) {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta && meta.getAttribute ? meta.getAttribute('content') : '';
  }

  return '';
}

function classifyResponse(status) {
  if (status === 401 || status === 403) return 'auth';
  if (status === 409) return 'conflict';
  if (status === 400 || status === 422) return 'validation';
  if (status === 408 || status >= 500) return 'transient';
  return 'ok';
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function readMessage(response) {
  try {
    const body = await response.json();
    return body?.message || 'Operación rechazada por el servidor.';
  } catch (_) {
    return 'Operación rechazada por el servidor.';
  }
}

/**
 * Trigger a programmatic sync. Returns immediately when the worker is
 * already running (lock). Safe to call from: browser 'online' event,
 * health-check confirmation, or visibility focus.
 *
 * @param {object} context - { company_id, branch_id, terminal_uuid }
 * @param {object} [options] - { authorization: string }
 * @returns {Promise<object>} Summary of the run.
 */
export async function syncPendingOperations(context, { authorization } = {}) {
  if (running) {
    return {
      started: false,
      locked: true,
      attempted: 0,
      synced: 0,
      failed: 0,
      retained: 0,
      reason: 'locked',
      backoffMs: currentBackoffMs,
    };
  }

  if (!context || !context.company_id || !context.branch_id || !context.user_id || !context.terminal_uuid) {
    return {
      started: false,
      locked: false,
      attempted: 0,
      synced: 0,
      failed: 0,
      retained: 0,
      reason: 'missing_context',
      backoffMs: currentBackoffMs,
    };
  }

  running = true;
  abortRequested = false;

  const summary = {
    started: true,
    locked: false,
    attempted: 0,
    synced: 0,
    failed: 0,
    retained: 0,
    reason: 'complete',
    backoffMs: currentBackoffMs,
  };

  const startedAt = Date.now();

  try {
    // eslint-disable-next-line no-constant-condition
    while (Date.now() - startedAt < MAX_SINGLE_RUN_MS) {
      if (abortRequested) {
        summary.reason = 'stopped';
        break;
      }

      // eslint-disable-next-line no-await-in-loop
      const reachable = await checkServerReachable();

      if (reachable !== 'online') {
        summary.reason = 'offline';
        summary.retained += 1;
        break;
      }

      // One run processes the FIFO queue; transient errors stop the run so the
      // caller waits the backoff before triggering again.
      // eslint-disable-next-line no-await-in-loop
      const pending = (await getPendingOperations(context))
        .filter((op) => op.status === 'pending')
        .filter((op) => Number(op.user_id) === Number(context.user_id))
        .sort((a, b) => new Date(a.created_at_local) - new Date(b.created_at_local));

      if (pending.length === 0) {
        resetBackoff();
        summary.reason = 'idle';
        break;
      }

      let transientHit = false;

      // eslint-disable-next-line no-await-in-loop
      for (const op of pending) {
        if (abortRequested) {
          summary.reason = 'stopped';
          break;
        }

        // eslint-disable-next-line no-await-in-loop
        await markSyncing(op.id);
        summary.attempted += 1;

        let response;
        let networkError = null;

        try {
          // eslint-disable-next-line no-await-in-loop
          response = await fetch(SYNC_URL, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': getCsrfToken(),
              'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              operation_uuid: op.operation_uuid,
              operation_type: op.operation_type,
              terminal_uuid: op.terminal_uuid,
              payload_version: (op.payload && op.payload.payload_version) || 1,
              created_at_local: op.created_at_local,
              payload: op.payload,
              authorization,
            }),
          });
        } catch (err) {
          networkError = err;
        }

        if (networkError) {
          // eslint-disable-next-line no-await-in-loop
          await markTransientError(op.id, 'Network error: no response from server.');
          summary.retained += 1;
          transientHit = true;
          break;
        }

        const kind = classifyResponse(response.status);

        if (kind === 'ok') {
          let body = {};
          try {
            // eslint-disable-next-line no-await-in-loop
            body = await response.json();
          } catch (_) {
            body = {};
          }

          const bodyStatus = body.status;

          if (bodyStatus === 'processed' || bodyStatus === 'already_processed') {
            // Server ACK: mark synced. already_processed means the server had
            // already committed this operation (e.g. lost ACK) and returned the
            // same sale — idempotency contract honored.
            // eslint-disable-next-line no-await-in-loop
            await markSynced(op.id, {
              server_sale_id: body.sale_id ?? body.server_sale_id,
              server_sale_number: body.sale_number ?? body.server_sale_number,
              acked_at: new Date().toISOString(),
            });
            summary.synced += 1;
            resetBackoff();
          } else if (bodyStatus === 'in_progress') {
            // Another request is currently processing the same UUID.
            // eslint-disable-next-line no-await-in-loop
            await markTransientError(op.id, 'Operación ya en proceso. Reintentando más tarde.');
            summary.retained += 1;
            transientHit = true;
            break;
          } else {
            // eslint-disable-next-line no-await-in-loop
            await markTransientError(op.id, body.message || 'Respuesta inesperada del servidor.');
            summary.retained += 1;
            transientHit = true;
            break;
          }
        } else if (kind === 'auth') {
          // eslint-disable-next-line no-await-in-loop
          await markFailed(op.id, await readMessage(response));
          summary.failed += 1;
          summary.reason = 'auth_revoked';
          summary.backoffMs = MAX_BACKOFF_MS;
          break;
        } else if (kind === 'conflict' || kind === 'validation') {
          // Permanent per-operation error: keep the operation (never delete),
          // record the reason, continue with the next FIFO operation.
          // eslint-disable-next-line no-await-in-loop
          await markFailed(op.id, await readMessage(response));
          summary.failed += 1;
        } else {
          // Transient (408 / 5xx): keep requeueable, stop the run, backoff.
          // eslint-disable-next-line no-await-in-loop
          await markTransientError(op.id, `Error transitorio del servidor (HTTP ${response.status}).`);
          summary.retained += 1;
          transientHit = true;
          break;
        }

        if (abortRequested) {
          break;
        }

        // Backpressure pause between operations.
        // eslint-disable-next-line no-await-in-loop
        await sleep(PAUSE_BETWEEN_OPERATIONS_MS);
      }

      if (transientHit) {
        // Registry currentBackoffMs growth via a scheduled delay; the caller
        // decides when to re-trigger. We expose the value for testing.
        summary.backoffMs = currentBackoffMs;
        currentBackoffMs = Math.min(MAX_BACKOFF_MS, currentBackoffMs * 2);
        summary.reason = 'transient';
        break;
      }
    }
  } finally {
    running = false;
  }

  return summary;
}

export { MAX_CONCURRENT, PAUSE_BETWEEN_OPERATIONS_MS, SYNC_URL };
