# Original master instructions, requirement sections and test-baseline history

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 1-106 1014-1641; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

---

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
- `analytics/` (the ML prototype and its README) — REMOVED 2026-09-27; the
  DSS runs no Python. It remains in Git history only.
- `ML_ARCHITECTURE.md`, `TRAINING_DATA_CONTRACT.md` — RETIRED, historical
  record of the removed prototype; not a description of the running system

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

The suite baseline is 1,196 passing, 0 skipped (as of the "Class Record
UI refinement" pass, 2026-09-28: 1,187 + 9 `ClassRecordUiTest`; run as
`tests/Unit` (101) + four `--testsuite Feature --filter 'Feature.[A-D]'`
/ `[E-L]` / `[M-R]` / `[S-Z]` chunks = 393 / 209 / 234 / 259 — Auth falls
in A-D; under Git Bash use `MSYS_NO_PATHCONV=1` and `.` not `\\` in the
filter), plus 15 JS tests and 98 isolated browser checks. Before that it
was 1,187 (the "Class Record
consolidation" pass, 2026-09-28: 1,180 + 7 `ClassRecordConsolidationTest`;
chunks 475 / 209 / 244 / 259), plus 15 JS tests and 78 isolated browser
checks. Before that it was 1,180 (the "Class Record
refinements" pass, 2026-09-28: 1,260 − 96 tests of the RETIRED teacher
assessment-file upload / score-carrying item endpoints + 16 new —
`RetiredAssessmentUploadTest` 5, `TeachingAssignmentEditTest` 6,
`ClassRecordLayoutTest` 5; chunks 468 / 209 / 244 / 259), plus 15 JS tests
(`node --test tests/js/confirm.test.mjs tests/js/class-record.test.mjs
tests/js/class-record-drafts.test.mjs`) and 56 browser checks
(`tests/Browser/run.mjs`, isolated server only). Before that it was 1,260
(1,241 + 19 `SubjectTeacherClassRecordTest`; chunks 509 / 225 / 270 / 256),
plus 10 JS tests. Before that the
suite baseline was 1,241 passing, 0 skipped, with NO Python needed (as
of the final release pass, 2026-09-27: 1,232 + 6 `SeederCredentialsTest` + 2
`ProgressMonitoringWithinTermTest` + 1 `FinalAuditScenariosTest`; run after
every fix as FOUR foreground chunks — `tests/Unit` + `Feature/[A-D]*` (509),
`Feature/[E-L]*` (225), `Feature/Auth` + `Feature/[M-R]*` (270),
`Feature/[S-Z]*` (237) — 9,036 assertions; a background run was reaped for
memory. 1,232 was the final pre-deployment audit, 2026-09-27: 1,225 + 7
`FinalAuditScenariosTest`; chunk 1 re-verified 731/731 after its fixes,
chunk 2 last completed 501/501 just before them; 1,225 was the Adviser
reporting UX pass, 2026-09-27: 1,219 + 6
`AdviserReportingUxTest`; the removed adviser edit route's tests were
rewritten to assert the refusal, not deleted; 1,219 was the demo-readiness
pass: 1,214 + 5 `AdviserSubjectFirstVisibilityTest`; 1,214 was the subject-first pass, 2026-09-27: 1,232 − 22 `AttendanceConcernTest`
and − 13 `OverallMonitoringClassificationTest`, both removed with the
retired attendance feature they tested, + 13 `SubjectFirstMonitoringTest`
+ 4 `AttendanceRetirementTest`; `tests/js/attendance-school-days.test.cjs`
was removed with it — the remaining JS test is `node --test
tests/js/confirm.test.mjs` (5). 1,232 was the Examination Type pass: 1,218 + 14 `ExaminationTypeTest`;
1,218 was the overall-monitoring pass: 1,205 + 13
`OverallMonitoringClassificationTest`; 1,205 was the system-alignment pass: 1,193 + 4 `AttendanceConcernTest`
(now 22) + 7 `AssessmentImportAtomicityTest` + 1 from splitting the Grade 12
over-HPS test in two; 1,193 was 1,160 + 14 `DssAssessmentRecordTest` + 18
`AttendanceConcernTest` + 1 `NoOperationalMlDependencyTest` — see
"Rule-based academic monitoring" at the end of this file). Separately,
`node --test tests/js/attendance-school-days.test.cjs` (9, not part of
`php artisan test`). 1,191 at Phase 2D → 1,224 (Phase 2E
review + intervention link, +22 `PrincipalConcernReviewAndInterventionTest`
and readiness guards) → 1,233 (2E delivery workflow, +9
`AdviserDeliveryWorkflowTest`) → 1,243 (No Evidence Yet +6, descriptive
In-Term labels +4) → 1,160 (ML cut-over: the classifier's own tests were
removed with the code they tested — 79 in 14 deleted files and 14 in 8
edited files — replaced by `NoOperationalMlDependencyTest` +4,
`InTermStatusHelpTest` 3-for-3, +2 `AdviserCannotAddOrImportStudentsTest`,
+1 delivery-state test). Earlier chain: 1,093 at
`39d9afe` → 1,124 (Phase 2A, +31 `AssessmentLearnerStatusTest`) → 1,146
(Phase 2B, +21 `AcademicConcernTest`, +1 `MigrationTimestampPortabilityTest`)
→ 1,169 (Phase 2C, +20 `AcademicMonitoringTriggerTest`, +3 in
`EditFormActionsAreRouteDerivedTest`) → 1,191 (Phase 2D, +22
`PrincipalAcademicMonitoringTest`). On this machine the full suite is run
as two sequential foreground chunks (`tests/Unit` + `tests/Feature/[A-L]*`,
then `tests/Feature/Auth` + `tests/Feature/[M-Z]*`, via
`vendor/bin/phpunit -c phpunit.xml`, `MSYS_NO_PATHCONV=1` under Git Bash)
because a single ~9-minute background run was reaped under memory
pressure; check `--list-tests` counts add up to the whole list first.
Before that: 1,090 (as of the "Railway readiness and test-environment isolation" pass,
2026-09-22 — 1,084 before it, plus the 6 tests in `TrustedProxyConfigTest`).
Before that: 1,084 (as of the "Final pre-demo full-system audit", 2026-09-21 evening —
1,081 before it, plus the 3 tests in `EnvPutenvDisabledTest`). Before
that: 1,081 (as of "Pre-demo hardening, Phase 2", 2026-09-21 — 1,071
before it, plus the 10 tests in `RepeatUploadMetadataConflictTest`). Before that: 1,071
(as of the "Final pre-demo audit" evening pass, 2026-09-20 — 1,063 before
it: +2 `ProfileTest`, +2 `AssessmentPerformancePageTest`, +2
`XssEscapingAcrossRolePagesTest` (new file), +1 `PreDemoAuditRegressionsTest`,
+1 `AdminDashboardScopingTest`). Before that: 1,063
(as of the "SSHS ECR grading correction" pass, 2026-09-20 — 1,064 before
it; `GradingPolicyResolutionTest` rewritten from 13 to 11 tests with the
workbook as oracle, `TransmutationServiceTest` +1). Before that: 1,064
(as of the "Grading policy display" pass, 2026-09-20 — 1,051 before it,
plus the 13 tests in `GradingPolicyResolutionTest`). Before that: 1,051
(as of the "Pre-demo full-system audit", 2026-09-20 — 1,046 before it:
+8 in `PreDemoAuditRegressionsTest`, +2 in `RoleAccessMatrixTest`, and 5
removed with the dead Breeze routes they tested — `Auth/
PasswordConfirmationTest` (3) and `Auth/PasswordUpdateTest` (2); see
"Pre-demo full-system audit" below). Before that: 1,046 (as of the
"Subject Group for both grade levels" pass, 2026-09-20 — 1,042
before it: 3 rewritten in `SubjectClassificationConsistencyTest`, 1 added
in `AdminDataHealthChecksTest`, and `SubjectFormConsistencyTest` went from
15 to 18). Before that: 1,042 (as of the "Subject form consistency" pass,
2026-09-20 — 1,027 before it, plus the 15 tests in
`SubjectFormConsistencyTest`). Before that: 1,027
(as of the "Subject applicability" refactor, 2026-09-20 — 1,097 before
it; 27 new tests in `SubjectApplicabilityTest`; 5 sync tests replaced by
4 in `PrescribedEcrMetadataValidationTest`; 3 rewritten in
`SectionElectiveStatusTest`; 4 route tests in `MimesFixSixMoreRoutesTest`
and 1 in `SubjectClassificationSafetyNetTest` folded into route-gone
assertions; and 92 removed with the four master-data importers and the
term-managed offerings workflow they tested: `SubjectsImportTest` (21),
`SubjectsImportDefaultGroupNoticeTest` (3), `TracksImportTest` (16),
`SpecializationsImportTest` (13), `SectionsImportTest` (13),
`TermSpecificSubjectOfferingsTest` (26). Removing a feature's tests with
the feature is the one legitimate way the count goes down; the
replacement coverage is named above). The Python suites (ARCHIVED with the
retired prototype under `analytics/`, not part of the running system) are
counted separately and run on their own:
`python analytics/test_classify.py` (6), `test_training_pipeline.py` (62),
and `test_inference_contract.py` (34) — 102 total, all passing, none wired
into `php artisan test`.

There are no conditional skips any more: `MlArchitectureBoundaryTest`, the
one file that shelled out to Python, was removed with the classifier.

Before that: 1,051 (as of the "Student identity
and term-specific subject offerings" pass, 2026-09-18 — 1,021 before it; one
future-birthdate test replaced by a legacy-column-ignored test, one
SectionElectiveStatus scenario folded into the per-term ones, plus 31 new
tests in TermSpecificSubjectOfferingsTest and StudentWithoutBirthdateTest). Before that: 1,021 (pre-demo audit,
2026-09-17 — 994 before it, plus 27 new tests; see `PRE_DEMO_FINAL_AUDIT.md`
for the exact list. The earlier `ElectiveClusterLimitationTest` skip was
un-skipped in ECR alignment PART 6). A run that is still at the baseline
because two failing tests were removed and two trivial ones added has made
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


