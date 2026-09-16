/**
 * MVS Commerce — Local Pending Operations Queue
 *
 * FIFO queue stored in IndexedDB for operations created offline.
 * Each operation gets a UUID v4 generated locally BEFORE persistence.
 *
 * States: pending → syncing → synced | failed
 */

import { STORES, put, getByKey, getByIndex, getAll, remove } from './db.js';

const VALID_STATES = ['pending', 'syncing', 'synced', 'failed'];

const VALID_TRANSITIONS = {
  pending: ['syncing'],
  syncing: ['synced', 'failed', 'pending'],
  synced: [],
  failed: ['pending'],
};

/**
 * Generate UUID v4 using crypto.randomUUID() or crypto.getRandomValues().
 * Never uses Math.random. Falls back to secure random only.
 * Throws if no cryptographic random source is available.
 */
export function generateUUID() {
  // 1. Try standard crypto.randomUUID() first (modern browsers)
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }

  // 2. Fallback: construct UUID v4 using crypto.getRandomValues()
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    const array = new Uint16Array(8); // 8 x 16-bit values = 128 bits
    crypto.getRandomValues(array);

    // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
    // The 13th character must be '4' (version), the 17th must be '8', '9', 'a', or 'b' (variant)
    const hex = (i) => array[i].toString(16).padStart(4, '0');

    // Generate with version 4 and variant bits
    return `${hex(0)}${hex(1)}${hex(2)}${hex(3)}-${hex(4)}${hex(5)}-4${hex(6)}${hex(7)}-8${hex(8)}${hex(9)}-${hex(10)}${hex(11)}${hex(12)}${hex(13)}${hex(14)}${hex(15)}`;
  }

  // 3. No cryptographic random source available — fail securely
  throw new Error('No cryptographic random source available for UUID generation. Cannot create operation.');
}

/**
 * Validate a pending operation before enqueuing.
 */
export function validateOperation(op) {
  if (!op || typeof op !== 'object') {
    return { valid: false, error: 'Operation must be a non-null object' };
  }

  if (!op.operation_type) {
    return { valid: false, error: 'Missing operation_type' };
  }

  const ctx = ['company_id', 'branch_id', 'terminal_uuid', 'user_id'];
  for (const field of ctx) {
    if (!op[field]) {
      return { valid: false, error: `Missing ${field}` };
    }
  }

  if (!op.payload || typeof op.payload !== 'object') {
    return { valid: false, error: 'Missing or invalid payload' };
  }

  return { valid: true };
}

/**
 * Enqueue a new pending operation.
 * Generates UUID v4 locally, sets status to pending, persists.
 */
export async function enqueueOperation(op) {
  const validation = validateOperation(op);
  if (!validation.valid) {
    throw new Error(`Invalid operation: ${validation.error}`);
  }

  const operation = {
    id: generateUUID(),
    operation_uuid: generateUUID(),
    operation_type: op.operation_type,
    company_id: op.company_id,
    branch_id: op.branch_id,
    terminal_uuid: op.terminal_uuid,
    user_id: op.user_id,
    created_at_local: new Date().toISOString(),
    payload: op.payload,
    status: 'pending',
    attempts: 0,
    last_attempt_at: null,
    last_error: null,
  };

  await put(STORES.pending_operations, operation);
  return operation;
}

/**
 * Get all pending operations for a terminal context, ordered by created_at_local (FIFO).
 */
export async function getPendingOperations(context) {
  if (!context?.terminal_uuid) {
    throw new Error('Missing terminal_uuid in context');
  }

  const all = await getByIndex(
    STORES.pending_operations,
    'by_context',
    [context.company_id, context.branch_id, context.terminal_uuid]
  );

  return all.sort((a, b) => new Date(a.created_at_local) - new Date(b.created_at_local));
}

/**
 * Get all operations (any status) for a terminal context.
 */
export async function getAllOperations(context) {
  return getPendingOperations(context);
}

/**
 * Get operations filtered by status for a terminal context.
 */
export async function getOperationsByStatus(context, status) {
  const all = await getPendingOperations(context);
  return all.filter((op) => op.status === status);
}

/**
 * Update operation status with validation.
 */
async function updateStatus(id, newStatus, extraFields = {}) {
  if (!VALID_STATES.includes(newStatus)) {
    throw new Error(`Invalid status: ${newStatus}`);
  }

  const op = await getByKey(STORES.pending_operations, id);
  if (!op) {
    throw new Error(`Operation not found: ${id}`);
  }

  const allowed = VALID_TRANSITIONS[op.status] || [];
  if (!allowed.includes(newStatus)) {
    throw new Error(`Cannot transition from ${op.status} to ${newStatus}`);
  }

  const updated = {
    ...op,
    status: newStatus,
    ...extraFields,
  };

  await put(STORES.pending_operations, updated);
  return updated;
}

/**
 * Mark operation as syncing (being sent to server).
 */
export async function markSyncing(id) {
  return updateStatus(id, 'syncing', {
    last_attempt_at: new Date().toISOString(),
  });
}

/**
 * Mark operation as pending (retry after failure).
 */
export async function markPending(id) {
  return updateStatus(id, 'pending');
}

/**
 * Mark operation as pending after a TRANSIENT error (timeout, 5xx, network).
 * Unlike markPending(), this increments attempts and stores the error reason so
 * the client retains traceability while keeping the operation requeueable.
 */
export async function markTransientError(id, errorMessage) {
  const op = await getByKey(STORES.pending_operations, id);
  if (!op) {
    throw new Error(`Operation not found: ${id}`);
  }

  return updateStatus(id, 'pending', {
    attempts: (op.attempts || 0) + 1,
    last_error: errorMessage || 'Transient error',
    last_attempt_at: new Date().toISOString(),
  });
}

/**
 * Mark operation as synced (server ACK received).
 */
export async function markSynced(id) {
  return updateStatus(id, 'synced');
}

/**
 * Mark operation as failed with error message and increment attempts.
 */
export async function markFailed(id, errorMessage) {
  const op = await getByKey(STORES.pending_operations, id);
  if (!op) {
    throw new Error(`Operation not found: ${id}`);
  }

  return updateStatus(id, 'failed', {
    attempts: (op.attempts || 0) + 1,
    last_error: errorMessage || 'Unknown error',
  });
}

/**
 * Delete a specific operation by id.
 */
export async function deleteOperation(id) {
  return remove(STORES.pending_operations, id);
}

/**
 * Delete all synced operations for a context (cleanup).
 */
export async function clearSyncedOperations(context) {
  const ops = await getOperationsByStatus(context, 'synced');
  for (const op of ops) {
    await remove(STORES.pending_operations, op.id);
  }
  return ops.length;
}

/**
 * Count operations by status for a context.
 */
export async function countOperations(context) {
  const ops = await getPendingOperations(context);
  const counts = { pending: 0, syncing: 0, synced: 0, failed: 0, total: ops.length };
  for (const op of ops) {
    counts[op.status] = (counts[op.status] || 0) + 1;
  }
  return counts;
}
