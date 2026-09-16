/**
 * MVS Commerce — Offline Snapshot Storage
 *
 * Stores and retrieves server-authorized snapshots in IndexedDB.
 * All operations scoped to company_id + branch_id + terminal_uuid.
 */

import { STORES, getByIndex, put, getByKey, remove, getAll, clearStore } from './db.js';

/**
 * Validate snapshot structure before storing.
 * Returns { valid: true } or { valid: false, error: string }.
 */
export function validateSnapshot(snapshot, context) {
  if (!snapshot || typeof snapshot !== 'object') {
    return { valid: false, error: 'Snapshot must be a non-null object' };
  }

  const requiredFields = ['schema_version', 'generated_at', 'company', 'branch', 'terminal'];
  for (const field of requiredFields) {
    if (!snapshot[field]) {
      return { valid: false, error: `Missing required field: ${field}` };
    }
  }

  if (!snapshot.company?.id) {
    return { valid: false, error: 'Missing company.id' };
  }
  if (!snapshot.branch?.id) {
    return { valid: false, error: 'Missing branch.id' };
  }
  if (!snapshot.terminal?.uuid) {
    return { valid: false, error: 'Missing terminal.uuid' };
  }

  if (context) {
    if (snapshot.company.id !== context.company_id) {
      return { valid: false, error: 'Company mismatch' };
    }
    if (snapshot.branch.id !== context.branch_id) {
      return { valid: false, error: 'Branch mismatch' };
    }
    if (snapshot.terminal.uuid !== context.terminal_uuid) {
      return { valid: false, error: 'Terminal mismatch' };
    }
  }

  return { valid: true };
}

/**
 * Generate the IndexedDB key for a snapshot record.
 */
function snapshotKey(companyId, branchId, terminalUuid) {
  return `${companyId}::${branchId}::${terminalUuid}`;
}

/**
 * Save a snapshot to IndexedDB.
 * Overwrites any existing snapshot for the same company+branch+terminal context.
 */
export async function saveSnapshot(snapshot, context) {
  const validation = validateSnapshot(snapshot, context);
  if (!validation.valid) {
    throw new Error(`Invalid snapshot: ${validation.error}`);
  }

  const record = {
    id: snapshotKey(snapshot.company.id, snapshot.branch.id, snapshot.terminal.uuid),
    company_id: snapshot.company.id,
    branch_id: snapshot.branch.id,
    terminal_uuid: snapshot.terminal.uuid,
    schema_version: snapshot.schema_version,
    generated_at: snapshot.generated_at,
    saved_at: new Date().toISOString(),
    product_count: Array.isArray(snapshot.products) ? snapshot.products.length : 0,
    customer_count: Array.isArray(snapshot.customers) ? snapshot.customers.length : 0,
    data: snapshot,
  };

  await put(STORES.snapshot, record);
  return record;
}

/**
 * Get the snapshot for the given terminal context.
 */
export async function getSnapshot(context) {
  const key = snapshotKey(context.company_id, context.branch_id, context.terminal_uuid);
  const record = await getByKey(STORES.snapshot, key);
  return record ? record.data : null;
}

/**
 * Get snapshot metadata (without full data).
 */
export async function getSnapshotMetadata(context) {
  const key = snapshotKey(context.company_id, context.branch_id, context.terminal_uuid);
  const record = await getByKey(STORES.snapshot, key);
  if (!record) return null;

  return {
    id: record.id,
    company_id: record.company_id,
    branch_id: record.branch_id,
    terminal_uuid: record.terminal_uuid,
    schema_version: record.schema_version,
    generated_at: record.generated_at,
    saved_at: record.saved_at,
    product_count: record.product_count,
    customer_count: record.customer_count,
  };
}

/**
 * Delete the snapshot for the given terminal context.
 */
export async function deleteSnapshot(context) {
  const key = snapshotKey(context.company_id, context.branch_id, context.terminal_uuid);
  return remove(STORES.snapshot, key);
}

/**
 * Check if a valid snapshot exists for the given context.
 */
export async function hasSnapshot(context) {
  const meta = await getSnapshotMetadata(context);
  return meta !== null;
}
