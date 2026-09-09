# SHS DECISION SUPPORT SYSTEM
# MASTER ENGINEERING INSTRUCTIONS

## PROJECT ROLE

This is an EXISTING Senior High School Decision Support
System built with Laravel.

Do not rebuild the application from scratch.

The system must be developed incrementally while preserving
existing functionality and existing data.

## Work orders

Task-level instructions live in separate files in the project root, not here.
This file holds architecture, conventions, and standing decisions — things that
stay true after the work is done.

- `ECR_ALIGNMENT_WORK_ORDER.md` — current, nine parts with stop points
- `WORK_ORDER.md` — the UI and decision-flow pass, mostly landed
- `MASTER_PROMPT.md` — earlier, partly superseded; where it conflicts with
  `WORK_ORDER.md`, the later one won and the code follows it
- `HANDOFF.md` — design decisions that may not change without asking

When a work order and this file disagree, the code is the answer and the
disagreement is worth reporting. Documents in this project have gone stale
before.

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

# STRENGTHENED SHS E-CLASS RECORD

The official DepEd instrument for SY 2026-2027, `ECRSHS2026`, version
`2026_v1.0`. Everything below was read from the workbook itself, not from
documentation about it.

## Seven sheets

`INSTRUCTIONS`, `INPUT DATA`, `Term 1`, `Term 2`, `Term 3`, `FINAL GRADES`,
and `HELPER` (hidden). One subject per workbook, three terms inside it.

The active sheet on open is `INSTRUCTIONS`, so the legacy reader's
`getActiveSheet()` call lands on the instructions page. The ECR path must
select sheets by name.

## The subject catalog — HELPER!J7:AC161

141 subjects: TRACK, CLUSTER, COURSE TITLE, TOTAL HOURS, GRADE LVL, terms and
units per grade level, then WW, PT, ST-TE, ST 1, ST 2, TE. The term sheets read
their weights from here by matching cluster and course title. This is a live
source inside the workbook, not reference material.

| Track | Cluster | WW/PT/EX | n |
|---|---|---|---|
| CORE | Core | 20/50/30 | 6 |
| ACADEMIC | Arts, Social Sciences, and Humanities | 20/60/20 | 24 |
| ACADEMIC | Arts, Social Sciences, and Humanities | 15/70/15 | 1 |
| ACADEMIC | Business and Entrepreneurship | 20/50/30 | 6 |
| ACADEMIC | STEM | 20/50/30 | 26 |
| ACADEMIC | Sports, Health, and Wellness | 20/60/20 | 10 |
| ACADEMIC | Field Experience | 40/60/—, 15/70/15, 20/80/— | 13 |
| TECH-PRO | nine clusters | 15/65/20 | 48 |
| TECH-PRO | Work Immersion | 20/80/— | 5 |

Plus one `OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM` row per track, whose
weights come from `INPUT DATA!F43:F48` — the only place in the workbook where a
teacher types the weights.

The client school is Academic Track only, so 87 of the 141 rows apply. Only the
TechPro 15/65/20 weighting drops out. Five weightings remain, and all nine
Term-Exam-only subjects are Academic.

## Term sheet geometry — identical on all three term sheets

| Row | Contents |
|---|---|
| 11 | Band headers: Written/Oral Works, Product/Performance Tasks, Examinations |
| 12 | Component weight as a fraction, pulled from HELPER |
| 13 | Item numbers 1-10, then TOTAL, PS, WS; then ST 1, ST 2, TE |
| 14 | HIGHEST POSSIBLE SCORE |
| 17-66 | Male learners |
| 68-117 | Female learners |

Columns: WW `D:M`, TOTAL `N`, PS `O`, WS `P`. PT `Q:Z`, TOTAL `AA`, PS `AB`,
WS `AC`. EX `AD` ST1, `AE` ST2, `AF` TE, weighted `AG:AI`, PS `AJ`, WS `AK`.
`AL` INITIAL GRADE, `AM` TERM GRADE.

Ceiling: 10 WW + 10 PT + 3 EX = 23 items per subject per term.

Learner names and LRNs live only on `INPUT DATA` — male in `N`/`O` rows 11-60,
female in `R`/`S`. Column `C` on a term sheet is a formula pointing there.

## What is never imported

`N`, `O`, `P`, `AA`, `AB`, `AC`, `AG`-`AK`, `AL`, `AM`. These are computed.
Raw scores only.

Two reasons, and both matter. In-Term Status is based on the computed grade,
not the transmuted one, so taking `AM` would discard the early warning
entirely. And the DSS analyses components, not final grades — "two components
below the 75 target" cannot be recovered from a single figure of 76.00.
Importing derived columns would also create assessment items out of totals and
double-count the evidence.

## The rule on conflicting evidence

Three sources of weight will disagree:

1. DO 015 Table 10 — six subject groups. The order itself.
2. The ECR catalog — 141 subjects, per-subject weights. DepEd's own instrument.
3. The uploaded file's weight cells — what a teacher typed.

Resolution order: catalog row, then subject group, then the scheme default.

The third never wins. That spreadsheet is filled in by hand, and a mistyped
cell must not be able to change how a grade is computed. A mismatch is a
warning on the Verify screen naming both figures, never an overwrite. The one
exception is `OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM`, where the order
publishes no weight at all — and the Verify screen must say so explicitly
rather than treating it like any other subject.

Where the catalog and Table 10 disagree, report it. Do not reconcile it
silently. Two authorities disagreeing is a finding.

## The client's real structure

Grade 11 runs the Strengthened SHS curriculum under DO 015: sections
**Shakespeare** and **Curie**, 42 learners each, no specialization, because
SSHS has no strands.

Grade 12 remains on the 2013 curriculum under DO 8: **ABM** 22, **HUMSS** 39,
**STEM** 20. Academic Track only — no TVL, Sports, or Arts and Design, so
three of DO 8's five weighting columns apply.

165 learners, one school, two curricula, two grading orders, running at the
same time. The pilot section Molave is not one of these and carries
`specialization = ABM`, a value that cannot correctly exist on an SSHS section.

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

## Forbidden routes to green

The loop exists to make the system right, not to make the signal green. When
stuck, the following are forbidden, and taking any of them is worse than
reporting failure:

- Deleting, skipping, or commenting out a failing test
- Weakening an assertion so it passes
- Mocking or stubbing over a real failure
- Catching an exception to silence it
- Editing a migration that has already run — write a new one
- Deleting rows so a count check passes
- Guessing an answer that a human was asked for

If the same error survives three attempts, stop and report what you tried,
what the error says, and what you think it means. Three failed attempts is
information. A twelfth is not.

If a fix would require changing something these instructions forbid, stop and
ask. Do not route around the constraint.

The suite baseline is 722 passing, 1 skipped — 723 total. The skip is
`ElectiveClusterLimitationTest` and it is deliberate. A run that is still at
722 because two failing tests were removed and two trivial ones added has made
the project worse while making it look better.

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

## The do015_2026 transmutation table is confirmed against DepEd's own instrument

The 41 bands seeded by `Do015TransmutationSeeder` were checked band by band
against `HELPER!B7:D47` of the official DepEd Strengthened SHS Electronic Class
Record for SY 2026-2027 (`ECRSHS2026`, `2026_v1.0`). All 41 match exactly: the
same minimum, the same maximum, the same transmuted grade.

This is DepEd's own operational instrument, not a secondary reproduction. It is
still not the signed PDF of the order, and that distinction should be stated
plainly in the paper rather than dropped.

`Do015BandsMatchOfficialEcrTest` holds the extracted bands as a fixture and
asserts them against the seeder, so a future edit to the table cannot pass
silently.

## The Examination role split is per subject, not universal

ST1 30 / ST2 30 / TE 40 is the common case, not the rule. The official SSHS
E-Class Record catalog assigns the split per subject, and nine subjects — all
of them in the Academic Track — carry Term Exam at 100 with no summative tests
at all: six in Field Experience, two in STEM, one Work Immersion.

A subject whose catalog row has a null `st1_share` must not expect ST1 or ST2
evidence. This is the same distinction a null `ex_weight` already makes, one
level further down: null means the item does not exist for this subject, never
that it is worth zero.

Because these are seeded rows rather than code, correcting a share is an
`UPDATE`, never a deployment. `GradingEngine::examinationPercentage()` still
falls back to an equal split among whichever roles are present, so a missing
share is wrong rather than fatal.

This amends `HANDOFF.md` design decision 5, which stated the split as universal.

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

## Recommendations for future work

Three items from the "COMPLETE WORK ORDER" (Parts 7-9) were deliberately deferred
for the defence — the pilot (one adviser, one section, two electives) never
exposes any of them, and none touches grading, risk, or authorization logic, so
none blocks demonstrating the system as it stands. If the school decides to run
this beyond the pilot, they become the next engineering pass, in this order:

1. **The elective pivot** (`section_subject` table) — see "Elective selection is
   per-cluster, not per-learner" above. Needs the school's answer to "does every
   learner in a section take the same subjects?" before any code is written; the
   answer changes whether the pivot is per-section or per-student.
2. **Transmutation table verification** — see "The `do015_2026` transmutation
   table is not yet confirmed against the signed order" above. Research, not
   code: download DO 015, s. 2026 from deped.gov.ph and check all 41 bands.
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

## COMPLETE WORK ORDER, PART 4 — count colour, shared components, scale

### Warm colour is status, cool colour is a count

`tailwind.config.js` also defines a `count` colour scale —
`count-1` through `count-8`, all cool hues (blue/indigo/violet/purple/
cyan/teal/slate) — deliberately disjoint from the warm `status.*` family
above. Warm colour means DSS status and nothing else. Cool colour means a
count. Grey means structure. A plain number never takes a status colour,
and a status is never shown by colour alone — it always carries its word.

`<x-stat-card accent="count-N">` (Admin's eight master-data cards) and
`<x-stat-card accent="status-*">` (a genuine status figure) are the two
places `count.*`/`status.*` reach a border colour. Because the accent
class is built at runtime (`'border-'.$accent`), Tailwind's static
scanner cannot see it — `tailwind.config.js`'s `safelist` pattern
(`border-(count|status)-(1..8|ontrack|attention|risk|failing)`) exists
for exactly this, and is the first thing to check if a card border ever
renders grey instead of its accent after a build.

**The Part 4a colour audit and what it deliberately left alone.** A pass
over every `red-`/`amber-`/`yellow-`/`orange-`/`rose-` in
`resources/views` confirmed the exemption list above (validation errors,
danger buttons, workflow-stage badges, account tags, trend arrows,
data-quality caveats) is still the right call — recolouring a delete
button or an error banner to a cool or grey tone would read as less
urgent than it is, for no benefit. Two genuine misses were fixed: the
failing-subject list and the weakest-component gap indicator in
`principal/partials/at-risk-results.blade.php` were raw `red-600` /
`red-500`/`green-600` even though they render real DSS signals (a
subject-level Failing outcome, and an In-Term Status gap) — now
`status-failing` and `status-attention`/`status-ontrack` respectively.

### Two shared component shells

`resources/views/components/stat-card.blade.php` and `panel.blade.php`
are the two building blocks every dashboard card and section reads from
going forward, instead of each page hand-rolling its own `bg-white
rounded-lg shadow-sm ...` string:

- `<x-stat-card :label :value :accent :icon :href :note>` — one number
  with a label, an accent-coloured top border, and an optional icon and
  link. `accent` is `count-1`..`count-8` for a neutral figure, or
  `status-ontrack`/`attention`/`risk`/`failing` for a genuine status
  figure.
- `<x-panel :title :subtitle :action>` — the card shell for anything that
  is not a single stat: a table, a list, a chart. `$slot` is the body;
  `title`/`subtitle` render a header only when given.

### Type and spacing scale

Four sizes, and a page that invents a fifth is wrong: page title
(`text-xl font-bold text-gray-800`), section heading (`text-sm
font-semibold text-gray-800`), card label/table header (`text-xs
text-gray-500`), card number (`text-2xl font-bold text-gray-800`).
Spacing: `gap-3` between cards, `mb-4` between sections, `p-4` inside
cards.