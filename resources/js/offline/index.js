/**
 * MVS Commerce — Offline Module Entry Point
 *
 * Re-exports all offline storage functions.
 * This module is the public API for the offline layer.
 */

export {
  openDB,
  closeDB,
  deleteDB,
  DB_NAME,
  DB_VERSION,
  STORES,
} from './db.js';

export {
  validateSnapshot,
  saveSnapshot,
  getSnapshot,
  getSnapshotMetadata,
  deleteSnapshot,
  hasSnapshot,
} from './snapshot.js';

export {
  validateAuthorization,
  saveAuthorization,
  getAuthorization,
  isAuthorizationValid,
  deleteAuthorization,
} from './authorization.js';

export {
  generateUUID,
  validateOperation,
  enqueueOperation,
  getPendingOperations,
  getAllOperations,
  getOperationsByStatus,
  markSyncing,
  markPending,
  markSynced,
  markFailed,
  deleteOperation,
  clearSyncedOperations,
  countOperations,
} from './pending-operations.js';

export {
  fetchAndStoreSnapshot,
} from './snapshot-client.js';

export {
  checkServerReachable,
  ConnectivityState,
  getState,
  resetState,
  getLastHealthStatus,
  shouldAttemptOffline,
} from './connectivity.js';

export {
  AUTH_TOKEN_TYPE,
  parseAuthToken,
  validateAuthWindow,
  verifyAuthSignature,
  checkOfflineSaleEligibility,
} from './auth-verification.js';

export {
  attemptOfflineSale,
  attemptSaleOffline,
  protectAgainstDoubleClick,
  buildSalePayload,
} from './sale.js';

export {
  checkOfflineReady,
  canMakeOfflineSale,
  checkServiceWorkerReady,
  checkSnapshotReady,
  checkAuthorizationReady,
  checkPublicKeyReady,
  getPublicKey,
  storePublicKeyLocally,
  clearStoredPublicKey,
} from './offline-ready.js';

export {
  searchSnapshotProducts,
  searchSnapshotCustomers,
  searchProductsOffline,
  searchCustomersOffline,
} from './pos-search.js';

export {
  syncPendingOperations,
  isSyncRunning,
  getBackoffMs,
  resetBackoff,
  stopSync,
  MAX_CONCURRENT,
  PAUSE_BETWEEN_OPERATIONS_MS,
  SYNC_URL,
} from './sync-worker.js';

export { bootstrapOffline, startOfflineBootstrap } from './bootstrap.js';
