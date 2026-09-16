/**
 * MVS Commerce — IndexedDB Core Wrapper
 *
 * Low-level database operations for offline storage.
 * Database: mvs-commerce-offline
 * Version: 1
 * Stores: metadata, snapshot, pending_operations
 */

const DB_NAME = 'mvs-commerce-offline';
const DB_VERSION = 1;

const STORES = {
  metadata: 'metadata',
  snapshot: 'snapshot',
  pending_operations: 'pending_operations',
};

let dbInstance = null;

/**
 * Open (or return cached) IndexedDB connection.
 * Creates object stores on version upgrade.
 */
export function openDB() {
  if (dbInstance && dbInstance.readyState === 'open') {
    return Promise.resolve(dbInstance);
  }

  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = (event) => {
      const db = event.target.result;

      if (!db.objectStoreNames.contains(STORES.metadata)) {
        const metaStore = db.createObjectStore(STORES.metadata, { keyPath: 'key' });
        metaStore.createIndex('by_company', ['company_id', 'branch_id', 'terminal_uuid'], { unique: false });
      }

      if (!db.objectStoreNames.contains(STORES.snapshot)) {
        const snapStore = db.createObjectStore(STORES.snapshot, { keyPath: 'id' });
        snapStore.createIndex('by_context', ['company_id', 'branch_id', 'terminal_uuid'], { unique: false });
        snapStore.createIndex('by_terminal', ['terminal_uuid'], { unique: false });
      }

      if (!db.objectStoreNames.contains(STORES.pending_operations)) {
        const opsStore = db.createObjectStore(STORES.pending_operations, { keyPath: 'id' });
        opsStore.createIndex('by_context', ['company_id', 'branch_id', 'terminal_uuid'], { unique: false });
        opsStore.createIndex('by_status', ['status'], { unique: false });
        opsStore.createIndex('by_uuid', ['operation_uuid'], { unique: true });
      }
    };

    request.onsuccess = (event) => {
      dbInstance = event.target.result;
      dbInstance.onclose = () => { dbInstance = null; };
      dbInstance.onerror = () => { dbInstance = null; };
      resolve(dbInstance);
    };

    request.onerror = () => {
      reject(new Error(`Failed to open IndexedDB: ${request.error?.message || 'unknown'}`));
    };
  });
}

/**
 * Close the database connection.
 */
export async function closeDB() {
  if (dbInstance) {
    dbInstance.close();
    dbInstance = null;
  }
}

/**
 * Delete the entire database (for testing/cleanup).
 */
export async function deleteDB() {
  await closeDB();
  return new Promise((resolve, reject) => {
    const request = indexedDB.deleteDatabase(DB_NAME);
    request.onsuccess = () => resolve(true);
    request.onerror = () => reject(new Error(`Failed to delete IndexedDB: ${request.error?.message || 'unknown'}`));
  });
}

/**
 * Get a value from any store by key.
 */
export async function getByKey(storeName, key) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readonly');
    const store = tx.objectStore(storeName);
    const req = store.get(key);
    req.onsuccess = () => resolve(req.result || null);
    req.onerror = () => reject(new Error(`getByKey failed: ${req.error?.message}`));
  });
}

/**
 * Get all values from any store.
 */
export async function getAll(storeName) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readonly');
    const store = tx.objectStore(storeName);
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(new Error(`getAll failed: ${req.error?.message}`));
  });
}

/**
 * Put (insert or update) a value into any store.
 */
export async function put(storeName, value) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readwrite');
    const store = tx.objectStore(storeName);
    const req = store.put(value);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(new Error(`put failed: ${req.error?.message}`));
  });
}

/**
 * Delete a value from any store by key.
 */
export async function remove(storeName, key) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readwrite');
    const store = tx.objectStore(storeName);
    const req = store.delete(key);
    req.onsuccess = () => resolve(true);
    req.onerror = () => reject(new Error(`remove failed: ${req.error?.message}`));
  });
}

/**
 * Query an index and return all matching records.
 */
export async function getByIndex(storeName, indexName, query) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readonly');
    const store = tx.objectStore(storeName);
    const index = store.index(indexName);
    const req = index.getAll(query);
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(new Error(`getByIndex failed: ${req.error?.message}`));
  });
}

/**
 * Clear all records from a store.
 */
export async function clearStore(storeName) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readwrite');
    const store = tx.objectStore(storeName);
    const req = store.clear();
    req.onsuccess = () => resolve(true);
    req.onerror = () => reject(new Error(`clearStore failed: ${req.error?.message}`));
  });
}

export { DB_NAME, DB_VERSION, STORES };
