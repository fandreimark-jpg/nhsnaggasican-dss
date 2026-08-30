// resources/js/search-filter.js
//
// One generic search box for any table on the site. Every page used to
// write its own copy of this same "hide rows that don't match" logic
// (adviser students, sections, admin students, subjects, users) —
// now they all just call initTableSearch() with their own element IDs.

window.initTableSearch = function (searchInputId, rowSelector, noResultsId) {
    const input = document.getElementById(searchInputId);
    if (!input) return;

    input.addEventListener("input", function () {
        const query = this.value.toLowerCase();
        const rows = document.querySelectorAll(rowSelector);
        let visibleCount = 0;

        rows.forEach(function (row) {
            const matches = row.textContent.toLowerCase().includes(query);
            row.style.display = matches ? "" : "none";
            if (matches) visibleCount++;
        });

        if (noResultsId) {
            const noResults = document.getElementById(noResultsId);
            if (noResults) noResults.classList.toggle("hidden", visibleCount > 0);
        }
    });
};

// Auto-submits a GET filter form as the person uses it — no separate
// "Filter" button needed. Dropdowns (<select>) submit immediately on
// change. Text inputs submit after a short pause in typing (debounced),
// so the page doesn't reload on every single keystroke.
//
// By default this does a real form submit (full page reload) — fine
// for simple filter pages like Admin Reports. Pass options.ajaxTarget
// (an element ID) to instead fetch the filtered results in the
// background and swap that element's contents in place — no page
// reload, no address-bar flash, no loss of scroll position on the
// rest of the page. Used by the Admin Dashboard's at-risk filters,
// where reloading the entire dashboard for a small filter change
// felt slow and jarring.
window.initAutoSubmitFilter = function (formId, options = {}) {
    const form = document.getElementById(formId);
    if (!form) return;

    const debounceMs   = options.debounceMs ?? 500;
    const ajaxTargetId = options.ajaxTarget || null;

    let debounceTimer = null;

    function submitForm() {
        if (!ajaxTargetId) {
            form.submit();
            return;
        }

        const target = document.getElementById(ajaxTargetId);
        if (!target) {
            // No matching container on the page — fall back to a
            // normal submit rather than silently doing nothing.
            form.submit();
            return;
        }

        const params  = new URLSearchParams(new FormData(form));
        const baseUrl = form.getAttribute('action') || window.location.pathname;
        const fullUrl = baseUrl + '?' + params.toString();

        target.classList.add('opacity-50');

        fetch(fullUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) throw new Error('Request failed');
                return response.text();
            })
            .then(function (html) {
                target.innerHTML = html;
                target.classList.remove('opacity-50');
                // Keep the URL shareable/refreshable without a full
                // navigation — the back/forward buttons and reload
                // still land on the right filtered view.
                window.history.replaceState({}, '', fullUrl);
            })
            .catch(function () {
                // Network hiccup — fall back to a real page load so
                // the person isn't stuck looking at a stale list.
                form.submit();
            });
    }

    form.querySelectorAll('select').forEach(function (el) {
        el.addEventListener('change', submitForm);
    });

    form.querySelectorAll('input[type="text"], input[type="search"]').forEach(function (el) {
        el.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(submitForm, debounceMs);
        });
    });
};

// Cascading dropdown: hides <option>s in `dependentSelectId` whose
// data-grade-level doesn't match the currently selected value of
// `controllingSelectId`. If the option that was selected gets hidden
// by a new Grade Level choice, its value is reset to "" (the "All"
// option) so the form never submits a now-invalid combination like
// "Grade 12" + "Section Narra" (which is actually a Grade 11 section).
//
// Runs client-side only — the full list of options is already on the
// page from the initial load, so no extra request is needed just to
// narrow which ones are visible.
window.initCascadingSelect = function (controllingSelectId, dependentSelectId) {
    const controlling = document.getElementById(controllingSelectId);
    const dependent    = document.getElementById(dependentSelectId);
    if (!controlling || !dependent) return;

    function applyFilter() {
        const selectedLevel = controlling.value;
        let selectedStillVisible = false;

        Array.from(dependent.options).forEach(function (opt) {
            if (!opt.value) {
                // The "All sections" option is always visible
                return;
            }
            const matches = !selectedLevel || opt.dataset.gradeLevel === selectedLevel;
            opt.hidden = !matches;
            if (matches && opt.selected) {
                selectedStillVisible = true;
            }
        });

        if (dependent.value && !selectedStillVisible) {
            dependent.value = '';
        }
    }

    controlling.addEventListener('change', applyFilter);
    applyFilter(); // apply on load too, in case a filter is already active
};