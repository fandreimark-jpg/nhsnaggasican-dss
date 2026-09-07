// resources/js/grade-verify.js
//
// "Verify & Use as Official" (adviser/assessments.blade.php) keeps the
// same confirm-before-submit UX as any other data-resubmit form (see
// confirm.js) — the "this will REPLACE the current official grade"
// warning still shows — but once confirmed, submits via fetch() instead
// of a full page reload, so verifying one student's grade doesn't
// re-render the whole Performance table and lose scroll position.
//
// Progressive enhancement: this listens for confirm.js's
// "resubmit:confirmed" event and calls preventDefault() to take over.
// If this script never runs (JS disabled, or this file fails to load),
// confirm.js's own fallback still does a real form submit + redirect —
// nothing here is required for the feature to work.
window.initGradeVerifyForms = function (formSelector) {
    // Delegated at the document so a form inserted later (e.g. this
    // same row, re-rendered after a successful verify) is still caught
    // without re-registering anything.
    document.addEventListener("resubmit:confirmed", function (e) {
        if (!e.target.matches(formSelector)) return;
        e.preventDefault();
        submitGradeVerifyForm(e.target);
    });
};

function submitGradeVerifyForm(form) {
    const submitBtn = form.querySelector('button[type="submit"]');
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
                    throw new Error((data && data.message) || "Could not verify this grade. Please try again.");
                }
                return data;
            });
        })
        .then(function (data) {
            applyVerifiedGradeToRow(form, data);
            window.showToast(data.message, "success");
        })
        .catch(function (err) {
            window.showToast(err.message, "error");
            if (submitBtn) submitBtn.disabled = false;
        })
        .finally(function () {
            window.hideModal("confirmResubmitModal");
        });
}

// Replaces only the OFFICIAL GRADE cell's contents — never the whole
// table row — with the verified value, then rebuilds a fresh
// (identically-behaved) verify form for a possible future re-verify.
function applyVerifiedGradeToRow(form, data) {
    const cell = form.closest("td");
    if (!cell) return;

    const provisionalNote = data.provisional
        ? '<span class="block text-[10px] text-amber-600"><i class="bi bi-exclamation-triangle-fill"></i> Provisional</span>'
        : "";

    // "The Failing layer" — next to the Official Grade, matching the
    // server-rendered badge exactly; never shown for a provisional grade.
    const failingNote = data.is_failing
        ? '<span class="block text-[10px] font-bold text-red-700"><i class="bi bi-x-octagon-fill"></i> Failing</span>'
        : "";

    const grade = Number(data.official_grade).toFixed(2);
    // Reuses the ORIGINAL form's hidden inputs verbatim (CSRF token,
    // student_id, subject_id, grading_period) rather than reconstructing
    // them, so nothing here can drift out of sync with what the server
    // actually expects.
    const hiddenInputs = Array.from(form.querySelectorAll('input[type="hidden"]'))
        .map((el) => el.outerHTML)
        .join("");

    cell.innerHTML =
        `<span class="font-medium text-gray-700">${grade}</span>` +
        `<span class="block text-[10px] text-green-600"><i class="bi bi-check-circle-fill"></i> Verified from evidence</span>` +
        failingNote +
        provisionalNote +
        `<form method="POST" action="${form.action}" class="mt-1" data-grade-verify-form data-resubmit="${form.getAttribute('data-resubmit')}">` +
        hiddenInputs +
        `<button type="submit" class="text-xs text-brand-700 underline hover:text-brand-900">Verify &amp; Use as Official</button>` +
        `</form>`;
}
