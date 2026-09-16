/**
 * MVS Commerce — Snapshot Client
 *
 * Fetches snapshots from the server and stores them locally.
 * Isolated module — does NOT modify POS, UI, or any business flow.
 */

import { validateSnapshot, saveSnapshot } from './snapshot.js';
import { saveAuthorization } from './authorization.js';

/**
 * Fetch a snapshot from the server and store locally.
 *
 * @param {object} params
 * @param {string} params.authorizationToken - Current offline authorization token
 * @param {string} params.terminalUuid - Terminal UUID
 * @param {number} params.companyId - Active company ID
 * @param {number} params.branchId - Active branch ID
 * @param {string} [params.baseUrl] - API base URL (defaults to current origin)
 * @returns {Promise<{success: boolean, snapshot?: object, error?: string}>}
 */
export async function fetchAndStoreSnapshot({
  authorizationToken,
  terminalUuid,
  companyId,
  branchId,
  baseUrl = '',
}) {
  if (!authorizationToken) {
    return { success: false, error: 'Missing authorizationToken' };
  }
  if (!terminalUuid) {
    return { success: false, error: 'Missing terminalUuid' };
  }
  if (!companyId) {
    return { success: false, error: 'Missing companyId' };
  }
  if (!branchId) {
    return { success: false, error: 'Missing branchId' };
  }

  const url = `${baseUrl}/mvs/offline/snapshot`;

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      body: JSON.stringify({
        terminal_uuid: terminalUuid,
        authorization_token: authorizationToken,
      }),
      credentials: 'same-origin',
    });

    if (!response.ok) {
      const body = await response.json().catch(() => ({}));
      return {
        success: false,
        error: body.message || `HTTP ${response.status}`,
      };
    }

    const result = await response.json();

    if (!result.snapshot) {
      return { success: false, error: 'Response missing snapshot' };
    }

    const context = {
      company_id: companyId,
      branch_id: branchId,
      terminal_uuid: terminalUuid,
    };

    const validation = validateSnapshot(result.snapshot, context);
    if (!validation.valid) {
      return { success: false, error: `Invalid snapshot: ${validation.error}` };
    }

    await saveSnapshot(result.snapshot, context);

    if (result.authorization) {
      await saveAuthorization(result.authorization, context);
    }

    return { success: true, snapshot: result.snapshot };
  } catch (err) {
    return { success: false, error: err.message || 'Network error' };
  }
}

/**
 * Extract CSRF token from meta tag or cookie.
 */
function getCsrfToken() {
  if (typeof document === 'undefined') return '';
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) return meta.getAttribute('content') || '';

  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  if (match) return decodeURIComponent(match[1]);

  return '';
}
