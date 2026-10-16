/**
 * Class Record local drafts — the ONE place draft keys are read, written and
 * purged. A draft is a convenience for recovering unsaved typing after a
 * failed save or reload; it never decides a grade or a concern, and every
 * save is re-authorized by the server.
 *
 * Key: cr-draft:{userId}:{schoolYear}:{sectionId}:{subjectId}:{term}
 *
 *  - purgeForeign(): drafts of any OTHER account, and drafts older than the
 *    expiry, are deleted whenever a Class Record page opens — a shared
 *    computer never shows one teacher's unsaved entries to another.
 *  - purgeAll(): on logout, and on the sign-in page (a session that simply
 *    expired still leaves nothing behind for the next person).
 *  - A page the server renders read-only (assignment removed or replaced,
 *    term closed) deletes that class's draft instead of offering it.
 */
export const DRAFT_PREFIX = 'cr-draft:';
export const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

function storage() {
    try {
        return window.localStorage;
    } catch (e) {
        return null;
    }
}

export function draftKeys(store = storage()) {
    if (!store) return [];
    const keys = [];
    try {
        for (let i = 0; i < store.length; i += 1) {
            const key = store.key(i);
            if (key && key.startsWith(DRAFT_PREFIX)) keys.push(key);
        }
    } catch (e) {
        return [];
    }
    return keys;
}

function safeRemove(store, key) {
    try { store.removeItem(key); } catch (e) { /* blocked storage */ }
}

/** Remove other accounts' drafts and expired drafts; keep this user's current ones. */
export function purgeForeign(userId, now = Date.now(), store = storage()) {
    if (!store) return;
    draftKeys(store).forEach((key) => {
        const owner = key.slice(DRAFT_PREFIX.length).split(':')[0];
        let expired = true;
        try {
            const draft = JSON.parse(store.getItem(key) || 'null');
            expired = !draft || !draft.at || now - Date.parse(draft.at) > DRAFT_MAX_AGE_MS;
        } catch (e) {
            expired = true;
        }
        if (owner !== String(userId) || expired) safeRemove(store, key);
    });
}

export function purgeAll(store = storage()) {
    if (!store) return;
    draftKeys(store).forEach((key) => safeRemove(store, key));
}

export function readDraft(key, store = storage()) {
    if (!store) return null;
    try {
        return JSON.parse(store.getItem(key) || 'null');
    } catch (e) {
        return null;
    }
}

/**
 * `scope`: assessment ids the teacher chose to save as Missing ("Save this
 * assessment's blanks as Missing") without changing a cell. Only the ids
 * are kept — on restore the page re-derives each assessment's name and
 * current blanks from what the server just rendered.
 */
export function writeDraft(key, cells, store = storage(), scope = []) {
    if (!store) return;
    try {
        const ids = [...new Set([...(scope || [])].map(String))];
        if ((!cells || Object.keys(cells).length === 0) && ids.length === 0) {
            store.removeItem(key);
            return;
        }
        store.setItem(key, JSON.stringify({ at: new Date().toISOString(), cells: cells || {}, scope: ids }));
    } catch (e) {
        /* storage full or blocked — the page still works without a draft */
    }
}

export function removeDraft(key, store = storage()) {
    if (!store) return;
    try { store.removeItem(key); } catch (e) { /* ignore */ }
}

/** Wire the global purges: every logout form, and the sign-in page. */
export function installDraftHygiene(doc = document) {
    doc.addEventListener('submit', (e) => {
        const form = e.target;
        if (form && form.action && /\/logout\/?$/.test(new URL(form.action, window.location.href).pathname)) purgeAll();
    }, true);
    if (doc.querySelector('form[action$="/login"]')) purgeAll();
}
