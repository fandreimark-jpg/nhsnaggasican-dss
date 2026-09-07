// resources/js/verify-all-remaining.js
//
// "Verify All Remaining" (adviser/assessments.blade.php) — safe to run in
// bulk because it is a mechanical transformation with no per-student
// claim (see Adviser\GradeController::verifyOneGrade()'s docblock): the
// same computed-grade-to-official-grade transmutation for everyone.
// Mark as Delivered stays individual-only; nothing here changes that.
//
// Flow: on load, fetch the read-only preview to enable/disable the
// button and know the eligible count. On click, re-fetch the preview
// (state may have changed) and show a confirmation dialog naming the
// exact count, subject/section/term, and every excluded student grouped
// by reason. Only on Confirm does anything get written — a single
// POST that the server re-classifies and executes inside one
// transaction, never trusting a client-held eligible list.
window.initVerifyAllRemaining = function (buttonSelector) {
    const btn = document.querySelector(buttonSelector);
    if (!btn) return;

    const previewUrl = btn.dataset.previewUrl;
    const verifyUrl = btn.dataset.verifyUrl;
    const label = btn.querySelector("[data-var-btn-label]");

    refreshButtonState();

    btn.addEventListener("click", function () {
        if (btn.disabled) return;
        openConfirmationDialog();
    });

    document.getElementById("varConfirmBtn")?.addEventListener("click", executeVerifyAll);

    // "Decision flow, report scoping, and dashboard pass" TASK 3b — the
    // count is shown on the button itself, not only inside the preview
    // dialog, so an adviser scanning the sticky header already knows how
    // many are left without opening anything.
    function setLabel(text) {
        if (label) {
            label.textContent = text;
        }
    }

    function refreshButtonState() {
        fetch(previewUrl, { headers: { Accept: "application/json" } })
            .then((r) => r.json())
            .then((data) => {
                btn.disabled = data.eligible_count === 0;
                btn.dataset.lastPreview = JSON.stringify(data);
                setLabel(data.eligible_count === 0 ? "All grades verified" : "Verify All Remaining (" + data.eligible_count + ")");
            })
            .catch(() => {
                btn.disabled = true;
                setLabel("Verify All Remaining");
            });
    }

    function openConfirmationDialog() {
        fetch(previewUrl, { headers: { Accept: "application/json" } })
            .then((r) => r.json())
            .then((data) => {
                if (data.eligible_count === 0) {
                    btn.disabled = true;
                    window.showToast("Nothing left to verify for this subject and term.", "error");
                    return;
                }
                populateDialog(data);
                window.showModal("verifyAllRemainingModal");
            })
            .catch(() => {
                window.showToast("Could not check who is eligible. Please try again.", "error");
            });
    }

    function populateDialog(data) {
        document.getElementById("varTitle").textContent = "Verify " + data.eligible_count + " student" + (data.eligible_count === 1 ? "" : "s") + "?";
        document.getElementById("varContext").textContent = data.subject_name + " — " + data.section_name + ", Term " + data.grading_period;

        const groups = [
            ["varExcludedAlreadyEncoded", data.excluded.already_encoded],
            ["varExcludedIncomplete", data.excluded.incomplete_evidence],
            ["varExcludedNoTransmutation", data.excluded.no_transmutation],
        ];

        let anyExcluded = false;
        groups.forEach(function ([elId, names]) {
            const el = document.getElementById(elId);
            const list = el.querySelector("[data-name-list]");
            list.innerHTML = "";
            if (names.length > 0) {
                anyExcluded = true;
                names.forEach(function (name) {
                    const li = document.createElement("li");
                    li.textContent = name;
                    list.appendChild(li);
                });
                el.hidden = false;
            } else {
                el.hidden = true;
            }
        });

        document.getElementById("varExcludedWrap").hidden = !anyExcluded;

        const confirmBtn = document.getElementById("varConfirmBtn");
        confirmBtn.disabled = false;
        confirmBtn.textContent = "Verify " + data.eligible_count + " student" + (data.eligible_count === 1 ? "" : "s");
        confirmBtn.dataset.subjectId = btn.dataset.subjectId;
        confirmBtn.dataset.gradingPeriod = btn.dataset.gradingPeriod;
    }

    function executeVerifyAll() {
        const confirmBtn = document.getElementById("varConfirmBtn");
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        confirmBtn.disabled = true;
        const originalLabel = confirmBtn.textContent;
        confirmBtn.textContent = "Verifying...";

        const body = new FormData();
        body.append("subject_id", confirmBtn.dataset.subjectId);
        body.append("grading_period", confirmBtn.dataset.gradingPeriod);

        fetch(verifyUrl, {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": csrfMeta ? csrfMeta.content : "",
                Accept: "application/json",
            },
            body: body,
        })
            .then(function (response) {
                return response.json().catch(() => null).then(function (data) {
                    if (!response.ok || !data) {
                        throw new Error((data && data.message) || "Verification failed. Please try again.");
                    }
                    return data;
                });
            })
            .then(function (data) {
                window.hideModal("verifyAllRemainingModal");
                window.showToast(data.message, "success");
                (data.verified || []).forEach(applyVerifiedRow);
                refreshButtonState();
            })
            .catch(function (err) {
                window.showToast(err.message, "error");
                confirmBtn.disabled = false;
                confirmBtn.textContent = originalLabel;
            });
    }

    // Rebuilds the cell exactly like grade-verify.js's applyVerifiedGradeToRow()
    // does for the single-verify path: the grade + badges, PLUS a fresh
    // re-submit form — the individual "Verify & Use as Official" action
    // stays available afterward for a later correction, one student at a
    // time (see Adviser\GradeController::verifyOneGrade()'s docblock on
    // why overwriting an existing official grade must stay individual).
    function applyVerifiedRow(row) {
        const tr = document.querySelector('[data-performance-row][data-student-id="' + row.student_id + '"]');
        if (!tr) return;
        const cell = tr.querySelector("[data-official-grade-cell]");
        if (!cell) return;

        const existingForm = cell.querySelector("[data-grade-verify-form]");
        if (!existingForm) return;

        const failingNote = row.is_failing
            ? '<span class="block text-[10px] font-bold text-red-700"><i class="bi bi-x-octagon-fill"></i> Failing</span>'
            : "";
        const grade = Number(row.official_grade).toFixed(2);
        const hiddenInputs = Array.from(existingForm.querySelectorAll('input[type="hidden"]'))
            .map((el) => el.outerHTML)
            .join("");

        cell.innerHTML =
            `<span class="font-medium text-gray-700">${grade}</span>` +
            `<span class="block text-[10px] text-green-600"><i class="bi bi-check-circle-fill"></i> Verified from evidence</span>` +
            failingNote +
            `<form method="POST" action="${existingForm.action}" class="mt-1" data-grade-verify-form data-resubmit="${existingForm.getAttribute('data-resubmit')}">` +
            hiddenInputs +
            `<button type="submit" class="text-xs text-brand-700 underline hover:text-brand-900">Verify &amp; Use as Official</button>` +
            `</form>`;
    }
};
