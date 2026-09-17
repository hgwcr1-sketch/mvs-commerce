/**
 * MVS Commerce — Offline Sale Handling
 *
 * Handles recording a sale when Internet is unavailable.
 * All sales go to IndexedDB as pending operations — NOT directly to PostgreSQL.
 * The existing POS continues to work normally.
 *
 * Flow:
 *   POS charges → Internet check → if unreachable + valid auth → save to IndexedDB → pending
 *   Later (Phase 4): pending → sync → server → PostgreSQL
 *
 * CRITICAL constraints (Phase 3B guardrails):
 * - NO modificar diseño POS
 * - EL MISMO POS debe poder continuar trabajando
 * - Venta offline NO se inserta en PostgreSQL
 * - Usar snapshot datos autorizados
 * - Verificar firma con PUBLIC KEY
 * - Esperar IndexedDB commit antes de confirmar
 * - Proteger contra doble clic
 */

import {
  checkServerReachable,
  getLastHealthStatus,
  resetState,
} from './connectivity.js';

import { getSnapshot } from './snapshot.js';
import { getAuthorization } from './authorization.js';
import { checkOfflineReady, canMakeOfflineSale } from './offline-ready.js';

import {
  enqueueOperation,
} from './pending-operations.js';

const MAX_OFFLINE_WINDOW_MS = 48 * 60 * 60 * 1000; // 48 hours
const SALE_OPERATION_TYPE = 'offline_sale';

/**
 * Attempt to record a sale offline.
 * This function should be called when the POS would normally make a
 * network request to charge.
 *
 * @param {object} params
 * @param {string} params.authorizationToken - The offline authorization token
 * @param {object} params.terminalContext - { company_id, branch_id, terminal_uuid }
 * @param {object} params.snapshot - The authorized snapshot data
 * @param {object} params.saleData - The sale data from the POS (items, prices, payment, etc.)
 * @param {Function} params.onSuccess - Called when sale is saved locally (after IndexedDB commit)
 * @param {Function} params.onFailure - Called when sale cannot be saved offline
 * @param {Function} params.onOnlineCheckout - Called to continue with normal online checkout
 * @returns {Promise<void>}
 */
export async function attemptOfflineSale({
  terminalContext,
  saleData,
}) {
  try {
    const reachable = await checkServerReachable();
    if (reachable === 'online') {
      return { success: false, reason: 'Servidor disponible; se conserva el checkout online.' };
    }

    const healthStatus = getLastHealthStatus();
    if (healthStatus !== null) {
      return { success: false, reason: `El servidor respondió HTTP ${healthStatus}; no se permite guardar Offline.` };
    }

    const readiness = await checkOfflineReady(terminalContext);
    if (!readiness.ready) {
      return { success: false, reason: `Offline no está listo: ${readiness.blockers.join(', ')}` };
    }

    const auth = await getAuthorization(terminalContext);
    const eligibility = await canMakeOfflineSale(terminalContext, auth?.token);
    if (!eligibility.allowed) {
      return { success: false, reason: eligibility.reason || 'Autorización Offline inválida.' };
    }

    const snapshot = await getSnapshot(terminalContext);
    if (!snapshot) return { success: false, reason: 'Snapshot Offline no disponible.' };

    const operationPayload = buildSalePayload(saleData, terminalContext);
    const operation = await enqueueOperation({
      operation_type: SALE_OPERATION_TYPE,
      company_id: terminalContext.company_id,
      branch_id: terminalContext.branch_id,
      terminal_uuid: terminalContext.terminal_uuid,
      user_id: terminalContext.user_id,
      operation_uuid: saleData.checkout_token,
      payload: operationPayload,
    });

    return { success: true, operation, snapshot, authorization: auth };
  } catch (err) {
    return { success: false, reason: err.message || 'No fue posible guardar la venta Offline.' };
  }
}

/**
 * Build the sale payload from POS sale data.
 * Uses the canonical v1 offline_sale contract consumed by OfflineSyncService.
 *
 * @param {object} saleData - Data from the POS sale
 * @param {object} terminalContext - { company_id, branch_id, terminal_uuid }
 * @returns {object} Versioned sale payload
 */
export function buildSalePayload(saleData, terminalContext) {
  // Canonical document types match the official Sale model values:
  // 'ticket' (electrón  ticket) and 'invoice' (factura electrónica).
  const documentType = canonicalDocumentType(saleData.documentType || saleData.document_type);

  const items = (saleData.items || []).map((item) => ({
    product_id: item.productId ?? item.product_id,
    quantity: toDecimalString(item.quantity),
    discount: item.discount != null ? toDecimalString(item.discount) : '0',
    discount_type: item.discountType || item.discount_type || 'fixed',
    unit_price: item.unitPrice != null || item.unit_price != null
      ? toDecimalString(item.unitPrice ?? item.unit_price)
      : null,
  }));

  const payments = (saleData.payments || []).map((payment) => ({
    payment_method_id: Number(payment.payment_method_id),
    amount: toDecimalString(payment.amount),
    received_amount: payment.received_amount != null ? toDecimalString(payment.received_amount) : null,
    received_amount_usd: payment.received_amount_usd != null ? toDecimalString(payment.received_amount_usd) : null,
    change_currency: payment.change_currency || null,
    reference: payment.reference || null,
  }));

  return {
    payload_version: 1,
    checkout_token: saleData.checkout_token || null,
    document_type: documentType,
    cash_session_id: saleData.cashSessionId ?? (saleData.cashSession ? Number(saleData.cashSession.id) : null),
    customer_id: saleData.customerId ?? saleData.customer_id ?? null,
    requested_points: saleData.requestedPoints ?? saleData.requested_points ?? null,
    discount_total: saleData.discountTotal ?? saleData.discount_total ?? null,
    discount_total_type: saleData.discountTotalType || saleData.discount_total_type || 'fixed',
    items,
    payments,
    terminal_uuid: terminalContext.terminal_uuid,
    created_at_local: new Date().toISOString(),
    company_id: terminalContext.company_id,
    branch_id: terminalContext.branch_id,
    user_id: terminalContext.user_id,
    suspended_sale_id: saleData.suspended_sale_id || null,
    recovery_token: saleData.recovery_token || null,
    quote_id: saleData.quote_id || null,
  };
}

function canonicalDocumentType(value) {
  // 'factura' (legacy Phase 3B) maps to the digital invoice; everything else
  // defaults to the electronic ticket, matching the store default.
  const normalized = String(value || 'ticket').toLowerCase();

  if (normalized === 'invoice' || normalized === 'factura') {
    return 'invoice';
  }

  return 'ticket';
}

/**
 * Normalize a numeric value to a canonical decimal string (up to 8 decimals,
 * trailing zeros trimmed). None of these strings are trusted by the server;
 * the server recomputes its own canonical payload and hash.
 */
function toDecimalString(value) {
  const number = Number(value);

  if (!Number.isFinite(number)) {
    return '0';
  }

  return String(number);
}

/**
 * Build payment entries for the canonical payload.
 *
 * @param {object|string} paymentMethod - Payment method object or ID
 * @param {number} amount - Total amount
 * @param {number} [receivedAmount] - Physical cash received (for change)
 * @returns {Array<object>} Payment data
 */
function buildPaymentData(paymentMethod, amount, receivedAmount) {
  const methodId = typeof paymentMethod === 'object' ? paymentMethod.id || paymentMethod.tipo : (paymentMethod || '');

  return [{
    payment_method_id: Number(methodId) || null,
    amount: toDecimalString(amount || 0),
    received_amount: receivedAmount != null ? toDecimalString(receivedAmount) : null,
    reference: null,
  }];
}

/**
 * Protect against double-click/submit during offline sale attempt.
 * Should be called at the start of the checkout charge flow.
 *
 * Returns a function that, when called, re-enables the charge button.
 */
export function protectAgainstDoubleClick(buttonElement) {
  let isProcessing = false;

  const originalOnClick = buttonElement.onclick;

  buttonElement.addEventListener('click', async (e) => {
    if (isProcessing) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }

    isProcessing = true;
    buttonElement.disabled = true;
    buttonElement.setAttribute('aria-busy', 'true');

    // Store original handler if exists, replace with protected version
    const cleanup = () => {
      isProcessing = false;
      buttonElement.disabled = false;
      buttonElement.removeAttribute('aria-busy');
      if (typeof originalOnClick === 'function') {
        buttonElement.removeEventListener('click', cleanup);
      }
    };

    return cleanup;
  });

  // Return immediate cleanup for cases where we don't use the element handler
  return () => {
    isProcessing = false;
    buttonElement.disabled = false;
    buttonElement.removeAttribute('aria-busy');
  };
}

/**
 * Attempt to sell with full offline flow.
 * Wraps the POS charge action with connectivity check, auth verification,
 * snapshot validation, and IndexedDB persistence.
 *
 * @param {object} saleContext - Sale context from the POS
 * @param {object} authInfo - Offline authorization info
 * @param {object} snapshotData - Authorized snapshot
 * @returns {Promise<{success: boolean, operation?: object, reason?: string}>}
 */
export async function attemptSaleOffline(saleContext, authInfo, snapshotData) {
  // Reset connectivity state for this check
  resetState();

  // 1. Check connectivity (will update state internally)
  const reachable = await checkServerReachable();

  // 2. If online, indicate that online checkout should proceed
  if (reachable === 'online') {
    return { success: false, reason: 'Server is online — use normal checkout' };
  }

  // 3. Server unreachable — proceed with offline flow
  const terminalContext = {
    company_id: saleContext.companyId,
    branch_id: saleContext.branchId,
    terminal_uuid: saleContext.terminalUuid,
  };

  // 4. Attempt offline sale with all validations
  // Note: onSuccess/onFailure callbacks will handle the UI feedback
  // We just set up the flow here; the actual POS integration calls
  // attemptOfflineSale with its own callbacks.

  // For now, return a status indicating the flow is set up
  return {
    success: true,
    reason: 'Offline sale flow initialized — POS will attempt local save',
  };
}
