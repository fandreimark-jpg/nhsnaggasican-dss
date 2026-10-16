// resources/js/verify-all-remaining.js
//
// "Verify all eligible" on Verify Grades (adviser/verify-grades.blade.php).
// Scope (unchanged server behaviour): EVERY learner of the adviser's section
// who is eligible in the selected subject and term — the Status filter and
// the visible rows do not narrow it (Adviser\GradeController::
// classifyForVerifyAll()).
//
// Flow: on click, fetch the read-only preview and show the exact scope —
// subject, section, school year, term, count, every included and every
// excluded learner. Only Confirm writes: one POST carrying the learner ids
// the adviser just saw. The server re-classifies under a lock and, if its
// set differs from the confirmed one, verifies nothing and answers 409; the
// dialog then refreshes from a new preview instead of silently expanding.
// Success is shown only after the server confirms, then the page reloads so
// every row, count and label reflects the stored grades.
const FLASH_KEY = "verify-grades-flash";

window.initVerifyAllRemaining = function (buttonSelector) {
    // A message carried across the reload that follows a successful batch.
    try {
        const flash = sessionStorage.getItem(FLASH_KEY);
        if (flash) {
            sessionStorage.removeItem(FLASH_KEY);
            window.showToast(flash, "success");
        }
    } catch (e) { /* storage unavailable: nothing to show */ }

    const btn = document.querySelector(buttonSelector);
    if (!btn) return;

    const previewUrl = btn.dataset.previewUrl;
    const verifyUrl = btn.dataset.verifyUrl;
    const confirmBtn = document.getElementById("varConfirmBtn");
    let confirmedIds = null;
    let pending = false;

    btn.addEventListener("click", function () {
        if (btn.disabled) return;
        loadPreview(null);
    });
    confirmBtn?.addEventListener("click", execute);

    function loadPreview(changedMessage) {
        btn.disabled = true;
        fetch(previewUrl, { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((data) => {
                btn.disabled = data.eligible_count === 0;
                if (data.eligible_count === 0) {
                    window.hideModal("verifyAllRemainingModal");
                    window.showToast(changedMessage || "No learner is eligible to verify in this subject and term.", "error");
                    return;
                }
                populate(data, changedMessage);
                window.showModal("verifyAllRemainingModal");
            })
            .catch(() => {
                btn.disabled = false;
                window.showToast("Could not check who is eligible. Please try again.", "error");
            });
    }

    function fillList(el, names) {
        el.innerHTML = "";
        names.forEach(function (name) {
            const li = document.createElement("li");
            li.textContent = name;
            el.appendChild(li);
        });
    }

    function populate(data, changedMessage) {
        confirmedIds = data.eligible_student_ids || [];
        const n = data.eligible_count;
        document.getElementById("varTitle").textContent = "Verify " + n + " eligible learner" + (n === 1 ? "" : "s") + "?";
        document.getElementById("varContext").textContent =
            data.subject_name + " — " + data.section_name + ", Term " + data.grading_period + ", SY " + data.school_year;
        fillList(document.getElementById("varIncluded"), data.eligible_names || []);

        const changed = document.getElementById("varChanged");
        changed.textContent = changedMessage || "";
        changed.classList.toggle("hidden", !changedMessage);

        let anyExcluded = false;
        [
            ["varExcludedAlreadyEncoded", data.excluded.already_encoded],
            ["varExcludedIncomplete", data.excluded.incomplete_evidence],
            ["varExcludedNoTransmutation", data.excluded.no_transmutation],
        ].forEach(function ([id, names]) {
            const el = document.getElementById(id);
            fillList(el.querySelector("[data-name-list]"), names);
            el.classList.toggle("hidden", names.length === 0);
            anyExcluded = anyExcluded || names.length > 0;
        });
        document.getElementById("varExcludedWrap").classList.toggle("hidden", !anyExcluded);

        confirmBtn.disabled = false;
        confirmBtn.textContent = "Verify " + n + " learner" + (n === 1 ? "" : "s");
    }

    function execute() {
        if (pending || !confirmedIds) return;
        pending = true;
        const original = confirmBtn.textContent;
        confirmBtn.disabled = true;
        confirmBtn.setAttribute("aria-busy", "true");
        confirmBtn.textContent = "Verifying...";

        const body = new FormData();
        body.append("subject_id", btn.dataset.subjectId);
        body.append("grading_period", btn.dataset.gradingPeriod);
        confirmedIds.forEach((id) => body.append("expected_student_ids[]", id));
        const csrf = document.querySelector('meta[name="csrf-token"]');

        fetch(verifyUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-CSRF-TOKEN": csrf ? csrf.content : "", Accept: "application/json" },
            body: body,
        })
            .then((response) => response.json().catch(() => null).then((data) => ({ response, data })))
            .then(({ response, data }) => {
                if (response.ok && data) {
                    try { sessionStorage.setItem(FLASH_KEY, data.message); } catch (e) { /* ignore */ }
                    window.location.reload();
                    return;
                }
                pending = false;
                confirmBtn.removeAttribute("aria-busy");
                if (response.status === 409 && data && data.changed) {
                    loadPreview(data.message);
                    return;
                }
                confirmBtn.disabled = false;
                confirmBtn.textContent = original;
                window.showToast((data && data.message) || "Verification failed. Nothing was saved.", "error");
            })
            .catch(() => {
                pending = false;
                confirmBtn.removeAttribute("aria-busy");
                confirmBtn.disabled = false;
                confirmBtn.textContent = original;
                window.showToast("Could not reach the server. Nothing was verified — try again.", "error");
            });
    }
};
