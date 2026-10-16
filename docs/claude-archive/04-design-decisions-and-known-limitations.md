# Design decisions and known limitations (pre-2026-10-04 text)

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 1642-1900 2143-2171 2317-2326 2416-2447; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

---

# DESIGN DECISIONS

Decisions made deliberately, recorded here so a future change doesn't
silently reverse them without someone noticing.

## 1. A subject with no evidence yet is excluded, never On Track

"In-Term Status reconciliation" work order — In-Term Status's worst-of
reduction (`InTermStatusService::overallStatusForSection()`) skips any
subject with zero scored items this term entirely. It does NOT count
toward the student's status in either direction.

The alternative — treating an unassessed subject as On Track — was
considered and rejected. A learner would then read On Track on a
dashboard purely because nobody has entered their scores yet, which is
the opposite of an early warning system. "Missing means incomplete, not
zero" is the same principle GradingEngine already applies to a single
component with no items; this decision applies it one level up, to a
whole subject with no evidence at all.

The honest treatment is: exclude the subject from the reduction, and let
the caller show the resulting status is based on fewer subjects than the
learner's full load (`overallStatusForSection()` returns both
`subjects_evaluated` and `subjects_total` for exactly this).

## 2. Overall In-Term Status is computed in one place

Both the Adviser dashboard and the Principal dashboard show the same
figure — the worst-of status across every subject a section takes, for
one term. Before this decision, each had its own implementation:
Adviser's called `GradingEngine` (respecting DO 015 exam-role weighting
and excluding no-role additional-support items from the Examination
component, per `GradingEngine::examinationPercentage()`'s own rule);
Principal's ran a hand-written SQL aggregate that agreed for
Written Work and Performance Task (both are a flat `SUM(earned)/
SUM(max_score)`) but silently disagreed for Examination once a subject
used exam roles — it neither weighted by role nor excluded the no-role
items. A real learner's Term 3 status read differently on the two
dashboards as a direct result (Molave, Vince Oribello, General
Mathematics — Adviser correctly showed Needs Attention at 72.78%
role-weighted; Principal wrongly showed On Track at 76.11% flat-summed,
including an additional-support item that should not have counted).

`InTermStatusService::overallStatusForSection()` is now the only place
this computation exists. Both dashboards call it. A change to the rule —
including a future change to decision 1 above — happens once, not twice.
Do not add a second implementation to make a page "faster"; the
Principal dashboard's whole-school aggregate used to exist for exactly
that reason, and it was wrong the whole time no one was measuring it
against the truth.

## 3. "Awaiting Your Decision" means `status = 'recommended'` OR `decided_by IS NULL`, not `status IN ('recommended', 'in_review')`

"Progress column honesty and the last duplicate rule" work order, PART
2 — the Part 3 sweep of the previous pass found this pair (dashboard
card vs. Interventions page banner) agreeing today only because no
`in_review` row has ever existed. On inspection the two rules weren't
equally valid variants — they actually disagreed on what `in_review`
means:

- The dashboard's rule (`only(['recommended', 'in_review'])->sum()`)
  treated `in_review` as still awaiting the Principal's decision.
- The Interventions banner's rule, and `Intervention::awaitingDecision()`
  — already load-bearing elsewhere, as the actual guard blocking an
  adviser from acknowledging an intervention before the Principal has
  decided — treats `in_review` as already decided, consistent with
  `Intervention::DECIDED_STATUSES`, which lists it as "a status that
  means the Principal has actually reviewed and decided this."

The dashboard's rule was the wrong one, not a harmless variant: `in_review`
is the Principal actively working the case, not an untouched
recommendation. `Intervention::scopeUndecided()` (query-level; named
differently from the existing `awaitingDecision()` instance method
because Eloquent resolves a same-named real method before it ever tries
the `scopeX` magic name, so `scopeAwaitingDecision` silently breaks
static calls) is now the only place this query exists. The dashboard
card, the Interventions banner, the "awaiting_decision=1" list filter,
the "Approve All Pending" bulk action, and `dss:report-undecided-
deliveries` all call it — five call sites that had each grown their own
copy or near-copy of this same WHERE clause. Do
not add a sixth.

## 4. School years are permanent historical entities; `school_year` is the join key, `student_enrollments` is the roster history

"Multi-school-year academic history" work order (2026-09-14). The
standing shape, and the reasons it was chosen over the alternatives:

- **`school_year` (the `'YYYY-YYYY'` string) stays the join key on every
  academic table** — grades, assessments, assessment_uploads,
  report_submissions, risk_results, interventions, sections,
  section_subjects, academic_terms, student_enrollments. An
  `academic_year_id` FK was added only where the relationship is
  structural (`academic_terms`, `student_enrollments`); everywhere else
  `AcademicYear` exposes `hasMany(..., 'school_year', 'school_year')`
  relations keyed on the string. Rewriting eight tables to an integer
  FK was rejected as a large, risky migration for no correctness gain:
  the string is unique on `academic_years`, and
  `AcademicYear::hasDependentRecords()` refuses to rename a year once
  anything references it, so the key is immutable in practice.
- **A record's context is stored on the record, never re-derived.**
  `sections` rows are themselves year-specific (`sections.school_year`),
  so a grade/assessment/report's `section_id` already pins grade level,
  track, specialization, and adviser as they were. `risk_results.
  section_id` and `interventions.school_year`/`section_id` were the two
  gaps (both resolved section through `students.section_id`, which
  changes on promotion) — now stored at creation and backfilled.
  `Intervention::contextSection()` is the one accessor evidence
  comparisons read; `$intervention->student->section` is never the
  historical answer.
- **`students.section_id` is the CURRENT pointer only; `student_enrollments`
  is history.** One row per learner per school year (unique), created/
  corrected by `Student::saved` → `StudentEnrollmentService::
  syncCurrentEnrollment()` on every ORM save path, and by
  `ensureForSection()` after batch imports (Maatwebsite batch inserts
  bypass model events). Promotion (`StudentEnrollmentService::enroll()`,
  `POST /admin/students/{student}/enroll`) creates a NEW row and moves the
  pointer only when the target year is the active one; it never rewrites
  the old row and never creates a second `Student`. Every "students of
  this section" lookup reads `Student::enrolledIn($section)` /
  `Section::enrolledStudents()`, so a historical section keeps its roster
  after its learners move on. `students.section_id` was kept (not
  dropped) because ~25 call sites read it and, for the active year, it
  always agrees with the enrollment row — it is the fast path, not a
  second source of truth.
- **Writes require the ACTIVE year's OPEN term, server-side.**
  `AcademicTerm::acceptsWrites($schoolYear, $term)` = `isOpen()` AND
  `$schoolYear === Section::activeSchoolYear()`; every Adviser write guard
  (grades, assessment items/uploads/edits, report submission) calls it.
  `AcademicYear::activate()` runs in a transaction with the table locked,
  deactivates every other year, and closes any term still open in the
  outgoing year (logged as `close_term`). A closed term or a completed
  year is read-only for Advisers even by direct POST; it is still fully
  viewable. There is deliberately no delete route for years or terms.
- **`Section::forAdviser($userId)`** replaces the 23 `where('adviser_id',
  ...)->first()` lookups: prefers the active year's section, falls back to
  the most recent historical one (shown read-only with a "Historical
  Record" badge). The old `first()` returned the LOWEST id — the adviser's
  oldest section — which would have pinned every Adviser screen to
  2026-2027 the moment a 2027-2028 section was assigned.
- **Historical pages select ONE year; nothing mixes years.** `AcademicYear::
  resolveSelected(request('school_year'))` is the only place a
  `?school_year=` parameter becomes a year — an unknown value falls back
  to the active year, never to "all years". Principal Dashboard/Students/
  Interventions/Subject Analysis and both Reports pages take it; the
  Admin dashboard's data-health figures stay on the active year by
  design. The at-risk widget used to read EVERY year's risk results with
  no year scope at all — that was a real mixed-year figure, now fixed.
- **Confirmation modals match the action.** Non-destructive actions
  (activate a year, open/close a term, enroll a learner) use the neutral
  `confirmActionModal` (`data-action-confirm` + `data-action-title/label/
  icon/loading` — see `resources/js/confirm.js`), never the red Delete
  modal with "Yes, Delete".

`tests/Feature/AcademicHistoryArchitectureTest.php` is the test of record
(TESTs 1-14 of the work order plus adviser-per-year and section-with-
history guards). `dss:check-integrity` now also reports more than one
active year, a learner whose current section has no enrollment row, and a
term row not linked to its year.

## Part 3 sweep — other figures shown on more than one screen

Same work order, Part 3: every other figure that appears on more than one
screen, checked against its actual code path rather than its label.
**Not fixed here** — each of these needs its own diagnosis, the same way
In-Term Status did, and a batch of simultaneous "corrections" is worse
than a known list.

- **Total Students** (Admin, Principal) — both call the identical
  `Student::count()`, so they can never disagree with each other. But
  neither is scoped to the active school year despite Principal's card
  reading "Across every section, Term N, [school year]" — a school that
  retains prior-year student records (e.g. not-yet-graduated learners
  from an earlier cohort) would show a bigger number than that label
  promises. Adviser's is correctly section-scoped, a different question
  by design. All three read 40 today only because exactly one section
  and one school year of data currently exist.
- **Grades Encoded** (Adviser dashboard, Adviser Submit Report) — both
  query `Grade::where(section, term, school_year)->count()` the same
  way; low drift risk. Found a THIRD copy of the `Subject::forSection()`
  query, byte-identical, private to `Adviser\ReportController::
  getSectionSubjects()` — the same "duplicate that agrees today" shape
  Part 2 just removed from `Adviser\DashboardController`. Not fixed here.
  Principal's "Assessment Completion" is a genuinely different metric
  (raw assessment-score entries across the whole school year, upstream
  of verification) from Grades Encoded (verified `grades` rows, per
  term) — correctly not the same number.
- **At Risk counts** — the Adviser/Principal dashboards' In-Term Status
  At Risk counts are now the same call (Part 2). Principal dashboard's
  separate Risk Level "High Risk" card and Principal Students' per-row
  Risk Level column are scoped differently on purpose (dashboard = the
  student's latest risk result this school year; Students page = the
  one term currently selected in that page's filter) — not expected to
  sum to the same total, and nothing in the interface claims they should.
- **Intervention counts** — a genuine latent duplicate rule, currently
  invisible: the Principal dashboard's "Awaiting Your Decision" counts
  `status IN ('recommended', 'in_review')`
  (`DashboardAnalyticsService::getPrincipalSummary()`); the Principal
  Interventions page's banner/link count is `status = 'recommended' OR
  decided_by IS NULL` (`Principal\InterventionController::index()`).
  These are two different rules. They read the same number today (18)
  only because no `in_review` row currently has `decided_by` set — the
  moment one does, they will disagree exactly the way the two In-Term
  Status paths did. Adviser dashboard's intervention counts answer a
  different question (what THIS adviser needs to act on next) and were
  never meant to match Principal's.
- **Risk Level distribution** — Principal dashboard's Low/Moderate/High
  cards and Principal Students' per-row Risk Level are scoped
  differently by design, same reasoning as the At Risk counts above.

---

# KNOWN LIMITATIONS

## Formal DepEd remediation (SRC / RCM / RFG) is not implemented

Under DO 8, s. 2015 and DO 015, s. 2026, remediation is a **post-term**
programme: the Summer Remedial Class (SRC), open to a learner who failed
at most two learning areas, runs after Final Grades are computed. It
produces a Remedial Class Mark (RCM), and the Recomputed Final Grade
(RFG) is the *average* of the Final Grade and the RCM — never an
addition to the term's points.

This system supports **within-term academic support only**: an adviser
can add an additional assessment item (e.g. a re-teach quiz) during the
term, which counts in full toward the term's total points, exactly like
any other summative item. The `remediation` intervention type's stored
enum value is unchanged for backward compatibility, but its display
label reads "Additional Practice and Re-teaching" for this reason — it
is not DepEd's formal remediation.

The Summer Remedial Class workflow, Remedial Class Mark, and Recomputed
Final Grade are **not implemented** anywhere in this codebase. This
would be a separate module, built once the school decides how SRC
eligibility, scheduling, and RCM entry should work here — out of scope
until then.

**Open policy question — how within-term additional support should count.**
Additional assessment items currently contribute their full points to the
term's total, alongside every other item. This means a strong remedial result
lifts the affected component by less than its own percentage, and a learner who
was well below target often improves without reaching it. In one observed case
a learner's Examination component moved from 58.89% to 63.89% after remedial
work — real improvement, still below the 75 target.

Three approaches were considered:

1. **Additive (current).** Simple, transparent, never advantages a
   remediated learner over one who passed first time. Weak rescue effect.
2. **Replacement.** The remedial score replaces the item it targets. Strong
   rescue, but a remediated learner can finish above a learner who passed
   without support.
3. **Averaging with a cap at 75.** Mirrors DepEd's Summer Remedial Class,
   where the Recomputed Final Grade is the average of the Final Grade and the
   Remedial Class Mark. Rescues to exactly passing and no further.

The school has not yet decided. The system implements (1) and marks grades
that include additional support so the situation is visible rather than
silently resolved.


## ECR roster reconciliation only catches one direction

`EcrReaderService`/`AssessmentUploadService` (ECR alignment work order,
PART 5e) report a learner who appears in an uploaded E-Class Record but has
no matching enrolled student — the same check the flat CSV/XLSX path
already had. They do **not** report the reverse: a student enrolled in the
section who never appears anywhere in the uploaded file at all.

This is the direction that matters more, because it fails silently rather
than loudly. A learner missing from the file simply ends up with no grade
for that subject and term, and nothing at upload time says so — Term
Readiness, Submit Report, and the risk classifier all proceed as though the
student's record for that subject were complete rather than absent, the
same "missing means incomplete, not zero" failure mode design decision 1
above exists to prevent one level down (a subject with zero scored items),
just one level up (a student with zero rows at all). Not built in this
pass; see `ECR_ALIGNMENT_WORK_ORDER.md` Part 5e for the full note.

## Attendance is not part of the DSS

Attendance was RETIRED from the operational DSS on 2026-09-27 (see
"Subject-first monitoring and the attendance retirement" at the end of this
file): no page, route, rule, count or new intervention type uses it. The
`attendance_records` table and its rows are kept as dormant history. It was
never a grading component — `GradingEngine` never read it. Under DO 8, s. 2015 and DO 015, s. 2026 the grade is computed
from Written Work, Performance Task, and Examination only.
Attendance-based promotion and retention rules are outside this
system's scope.


## Interventions have an origin

Every intervention record is created by a Principal through the interface; the
`origin` column records this as `principal`. The value `system` exists for a
future source that generates intervention records from risk analysis, and
nothing sets it today. The interface must not claim the DSS decided something
a person decided. The DSS produces the recommendation text, the focus area,
and the risk classification that inform the Principal's judgment. It does not
create, approve, or close intervention records.


## Recommendations for future work

Three items from the "COMPLETE WORK ORDER" (Parts 7-9) were deliberately deferred
for the defence — the pilot (one adviser, one section, two electives) never
exposes any of them, and none touches grading, risk, or authorization logic, so
none blocks demonstrating the system as it stands. If the school decides to run
this beyond the pilot, they become the next engineering pass, in this order:

1. **Assigning real electives** — the `section_subject` pivot itself is built
   (PART 6; see "RESOLVED (mechanism)" above). What's left is data, not code:
   the school's answer to Q1 ("does every learner in a section take the same
   subjects, or does each learner choose individually?") followed by a real
   roster with real elective choices to populate the pivot. If Q1's answer is
   "per learner," `section_subject` → `student_subject` is an addition on top
   of what exists, not a rebuild.
2. **Transmutation table verification against the signed order** — see "The
   `do015_2026` transmutation table is confirmed against DepEd's own
   instrument" above: confirmed against the operational ECR workbook, not yet
   against the signed PDF of DO 015, s. 2026 itself. Research, not code:
   download the signed order from deped.gov.ph and check all 41 bands.
3. **Subject teachers** — `sections.adviser_id` is a single user who encodes
   every subject in the section; a real SHS assigns one teacher per subject,
   with the class adviser compiling. Invisible in the pilot (one section, two
   subjects, one adviser) for the same reason the elective limitation is.
   Touches authorization, the encoding screen, upload scoping, term readiness,
   and report submission together — grep `adviser_id` for the full list of call
   sites this would change. Most likely schema: a nullable `teacher_id` folded
   into the `section_subject` table from item 1, once it exists. Not built here;
   this paragraph is the future work item, not a design doc.

---


