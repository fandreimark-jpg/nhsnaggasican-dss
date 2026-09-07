// resources/js/group-delivery.js
//
// "Clarity, progress, and visual design pass" TASK 3 — the ONE exception
// to "Mark as Delivered is never bulk" (see resources/js/
// intervention-delivery.js's own docblock): a genuine group activity
// covering several checked learners at once, still gated behind ONE
// required, real (20+ character, not just whitespace) note — never a
// "select all and deliver" shortcut with no note step, and never one
// learner's individual note copied onto others.
//
// The trigger button and the modal's textarea/Confirm button are wired
// to #groupDeliverForm purely via the HTML `form=""` attribute in the
// Blade template — this file only manages the button's enabled state,
// the modal's live count/name list, and the Confirm button's own
// enabled state (never submits anything on its own).
window.initGroupDelivery = function () {
    function checkedBoxes() {
        return Array.from(document.querySelectorAll('[data-group-deliver-checkbox]:checked'));
    }

    function refreshTrigger() {
        const trigger = document.getElementById('groupDeliverTriggerBtn');
        if (!trigger) return;
        const count = checkedBoxes().length;
        trigger.disabled = count === 0;
        trigger.innerHTML = count > 0
            ? '<i class="bi bi-people"></i> Mark selected as delivered together (' + count + ')'
            : '<i class="bi bi-people"></i> Mark selected as delivered together';
    }

    function refreshConfirmState() {
        const textarea = document.getElementById('gdNoteText');
        const confirmBtn = document.getElementById('gdConfirmBtn');
        if (!textarea || !confirmBtn) return;
        // Trimmed length, matching the server's own check — a
        // whitespace-only note must never enable Confirm client-side
        // either, even though the server is the actual authority.
        confirmBtn.disabled = textarea.value.trim().length < 20;
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-group-deliver-checkbox]')) refreshTrigger();
    });

    document.addEventListener('input', function (e) {
        if (e.target.id === 'gdNoteText') refreshConfirmState();
    });

    const openBtn = document.getElementById('groupDeliverTriggerBtn');
    if (openBtn) {
        openBtn.addEventListener('click', function () {
            const boxes = checkedBoxes();

            const countEl = document.getElementById('gdCount');
            if (countEl) countEl.textContent = String(boxes.length);

            const list = document.getElementById('gdNamesList');
            if (list) {
                list.innerHTML = '';
                boxes.forEach(function (box) {
                    const li = document.createElement('li');
                    li.textContent = box.dataset.studentName || 'Student';
                    list.appendChild(li);
                });
            }

            const textarea = document.getElementById('gdNoteText');
            if (textarea) textarea.value = '';
            refreshConfirmState();

            window.showModal('groupDeliverModal');
        });
    }

    refreshTrigger();
};
