/**
 * MVS Commerce — Offline Authorization Verification
 *
 * Verifies offline authorization before allowing a sale when server
 * is unreachable. Uses PUBLIC KEY for signature verification (private
 * key NEVER reaches the browser).
 *
 * Rules:
 * - authorization MUST be present
 * - cryptographic signature must be valid (PUBLIC KEY)
 * - company_id, branch_id, terminal_uuid must match terminal context
 * - issued_at / valid_until must be present and not expired
 * - valid window must not exceed maximum (48h)
 * - local context must coincide with terminal context
 *
 * CRITICAL: If PUBLIC KEY is not available, OFFLINE SALES MUST BE BLOCKED
 * and this module must report the blocker. Never skip signature verification.
 */

import { generateUUID } from './pending-operations.js';

/**
 * Structure of the offline authorization token received from the server.
 * The token itself (base64url encoded JWT-like format) is delivered to
 * the browser via a secure mechanism (HTTP-only cookie, response header,
 * or meta tag — never in JavaScript source code).
 *
 * Format: base64url(header).base64url(payload).base64url(signature)
 * - header: { alg: "RS256", typ: "MVS-Offline-Auth" }
 * - payload: { company_id, branch_id, terminal_uuid, issued_at, valid_until, server_time, iat, jti }
 * - signature: RSA-256 over header.payload
 *
 * The PUBLIC KEY must be available to verify the signature.
 * Delivery mechanisms (out of scope for this module):
 * - <meta> tag: <meta name="mvsoffline-public-key" content="..."> (base64 PEM)
 * - Response header: X-MVS-Offline-Public-Key
 * - Separate secure endpoint: GET /mvs/offline/public-key
 */
export const AUTH_TOKEN_TYPE = 'MVS-Offline';

/**
 * Decode a base64url string (no padding).
 */
function base64urlDecode(str) {
  const base64 = str.replace(/-/g, '+').replace(/_/g, '/');
  const padding = (4 - (base64.length % 4)) % 4;
  const paddedBase64 = base64 + '='.repeat(padding);
  return decodeURIComponent(atob(paddedBase64).split('').map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join(''));
}

/**
 * Parse the authorization token payload.
 * Returns { header, payload } object, or throws if invalid.
 *
 * The token is expected as a base64url-encoded JWT-like string.
 */
export function parseAuthToken(token) {
  if (!token || typeof token !== 'string') {
    throw new Error('Authorization token must be a non-null string');
  }

  const parts = token.split('.');
  if (parts.length !== 3) {
    throw new Error('Invalid authorization token format: expected 3 parts separated by dots');
  }

  let header, payload;
  try {
    header = JSON.parse(base64urlDecode(parts[0]));
    payload = JSON.parse(base64urlDecode(parts[1]));
  } catch (e) {
    throw new Error(`Failed to decode authorization token: ${e.message}`);
  }

  // Validate required payload fields
  const required = ['company_id', 'branch_id', 'terminal_uuid', 'issued_at', 'valid_until', 'server_time'];
  for (const field of required) {
    if (payload[field] === undefined || payload[field] === null) {
      throw new Error(`Missing required field in auth payload: ${field}`);
    }
  }

  // Validate algorithm
  if (header.alg !== 'RS256') {
    throw new Error(`Unsupported signature algorithm: ${header.alg}`);
  }

  // Validate typ
  if (header.typ !== 'MVS-Offline-Auth') {
    throw new Error(`Unexpected token type: ${header.typ}`);
  }

  return { header, payload };
}

/**
 * Validate that the authorization is still within its valid window.
 *
 * @param {object} payload - parsed auth token payload
 * @param {number} [serverTimeOverride] - optional server time for testing
 * @returns {{ valid: boolean, error?: string, remainingSeconds?: number }}
 */
function validateAuthWindow(payload, serverTimeOverride) {
  const now = typeof navigator !== 'undefined' && navigator.deviceMemory
    ? Date.now()
    : (serverTimeOverride !== undefined ? serverTimeOverride : Date.now());

  const issuedAt = new Date(payload.issued_at).getTime();
  const validUntil = new Date(payload.valid_until).getTime();
  const serverTime = new Date(payload.server_time).getTime();

  // Check that issued_at is not in the future (relative to provided server_time)
  if (issuedAt > serverTime) {
    return { valid: false, error: 'Authorization issued at is in the future' };
  }

  // Check that valid_until has passed (expired)
  if (validUntil < now) {
    return { valid: false, error: 'Authorization has expired' };
  }

  // Check window limit: maximum 48 hours from issued_at
  const maxWindowMs = 48 * 60 * 60 * 1000; // 48 hours
  const windowMs = now - issuedAt;
  if (windowMs > maxWindowMs) {
    return { valid: false, error: `Authorization exceeds maximum valid window (${Math.round(windowMs / (60 * 60 * 1000)}h > 48h)` };
  }

  // Return remaining seconds until expiry (for UI display)
  const remainingSeconds = Math.max(0, Math.ceil((validUntil - now) / 1000));

  return { valid: true, remainingSeconds };
}

/**
 * Validate authorization against the PUBLIC KEY.
 * The PUBLIC KEY must have been delivered to the browser through a
 * secure mechanism (see AUTH_TOKEN_TYPE docs).
 *
 * @param {string} token - base64url-encoded offline authorization token
 * @param {CryptoKey|string} publicKey - PUBLIC KEY (CryptoKey or PEM string)
 * @param {object} [context] - { company_id, branch_id, terminal_uuid } for context validation
 * @returns {{ valid: boolean, error?: string, auth?: object, blockReason?: string }}
 */
export async function verifyAuthSignature(token, publicKey, context) {
  let payload, header;
  try {
    const parsed = parseAuthToken(token);
    payload = parsed.payload;
    header = parsed.header;
  } catch (e) {
    return { valid: false, error: `Invalid token format: ${e.message}` };
  }

  // Validate auth window
  const windowCheck = validateAuthWindow(payload, payload.server_time);
  if (!windowCheck.valid) {
    return { valid: false, error: windowCheck.error };
  }

  // Context validation
  if (context) {
    if (payload.company_id !== context.company_id) {
      return { valid: false, error: `Company mismatch` };
    }
    if (payload.branch_id !== context.branch_id) {
      return { valid: false, error: `Branch mismatch` };
    }
    if (payload.terminal_uuid !== context.terminal_uuid) {
      return { valid: false, error: `Terminal mismatch` };
    }
  }

  // Signature verification using PUBLIC KEY
  const tokenParts = token.split('.');

  // Prepare the raw data to verify: header_base64url + '.' + payload_base64url
  const rawData = `${tokenParts[0]}.${tokenParts[1]}`;

  // Convert signature from base64url to Uint8Array
  const signatureBytes = Uint8Array.from(atob(tokenParts[2].replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));

  // Convert data to verify from base64url to Uint8Array
  const dataBytes = Uint8Array.from(atob(rawData.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));

  let cryptoKey;
  if (typeof publicKey === 'string') {
    // PEM string — import as CryptoKey
    try {
      const keyData = await window.crypto.subtle.importKey(
        'spki',
        Uint8Array.from(atob(publicKey.replace(/[^a-zA-Z0-9+\/=]/g, '')), c => c.charCodeAt(0)),
        { name: 'RSA-SHA256', hash: { name: 'SHA-256' } },
        false,
        ['verify']
      );
      cryptoKey = keyData;
    } catch (e) {
      // CRITICAL: If we can't import the PUBLIC KEY, we MUST block offline sales
      console.error('Offline sale blocked: unable to import PUBLIC KEY', e);
      return {
        valid: false,
        error: 'Offline sales blocked: PUBLIC KEY not available or invalid. Contact system administrator.',
        blockReason: 'public_key_unavailable'
      };
    }
  } else {
    cryptoKey = publicKey;
  }

  // Perform RSA-SHA256 signature verification
  let verifySuccess;
  try {
    verifySuccess = await window.crypto.subtle.verify(
      'RSA-SHA256',
      cryptoKey,
      signatureBytes,
      dataBytes
    );
  } catch (e) {
    console.error('Offline sale blocked: signature verification exception', e);
    return {
      valid: false,
      error: 'Offline sale blocked: signature verification failed.',
      blockReason: 'invalid_signature'
    };
  }

  if (!verifySuccess) {
    return {
      valid: false,
      error: 'Offline sale blocked: invalid signature. Authorization token verification failed.',
      blockReason: 'invalid_signature'
    };
  }

  // Signature valid — return verified auth info
  return {
    valid: true,
    auth: {
      company_id: payload.company_id,
      branch_id: payload.branch_id,
      terminal_uuid: payload.terminal_uuid,
      issued_at: payload.issued_at,
      valid_until: payload.valid_until,
      server_time: payload.server_time,
    }
  };
}

/**
 * Convenience function: check if offline sale should be allowed.
 * Combines auth verification and context checks.
 *
 * @param {string} token - offline authorization token
 * @param {CryptoKey|string} publicKey - PUBLIC KEY for signature verification
 * @param {object} [context] - { company_id, branch_id, terminal_uuid }
 * @returns {{ allowed: boolean, reason?: string, auth?: object }}
 */
export function checkOfflineSaleEligibility(token, publicKey, context) {
  // 1. Verify the authorization signature
  const authResult = verifyAuthSignature(token, publicKey, context);
  if (!authResult.valid) {
    return { allowed: false, reason: authResult.error };
  }

  // 2. Check that we have all required context
  if (!context) {
    return { allowed: false, reason: 'Offline sale requires company_id, branch_id, and terminal_uuid context' };
  }

  // 3. All checks passed
  return {
    allowed: true,
    auth: authResult.auth,
    reason: null
  };
}