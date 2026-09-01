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

    document.addEventListener("submit", function (e) {
        const form = e.target;

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
    });

    window.closeConfirmDelete = () => window.hideModal("confirmDeleteModal");
    window.closeConfirmResubmit = () => window.hideModal("confirmResubmitModal");

    window.proceedDelete = function () {
        if (!pendingDeleteForm) return;
        const btn = document.getElementById("confirmDeleteBtn");
        btn.disabled = true;
        btn.innerHTML = `<i class="bi bi-hourglass-split mr-1"></i> ${btn.dataset.loadingLabel || "Deleting..."}`;
        pendingDeleteForm.submit();
    };

    window.proceedResubmit = function () {
        if (!pendingResubmitForm) return;
        const btn = document.getElementById("confirmResubmitBtn");
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split mr-1"></i> Submitting...';
        pendingResubmitForm.submit();
    };

    window.bindModalOverlayClose("confirmDeleteModal", () => { pendingDeleteForm = null; });
    window.bindModalOverlayClose("confirmResubmitModal", () => { pendingResubmitForm = null; });

    // Pressing Escape closes either confirm popup, same as clicking Cancel
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        window.closeConfirmDelete();
        window.closeConfirmResubmit();
    });
});

function dismissToast() {
    const toast = document.getElementById("flashToast");
    if (!toast) return;
    toast.classList.add("opacity-0", "translate-y-3");
    setTimeout(() => toast.remove(), 500);
}