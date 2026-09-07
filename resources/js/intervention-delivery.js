// resources/js/intervention-delivery.js
//
// "Mark as Delivered" (adviser/interventions.blade.php) — an inline
// <details> disclosure in the row itself, not a centered modal. Works
// with no JS at all (native <details> + a real form POST/redirect);
// this file only upgrades the submit to fetch() so delivering a note
// doesn't reload the whole table, plus a few niceties: cursor already
// in the textarea when the row opens, Ctrl/Cmd+Enter to submit,
// one-click note-template chips (never auto-submitting — the Adviser
// always confirms the note before it saves), and moving focus to the
// next undelivered row after a successful save.
//
// Delivery is deliberately NEVER bulk — every submit here is exactly
// ONE intervention's own note (see Adviser\InterventionController::
// markDelivered()'s docblock). Nothing in this file can submit more
// than the one row a person actually opened.
window.initInterventionDelivery = function (rowSelector) {
    const formSelector = rowSelector + ' [data-deliver-form]';

    // Cursor into the textarea the moment a row's note field opens —
    // <details>'s native "toggle" event fires for both open and close.
    document.addEventListener("toggle", function (e) {
        if (!e.target.matches(rowSelector)) return;
        if (!e.target.open) return;
        const textarea = e.target.querySelector("[data-deliver-textarea]");
        if (textarea) textarea.focus();
    }, true);

    // Chips prefill the note — cursor moves to the end, but this NEVER
    // submits on its own. The Adviser still has to confirm every note.
    document.addEventListener("click", function (e) {
        const chip = e.target.closest("[data-deliver-chip]");
        if (chip && chip.closest(rowSelector)) {
            const textarea = chip.closest("form").querySelector("[data-deliver-textarea]");
            if (textarea) {
                textarea.value = chip.textContent.trim();
                textarea.focus();
                textarea.setSelectionRange(textarea.value.length, textarea.value.length);
            }
            return;
        }

        const cancelBtn = e.target.closest("[data-deliver-cancel]");
        if (cancelBtn && cancelBtn.closest(rowSelector)) {
            const details = cancelBtn.closest(rowSelector);
            const textarea = details.querySelector("[data-deliver-textarea]");
            if (textarea) textarea.value = "";
            details.open = false;
        }
    });

    // Ctrl/Cmd+Enter submits the currently-open note.
    document.addEventListener("keydown", function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.key !== "Enter") return;
        const textarea = e.target.closest ? e.target.closest("[data-deliver-textarea]") : null;
        if (!textarea) return;
        const form = textarea.closest("[data-deliver-form]");
        if (form) form.requestSubmit();
    });

    document.addEventListener("submit", function (e) {
        if (!e.target.matches(formSelector)) return;
        e.preventDefault();
        submitDeliverForm(e.target, rowSelector);
    });
};

function submitDeliverForm(form, rowSelector) {
    const submitBtn = form.querySelector("[data-deliver-submit]");
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch(form.action, {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": csrfMeta ? csrfMeta.content : "",
            "Accept": "application/json",
        },
        body: new FormData(form),
    })
        .then(function (response) {
            return response.json().catch(() => null).then(function (data) {
                if (!response.ok || !data) {
                    throw new Error((data && data.message) || "Could not save this delivery note. Please try again.");
                }
                return data;
            });
        })
        .then(function (data) {
            applyDeliveredToRow(form, data);
            window.showToast(data.message, "success");
            bumpProgressCounter();
            focusNextUndeliveredRow(form);
        })
        .catch(function (err) {
            window.showToast(err.message, "error");
            if (submitBtn) submitBtn.disabled = false;
        });
}

// Replaces only the Delivery cell's contents — never the whole row —
// with the delivered state. delivery_notes is the Adviser's own typed
// text, so it goes in via textContent, never interpolated into markup.
function applyDeliveredToRow(form, data) {
    const cell = form.closest("td");
    if (!cell) return;

    const addItemHtml = data.add_assessment_item_url
        ? `<a href="${data.add_assessment_item_url}" class="inline-flex items-center gap-1 text-brand-700 border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 mt-1"><i class="bi bi-plus-circle"></i> Add Assessment Item</a>`
        : "";

    cell.innerHTML =
        `<span class="inline-flex items-center gap-1 text-green-700"><i class="bi bi-check-circle-fill"></i> Delivered</span>` +
        `<span class="block text-gray-400 mt-0.5">${data.delivered_at}</span>` +
        `<p class="text-gray-500 italic mt-1 max-w-xs whitespace-normal" data-delivery-note></p>` +
        addItemHtml;

    const note = cell.querySelector("[data-delivery-note]");
    if (note) note.textContent = '"' + data.delivery_notes + '"';
}

function bumpProgressCounter() {
    const counter = document.getElementById("deliveryProgressCounter");
    if (!counter) return;

    const total = parseInt(counter.dataset.acknowledgedCount, 10) || 0;
    const delivered = (parseInt(counter.dataset.deliveredCount, 10) || 0) + 1;
    counter.dataset.deliveredCount = String(delivered);

    if (delivered >= total) {
        counter.innerHTML = '&middot; <span class="text-green-600"><i class="bi bi-check-circle-fill"></i> All ' + total + ' delivered</span>';
    } else {
        counter.textContent = "· " + delivered + " of " + total + " delivered";
    }
}

function focusNextUndeliveredRow(form) {
    const currentRow = form.closest("[data-intervention-row]");
    if (!currentRow) return;

    let row = currentRow.nextElementSibling;
    while (row) {
        const summary = row.querySelector("[data-deliver-row] summary");
        if (summary) {
            summary.focus();
            return;
        }
        row = row.nextElementSibling;
    }
}
