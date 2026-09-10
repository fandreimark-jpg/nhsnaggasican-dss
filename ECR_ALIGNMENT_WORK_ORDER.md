# NAGGASICAN NHS DSS — ECR ALIGNMENT WORK ORDER

Save this in the project root beside `CLAUDE.md`, `MASTER_PROMPT.md`, and
`WORK_ORDER.md`.

This work order exists because two things arrived that were not available when
the earlier ones were written:

1. The official **DepEd Strengthened SHS Electronic Class Record**,
   `SSHS-E-Class-Record-SY-2026-2027.xlsx`, tagged `ECRSHS2026` / `2026_v1.0`.
2. The client school's **actual roster shape** — Grade 11 sections Shakespeare
   and Curie (42 each, no specialization), Grade 12 specializations ABM (22),
   HUMSS (39), STEM (20), Academic Track only.

Neither invalidates `MASTER_PROMPT.md` or `WORK_ORDER.md`. Both add work in
front of them.

---

## THE CENTRAL DECISION — read this before anything else

**The system's internal format does not change to match the E-Class Record.**

The ECR is a teacher's instrument: one subject per workbook, one term per
sheet, learners split into MALE and FEMALE blocks, names carried by formula
from a separate input sheet. The system is a school-wide record: many subjects,
many sections, three terms, one roster. These are different shapes because they
serve different jobs, and the ECR is explicitly versioned (`2026_v1.0`), so a
schema built to mirror it would need a migration every time DepEd revises it.

Instead: **an ECR reader profile translates the file into the shape
`AssessmentUploadService::detectColumns()` already returns.** The existing flat
`lrn / last_name / first_name / item…` path stays untouched and keeps working.
Detect → Verify → Preview → Validate → Import stays intact. No existing test
breaks.

What *does* change is the **data model** — and those changes are driven by the
DepEd curriculum, not by the file's layout. They would be needed even if no
spreadsheet had ever been uploaded. Keep the two apart when reporting progress:

| Driven by the file's layout | Driven by the curriculum |
|---|---|
| Reader profile / adapter | Per-subject weights |
| Sheet and header geometry | Per-subject examination role split |
| Roster reconciliation | Curriculum taxonomy split |
| Weight cross-check | Elective assignment |

---

## EVIDENCE FROM THE FILE

Verified by reading the workbook directly, not from documentation.

**Seven sheets:** `INSTRUCTIONS`, `INPUT DATA`, `Term 1`, `Term 2`, `Term 3`,
`FINAL GRADES`, `HELPER` (hidden).

### The subject catalog — `HELPER!J7:AC161`

141 subjects. Columns: TRACK, CLUSTER, COURSE TITLE, TOTAL HOURS, GRADE LVL,
terms and units per grade level, then `WW`, `PT`, `ST - TE`, `ST 1`, `ST 2`,
`TE`. The term sheets read their weights from here by matching cluster and
course title — this is the live source, not reference material.

| Track | Cluster | WW/PT/EX | ST1/ST2/TE | n |
|---|---|---|---|---|
| CORE | Core | 20/50/30 | 30/30/40 | 6 |
| ACADEMIC | Arts, Social Sciences, and Humanities | 20/60/20 | 30/30/40 | 24 |
| ACADEMIC | Arts, Social Sciences, and Humanities | 15/70/15 | 30/30/40 | 1 |
| ACADEMIC | Business and Entrepreneurship | 20/50/30 | 30/30/40 | 6 |
| ACADEMIC | STEM | 20/50/30 | 30/30/40 | 24 |
| ACADEMIC | STEM | 20/50/30 | **TE 100** | 2 |
| ACADEMIC | Sports, Health, and Wellness | 20/60/20 | 30/30/40 | 10 |
| ACADEMIC | Field Experience | 40/60/— | none | 3 |
| ACADEMIC | Field Experience | 15/70/15 | 30/30/40 | 3 |
| ACADEMIC | Field Experience | 15/70/15 | **TE 100** | 6 |
| ACADEMIC | Field Experience | 20/80/— | **TE 100** | 1 |
| TECH-PRO | 9 clusters | 15/65/20 | 30/30/40 | 48 |
| TECH-PRO | Work Immersion | 20/80/— | none | 5 |

Plus one `OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM` row per track, where the
weights come from `INPUT DATA!F43:F48` — the only case in the whole workbook
where a teacher types the weights.

**Client scope:** Academic Track only, so 87 of the 141 rows apply. The
`techpro` 15/65/20 weighting drops out entirely. Five weightings remain, and
**all nine Term-Exam-only subjects are Academic** — this is not a TechPro-only
concern that can be deferred.

### The transmutation table — `HELPER!B7:D47`

41 bands. Compared band by band against `Do015TransmutationSeeder::BANDS`:
**41 of 41 identical, zero mismatches.**

### Term sheet geometry — identical on `Term 1`, `Term 2`, `Term 3`

| Row | Contents |
|---|---|
| 2–3 | Title |
| 6–7 | Region, Division, School ID, School Name, School Year (formulas) |
| 8 | Wrong-sheet guard |
| 9–10 | Grade level, section, subject, teacher, cluster, units |
| 11 | Band headers: `WRITTEN / ORAL WORKS (WWs)`, `PRODUCT / PERFORMANCE TASKS (PTs)`, `EXAMINATIONS (EXs)`, `INITIAL GRADE`, `TERM GRADE` |
| 12 | Component weight as a fraction, pulled from HELPER |
| 13 | Item numbers `1`–`10`, then `TOTAL`, `PS`, `WS`; then `ST 1`, `ST 2`, `TE` |
| 14 | `HIGHEST POSSIBLE SCORE` |
| 15–16 | `LEARNERS' NAMES`, `MALE` |
| 17–66 | Male learners (50 slots) |
| 67 | `FEMALE` |
| 68–117 | Female learners (50 slots) |

Column map: WW `D:M` (10 items), `N` TOTAL, `O` PS, `P` WS. PT `Q:Z` (10
items), `AA` TOTAL, `AB` PS, `AC` WS. EX `AD` ST1, `AE` ST2, `AF` TE, `AG:AI`
weighted scores, `AJ` PS, `AK` WS. `AL` INITIAL GRADE, `AM` TERM GRADE
(transmuted via XLOOKUP into the HELPER band table).

**Maximum 23 assessment items per subject per term: 10 WW + 10 PT + 3 EX.**

Column `C` on a term sheet is a formula. Learner names and LRNs live only on
`INPUT DATA` — male in `N`/`O` (rows 11–60), female in `R`/`S`.

---

## TWO QUESTIONS THAT BLOCK PART 3 AND PART 6

Do not guess either. Ask the school.

**Q1. Do all learners in a Grade 11 section take the same electives, or does
each learner choose?**
Same → `section_subject` pivot, moderate work. Per-learner →
`student_subject`, and it touches encoding, term readiness, Submit Report, and
risk analysis together.

**Q2. How many Grade 12 sections are there?**
The counts given (22 / 39 / 20) are per specialization, not per section.
Sections, advisers, and Submit Report are all per section.

Parts 0, 1, 2, 4, and 5 can proceed without these answers.

---

## HARD CONSTRAINTS

1. `migrate:fresh`, `db:wipe`, and `DROP DATABASE` are forbidden. Back up
   before every migration.
2. The existing flat upload format keeps working, unchanged, with no
   regression. Backward compatibility is not negotiable.
3. Nothing in the E-Class Record overrides a seeded weight. The file is
   evidence to check against, never the authority — a teacher fills it in by
   hand, and a mistyped cell must not be able to change how a grade is
   computed. The single exception is `OTHER ELECTIVE / SPECIAL CURRICULAR
   PROGRAM`, where the order itself has no weight to publish.
4. Part 2 changes grading arithmetic. That is a deliberate exception to
   `MASTER_PROMPT.md` Hard Constraint 1 and must be recorded as one. Every
   other part leaves arithmetic alone.
5. Do not touch In-Term Status rules, risk classification, intervention
   workflow, or authorization anywhere in this work order.
6. Run the full suite after every part. Baseline is **723 passing**.

---

# PART 0 — Baseline and backup

```
mysqldump -u root naggasican_dss > backup_$(date +%Y%m%d_%H%M)_pre_ecr.sql
php artisan test
php artisan migrate:status
```

Record the current Molave completion counts for all three terms and the
Adviser and Principal dashboard figures. Anything that moves later is a
regression, not a redesign.

**STOP POINT 0.** Report the test count and the recorded figures.

---

# PART 1 — Close the transmutation limitation

No code changes. The seeded table is already correct.

**1a.** Update the `SOURCE NOTE` in `Do015TransmutationSeeder`: the 41 bands
are confirmed against the official DepEd SSHS Electronic Class Record for
SY 2026-2027, `HELPER!B7:D47`, 41 of 41 exact. Keep the standing caveat that
this is DepEd's own instrument rather than the signed PDF of the order.

**1b.** Rewrite the `CLAUDE.md` limitation "The `do015_2026` transmutation
table is not yet confirmed against the signed order" to reflect the new
evidence. It moves from open limitation to verified.

**1c.** Add the matching edit to `PAPER_REVISION_WORK_ORDER.md`. A capstone
that can name its verification source is stronger than one that cannot.

**1d.** Add `Do015BandsMatchOfficialEcrTest` — a fixture of the 41 bands
extracted from the workbook, asserted against the seeder.

**STOP POINT 1.**

---

# PART 2 — Per-subject weights and examination roles

This is the part that changes grading arithmetic. Back up first.

## 2a — The catalog table

New migration creating `deped_subject_catalog`:

| Column | Notes |
|---|---|
| `scheme` | `do015_2026` — leaves room for a future order |
| `track` | `SSHS - CORE`, `SSHS - ACADEMIC`, `SSHS - TECH-PRO` |
| `cluster` | as printed in the ECR |
| `course_title` | as printed — this is the match key |
| `total_hours`, `grade_levels`, `terms`, `units_per_term` | from the catalog |
| `ww_weight`, `pt_weight`, `ex_weight` | `ex_weight` nullable — null means the subject has no Examinations component at all, never zero |
| `st1_share`, `st2_share`, `te_share` | nullable, same reasoning |

Unique on `(scheme, course_title, track)`.

Seed all 141 rows in the migration itself, following the precedent set by
`subject_group_weights`' own migration — this is confirmed published data, and
every environment needs it before a grade can be computed.

`subject_group_weights` stays exactly as it is. It represents DO 015 Table 10,
which is the order. The catalog represents DepEd's operational instrument. They
are two different authorities and must be able to disagree visibly. Resolution
order: the subject's catalog row first, then its `subject_group`, then the
scheme's `all` bucket.

**Report any row where the catalog and Table 10 disagree.** Do not reconcile
them silently.

## 2b — Link subjects to the catalog

Migration adding `subjects.catalog_id`, nullable, foreign key to
`deped_subject_catalog`. Nullable because a Grade 12 subject under DO 8 has no
SSHS catalog row and never will.

Backfill by exact course-title match, case-insensitive. Report matched and
unmatched counts. Do not fuzzy-match — a wrong link mis-weights every grade in
that subject.

## 2c — Examination roles become per-subject

`ExamRoleSharesSeeder` currently seeds one global 30/30/40. Nine Academic
subjects are Term-Exam-only at 100 with no summative tests.

Change `ExamRoleShare` resolution to read the catalog row when one exists,
falling back to the current global split when it does not. A subject whose
catalog row has `st1_share` null must not expect ST1 or ST2 evidence at all —
this is the same distinction `ex_weight` null already makes, applied one level
down.

Amend `HANDOFF.md` design decision 5. It currently states 30/30/40 as
universal. It is not.

## 2d — The DO 8 table

The client's Grade 12 is Academic Track only, so three of DO 8's five columns
apply. Seed all five anyway; a school that adds a TVL section later should not
need a migration.

| Group | WW | PT | QA |
|---|---|---|---|
| Core subjects | 25 | 50 | 25 |
| Academic — all other subjects | 25 | 45 | 30 |
| Academic — Work Immersion / Research / Business Enterprise Simulation | 35 | 40 | 25 |
| TVL, Sports, Arts and Design — all other subjects | 20 | 60 | 20 |
| TVL, Sports, Arts and Design — Work Immersion / Research / Exhibit / Performance | 20 | 60 | 20 |

New migration, not an edit to `2026_09_06_000001`, which has already been
applied. Update `SubjectGroupWeightsSeeder` to match.

**These five rows were read from secondary reproductions, not the signed DO 8
PDF.** Carry the same `SOURCE NOTE` discipline as the transmutation table, and
add it to the paper's limitations until someone checks the signed order.

DO 8 keys weights by **track**, DO 015 by **subject group**. These are
different axes and `subject_group` cannot answer both. Resolve DO 8 from the
section's track plus the subject's type, in one place, with the mapping written
down.

## 2e — Recompute and compare

Run `RecomputeGradesCommand`. Diff every computed grade against the backup
taken in Part 0. In the pilot data (General Mathematics and Oral Communication,
both 20/50/30 and both 30/30/40) **nothing should move**. If anything moves,
stop and find out why before continuing.

**STOP POINT 2.** Report catalog/Table 10 disagreements, backfill match
counts, and the recompute diff.

---

# PART 3 — The curriculum taxonomy split

Blocked on Q1 for the elective half; the taxonomy half can start now.

## 3a — Mark which curriculum a row belongs to

`specializations` currently mixes two taxonomies with nothing to tell them
apart:

```
1  STEM      Science, Technology, Engineering, and Mathematics   ← SSHS cluster
2  ASSH      Arts, Social Sciences, and Humanities               ← SSHS cluster
3  BUSENT    Business and Entrepreneurship                       ← SSHS cluster
4  SHW       Sports, Health, and Wellness                        ← SSHS cluster
5  ABM       Accountancy, Business and Management                ← old strand
6  HUMSS     Humanities and Social Sciences                      ← old strand
7  GAS       General Academic Strand                             ← old strand
```

All seven sit under `track_id = 1`. Row 1 is an SSHS cluster, but a Grade 12
STEM section has nowhere else to point, so the two collide on one row despite
being different things under different orders with different weights.

Add a `curriculum` column (`sshs` / `k12_2013`) to `specializations` and to
`sections`. Backfill rows 1–4 as `sshs`, rows 5–7 as `k12_2013`. Add the
missing old-curriculum STEM strand row. Backfill `sections.curriculum` from
grade level for existing rows, then let it be set explicitly.

This column, not the grade level, becomes what selects the grading scheme.
`TransmutationService::schemeFor()` currently infers it from grade level and
school year; that inference is right today and wrong the moment a school runs a
transition cohort differently.

## 3b — Fix `Subject::forSection()`

```php
$q2->whereNull('specialization_id')
   ->orWhere('specialization_id', $section->specialization_id);
```

**Correction, 2026-09-10 — verified directly against this codebase's Laravel
version, both in isolation and through `forSection()` itself, and the
original claim below was wrong.** Laravel's query builder converts
`where('specialization_id', null)` to `IS NULL` automatically, not a literal
`= NULL` comparison — so for a Grade 11 SSHS section the generated SQL is
`(specialization_id IS NULL OR specialization_id IS NULL)`, redundant, not
broken. No SQL bug exists here; the query runs exactly as written.

~~When a Grade 11 SSHS section has `specialization_id = NULL`, the second
condition becomes `specialization_id = NULL`, which is never true in SQL. No
error is raised.~~ That description does not match this codebase's actual
behavior.

**The real problem is semantic, not syntactic**, and it's why a pivot table
is required rather than merely convenient: `specialization_id` means "the
strand a section chose" under the old 2013 curriculum, but under SSHS a
subject's cluster is a property of the subject itself — a section has no
strand to choose, so comparing `section.specialization_id` to
`subject.specialization_id` isn't asking a meaningful question for these
subjects. Two outcomes result, both still real:

- Electives imported **with** a specific old-style `specialization_id`: none
  match an SSHS section (correctly excluded — the section has no strand to
  match). The section gets core subjects only, and Submit Report reports the
  term complete while every elective grade is missing. This one is silent.
- Electives imported **without** one (`specialization_id` null — the shape
  any real SSHS elective would actually have, since SSHS has no strands):
  every such elective in the track returns at once. `totalExpected` demands
  a grade for all of them from every student and the term can never
  complete.

The pilot hides this because Molave carries `specialization = ABM`, a value
that should not exist on an SSHS section at all — and because zero elective
subjects exist in this database at all as of Part 2/3.

An SSHS section needs its own resolution path, and that path is where the
elective assignment from Part 6 will be read. See `CLAUDE.md`, "Elective
selection is per-cluster, not per-learner," for the full corrected writeup.

**STOP POINT 3.**

---

# PART 4 — Make the subject-group default visible

The `core_academic` default is correct behaviour and stays — removing it breaks
every older import file. The problem is that it is silent.

With the client's real catalog, **34 of 86 applicable subjects are 20/60/20**
and only 38 are 20/50/30. The default is now wrong more often than it is right.

**4a.** `SubjectsImport` reports every row that fell back to the default, by
name, in the existing import result panel. Do not change the panel's logic;
add a line to it.

**4b.** New `getDataHealthChecks()` entry on the Admin dashboard: subjects
whose catalog row implies a weighting different from their stored
`subject_group`, and elective subjects still sitting on `core_academic`. A
failing check links to the subject list, filtered.

**4c.** The Admin subject screen labels each group by what it *covers*, not by
its slug. `core_academic` covers "Core Subjects and Other Academic Electives" —
an admin reading only the slug will leave the column blank on an elective and
be right by accident, then do the same on a Sports elective and be wrong.

**4d.** Test that the notice appears, not merely that the default is applied.

---

# PART 5 — The E-Class Record reader

## 5a — Profile detection

Recognise the template by three marks together: the sheet name set,
`ECRSHS2026` in `HELPER!B4`, and the version tag in `INPUT DATA!T64`. If any is
absent, fall through to the existing flat reader unchanged.

Store the version tag on `AssessmentUpload`. When DepEd ships `2027_v1.0` you
need to know which files were read under which profile.

## 5b — Two-pass read

`AssessmentUploadService` currently calls `getActiveSheet()`. The ECR path
reads `INPUT DATA` for the roster and subject identity, then the requested
`Term N` sheet for scores.

Pass one, from `INPUT DATA`: grade level, section, subject category, cluster,
subject, number of terms, and the roster — LRN and name, male `N`/`O`, female
`R`/`S`, merged into one list. The MALE/FEMALE split is a reporting convention
and carries nothing the DSS uses.

Pass two, from `Term N`: `HIGHEST POSSIBLE SCORE` at row 14 becomes the MAX
row. Items come from `D:M`, `Q:Z`, `AD:AF` with their component already known
from the band, so `AssessmentColumnClassifier` is not asked to guess a
component from a column named `"2"`. Learner rows are 17–66 and 68–117.

**Never import** `N`, `O`, `P`, `AA`, `AB`, `AC`, `AG`–`AK`, `AL`, `AM`. These
are computed. Importing them would create assessment items out of derived
figures and silently double-count evidence.

## 5c — Empty columns

The template ships ten WW and ten PT slots whether or not the teacher used
them. A column whose header number exists but which holds no score in any
learner row is not an assessment item. Skip it, and report how many were
skipped so the adviser can tell the difference between "not used" and "I forgot
to fill this in".

## 5d — Item ceiling

Validate against 10 WW, 10 PT, 3 EX. A file exceeding any of these is not a
valid SSHS class record and the upload is rejected with the specific count.

## 5e — Roster reconciliation

Match by LRN against existing students. Report unmatched learners; never create
a student silently from an assessment upload. A learner in the file but not in
the section, or in the section but not the file, is a finding the adviser
must see before importing.

## 5f — Weight cross-check

Compare the weights the file carries against what the system resolves for that
subject. On mismatch, warn clearly on the Verify screen and name both numbers.
Never overwrite. The one exception is `OTHER ELECTIVE / SPECIAL CURRICULAR
PROGRAM`, where the file genuinely is the only source — and the Verify screen
must say so explicitly rather than treating it like any other subject.

**STOP POINT 5.** Report a full dry run against the supplied workbook before
importing anything.

---

# PART 6 — Elective assignment

Blocked on Q1. Do not start until it is answered.

This is `WORK_ORDER.md` Part 7, promoted from deferred to required. The reason
it was deferrable was that the pilot has two electives in one cluster, where
"every elective in the cluster" and "the electives this section takes" coincide
by accident. Two Grade 11 sections of 42 choosing across clusters removes that
coincidence immediately.

The SSHS catalog adds a second problem the earlier work order did not know
about: **electives run for one, two, or three terms.** The ECR enforces this —
it refuses to let a two-term elective begin in Term 3. The system assumes three
terms for everything, so a one-term elective will make `totalExpected` demand
grades for terms that were never taught, and Submit Report will never complete.

Whatever shape Q1 produces, the assignment record must carry which terms the
subject runs, and `AcademicTerm::completionStatus()`,
`ReportController::getSectionSubjects()`, and `TermReadinessService` must all
read it.

---

# PART 7 — Load the client's real structure

Blocked on Q2 for the Grade 12 half.

Grade 11: sections Shakespeare and Curie, 42 each, `curriculum = sshs`,
`track = Academic`, `specialization_id = NULL`.

Grade 12: ABM 22, HUMSS 39, STEM 20, `curriculum = k12_2013`,
`track = Academic`, specialization set — once the section count is known.

**`curriculum` must be set explicitly on every section this part creates —
never left null to fall back on `TransmutationService::schemeFor()`'s
grade-level inference.** Part 3a's migration backfilled existing sections
using that exact inference specifically because it happened to already be
correct for the single school year on file; that is luck, not a guarantee,
and Part 3a's own documentation in `CLAUDE.md` says so explicitly. A section
created here with `curriculum` left null would compute correctly today only
by the same coincidence — set it on purpose instead of relying on it twice.

**Same requirement for `specializations.curriculum`** on any specialization
row this part touches or creates (e.g. if Grade 12's ABM/HUMSS/STEM sections
need a specialization row that doesn't already exist as `k12_2013`). That
column is nullable for the same reason `sections.curriculum` is — no
existing creation path could answer the question — and every specialization
created since Part 3a without an explicit value has silently fallen out of
the curriculum split. See `CLAUDE.md`, "A new specialization can silently
fall out of the curriculum split."

Existing Molave data is not deleted. Decide explicitly whether it becomes one
of the real sections or stays as demonstration data, and write the decision
into `CLAUDE.md`. Do not resolve this by migration.

---

# PART 8 — Documentation

- `CLAUDE.md`: the catalog and its relationship to Table 10; the curriculum
  column and what it governs; the ECR reader profile and its version tag; the
  cross-check rule and its single exception.
- `HANDOFF.md`: amend design decision 5; add the client's real roster shape.
- `PAPER_REVISION_WORK_ORDER.md`: the transmutation verification, the DO 8
  source caveat, and the transition-year finding — one school running two
  curricula and two grading orders simultaneously is a genuinely interesting
  result and belongs in the paper.

---

# TESTS TO ADD

- `Do015BandsMatchOfficialEcrTest`
- `DepedCatalogSeededCompletelyTest` — 141 rows, weights intact
- `CatalogWeightsBeatSubjectGroupTest`
- `TermExamOnlySubjectExpectsNoSummativeTestsTest`
- `Do8WeightsResolveByTrackTest`
- `SshsSectionHasNoSpecializationTest`
- `ForSectionReturnsElectivesForNullSpecializationTest`
- `SubjectGroupDefaultIsReportedTest`
- `EcrProfileDetectedByThreeMarkersTest`
- `EcrFallsBackToFlatReaderTest`
- `EcrComputedColumnsAreNeverImportedTest`
- `EcrRosterMergesMaleAndFemaleBlocksTest`
- `EcrWeightMismatchWarnsWithoutOverwritingTest`
- `EcrItemCeilingRejectedTest`

Re-run every existing test. Baseline 723.

---

# DEFINITION OF DONE

- [ ] Transmutation limitation closed in seeder, `CLAUDE.md`, and paper
- [ ] 141 catalog rows seeded; disagreements with Table 10 reported, not reconciled
- [ ] `subjects.catalog_id` backfilled by exact match; unmatched reported
- [ ] Examination roles per subject; the nine TE-only subjects expect no ST evidence
- [ ] DO 8 seeded with five rows and a source note
- [ ] Recompute diff clean on pilot data
- [ ] `curriculum` column on `specializations` and `sections`; STEM collision resolved
- [ ] `forSection()` handles a null specialization explicitly
- [ ] Subject-group default reported in the import panel and Data Health
- [ ] ECR profile detected; flat reader unchanged and still passing
- [ ] Computed columns never imported; empty slots skipped and counted
- [ ] Roster reconciled by LRN; no student created from an upload
- [ ] Weight mismatch warns and never overwrites
- [ ] Full suite passing, above the 723 baseline
- [ ] `npm run build` run; every touched screen loaded with a hard refresh