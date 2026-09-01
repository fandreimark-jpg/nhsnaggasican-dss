// resources/js/modal.js

window.toDateInputValue = function (value) {
    return value ? value.substring(0, 10) : "";
};

// Mobile sidebar (hamburger menu) — shows/hides the navigation drawer
// on small screens. On desktop the sidebar is always visible via the
// md:translate-x-0 class in the blade file, so these functions only
// ever matter below the md breakpoint.
window.toggleSidebar = function () {
    const sidebar = document.getElementById("sidebar");
    if (!sidebar) return;
    const isOpen = !sidebar.classList.contains("-translate-x-full");
    if (isOpen) {
        window.closeSidebar();
    } else {
        sidebar.classList.remove("-translate-x-full");
        document.getElementById("sidebarOverlay")?.classList.remove("hidden");
    }
};

window.closeSidebar = function () {
    const sidebar = document.getElementById("sidebar");
    if (!sidebar) return;
    sidebar.classList.add("-translate-x-full");
    document.getElementById("sidebarOverlay")?.classList.add("hidden");
};

window.showModal = function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    const box = el.querySelector(".modal-box");

    el.classList.remove("hidden");

    // Force the browser to register the "closed" state first, so the
    // change to "open" state below actually animates instead of
    // jumping straight to the end (a common CSS transition gotcha).
    void el.offsetWidth;

    el.classList.remove("opacity-0");
    el.classList.add("opacity-100");
    if (box) {
        box.classList.remove("scale-95", "opacity-0");
        box.classList.add("scale-100", "opacity-100");
    }
};

window.hideModal = function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    const box = el.querySelector(".modal-box");

    el.classList.remove("opacity-100");
    el.classList.add("opacity-0");
    if (box) {
        box.classList.remove("scale-100", "opacity-100");
        box.classList.add("scale-95", "opacity-0");
    }

    // Wait for the fade-out transition (200ms) to finish before actually
    // hiding the element — otherwise it disappears instantly and the
    // closing animation never gets to play.
    setTimeout(function () {
        el.classList.add("hidden");
    }, 200);
};

// Makes a modal close when the user clicks the dark overlay outside it.
// extraCleanup is optional — for modals that need to reset something
// (like re-enabling a disabled field) when they close.
window.bindModalOverlayClose = function (id, extraCleanup) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.addEventListener("click", function (e) {
        if (e.target !== modal) return;
        if (extraCleanup) extraCleanup();
        window.hideModal(id);
    });
};

document.addEventListener("DOMContentLoaded", function () {
    // =============================================
    // ADVISER — EDIT STUDENT MODAL
    // =============================================
    const editStudentForm = document.getElementById("editForm");

    if (document.getElementById("editModal") && editStudentForm) {
        window.openEditModal = function (student) {
            document.getElementById("edit_last_name").value = student.last_name;
            document.getElementById("edit_first_name").value =
                student.first_name;
            document.getElementById("edit_middle_name").value =
                student.middle_name ?? "";
            document.getElementById("edit_gender").value = student.gender;
            document.getElementById("edit_birthdate").value =
                window.toDateInputValue(student.birthdate);
            editStudentForm.action = `/adviser/students/${student.id}`;
            window.showModal("editModal");
        };

        window.closeEditModal = () => window.hideModal("editModal");
        window.bindModalOverlayClose("editModal");
    }

    // =============================================
    // PROFILE MODAL (used on every page, adviser or admin)
    // =============================================
    if (document.getElementById("profileModal")) {
        window.openProfileModal = () => window.showModal("profileModal");
        window.closeProfileModal = () => window.hideModal("profileModal");
        window.bindModalOverlayClose("profileModal");

        // Shows/hides the "Current Password" field depending on whether
        // the email was actually changed from what's saved in the database.
        window.toggleCurrentPasswordField = function () {
            const emailInput = document.getElementById("profile_email");
            const wrapper = document.getElementById("currentPasswordWrapper");
            const pwField = document.getElementById("current_password_field");
            if (!emailInput || !wrapper || !pwField) return;

            const emailWasChanged =
                emailInput.value !== emailInput.dataset.originalEmail;

            if (emailWasChanged) {
                wrapper.classList.remove("hidden");
                pwField.required = true;
            } else {
                wrapper.classList.add("hidden");
                pwField.required = false;
                pwField.value = "";
            }
        };

        // Run once on load too, in case the form re-rendered after a
        // validation error with a changed email already typed in.
        window.toggleCurrentPasswordField();
    }

    // =============================================
    // ADMIN — USER MANAGEMENT MODAL
    // =============================================
    const userForm = document.getElementById("userForm");
    const userTitle = document.getElementById("modalTitle");
    const userMethod = document.getElementById("formMethod");
    const userSubmit = document.getElementById("submitBtn");
    const passwordNote = document.getElementById("passwordNote");

    if (document.getElementById("userModal") && userForm) {
        const storeUrl = userForm.dataset.storeUrl;

        window.openAddModal = function () {
            userForm.reset();
            userTitle.textContent = "Add User";
            userSubmit.textContent = "Save User";
            userForm.action = storeUrl;
            userMethod.value = "POST";
            document.getElementById("field_password").required = true;
            if (passwordNote) passwordNote.classList.add("hidden");
            window.showModal("userModal");
        };

        window.openUserEditModal = function (user) {
            userForm.reset();
            userTitle.textContent = "Edit User";
            userSubmit.textContent = "Update User";
            userForm.action = `/admin/users/${user.id}`;
            userMethod.value = "PUT";

            document.getElementById("field_last_name").value = user.last_name ?? "";
            document.getElementById("field_first_name").value = user.first_name ?? "";
            document.getElementById("field_middle_name").value = user.middle_name ?? "";

            const username = user.email
                ? user.email.replace("@naggasican.edu.ph", "")
                : "";
            document.getElementById("field_username").value = username;
            document.getElementById("field_role").value = user.role;
            document.getElementById("field_password").required = false;
            if (passwordNote) passwordNote.classList.remove("hidden");

            window.showModal("userModal");
        };

        window.closeUserModal = () => window.hideModal("userModal");
        window.bindModalOverlayClose("userModal");
    }

    // Loads a specialization dropdown for whichever track is picked.
    // Shared by BOTH the Section modal and the Subject modal — each just
    // tells it which <select> to fill in and which URL to fetch from,
    // instead of each page writing its own near-identical copy.
    window.loadSpecializations = function (trackId, specSelectId, byTrackUrl, selectedSpecId = null) {
        const specSelect = document.getElementById(specSelectId);
        specSelect.innerHTML = '<option value="">— Loading... —</option>';

        if (!trackId) {
            specSelect.innerHTML = '<option value="">— Select Track First —</option>';
            return;
        }

        fetch(`${byTrackUrl}/${trackId}`)
            .then((response) => response.json())
            .then((data) => {
                specSelect.innerHTML = '<option value="">— Select Specialization —</option>';

                if (data.length === 0) {
                    specSelect.innerHTML = '<option value="">— No specializations for this track —</option>';
                    return;
                }

                data.forEach((spec) => {
                    const option = document.createElement("option");
                    option.value = spec.id;
                    option.textContent = spec.name;
                    if (selectedSpecId && spec.id == selectedSpecId) {
                        option.selected = true;
                    }
                    specSelect.appendChild(option);
                });
            })
            .catch(() => {
                specSelect.innerHTML = '<option value="">— Error loading specializations —</option>';
            });
    };

    // =============================================
    // ADMIN — SECTION MANAGEMENT MODAL
    // (moved here from the old standalone public/js/admin/sections.js,
    // which duplicated this same logic in a separate, non-bundled file)
    // =============================================
    const sectionForm = document.getElementById("sectionForm");
    const sectionTitle = document.getElementById("modalTitle");
    const sectionMethod = document.getElementById("sectionMethod");

    if (document.getElementById("sectionModal") && sectionForm) {
        const sectionStoreUrl = sectionForm.dataset.storeUrl;
        const specByTrackUrl = sectionForm.dataset.specUrl;
        const adviserSelect = document.getElementById("sectionAdviser");

        window.openAddSectionModal = function () {
            sectionForm.reset();
            sectionTitle.textContent = "Add Section";
            sectionMethod.value = "POST";
            sectionForm.action = sectionStoreUrl;
            document.getElementById("sectionSchoolYear").value = "2026-2027";
            document.getElementById("sectionSpec").innerHTML = '<option value="">— Select Track First —</option>';

            // Only hide advisers who already have a DIFFERENT section.
            // (BUG FIX: the old sections.js version referenced a
            // "section" variable that didn't exist in this function,
            // which threw a JS error every time Add Section was clicked.)
            Array.from(adviserSelect.options).forEach((option) => {
                const hasOtherSection = option.value !== "" && option.dataset.sectionId !== "";
                option.style.display = hasOtherSection ? "none" : "";
            });
            adviserSelect.value = "";

            window.showModal("sectionModal");
        };

        window.openEditSectionModal = function (section) {
            sectionForm.reset();
            sectionTitle.textContent = "Edit Section";
            sectionMethod.value = "PUT";
            sectionForm.action = `/admin/sections/${section.id}`;

            document.getElementById("sectionName").value = section.name;
            document.getElementById("sectionGrade").value = section.grade_level;
            document.getElementById("sectionTrack").value = section.track_id ?? "";
            document.getElementById("sectionSchoolYear").value = section.school_year;

            // Show every adviser EXCEPT those already on a DIFFERENT section —
            // the adviser currently on THIS section stays visible and selected.
            // (Earlier we removed this filtering entirely, thinking the hide
            // logic itself was the bug — but the real bug was one level up:
            // the dropdown's options came from $availableAdvisers, which
            // excluded the current section's own adviser from the list
            // completely. Now that the blade loops over $allAdvisers instead,
            // the option genuinely exists, so this filtering is safe again.)
            Array.from(adviserSelect.options).forEach((option) => {
                const belongsToAnotherSection =
                    option.value !== "" &&
                    option.dataset.sectionId !== "" &&
                    option.dataset.sectionId != section.id;
                option.style.display = belongsToAnotherSection ? "none" : "";
            });
            adviserSelect.value = section.adviser_id ?? "";

            if (section.track_id) {
                window.loadSpecializations(section.track_id, "sectionSpec", specByTrackUrl, section.specialization_id);
            }

            window.showModal("sectionModal");
        };

        window.closeSectionModal = () => window.hideModal("sectionModal");
        window.bindModalOverlayClose("sectionModal");
    }

    // =============================================
    // ADMIN — STUDENT MANAGEMENT MODAL
    // (Edit-only — adding new students moved to the Adviser side)
    // =============================================
    const adminStudentForm = document.getElementById("adminStudentForm");
    const studentModalTitle = document.getElementById("studentModalTitle");
    const studentMethod = document.getElementById("studentMethod");
    const studentSubmitBtn = document.getElementById("studentSubmitBtn");

    if (document.getElementById("adminStudentModal") && adminStudentForm) {
        const lrnField = () => document.getElementById("ps_lrn");

        window.openEditStudentModal = function (student) {
            adminStudentForm.reset();
            studentModalTitle.textContent = "Edit Student";
            studentSubmitBtn.textContent = "Update Student";
            adminStudentForm.action = `/admin/students/${student.id}`;
            studentMethod.value = "PUT";

            document.getElementById("ps_lrn").value = student.lrn;
            document.getElementById("ps_last_name").value = student.last_name;
            document.getElementById("ps_first_name").value = student.first_name;
            document.getElementById("ps_middle_name").value = student.middle_name ?? "";
            document.getElementById("ps_gender").value = student.gender;
            document.getElementById("ps_birthdate").value = window.toDateInputValue(student.birthdate);
            document.getElementById("ps_section_id").value = student.section_id;

            // LRN should not be changed while editing
            lrnField().readOnly = true;
            lrnField().classList.add("bg-gray-50", "text-gray-400");

            window.showModal("adminStudentModal");
        };

        const resetLrnField = function () {
            lrnField().readOnly = false;
            lrnField().classList.remove("bg-gray-50", "text-gray-400");
        };

        window.closeStudentModal = function () {
            resetLrnField();
            window.hideModal("adminStudentModal");
        };

        window.bindModalOverlayClose("adminStudentModal", resetLrnField);
    }

    // =============================================
    // ADMIN — ADD STUDENT MODAL
    // =============================================
    if (document.getElementById("addStudentModal")) {
        window.openAddStudentModal = () => window.showModal("addStudentModal");
        window.closeAddStudentModal = () => window.hideModal("addStudentModal");
        window.bindModalOverlayClose("addStudentModal");
    }

    // =============================================
    // ADMIN — IMPORT STUDENTS MODAL
    // =============================================
    if (document.getElementById("importStudentsModal")) {
        window.openImportStudentsModal = () => window.showModal("importStudentsModal");
        window.closeImportStudentsModal = () => window.hideModal("importStudentsModal");
        window.bindModalOverlayClose("importStudentsModal");
    }

    // =============================================
    // ADMIN — TRACK MANAGEMENT MODAL
    // =============================================
    const trackForm = document.getElementById("trackForm");

    if (document.getElementById("trackModal") && trackForm) {
        const trackStoreUrl = trackForm.dataset.storeUrl;

        window.openAddTrackModal = function () {
            document.getElementById("modalTitle").textContent = "Add Track";
            document.getElementById("formMethod").value = "POST";
            trackForm.action = trackStoreUrl;
            document.getElementById("trackName").value = "";
            document.getElementById("trackCode").value = "";
            window.showModal("trackModal");
        };

        window.openEditTrackModal = function (track) {
            document.getElementById("modalTitle").textContent = "Edit Track";
            document.getElementById("formMethod").value = "PUT";
            trackForm.action = `/admin/tracks/${track.id}`;
            document.getElementById("trackName").value = track.name;
            document.getElementById("trackCode").value = track.code;
            window.showModal("trackModal");
        };

        window.closeTrackModal = () => window.hideModal("trackModal");
        window.bindModalOverlayClose("trackModal");
    }

    // =============================================
    // ADMIN — SPECIALIZATION MANAGEMENT MODAL
    // =============================================
    const specForm = document.getElementById("specForm");

    if (document.getElementById("specModal") && specForm) {
        const specStoreUrl = specForm.dataset.storeUrl;

        window.openAddSpecModal = function () {
            document.getElementById("modalTitle").textContent = "Add Specialization";
            document.getElementById("formMethod").value = "POST";
            specForm.action = specStoreUrl;
            document.getElementById("specTrack").value = "";
            document.getElementById("specName").value = "";
            document.getElementById("specCode").value = "";
            window.showModal("specModal");
        };

        window.openEditSpecModal = function (spec) {
            document.getElementById("modalTitle").textContent = "Edit Specialization";
            document.getElementById("formMethod").value = "PUT";
            specForm.action = `/admin/specializations/${spec.id}`;
            document.getElementById("specTrack").value = spec.track_id;
            document.getElementById("specName").value = spec.name;
            document.getElementById("specCode").value = spec.code;
            window.showModal("specModal");
        };

        window.closeSpecModal = () => window.hideModal("specModal");
        window.bindModalOverlayClose("specModal");
    }

    // =============================================
    // ADMIN — SUBJECT MANAGEMENT MODAL
    // =============================================
    const subjectForm = document.getElementById("subjectForm");

    if (document.getElementById("subjectModal") && subjectForm) {
        const subjectStoreUrl = subjectForm.dataset.storeUrl;
        const subjectSpecByTrackUrl = subjectForm.dataset.specUrl;

        // Shows the track/specialization fields only for elective subjects —
        // core subjects apply to everyone, so they don't need a track.
        window.toggleTrackFields = function () {
            const type = document.getElementById("subjectType").value;
            const trackFields = document.getElementById("trackFields");

            if (type === "elective") {
                trackFields.classList.remove("hidden");
            } else {
                trackFields.classList.add("hidden");
                document.getElementById("subjectTrack").value = "";
                document.getElementById("subjectSpec").innerHTML =
                    '<option value="">— All specializations in track —</option>';
            }
        };

        window.openAddSubjectModal = function () {
            document.getElementById("modalTitle").textContent = "Add Subject";
            document.getElementById("formMethod").value = "POST";
            subjectForm.action = subjectStoreUrl;
            document.getElementById("subjectName").value = "";
            document.getElementById("subjectType").value = "";
            document.getElementById("subjectGrade").value = "";
            document.getElementById("subjectTrack").value = "";
            document.getElementById("subjectSpec").innerHTML =
                '<option value="">— All specializations in track —</option>';
            document.getElementById("trackFields").classList.add("hidden");
            window.showModal("subjectModal");
        };

        window.openEditSubjectModal = function (subject) {
            document.getElementById("modalTitle").textContent = "Edit Subject";
            document.getElementById("formMethod").value = "PUT";
            subjectForm.action = `/admin/subjects/${subject.id}`;
            document.getElementById("subjectName").value = subject.name;
            document.getElementById("subjectType").value = subject.type;
            document.getElementById("subjectGrade").value = subject.grade_level;

            if (subject.type === "elective") {
                document.getElementById("trackFields").classList.remove("hidden");
                document.getElementById("subjectTrack").value = subject.track_id ?? "";
                if (subject.track_id) {
                    window.loadSpecializations(
                        subject.track_id,
                        "subjectSpec",
                        subjectSpecByTrackUrl,
                        subject.specialization_id
                    );
                }
            } else {
                document.getElementById("trackFields").classList.add("hidden");
            }

            window.showModal("subjectModal");
        };

        window.closeSubjectModal = () => window.hideModal("subjectModal");
        window.bindModalOverlayClose("subjectModal");
    }

    // =============================================
    // ADMIN — IMPORT SUBJECTS MODAL
    // =============================================
    if (document.getElementById("importSubjectsModal")) {
        window.openImportSubjectsModal = () => window.showModal("importSubjectsModal");
        window.closeImportSubjectsModal = () => window.hideModal("importSubjectsModal");
        window.bindModalOverlayClose("importSubjectsModal");
    }
});