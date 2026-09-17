/**
 * MVS Commerce — Offline Authorization Storage
 *
 * Stores authorization metadata locally in IndexedDB.
 * Does NOT store: private key, APP_KEY, passwords, secrets.
 */

import { STORES, put, getByKey, remove } from './db.js';

/**
 * Validate authorization metadata before storing.
 */
export function validateAuthorization(auth) {
  if (!auth || typeof auth !== 'object') {
    return { valid: false, error: 'Authorization must be a non-null object' };
  }

  const required = ['authorization_id', 'token', 'issued_at', 'valid_until', 'server_time'];
  for (const field of required) {
    if (!auth[field]) {
      return { valid: false, error: `Missing required field: ${field}` };
    }
  }

  return { valid: true };
}

/**
 * Generate the IndexedDB key for an authorization record.
 */
function authKey(companyId, branchId, terminalUuid, userId = 0) {
  return `auth::${companyId}::${branchId}::${terminalUuid}::${userId}`;
}

/**
 * Save authorization metadata.
 */
export async function saveAuthorization(auth, context) {
  const normalized = typeof auth === 'string' ? { token: auth } : { ...auth };
  normalized.token ||= normalized.authorization;
  normalized.company_id ||= context?.company_id;
  normalized.branch_id ||= context?.branch_id;
  normalized.terminal_uuid ||= context?.terminal_uuid;
  const validation = validateAuthorization(normalized);
  if (!validation.valid) {
    throw new Error(`Invalid authorization: ${validation.error}`);
  }

  if (!context?.company_id || !context?.branch_id || !context?.terminal_uuid) {
    throw new Error('Missing context (company_id, branch_id, terminal_uuid)');
  }

  const record = {
    key: authKey(context.company_id, context.branch_id, context.terminal_uuid, context.user_id),
    company_id: context.company_id,
    branch_id: context.branch_id,
    terminal_uuid: context.terminal_uuid,
    user_id: context.user_id,
    authorization_id: normalized.authorization_id,
    token: normalized.token,
    issued_at: normalized.issued_at,
    valid_until: normalized.valid_until,
    server_time: normalized.server_time,
    saved_at: new Date().toISOString(),
  };

  await put(STORES.metadata, record);
  return record;
}

/**
 * Get authorization metadata for the given context.
 */
export async function getAuthorization(context) {
  if (!context?.company_id || !context?.branch_id || !context?.terminal_uuid) {
    return null;
  }
  const key = authKey(context.company_id, context.branch_id, context.terminal_uuid, context.user_id);
  const record = await getByKey(STORES.metadata, key);
  if (!record) return null;

  return {
    authorization_id: record.authorization_id,
    token: record.token,
    issued_at: record.issued_at,
    valid_until: record.valid_until,
    server_time: record.server_time,
    saved_at: record.saved_at,
    company_id: record.company_id,
    branch_id: record.branch_id,
    terminal_uuid: record.terminal_uuid,
    user_id: record.user_id,
  };
}

/**
 * Check if the stored authorization is still valid (not expired).
 */
export async function isAuthorizationValid(context) {
  const auth = await getAuthorization(context);
  if (!auth) return false;
  return new Date(auth.valid_until) > new Date();
}

/**
 * Delete authorization metadata.
 */
export async function deleteAuthorization(context) {
  if (!context?.company_id || !context?.branch_id || !context?.terminal_uuid) {
    return false;
  }
  const key = authKey(context.company_id, context.branch_id, context.terminal_uuid, context.user_id);
  return remove(STORES.metadata, key);
}
