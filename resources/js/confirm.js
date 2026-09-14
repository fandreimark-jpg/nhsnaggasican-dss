// resources/js/confirm.js
// Flash message toast, and the "Are you sure?" confirmation popups used
// before deleting or re-submitting something. These reuse the same
// showModal()/hideModal() helpers from modal.js instead of writing
// their own separate show/hide + animation code.

document.addEventListener("DOMContentLoaded", function () {
    // ===== FLASH TOAST (auto-dismissing success/error message) =====
    const toast = document.getElementById("flashToast");
    if (toast) {
        requestAnimationFrame(function () {
            setTimeout(function () {
                toast.classList.remove("opacity-0", "translate-y-3");
                toast.classList.add("opacity-100", "translate-y-0");
            }, 80);
        });

        let autoDismiss = setTimeout(dismissToast, 4000);
        toast.addEventListener("mouseenter", () => clearTimeout(autoDismiss));
        toast.addEventListener("mouseleave", () => {
            autoDismiss = setTimeout(dismissToast, 1500);
        });
    }

    // ===== CONFIRM DELETE / CONFIRM RESUBMIT =====
    // Any <form> with a data-confirm="..." or data-resubmit="..." attribute
    // gets intercepted here — instead of submitting immediately, it shows
    // a popup first and only submits if the user clicks "Yes".
    let pendingDeleteForm = null;
    let pendingResubmitForm = null;
    let pendingImportForm = null;
    let pendingActionForm = null;

    document.addEventListener("submit", function (e) {
        const form = e.target;

        // ===== GENERIC NON-DESTRUCTIVE ACTION (activate / open / close /
        // enroll) — "Multi-school-year academic history" work order, PART
        // 19: a confirmation whose title, button, and icon match the
        // action, in neutral (brand) styling. Never the red Delete modal —
        // nothing here deletes anything.
        //   data-action-confirm="message"  data-action-title="Confirm Close"
        //   data-action-label="Yes, Close" data-action-icon="bi-lock"
        //   data-action-loading="Closing..."
        if (form.dataset.actionConfirm) {
            e.preventDefault();
            pendingActionForm = form;
            document.getElementById("confirmActionMessage").textContent = form.dataset.actionConfirm;
            document.getElementById("confirmActionTitle").textContent = form.dataset.actionTitle || "Confirm Action";
            const icon = form.dataset.actionIcon || "bi-check-circle";
            const iconEl = document.getElementById("confirmActionIcon");
            if (iconEl) iconEl.className = `bi ${icon} text-brand-700 text-lg`;
            const btn = document.getElementById("confirmActionBtn");
            btn.disabled = false;
            btn.dataset.loadingLabel = form.dataset.actionLoading || "Working...";
            btn.innerHTML = `<i class="bi ${icon} mr-1"></i> ${form.dataset.actionLabel || "Yes, Continue"}`;
            window.showModal("confirmActionModal");
            return;
        }

        if (form.dataset.importConfirm) {
            e.preventDefault();
            pendingImportForm = form;
            document.getElementById("confirmImportMessage").textContent = form.dataset.importConfirm;
            const btn = document.getElementById("confirmImportBtn");
            btn.disabled = false;
            btn.textContent = "Yes, Import";
            window.showModal("confirmImportModal");
            return;
        }

        if (form.dataset.confirm) {
            e.preventDefault();
            pendingDeleteForm = form;
            document.getElementById("confirmDeleteMessage").textContent = form.dataset.confirm;

            // This modal is shared by every destructive-looking action
            // (delete section/subject/track/student/user, disable user).
            // Only the label/icon/in-flight text differ — data-confirm-*
            // overrides let a non-delete action (like "Disable") say so,
            // while every plain data-confirm form keeps the original
            // "Yes, Delete" wording by falling back to these defaults.
            const btn = document.getElementById("confirmDeleteBtn");
            const label = form.dataset.confirmLabel || "Yes, Delete";
            const icon = form.dataset.confirmIcon || "bi-trash";
            btn.dataset.loadingLabel = form.dataset.confirmLoadingLabel || "Deleting...";
            btn.innerHTML = `<i class="bi ${icon} mr-1"></i> ${label}`;

            window.showModal("confirmDeleteModal");
            return;
        }

        if (form.dataset.resubmit) {
            e.preventDefault();
            pendingResubmitForm = form;
            document.getElementById("confirmResubmitMessage").textContent = form.dataset.resubmit;
            window.showModal("confirmResubmitModal");
            return;
        }

        markFormAsProcessing(form);
    });

    // ===== PROCESSING STATE ON SUBMIT ("UI modernization pass", PART 19)
    // Any form that actually submits (not intercepted by a confirm modal
    // above — those show their own in-flight label) gets its submit button
    // disabled and relabelled so a slow request (an import, a term report
    // that runs the classifier) cannot be double-submitted. The label comes
    // from the form's data-loading attribute, else "Processing...".
    function markFormAsProcessing(form) {
        if (!form || typeof form.querySelector !== "function" || form.dataset.noLoading !== undefined) return;
        const btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (!btn) return;
        const label = form.dataset.loading || "Processing...";
        // Defer so the click's own value still posts and the browser has
        // started navigation before the control is disabled.
        setTimeout(function () {
            btn.disabled = true;
            btn.setAttribute("aria-busy", "true");
            if (btn.tagName === "BUTTON") {
                btn.dataset.originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split mr-1"></i> ' + label;
            } else {
                btn.value = label;
            }
        }, 0);
        // Re-enable if the page is restored from bfcache (back button).
        window.addEventListener("pageshow", function () {
            btn.disabled = false;
            btn.removeAttribute("aria-busy");
            if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
        }, { once: true });
    }

    window.closeConfirmDelete = () => window.hideModal("confirmDeleteModal");
    window.closeConfirmResubmit = () => window.hideModal("confirmResubmitModal");
    window.closeConfirmAction = () => {
        pendingActionForm = null;
        window.hideModal("confirmActionModal");
    };
    window.proceedAction = function () {
        if (!pendingActionForm) return;
        const form = pendingActionForm;
        pendingActionForm = null;
        const btn = document.getElementById("confirmActionBtn");
        btn.disabled = true;
        btn.innerHTML = `<i class="bi bi-hourglass-split mr-1"></i> ${btn.dataset.loadingLabel || "Working..."}`;
        form.submit();
    };
    window.closeConfirmImport = () => {
        pendingImportForm = null;
        window.hideModal("confirmImportModal");
    };
    window.proceedImport = function () {
        if (!pendingImportForm) return;
        const form = pendingImportForm;
        pendingImportForm = null;
        const btn = document.getElementById("confirmImportBtn");
        btn.disabled = true;
        btn.textContent = "Importing...";
        form.submit();
    };

    window.proceedDelete = function () {
        if (!pendingDeleteForm) return;
        const btn = document.getElementById("confirmDeleteBtn");
        btn.disabled = true;
        btn.innerHTML = `<i class="bi bi-hourglass-split mr-1"></i> ${btn.dataset.loadingLabel || "Deleting..."}`;
        pendingDeleteForm.submit();
    };

    window.proceedResubmit = function () {
        if (!pendingResubmitForm) return;
        const form = pendingResubmitForm;
        const btn = document.getElementById("confirmResubmitBtn");
        const originalLabel = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split mr-1"></i> Submitting...';

        // A specific form (e.g. grade verification, resources/js/grade-
        // verify.js) can listen for this and call preventDefault() to
        // submit via fetch() instead of a full page reload. If nothing
        // does, this falls through to the original full-page submit —
        // the same behavior as before this event existed.
        const event = new CustomEvent("resubmit:confirmed", { cancelable: true });
        const shouldSubmitNatively = form.dispatchEvent(event);

        if (shouldSubmitNatively) {
            form.submit();
            return;
        }

        window.hideModal("confirmResubmitModal");
        btn.disabled = false;
        btn.innerHTML = originalLabel;
    };

    window.bindModalOverlayClose("confirmDeleteModal", () => { pendingDeleteForm = null; });
    window.bindModalOverlayClose("confirmResubmitModal", () => { pendingResubmitForm = null; });
    window.bindModalOverlayClose("confirmImportModal", () => { pendingImportForm = null; });
    window.bindModalOverlayClose("confirmActionModal", () => { pendingActionForm = null; });

    // Pressing Escape closes either confirm popup, same as clicking Cancel
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        window.closeConfirmDelete();
        window.closeConfirmResubmit();
        window.closeConfirmImport();
        window.closeConfirmAction();
    });

    // ===== DISMISSIBLE PANELS (e.g. partials/import-result.blade.php) =====
    // Any element with data-dismiss removes its closest [data-dismissible]
    // ancestor when clicked — one delegated listener covers every panel
    // on the page, including ones added after this script ran.
    document.addEventListener("click", function (e) {
        const btn = e.target.closest("[data-dismiss]");
        if (!btn) return;
        const panel = btn.closest("[data-dismissible]");
        if (panel) panel.remove();
    });
});

function dismissToast() {
    const toast = document.getElementById("flashToast");
    if (!toast) return;
    toast.classList.add("opacity-0", "translate-y-3");
    setTimeout(() => toast.remove(), 500);
}

// Short-lived corner toast for JS-driven actions that update the page
// in place (fetch-based row updates) — same visual language as the
// server-rendered #flashToast above, but created on demand instead of
// requiring a full page load to appear. Deliberately NOT a full-width
// inline banner: those are for page-load flash messages, this is for
// "something you just did in this row succeeded/failed."
window.showToast = function (message, type = "success") {
    const existing = document.getElementById("jsToast");
    if (existing) existing.remove();

    const styles = {
        success: { border: "border-green-300", bar: "bg-green-500", icon: "bi-check-circle-fill", iconColor: "text-green-500", label: "Success" },
        error:   { border: "border-red-300",   bar: "bg-red-500",   icon: "bi-x-circle-fill",      iconColor: "text-red-500",   label: "Error" },
    };
    const s = styles[type] || styles.success;

    const toast = document.createElement("div");
    toast.id = "jsToast";
    toast.className = `fixed top-6 right-6 z-[9999] flex items-start gap-3 px-5 py-4 rounded-xl shadow-2xl border w-80 bg-white ${s.border} opacity-0 translate-y-3 transition-all duration-500`;
    toast.innerHTML = `
        <div class="absolute left-0 top-0 bottom-0 w-1 rounded-l-xl ${s.bar}"></div>
        <div class="ml-2 mt-0.5 text-lg shrink-0 ${s.iconColor}"><i class="bi ${s.icon}"></i></div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">${s.label}</p>
            <p class="text-xs text-gray-500 mt-0.5 leading-relaxed"></p>
        </div>
        <button type="button" aria-label="Dismiss notification" class="shrink-0 text-gray-300 hover:text-gray-500 text-lg leading-none mt-0.5">
            <i class="bi bi-x"></i>
        </button>
    `;
    // textContent, not innerHTML — the message can echo a student name
    // or file-derived value, and must never be interpreted as markup.
    toast.querySelector("p.text-xs").textContent = message;

    const remove = () => {
        toast.classList.add("opacity-0", "translate-y-3");
        setTimeout(() => toast.remove(), 500);
    };
    toast.querySelector("button").addEventListener("click", remove);

    document.body.appendChild(toast);
    requestAnimationFrame(function () {
        setTimeout(function () {
            toast.classList.remove("opacity-0", "translate-y-3");
            toast.classList.add("opacity-100", "translate-y-0");
        }, 20);
    });

    let autoDismiss = setTimeout(remove, 3500);
    toast.addEventListener("mouseenter", () => clearTimeout(autoDismiss));
    toast.addEventListener("mouseleave", () => {
        autoDismiss = setTimeout(remove, 1500);
    });
};
