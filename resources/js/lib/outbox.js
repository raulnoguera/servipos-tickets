/**
 * Bandeja de envíos hechos sin señal, guardada en el teléfono (IndexedDB).
 * Cada elemento es un cambio de estado o una atención (con su foto) que se
 * envía al servidor cuando vuelve la conexión, en el mismo orden en que se hizo.
 */
const DB_NAME = 'servipos';
const STORE = 'outbox';
const CHANGE = 'servipos:outbox-change';

let dbPromise;
function db() {
    dbPromise ??= new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
    return dbPromise;
}

async function run(mode, fn) {
    const database = await db();
    return new Promise((resolve, reject) => {
        const tx = database.transaction(STORE, mode);
        const result = fn(tx.objectStore(STORE));
        tx.oncomplete = () => resolve(result?.result ?? result);
        tx.onerror = () => reject(tx.error);
    });
}

const notify = () => window.dispatchEvent(new Event(CHANGE));

export const outbox = {
    /** @param {{type: 'status'|'visit', userId: number, ticketId: number, ticketNumber: string, payload: object}} item */
    async add(item) {
        const id = await run('readwrite', (store) => store.add({ ...item, state: 'pending', error: null, createdAt: new Date().toISOString() }));
        notify();
        return id;
    },
    async all() {
        return run('readonly', (store) => store.getAll());
    },
    async update(id, changes) {
        const item = await run('readonly', (store) => store.get(id));
        if (item) {
            await run('readwrite', (store) => store.put({ ...item, ...changes }));
            notify();
        }
    },
    async remove(id) {
        await run('readwrite', (store) => store.delete(id));
        notify();
    },
    async clear(userId) {
        const items = await this.all();
        await run('readwrite', (store) => items.filter((i) => userId === undefined || i.userId === userId).forEach((i) => store.delete(i.id)));
        notify();
    },
    onChange(listener) {
        window.addEventListener(CHANGE, listener);
        return () => window.removeEventListener(CHANGE, listener);
    },
};
