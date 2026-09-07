# SHS DECISION SUPPORT SYSTEM
# MASTER ENGINEERING INSTRUCTIONS

## PROJECT ROLE

This is an EXISTING Senior High School Decision Support
System built with Laravel.

Do not rebuild the application from scratch.

The system must be developed incrementally while preserving
existing functionality and existing data.

---

# THREE SYSTEM ROLES

The system has three primary roles:

1. ADMIN
2. ADVISER
3. PRINCIPAL

## ADMIN

Admin is responsible for system/master data management.

Potential areas:

- Users
- Tracks
- Specializations
- Subjects
- Sections
- Students
- Academic Terms
- School Year
- Other system configuration

## ADVISER

Adviser is responsible for assigned academic data.

Main workflow:

Select Academic Term
→ Select Section
→ Select Subject
→ Upload Assessment Form
→ Detect Assessment Columns
→ Verify Classification
→ Validate Data
→ Preview
→ Import
→ Analyze Performance
→ Prepare Final Grade
→ Term Readiness
→ Submit

Advisers must only access students, sections, subjects,
and academic data that they are authorized to manage.

## PRINCIPAL

Principal is the primary Decision Support user.

Principal areas include:

- Dashboard
- Student Performance
- Assessment Analysis
- Subject Analysis
- Section Analysis
- At-Risk Students
- Students Needing Monitoring
- Decision Support
- Intervention
- Progress Monitoring
- Reports

The DSS provides recommendations and evidence.

The Principal makes the final academic decision.

---

# GRADING CONFIGURATION

Current grading configuration (DO 8, s. 2015 — still Grade 12's scheme,
and the default for any subject with no more specific rule):

Written Work = 25%

Performance Task = 50%

Examination = 25%

Total = 100%.

Grade 11, from SY 2026-2027 onward, is on DO 015, s. 2026 instead, which
assigns a DIFFERENT split per SHS subject group (Core Academic,
Field Exposure, Arts/Sports/Wellness, Research/Innovation, TechPro, Work
Immersion) — two of which have no Examination component at all. These
weights are DATA, never hardcoded: see `subject_group_weights` (keyed by
scheme + subject group) and `SubjectGroupWeight::resolve()`, which
`GradingEngine` reads instead of a fixed percentage. Under DO 015, the
Examination component is itself further split between two Summative
Tests and a Term Examination — also data, see `exam_role_shares` and
`GradingEngine::examinationPercentage()`.

Do not simply average the three component percentages.

Component percentage:

earned score / maximum score × 100

Weighted component:

component percentage × component weight

Final Grade:

Written Work contribution
+
Performance Task contribution
+
Examination contribution

Example test:

Written Work = 84.44%
Performance Task = 85%
Examination = 70%

Expected:

WW = 21.11
PT = 42.50
Exam = 17.50

Final = 81.11

This must be tested through automated tests.

Do not hard-code the example into production logic.

---

# ASSESSMENT SYSTEM

Assessment forms may contain:

- Quiz
- Activity
- Performance Task
- Examination
- Other assessment records

The system should detect likely assessment categories.

Examples:

Quiz 1
Quiz 2
Activity 1

→ Written Work

Performance Task 1
Project
Presentation

→ Performance Task

Exam
Final Exam

→ Examination

The detection must be shown to the Adviser for verification.

Ambiguous columns must not be silently classified.

The Adviser must be able to correct the classification before
import.

## Upload file format

Columns 0/1/2 are always `lrn, last_name, first_name`. Every column
from index 3 onward is a candidate assessment item, named by its
header.

The row immediately after the header may optionally be a MAX row,
carrying each column's maximum score so it does not have to be
retyped by hand on the Verify screen every upload:

```
lrn           | last_name | first_name | Quiz 1 | Quiz 2 | Quiz 3
MAX           |           |            |   20   |   15   |   25
110000000001  | Agbayani  | Rhea Mae   |   18   |   13   |   23
```

The MAX row is identified by the literal string `MAX`
(case-insensitive, trimmed) in column 0, where a real student's LRN
would otherwise be. A real student row always has a 12-digit LRN
(enforced everywhere a student is created), so this sentinel can
never collide with an actual student — there is no ambiguity.

Backward compatibility is mandatory: a file with no MAX row (row 2's
column 0 is not `MAX`) behaves exactly as before this row existed —
every max score is typed by hand on the Verify screen, unchanged.

The Verify screen prefills each Max Score field from the MAX row
when present, labeled "from file", but the field stays editable —
the Adviser is still the authority on what the paper was worth and
can override it. A blank MAX-row cell is treated as "not supplied"
for that column, not an error. A MAX-row cell that is non-numeric or
zero/negative is a file-level error that blocks the upload and names
the offending column — a wrong max is exactly what this feature
exists to prevent, so it is never silently ignored or defaulted.

`assessments.max_score` remains the single source of truth once a
column is imported — the MAX row only changes where the Verify
screen's prefilled value initially comes from, never the schema or
the validation that runs against the confirmed value afterward. A
declared maximum that looks implausibly high relative to the actual
scores in the file still produces the same non-blocking Preview
warning regardless of whether that maximum was typed by hand or read
from a MAX row (see `AssessmentUploadService::SUSPICIOUS_MAX_RATIO`).

---

# ASSESSMENT VALIDATION

Validate:

- Student existence
- Student-section relationship
- Adviser authorization
- Subject
- Academic term
- School year
- Assessment type
- Score
- Maximum score
- Duplicate records
- Missing records
- Invalid values

Invalid data must not silently enter the database.

---

# DECISION SUPPORT

The DSS must not depend only on final grade.

It must analyze the underlying assessment components.

Analyze:

- Written Work
- Performance Task
- Examination
- Individual assessments when available
- Subject performance
- Component gaps
- Trends
- Completion
- Risk indicators
- Intervention status

Example:

Written Work = 84%
Performance Task = 60%
Examination = 70%

Target = 75%.

The system should identify:

Written Work:
84%
Gap = +9
Status = On Track

Performance Task:
60%
Gap = -15
Status = Needs Attention

Examination:
70%
Gap = -5
Status = Needs Attention

Primary concern:

Performance Task.

The DSS should explain the reason.

---

# PRINCIPAL DECISION SUPPORT

The Principal dashboard should provide useful information such
as:

- Total Students
- On Track
- Needs Monitoring
- At Risk
- Under Intervention
- Weakest Subjects
- Weakest Components
- Assessment Completion
- Performance Trends
- Intervention Status

Filters may include:

- School Year
- Academic Term
- Grade Level
- Track
- Specialization
- Section
- Subject
- Risk Level
- Assessment Component

---

# INTERVENTION

The DSS recommends.

The Principal decides.

Do not automatically approve interventions.

Possible recommendations:

- Remediation
- Additional Learning Activity
- Additional Performance Task
- Teacher Monitoring
- Attendance Monitoring
- Parent/Guardian Conference
- Other appropriate intervention

Principal decisions must be recorded where appropriate.

**Delivery is per-learner or per-genuine-group, never blind bulk.** An
intervention may be marked delivered individually, or together with others
when one activity genuinely covered all of them. Either way a written note
describing what was actually done is required, and group deliveries are
labelled as such so the record never implies individual attention that was not
given.

---

# PROGRESS MONITORING

The system should support before/after comparison.

Example:

Before Intervention:
Performance Task = 60%

After Intervention:
Performance Task = 78%

Change:
+18 percentage points

Use neutral language.

Do not claim an intervention caused improvement unless the
available evidence supports that conclusion.

---

# TERM READINESS

Before final term submission, verify:

- Assessment forms uploaded
- Assessment mappings verified
- Required records present
- Invalid records resolved
- Students accounted for
- Final grade calculation available
- No unresolved blocking errors

Example:

45 students
43 complete
2 incomplete

Status:

NOT READY

---

# DATABASE SAFETY

This is an existing system.

Protect existing data.

NEVER use:

php artisan migrate:fresh

php artisan db:wipe

DROP DATABASE

unless explicitly authorized.

Before modifying the database:

1. Inspect existing migrations.
2. Inspect current schema.
3. Identify reusable tables.
4. Identify relationships.
5. Create the smallest safe migration.
6. Run the migration.
7. Verify migration status.
8. Test affected functionality.

Do not create duplicate tables when existing tables can be
extended safely.

---

# AUTHORIZATION

Do not rely only on hiding UI menu items.

Use proper authorization through appropriate:

- Middleware
- Policies
- Gates
- Query scoping

Test direct URL access.

Test unauthorized access.

Examples:

Adviser must not access another Adviser's students.

Adviser must not access Admin functions.

Admin must not access unauthorized Principal DSS functions.

Principal must be able to access authorized DSS information.

---

# LOOP ENGINEERING

EVERY FEATURE MUST FOLLOW:

INSPECT
↓
PLAN
↓
IMPLEMENT
↓
RUN
↓
TEST
↓
DIAGNOSE
↓
FIX
↓
RETEST
↓
REGRESSION CHECK
↓
VERIFY
↓
DOCUMENT

Never stop immediately after generating code.

---

# MANDATORY EXECUTION RULE

DO NOT STOP AFTER GENERATING CODE.

After modifying code:

1. Run required commands.
2. Run relevant tests.
3. Verify migrations.
4. Verify seeders when applicable.
5. Verify database structure.
6. Verify relationships.
7. Verify routes.
8. Verify authorization.
9. Verify validation.
10. Verify affected workflows.
11. Check runtime errors.
12. Check frontend/build errors when applicable.
13. Check regressions.

If an error occurs:

READ ERROR
↓
IDENTIFY ROOT CAUSE
↓
INSPECT RELATED CODE
↓
APPLY SAFE FIX
↓
RUN COMMAND AGAIN
↓
RUN TEST AGAIN
↓
CHECK REGRESSIONS
↓
VERIFY AGAIN

Do not merely report errors.

Fix them whenever possible.

---

# NO FALSE COMPLETION

Never claim:

"Done"

"Completed"

"Successfully implemented"

unless the implementation has actually been verified.

If something remains broken:

STATUS: INCOMPLETE

Explain the exact problem and continue fixing it if possible.

---

# TESTING

Create or update automated tests where appropriate.

Test:

- Grading calculations
- Assessment classification
- Assessment validation
- Assessment import
- Authorization
- DSS calculations
- Risk classification
- Term readiness
- Intervention workflow

Test both:

- successful cases
- failure/invalid cases

---

# REGRESSION

After every feature:

Check that existing:

- authentication
- roles
- dashboards
- students
- subjects
- sections
- academic terms
- grading
- reports
- existing DSS

still work.

Do not sacrifice existing functionality to implement a new
feature.

---

# DEFINITION OF DONE

A feature is complete only when:

[ ] Requirement implemented
[ ] Existing functionality preserved
[ ] Database verified
[ ] Migration verified
[ ] Seeders verified when applicable
[ ] Models verified
[ ] Relationships verified
[ ] Routes verified
[ ] Authorization verified
[ ] Validation verified
[ ] Tests pass
[ ] Frontend verified
[ ] Workflow verified
[ ] Error cases checked
[ ] Regression checked
[ ] No known blocking errors remain
[ ] Documentation updated

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

## The `do015_2026` transmutation table is not yet confirmed against the signed order

`transmutation_ranges` is now seeded in full for both schemes: `do8_2015`
(DO 8, s. 2015, Grade 12's scheme this school year) and `do015_2026` (DO
015, s. 2026's adjusted table for Grade 11 under the Strengthened SHS
curriculum, from `Do015TransmutationSeeder` — see
`TransmutationService::schemeFor()`). Both cover 0–100 with no gaps or
overlaps, and `php artisan dss:verify-transmutation` checks this on
demand.

The remaining limitation is provenance, not coverage: `Do015TransmutationSeeder`'s
41 bands were cross-checked across three independent secondary
reproductions that agree on every figure, but have **not** been read
from the signed PDF of DO 015, s. 2026 itself (see the `SOURCE NOTE` in
that seeder). Before citing this table in the thesis or any official
report, download the order from deped.gov.ph, confirm the bands against
it, and remove that note. Until then, treat `do015_2026` results as
computationally correct against the table this codebase has, not as
independently verified against the original order.

## The Examination role split (30/30/40) is provisional

Under DO 015, s. 2026 the Examination component splits between two
Summative Tests and a Term Examination (`exam_role_shares`, scheme
`do015_2026`, roles `st1`/`st2`/`term_exam`) — but the 30/30/40 split
itself is NOT confirmed anywhere in this codebase against the published
order, only entered as the best available estimate at the time this was
built. Because it's a seeded row rather than code, correcting it later
is three `UPDATE`s to `exam_role_shares`, never a deployment. Unlike
`transmutation_ranges`, this table being unseeded degrades gracefully
rather than blocking anything: `GradingEngine::examinationPercentage()`
falls back to an equal split among whichever roles are actually present
for a role missing from the table, so a wrong or absent share is never
fatal — only wrong, in a way a data correction fixes immediately.

## Elective selection is per-cluster, not per-learner

`Subject::forSection()` returns EVERY elective subject matching a
section's track and specialization — it has no way to return a
SUBSET. Under the Strengthened SHS curriculum a Grade 11 learner
picks two electives from a cluster, not the whole cluster (e.g. a
STEM section offering Pre-Calculus, General Biology 1, and Physics
might have some students taking Pre-Calc + Biology and others taking
Pre-Calc + Physics). There is no `section_subject` (or
`student_subject`) pivot table anywhere in the schema to record which
electives a given section — let alone a given student — actually
takes, so `forSection()` cannot distinguish "offered to this
track/specialization" from "actually taken."

**Why this is currently invisible:** only two STEM electives
(Pre-Calculus, General Biology 1) have ever been imported in this
codebase's fixtures/demo data. With exactly two electives in the
cluster, "every elective in the cluster" and "the two electives this
section takes" happen to be the same set by coincidence — there is
nothing to distinguish because there is no third option to leave out.

**What breaks when a third elective is added:** `Subject::forSection()`
will return all three, so `AcademicTerm::completionStatus()` (which
expects a grade for every subject `forSection()` returns, for every
student in the section) will require grades for all three electives
from every student — including the one they didn't take. No student
can ever supply that third grade, so `expected` permanently exceeds
what `actual` can reach and the term can never be marked complete.
The same over-counting would show up in `ReportController::submit()`'s
`totalExpected` check (blocking Submit Report the same way) and in
`getSectionSubjects()`'s duplicate copy of this same query.

**The fix (not built in this pass):** a `section_subject` pivot table
recording exactly which electives a given section has chosen for the
current school year, with `Subject::forSection()`,
`AcademicTerm::completionStatus()`, `ReportController::getSectionSubjects()`,
the grade-encoding screen, and the subjects import format all updated
together to read from it instead of "every matching elective." This
needs the user's decision on how a section's electives get assigned
(picked when the section is created? per-student? via a new admin
screen?) before it can be built, which is why it is deliberately out
of scope here — see
`tests/Feature/ElectiveClusterLimitationTest.php` (marked skipped)
for the assertion this will need to satisfy once the pivot exists.

## Attendance is not part of any grade

Attendance exists in this system only as an intervention type
(`attendance_monitoring`) that a Principal can record. It is never
collected as data, never a grading component, and never a classifier
feature. Under DO 8, s. 2015 and DO 015, s. 2026 the grade is computed
from Written Work, Performance Task, and Examination only.
Attendance-based promotion and retention rules are outside this
system's scope.

## "Failing" catches Grade 11 and Grade 12 at different levels of mastery

The Failing signal is defined on the reported (transmuted) grade at 74 and
below, which is DepEd's failing mark. Because the two curricula transmute
differently, the same threshold corresponds to a different raw score in each
grade level during SY 2026-2027:

- **Grade 11 (DO 015, s. 2026):** an Initial Grade of 70.00 transmutes to 75,
  so Failing corresponds to a computed grade below 70.00.
- **Grade 12 (DO 8, s. 2015):** an Initial Grade of 60.00 transmutes to 75,
  so Failing corresponds to a computed grade below 60.00.

A Grade 12 learner with 62% raw mastery is therefore reported as passing and
is never Failing, while a Grade 11 learner with the same raw mastery is.
This is a property of the DepEd transmutation tables themselves, not of this
system, and it disappears from SY 2027-2028 when transmutation is removed.

## The risk classifier's levels are calibrated thresholds, not a learned signal

Because the model is trained on a single feature, its risk levels are
effectively thresholds on average grade. Calibration determines the
boundaries between low, moderate, and high, and different cohorts may
require different boundaries. The system does not learn these boundaries
from outcomes.

As of the "correctness and interface pass," the boundaries are Low
85-100, Moderate 75-84.9, High 0-74.9 (`analytics/classify.py`'s
`train_model()`) — recalibrated from the original 90/75/60 split, which put
nearly an entire passing cohort in one 15-point Moderate band (observed
live: 39 of 40 learners Moderate, 1 Low, 0 High). The new boundaries are
anchored to figures already used elsewhere in this codebase
(`PerformanceAnalysisService::DEFAULT_TARGET` = 75,
`InTermStatusService::FAILING_THRESHOLD` = 74) rather than percentiles of
any one section's snapshot, so they generalize instead of being tuned to
fit today's roster. `analytics/test_classify.py`'s
`TestRiskLevelDistribution` pins that a cohort with a genuine spread of
averages no longer lands almost entirely in one level.

**This asymmetry is why the component-based In-Term Status must not be
replaced by the Failing rule.** In-Term Status is computed against a flat 75%
target per component regardless of grade level and curriculum, so the Grade 12
learner above still surfaces as At Risk or Needs Attention from the evidence,
even though their reported grade is passing. Removing the component rule in
favour of a grade threshold would leave Grade 12 learners with materially
weaker early detection than Grade 11 learners in the same school year.

## Interventions have an origin

Every intervention record is created by a Principal through the interface; the
`origin` column records this as `principal`. The value `system` exists for a
future source that generates intervention records from risk analysis, and
nothing sets it today. The interface must not claim the DSS decided something
a person decided. The DSS produces the recommendation text, the focus area,
and the risk classification that inform the Principal's judgment. It does not
create, approve, or close intervention records.

---

# UI DESIGN SYSTEM

## Status colour is reserved for the four DSS states

`tailwind.config.js` defines a `status` colour scale — `status-ontrack`,
`status-attention`, `status-risk`, `status-failing` — used ONLY for On Track,
Needs Attention, At Risk, and Failing (and Risk Level's Low/Moderate/High,
which is the same green/amber/red gradient by design). Every genuine
DSS-state badge, stacked-bar segment, and card border across all three
roles reads from these four tokens instead of a literal `green-500` /
`yellow-400` / `red-600`, so "what colour is At Risk" is answered in one
place.

Do NOT reach for `status-*` outside these four states. Validation errors,
delete/danger buttons, workflow-stage badges (recommended/approved/
delivered), account active/inactive tags, trend arrows (improving/
declining), and data-quality caveats (Provisional/Not available) are
deliberately literal Tailwind classes — they are not one of the four DSS
states, and using a status token for them would blur the one thing this
token set exists to keep unambiguous. When in doubt, ask whether the
colour is describing a *student's or subject's* On Track/Needs Attention/
At Risk/Failing status — if not, it isn't a `status-*` case.

Card *identity* (which master-data type a card is — Users, Students,
Sections, and so on, on the Admin dashboard) uses its own literal colour
per card and must never resolve to one of the four status values; identity
and status are different questions and must stay visually distinguishable.

## One table component for every table in the app

`resources/css/app.css` defines `.tbl` / `.tbl-wrap` / `.tbl-scroll` /
`.tbl-sticky` / `.tbl-num` in a `@layer components` block. Every real data
table in the app (as of the "UI work order" pass) uses this set instead of
its own `thead`/`th`/`td` class string:

- `.tbl-wrap` — the table's own visual card (`bg-white rounded-lg shadow-sm
  overflow-hidden`), for a table with no other ancestor already providing
  that chrome.
- `.tbl-scroll` — `max-h-[32rem] overflow-y-auto`, the shared scroll
  container height. Put it on whichever div directly wraps the table.
- `.tbl` — on the `<table>` itself: base typography and the shared
  `thead`/`tbody tr`/`tbody td` styling.
- `.tbl-sticky` — add alongside `.tbl` on the `<table>` only when the
  header should pin on scroll (matches the file's PRE-EXISTING behaviour;
  don't add sticky where a table never had it).
- `.tbl-num` — on both the `th` and `td` of a numeric column (grades,
  percentages, counts, scores) so digits align down the column.

Plain `<th>`/`<td>` need no class at all — `.tbl` sets `text-align: left`
at the table level, so left alignment is the default. Only alignment
(`text-right`, `text-center`, or `tbl-num` for numeric), a genuine
emphasis (`font-medium`, `text-gray-500` for a de-emphasised field), or a
content-specific detail (a fixed `w-*`, `whitespace-nowrap`, a status
colour) should ever appear on a `th`/`td` beyond that.

**Three tables are deliberately NOT on `.tbl`**: the Adviser Interventions
page's two "awaiting decision" callout tables and the assessment
edit-summary table are themed (amber/blue) mini-tables embedded inside a
matching coloured notification banner, not ordinary data tables — forcing
them onto `.tbl`'s neutral grey chrome would fight the banner they live
inside rather than read as one thing with it.

Adding a table without this component set is how the app previously ended
up with eight different `thead` variants that drifted apart from each
other one Blade file at a time — reach for `.tbl` first.