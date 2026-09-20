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
- `analytics/README.md` — THE operational reference for the ML module:
  canonical feature schema, target definition, missing-data policy, how to
  validate/train/evaluate/promote/roll back, and "Why the previous prototype
  could be described as hardcoded". Read this first before touching anything
  under `analytics/`
- `ML_ARCHITECTURE.md` — academic-rules-vs-ML boundary and the architectural
  reasoning; the current active model is synthetic-trained only. Where it
  overlaps `analytics/README.md`, the README is the one kept current
- `TRAINING_DATA_CONTRACT.md` — the schema a real historical dataset must
  satisfy before `analytics/train_model.py` will train a candidate from it

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

Current grading configuration (DO 8, s. 2015 — the scheme of a section
whose curriculum is EXPLICITLY `k12_2013`, and of school years before
2026-2027; see "SSHS ECR grading correction" below — and the default for
any subject with no more specific rule):

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

## No custom Subject Offerings spreadsheet — the prescribed ECR is the only external academic format

> **Read with the "Subject applicability refactor (2026-09-20)" section
> below.** The rule here — no invented spreadsheet for master data — still
> holds and was extended to Tracks, Specializations, Subjects and Sections.
> The per-term "Assign Subject" workflow this section describes as
> remaining on screen is GONE; `section_subjects` now records section
> elective CHOICES only.

**"Prescribed ECR alignment" pass, 2026-09-19.** Admin > Sections used to
carry an "Import Subject Offerings" upload taking a
`section,academic_year,academic_term,subject` spreadsheet. **That format was
invented by this project.** No such file exists at the school, DepEd
publishes nothing like it, and the prescribed E-Class Record already states
the section, the subject, the grade level, the school year and how many
terms the subject runs for. It was a second source of truth for data the
official instrument already carries, and a second thing to keep in sync.

Removed: the route (`admin.sections.subjects.import`),
`SectionSubjectController::import()`, `App\Imports\SectionSubjectOfferingsImport`,
the Sections-page button and modal, and the two `modal.js` handlers.
`TermSpecificSubjectOfferingsTest` now asserts the route, the class and the
on-screen wording are all gone, and that per-term assignment still works on
screen — the FORMAT was removed, not the feature.

**Do not add a second external format for anything the prescribed workbook
already states.** If a future need looks like it wants one, read the
workbook first.

### What did NOT change: `section_subjects` is still the internal relationship

`section_subjects` is an INTERNAL table — (section, subject, academic term),
unique on all three, FKs restrict on delete, protected by
`ProtectsAcademicHistory`. Nothing about it was weakened. It is still what
makes Term 1, Term 2 and Term 3 able to hold different subjects; what scopes
the Adviser's subject dropdown; what every Adviser write path enforces
server-side; and what keeps Principal Subject Analysis historically correct.
Removing the invented upload format removed a way of WRITING to this table,
not the table or its meaning. `SubjectOfferingService` remains the one place
its rules live.

## `EcrSubjectTermResolver` — the one place a prescribed ECR is checked, and the one place term applicability is decided

**Identity metadata is not advisory.** Before this pass, the workbook's
cover cells were compared against the on-screen selection and a mismatch
produced a DISMISSIBLE WARNING: an adviser could read "this file says
section Curie but you selected Shakespeare", click through, and import one
class's scores onto another class's learners. A file naming a different
section is the wrong file. `AssessmentUploadService::checkEcrMetadataMismatch()`
is gone and `EcrSubjectTermResolver` replaces it.

**Blocking vs. advisory, and why the line is drawn there:**

- **BLOCKS the upload** — the workbook STATES a value and it CONTRADICTS the
  selection: a different section, subject, grade level or school year, or a
  term the subject does not run in.
- **Warns only** — the workbook does not state the value at all (the cover
  cell is blank). The real instrument ships blank; refusing every unfilled
  cover would block legitimate uploads to guard against a conflict nobody
  demonstrated. It is said in words, not passed over.

**This does NOT relax the standing rule on conflicting WEIGHTS** (below). A
teacher-typed weight cell that disagrees with the catalog is still a warning
and still never overwrites a grading profile. That rule governs how a grade
is COMPUTED; this one governs which class a file BELONGS to. A wrong weight
computes a grade differently; a wrong section files it under a different
learner.

**Enforced in all three requests.** detect(), preview() and import() are
separate POSTs, so the check is re-run against the stored file in each —
backend enforcement, not UI hiding. A crafted request that jumps straight to
import() is refused the same way.

### How "which terms does this subject run in" is answered

From the workbook's own TERMS AND UNITS block, read at its source. The cells
were confirmed against the real instrument, not assumed:

| Cell | Meaning | Read from |
|---|---|---|
| `F33` | NO. TERMS TAUGHT | **not read — an ARRAY FORMULA** |
| `H33` | Term Blk (dropdown) | the file, teacher-typed |
| `F51`/`H51`/`F52` | the same three for an OTHER ELECTIVE | the file, teacher-typed |

`F33` is an array formula doing an `XLOOKUP` into HELPER, and this codebase
never evaluates a workbook formula (`_xlfn.XLOOKUP`/`_xlfn.IFNA` may not be
supported by the spreadsheet library, and a wrong value is worse than no
value). It does not need to: **that entire HELPER catalog is already seeded
into `deped_subject_catalog` with `g11_terms`/`g12_terms` per grade level**,
so the count comes from there — the same number the formula would produce.
An OTHER ELECTIVE has no catalog row by definition, which is exactly why the
workbook has the teacher type the count, so there it is read from the file.

Applicability is the workbook's own rule, quoted from `HELPER!G24:G26` and
`G34`: 1 term to the block term only; 2 terms to the block and the one after
it, and **a 2-term subject cannot begin in Term 3**; 3 terms to all three.
`H33`'s allowed values are literally `FIRST TERM, SECOND TERM, THIRD TERM`.

An unknown term count (no catalog row, nothing typed) is a WARNING, not a
block — missing evidence is not conflicting evidence.

### Synchronizing `section_subjects` from a validated ECR — SUPERSEDED 2026-09-20

> `EcrSubjectTermResolver::synchronize()` and `AssessmentController::
> ecrSyncCandidateOrNull()` no longer exist. An upload validates against
> the subject configuration and never writes it — see the "Subject
> applicability refactor" section. Kept for the reasoning it records.

The boundary, deliberately:

> **Admin decides WHICH SUBJECTS a section takes.
> The prescribed ECR settles WHICH TERMS an already-assigned subject runs in.**

`EcrSubjectTermResolver::synchronize()` only ever INSERTS one
(section, subject, term) row, in a transaction, logged as
`sync_section_subject_from_ecr`, and only when all of these hold: the file
validated with zero blocking errors; the workbook says this term applies;
the section is already term-managed; and the subject is already assigned to
this section in **some** term of the school year.

It will not introduce a subject nobody assigned, will not update or delete
any row (so an offering with academic history cannot be mutated by an
upload), and **will not flip a section off the curriculum default onto
term-managed resolution** — that transition rewrites what every Adviser and
Principal screen resolves to, and belongs to the Admin who can see its
consequences.

`AssessmentController::ecrSyncCandidateOrNull()` is the one narrow opening
in the "subject must be offered this term" guard that makes this reachable,
and it grants nothing on its own: it only lets the file be read far enough
to ask the workbook, and detect() refuses with the ordinary "not assigned"
message when the workbook does not back it up.

### Grade 11 and Grade 12 formats stay separate

Unchanged by this pass and must stay that way: `EcrProfileDetector`
(Strengthened SHS) and `Grade12EcrProfileDetector` recognise their workbooks
structurally, and `AssessmentUploadService` routes to the matching reader.
`EcrSubjectTermResolver::validate()` returns **null** for anything that is
not a prescribed Strengthened SHS ECR, so the Grade 12 workbook and the flat
CSV/XLSX path are untouched by it. Do not force a Grade 12 file through the
SSHS parser.

### Performance note that is load-bearing, not cosmetic

`EcrReaderService::describe()` loads **only the INPUT DATA sheet**
(`setLoadSheetsOnly`), and `EcrSubjectTermResolver` caches its result per
file (path + size + mtime). This is not tidying: describe() is now called on
every detect/preview/import, and loading all seven sheets of this 434KB
workbook three times per upload exhausted a 512MB PHP memory limit when
several uploads ran in one process. Keep both.

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

## The client's real structure — NOT CONFIRMED ROSTER DATA

**Correction, 2026-09-11.** The section names and counts below came from a
screenshot and from sample files generated for testing — not from the
school, which has not sent a real roster. The two-curricula/two-grading-
order SHAPE this describes is real and is what the `curriculum` column and
DO 8/DO 015 split exist to handle; the specific names Shakespeare/Curie and
counts 22/39/20 are not confirmed and must not be treated as real
enrollment data anywhere (a data-loading pass, a report, a paper claim).
See `HANDOFF.md`'s matching correction and `ECR_ALIGNMENT_WORK_ORDER.md`
Part 7, blocked on the real roster for exactly this reason.

Grade 11 runs the Strengthened SHS curriculum under DO 015: sections
**Shakespeare** and **Curie**, 42 learners each, no specialization, because
SSHS has no strands — as communicated, not verified.

**Superseded 2026-09-20 ("SSHS ECR grading correction"):** the claim
below that Grade 12 stays on DO 8 was never verified and is contradicted by
the school's own SY 2026-2027 instruments (both DO 015-era). An unset
curriculum in SY 2026-2027 now resolves to DO 015 for BOTH grade levels; a
section that genuinely stays on the 2013 curriculum must carry
`curriculum = k12_2013` explicitly.
Grade 12 remains on the 2013 curriculum under DO 8: **ABM** 22, **HUMSS** 39,
**STEM** 20. Academic Track only — no TVL, Sports, or Arts and Design, so
three of DO 8's five weighting columns apply — as communicated, not verified.

165 learners, one school, two curricula, two grading orders, running at the
same time. The pilot section Molave is not one of these.

**Corrected, 2026-09-11.** Molave (`curriculum = sshs`) carried
`specialization_id` pointing at `ABM` — a value that cannot correctly exist
on an SSHS section, since SSHS has no strands. Set to `NULL` after PART 6
made it safe to do so: the SSHS branch of `Subject::forSection()` no longer
reads `specialization_id` for electives at all (it reads the
`section_subject` pivot instead), so nulling this column no longer changes
which subjects Molave's grading resolves against. Before PART 6 this same
change would have silently emptied Molave's elective list, since the old
specialization-matching query was the only thing selecting electives for
every section, `curriculum` notwithstanding. Backed up first
(`backups/backup_20260911_2350_pre_molave_fix.sql`), verified via
`dss:check-integrity` (clean before and after) and `dss:recompute-grades`
on all three terms (0 of 80 verified grades affected, checksum 20439.00
unchanged).

## Implementation — `deped_subject_catalog` ("ECR alignment" work order, PART 2)

The 141-row catalog above is seeded into `deped_subject_catalog`, extracted
from `HELPER!J7:AC161` into `database/seeders/deped_sshs_catalog.csv` and
parsed by `DepedSubjectCatalog::rowsFromCsv()` — one parser, called by both
the seeding migration (fresh environments) and `DepedSubjectCatalogSeeder`
(re-seeding when DepEd ships a future version and the CSV is replaced), so
the normalisation rules live once. `subjects.catalog_id` links an existing
`Subject` to its row by exact, case-insensitive `course_title` match — no
fuzzy matching. `GradingEngine::computeGrade()` reads a linked catalog row's
weights and exam-role shares FIRST, falling through to
`SubjectGroupWeight::resolve()` exactly as before Part 2 for any subject with
no link. `subjects.subject_group` and `subject_group_weights` are untouched
and still used for everything the catalog doesn't cover.

**The catalog vs. the pre-Part-2 6-bucket default — the actual disagreement,
counted, not just described.** `subjects.subject_group` has no cluster-aware
assignment logic anywhere in this codebase; every subject silently defaults
to `core_academic` (20/50/30) unless something explicitly overrides it. Of
the 139 catalog rows with a real weight (141 minus the 2 teacher-supplied
`OTHER ELECTIVE` rows), only **38 actually agree with that default** —
exactly CORE (6) + STEM (26) + Business & Entrepreneurship (6), all
genuinely 20/50/30. **101 disagree.** 34 of those are Arts, Social Sciences,
and Humanities + Sports, Health, and Wellness at 20/60/20 (e.g. *Citizenship
and Civic Engagement*: catalog 20/60/20, silent default 20/50/30) — the rest
are Field Experience/Research/Work-Immersion/Tech-Pro rows defaulting to a
triple that's wrong in a different way. `CatalogDisagreesWithSilentDefaultTest`
pins these exact counts.

**Live backfill result, restored pilot data (2 subjects exist)**: `General
Mathematics` matched — its catalog weights (20/50/30, 30/30/40) are
numerically identical to its `core_academic` fallback, so linking it changed
nothing about WW/PT/EX. `Oral Communication` did **not** match, and this is a
finding, not a gap to gloss over: that name is not in the Strengthened SHS
catalog at all. It is a K-12 2013 core-subject name; the Strengthened SHS
Grade 11 core list (Effective Communication, General Mathematics, General
Science, Life and Career Skills, Mabisang Komunikasyon, Pag-aaral ng
Kasaysayan at Lipunang Pilipino) replaced it outright. One of the two
subjects in the pilot data is not a real Strengthened SHS subject.

**A real pre-existing gap this surfaced, corrected in the same pass**:
`exam_role_shares` had zero rows on this database — `ExamRoleSharesSeeder`
had never been run against it. Every `do015_2026` subject's Examination
component had therefore been computing on an equal-thirds split
(33.33/33.33/33.33) instead of the documented 30/30/40, silently, since
nothing in the test suite exercises a real subject against an unseeded
table. Linking `General Mathematics` to its catalog row (which carries real
`st1_share`/`st2_share`/`te_share`) surfaced this the moment its Examination
percentage stopped matching the old equal-thirds figure. Fixed by seeding
`ExamRoleSharesSeeder` and re-running `dss:recompute-grades` for all three
terms — 54/40/39 grades moved (Terms 1/2/3), largest movement 1.00 point,
**zero passing-status changes**. This was a real correction to real grades,
not a Part 2 side effect to route around — recorded here so it isn't
mistaken for one later.

**DO 8, s. 2015's five-row table** — unlike DO 015, DO 8 weights by **track**
(Core / Academic / TVL-Sports-Arts-Design), not by subject group, and has no
equivalent 141-row catalog. Seeded as five new rows in the *existing*
`subject_group_weights` table (`do8_core`, `do8_academic_other`,
`do8_academic_work_immersion`, `do8_tvl_sports_arts_other`,
`do8_tvl_sports_arts_work_immersion`) rather than a new table.

SOURCE NOTE — these five rows are read from secondary reproductions of the
DO 8, s. 2015 table, not the signed PDF of the order itself. Correcting one
later is an `UPDATE`, never a deployment. Before citing this table in
writing, confirm it against the signed order and delete this note.

`GradingEngine::resolveDo8GroupKey(Section, Subject)` is the mapping, written
down in full: a section's `track.code` of `ACAD` is the Academic branch;
`TECHPRO` or `TVL` is the non-Academic branch (DO 8's table groups TVL,
Sports, and Arts and Design into one weighting bucket — this codebase's
"TechPro Track" is DO 015-era vocabulary for what DO 8 calls TVL). A section
with **no track set at all** falls to the scheme's universal `all` row
(25/50/25) rather than guessing a branch — every pre-Part-2 test and any
untracked real subject relied on exactly that fallback. Within a branch, a
`core` subject gets that branch's `*_core` slug (Academic only — DO 8 defines
Core Subjects once, not per track); an elective is matched by name against a
short keyword list (`work immersion`/`research`/`business enterprise
simulation` for Academic, `work immersion`/`research`/`exhibit`/`performance`
for non-Academic) to the `*_work_immersion` slug, else `*_other`. Every such
keyword match is logged (`Log::info`) and listed by `dss:check-integrity`
(non-failing — a genuinely-named "Work Immersion" subject routed there is
correct, not an error) precisely because no per-subject DO 8 catalog exists
to check against and zero Grade 12 subjects exist in this database yet (Part
7 is blocked on the school's answer to Q2) — this is a stopgap, and it is
built to look like one.

This "advisory, not an error" severity is the same distinction the Admin
dashboard's Data Health panel draws with its red/yellow dots (`admin/
dashboard.blade.php`) — red blocks the academic workflow (no adviser on a
section, a learner in no roster), yellow is worth a look but nothing is
actually broken (this keyword-match routing, accounts that have never
logged in, a subject on the silent `subject_group` default). "UI legibility
pass" item 4 put that distinction in the panel's own subtitle ("Yellow is
worth reviewing but not blocking. Red blocks the academic workflow.") so a
first-time reader isn't left guessing what yellow means from color
convention alone. If the panel's wording changes, this paragraph should
change with it — the two are one statement now, not two that happen to
agree.

## The five do8_* rows must never be human-selectable — and, until commit 39c64d2, one upload path silently accepted one anyway

The five `do8_*` `subject_group_weights` rows above are computed by
`GradingEngine::resolveDo8GroupKey()` from a section's track and a
subject's type — a human never picks one directly, and a subject's own
`subject_group` column isn't even read for the `do8_2015` scheme. Selecting
one on a Grade 11 SSHS subject would put DO 8 weights on a DO 015 subject,
silently.

`Admin\SubjectController::availableSubjectGroups()` (the Admin subject
form's dropdown and validation) and `SubjectsImport::validSubjectGroups()`
(the bulk-import validation) both queried `subject_group_weights` filtered
only to exclude the scheme-wide `all` fallback bucket — neither scoped to
`scheme = 'do015_2026'`, so both listed all five `do8_*` rows as valid
choices. The dropdown bug was visible (an admin could see and pick a wrong
option on screen). **The import bug was not**: confirmed directly, before
the fix, that `Validator::make(['subject_group' => 'do8_core'], ['subject_group'
=> Rule::in($this->validSubjectGroups())])` passed — a `do8_core` value
typed into an uploaded file's `subject_group` column would have been
silently accepted and created a subject silently mis-weighted, with
nothing on screen to catch it. Fixed in `39c64d2` by scoping both queries
to `scheme = 'do015_2026'`; regression tests assert no `do8_` slug appears
in the form's rendered HTML or view data, and that a `do8_core` value is
rejected both from the manual form and from an uploaded file.

## Subject classification: `core_academic` is Core-only now — `academic_other` exists so an elective never has to borrow it

**"Subject classification and grading weights cleanup" pass.** `core_academic`
(20/50/30) was quietly serving two different DepEd profiles at once: Core
subjects themselves, and the separate "Academic Elective — all other" profile
(STEM and Business & Entrepreneurship cluster electives), which happens to
carry the identical 20/50/30 split. Sharing one slug between them is the exact
mechanism behind the reported "TYPE: Elective, SUBJECT GROUP: Core Academic"
contradiction — an elective correctly weighted at 20/50/30 had no honest group
to sit in except the one named for Core. A new `academic_other` row (same
20/50/30 numbers, a distinct slug) was added to `subject_group_weights`, and
`core_academic` is now reserved for `type=core` subjects only.

**`SubjectGroupWeight::classificationError(string $type, int $gradeLevel,
?string $subjectGroup): ?string`** is the single authoritative check this
enforces — called from both `Admin\SubjectController::store()`/`update()` and
`SubjectsImport::withValidator()`, so the manual form and the bulk importer
can never disagree about what's a valid combination:

- Grade 11 AND Grade 12 (same rule — "Subject Group for both grade levels"
  pass, 2026-09-20, which SUPERSEDED the earlier "Grade 12 must be null"
  rule by decision): `subject_group` is REQUIRED, must be a real seeded
  group, and must match type — `core_academic` for `type=core` only, never
  for an elective, and every other group is elective-only, never for a core
  subject. `classificationError()` does not read grade level at all.
- What that decision did NOT change — Grade 12 GRADING: DO 8, s. 2015 still
  weighs by a SECTION's track (`GradingEngine::resolveDo8GroupKey()`) and
  never reads a subject's `subject_group`. A Grade 12 subject's group is
  stored, listed and edited exactly like a Grade 11 subject's, and its
  WW/PT/Exam split is byte-identical with or without one
  (`SubjectFormConsistencyTest::test_a_grade_12_subject_computes_the_
  identical_grade_with_and_without_a_subject_group` cycles every group).
  The one other reader, `DashboardAnalyticsService`'s evidence trend, calls
  `resolve('do8_2015', $group)`; no `do8_2015` row carries a DO 015 group
  name, so it falls to the scheme's `all` row — the row a null reached.

**Every "blank/unknown → `core_academic`" default was removed, on purpose,
across all three layers it existed in**: the `subjects.subject_group` column's
DB-level `DEFAULT 'core_academic'` (dropped via a new migration — the
already-run column-add migration was never edited), `SubjectGroupWeight::
resolve()`'s own `$subjectGroup ?: 'core_academic'` fallback (removed — a null
group for `do015_2026` now falls through to the same "no `all` bucket either"
`RuntimeException` an unseeded scheme would throw, since do015_2026 has no
`all` row and never had one), and `SubjectsImport`'s prior CORE-row leniency
(a blank `subject_group` cell used to default quietly for CORE rows only,
reported via an `import_warnings` notice — that entire mechanism is gone; a
blank cell on any Grade 11 row, core or elective, is now a rejected row). An
unclassified Grade 11 subject now surfaces loudly — a validation error at
creation/import time, or a `RuntimeException` from `GradingEngine` if one ever
reaches grading — never a quiet, wrong `core_academic` guess.

**Three live subjects the audit found actually misclassified, corrected by a
targeted migration** (`2026_09_12_000003_normalize_existing_subject_
classification.php`, scoped by exact name+grade_level match, not a blanket
re-derivation — verified zero grade/assessment rows referenced any of the
three before touching them, so this was a pure classification fix with no
computed-grade movement):

- **Basic Calculus** (Grade 11 elective) — was `core_academic`; its real
  catalog row (STEM cluster) is also 20/50/30, which is exactly why the
  mislabel went unnoticed, but it is TE-only (no ST1/ST2 at all). Linked to
  its `deped_subject_catalog` row and moved to `academic_other`. The catalog
  link isn't just a label fix — it corrects a real grading bug: before this,
  the subject had no catalog link and was computing on the equal-thirds
  ST1/ST2/TE fallback it was never supposed to have.
- **Art Criticism and Creative Markets** (Grade 11 elective) — was
  `core_academic`; its real catalog row (Arts, Social Sciences, and Humanities
  cluster) is genuinely 20/60/20, a different split. This one was silently
  mis-GRADED, not just mislabeled. Linked to its catalog row and moved to the
  already-seeded `arts_sports_wellness` group.
- **Community Engagement Solidarity and Citizenship** (Grade 12 elective) —
  had `subject_group = core_academic`, a leftover from the Admin form always
  requiring a do015_2026 group even for a Grade 12 row (now fixed — see
  above). Not wrong the way the two Grade 11 rows are, since Grade 12 never
  read this column at all; just meaningless. Set to `null`.

**`SubjectsImport` also now auto-links `catalog_id`** by exact,
case-insensitive subject-name match against `deped_subject_catalog` for every
imported row (reported via the existing `import_warnings` notice channel,
repurposed from the removed default-notice) — the same automatic resolution a
manually-created subject still doesn't get (there's no equivalent lookup on
`Admin\SubjectController::store()`; only import reads the whole file row by
row already).

`tests/Feature/SubjectClassificationConsistencyTest.php` is the test-of-record
for all of this: every one of the seven named DO 015, s. 2026 profiles (Core,
Academic Elective — all other, Research/Design/Innovation, Arts/Sports/
Health/Wellness, Field Experience, Tech-Pro — all other, Work Immersion)
computed end-to-end through `GradingEngine`, plus every invalid type/group/
grade-level combination `classificationError()` is meant to catch.

**"Subject form consistency" + "Subject Group for both grade levels" passes
(2026-09-20).** The first pass kept the Grade 12 control present-but-
disabled ("Not applicable to Grade 12") because the rule above said Grade
12 must be null, and reported the conflict. The decision came back: Subject
Group is a legitimate field for BOTH grade levels. Now: Admin > Subjects
has ONE Subject Group control, `required` in the markup, never hidden,
disabled or cleared by grade level (`modal.js`'s `refreshSubjectGroupField()`
only narrows the option list to the selected Type; the Grade Level select
has no hook into it). The option list is `SubjectGroupWeight::allGroups()`
— the DO 015, s. 2026 Table 10 groups, the one authoritative source — for
both grade levels; no Grade 12-specific list was invented and no `do8_*`
row is selectable. `store()`/`update()` persist the group for Grade 12 (the
`=== 12 ? null` coercion is gone). The Subjects list shows the group under
the Type badge for every subject and flags a row with none as "Subject
Group needed" (only reachable for data created before the rule — the live
database has zero Grade 12 subjects, so nothing was backfilled and nothing
is guessed); `Subject::withSuspectSubjectGroup()` lists such rows so the
Admin dashboard's Data Health item 7 and its "Review subjects" link surface
them. Labels are the bare "Subject Group" and "Specialization" — the
"(optional)" word left the label only; `specialization_id` stays nullable
and "— All specializations in track —" is still a valid choice. The page's
validation banner renders every error key (it used to list three and
silently swallowed a `subject_group` rejection). `SubjectFormConsistencyTest`
is the test of record.

**Open decision, deliberately NOT taken here: whether Grade 12 GRADING
should read the group.** The DO 015 group names have no DO 8 counterpart —
DO 8 weighs by track (Academic vs TVL/Sports/Arts, a SECTION property the
subject does not know) and, within the Academic branch, by a Work
Immersion/Research/Business Enterprise Simulation bucket vs "all other",
which `resolveDo8GroupKey()` currently identifies by name keyword (the
documented stopgap). Replacing that keyword match with a `subject_group`
→ DO 8 bucket mapping (e.g. `work_immersion`/`research_innovation` →
`do8_academic_work_immersion`, everything else → `*_other`) is plausible
but is a grading-policy mapping nobody has published; it needs the
school's confirmation and its own tests before it is written. Until then
Grade 12 grading is unchanged.

## mimes: MIME-sniffing rejects the official DepEd ECR — fixed everywhere it appeared

The real official SSHS E-Class Record's actual bytes sniff as
`application/octet-stream`, not a recognised spreadsheet MIME type —
confirmed directly (`mime_content_type()` and `file --mime-type` on
`tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx`, both agree; the file
genuinely starts with a valid `PK\x03\x04` zip signature, so this is a
libmagic recognition gap, not a corrupt file), and via
`Validator::make(['file' => <the real fixture>], ['file' =>
'mimes:xlsx,xls'])->passes()` returning `false`. Laravel's `mimes:` rule
checks the file's actual sniffed content against an extension-to-MIME map,
not the extension alone, so `mimes:xlsx,xls,csv,txt` rejected the exact file
the upload exists to accept.

**Found first** in `Adviser\AssessmentController::detect()` — the shipped
Part 5 upload path every adviser actually uses — proved at the real HTTP
layer before being fixed: a test posting the real fixture to `/adviser/
assessments/detect` failed with the literal browser-facing message *"The
file field must be a file of type: xlsx, xls, csv, txt"* against the
unfixed code, then reached real controller processing after.

**The standing rule now: validate by EXTENSION, never by `mimes:`
(content-sniffing), for any spreadsheet/CSV upload.** Real content is
verified immediately after by whatever actually reads the file —
`EcrProfileDetector` for a genuine ECR, `AssessmentColumnClassifier`'s own
header parsing for the flat path, or a Maatwebsite import's own row
validation — the extension check only ever needs to keep out something
that obviously isn't one of the accepted file types at all; `mimes:` was
never the thing actually guaranteeing a file's real shape, only an
accident of whether libmagic happened to recognise it.

One place this now lives: `App\Http\Controllers\Concerns\
ValidatesSpreadsheetUpload::spreadsheetFileRule(array $extensions =
['xlsx','xls','csv','txt'], int $maxKb = 2048)`. Every route that used to
carry its own `mimes:` copy uses it instead — `Adviser\
AssessmentController::detect()`, `Admin\StudentController::import()` and
`::extractRosterPreview()`, `Admin\SectionController::import()`, `Admin\
SpecializationController::import()`, `Admin\SubjectController::import()`,
`Admin\TrackController::import()`, and `Adviser\GradeController::
importGrades()` — eight call sites that had each carried an independent
copy of the same rule. Do not add a ninth; extend the trait instead.

**Why this survived through all of Part 5 undetected**: every ECR test
written for Part 5 called `EcrReaderService`/`EcrProfileDetector` directly
or went through `dss:ecr-dry-run` — none of them ever pushed the real
checked-in fixture through an HTTP route and its `mimes:` validation layer.
The bug lived entirely in a layer nothing was testing. `tests/Feature/
EcrHttpUploadValidationTest.php` and `tests/Feature/
MimesFixSixMoreRoutesTest.php` close that gap for all eight routes — real
HTTP POSTs with a real file that reproduces the actual sniffing bug, not a
service-layer or CLI call, specifically so a future validation-rule change
on any of these routes can't reintroduce the same class of bug without a
test noticing.

**A separate, unrelated gap this surfaced**: the six generic importers
(Sections/Specializations/Subjects/Tracks/Students/Grades) call
`Excel::import()` without `setReadDataOnly(true)` — unlike `EcrReaderService`
and `EcrProfileDetector`, which learned this lesson in Part 5 (see "The
subject catalog" section above: ~42s and heavy memory for this workbook
without the flag, ~2s with it). Pushing the real 434KB ECR fixture through
one of these six as a test upload exhausted PHP's memory limit entirely —
not a validation failure, a fatal error, during Maatwebsite's own row
reading, well after the `mimes:` fix had already let the file through
correctly. These six importers were never built to expect a file this
heavy (nobody uploads a full E-Class Record to "Import Tracks"), so this
isn't urgent, but it is real and unfixed: a genuinely large or complex
spreadsheet uploaded to any of these six could exhaust memory the same
way. `tests/Feature/MimesFixSixMoreRoutesTest.php` uses a 64KB truncated
copy of the same real fixture instead (`tests/Fixtures/
octet-stream-sniffing-truncated.xlsx` — still genuinely sniffs as
`application/octet-stream`, confirmed the same way) specifically to prove
the `mimes:` fix without hitting this separate, pre-existing memory gap.

## Implementation — `curriculum` on `specializations` and `sections` ("ECR alignment" work order, PART 3a)

`specializations` mixed two DepEd taxonomies on one row with nothing to tell
them apart — a Strengthened SHS cluster and an old 2013-curriculum strand
could collide on the same `(track_id, code)` pair (e.g. `STEM` meant either
depending on which section asked). `curriculum` (`sshs` / `k12_2013`) now
distinguishes them, backfilled by `code` against the live data: `STEM`,
`ASSH`, `BUSENT`, `SHW` (Academic Track) and `ICTPROG`, `AGRIFOOD`,
`HOSPTOUR` (Tech-Pro Track — these three match Strengthened SHS Tech-Pro
cluster names in the Part 2 catalog exactly, not old strand names) are
`sshs`; `ABM`, `HUMSS`, `GAS` (Academic Track) and `ICT`, `HE` (TVL Track —
the old 2013 TVL strand names) are `k12_2013`. The missing old-curriculum
STEM strand row (Academic Track, code `STEM`, curriculum `k12_2013`) was
inserted — the reason the unique index widened from `(track_id, code)` to
`(track_id, code, curriculum)`: two rows now legitimately share
`(track_id, code)`, disambiguated only by curriculum.

`specializations.curriculum` stays **nullable**, deliberately, matching
`sections.curriculum` below: three live creation paths — `Admin\
SpecializationController::store()`, and the `SpecializationsImport` and
`TracksImport` bulk-import classes — have no curriculum concept in their
form or file format at all and no way to answer the question. Forcing
`NOT NULL` would mean fabricating a classification for every specialization
those paths create rather than honestly recording "not yet classified."
None of the three were changed to ask for it in this pass.

`sections.curriculum` is what `TransmutationService::schemeFor()` actually
reads now (an added, optional third parameter — every pre-existing call
site, including three direct test call sites, keeps working unchanged via
the original grade-level/year inference as the fallback for a null or
unrecognised curriculum). `GradingEngine::computeGrade()` and
`DashboardAnalyticsService`'s section-scheme map both pass it through.
Existing sections were backfilled using that exact same inference (so
nothing about any already-computed grade moved — verified: zero movement
on `dss:recompute-grades` for all three terms), which means it is **now
true by luck, not by design**, that this client's sections land correctly:
Molave backfilled to `sshs` (Grade 11, SY 2026-2027) by the same rule
`schemeFor()` always used.

**No Admin UI change was made to let curriculum be set explicitly on
section create/update** — deliberately out of scope for this pass; the work
order's own PART 3a text only names the column as a forward-looking
capability, not a UI to build now. Every section created after this
migration keeps `curriculum = null` until something sets it, which is safe
today only because grade-level inference still happens to be right for
every section this client actually has. **This is a fact that is true by
luck and must not be trusted to stay true**: `ECR_ALIGNMENT_WORK_ORDER.md`
Part 7 (loading Shakespeare, Curie, ABM, HUMSS, STEM) now requires
`curriculum` to be set explicitly on every section it creates, rather than
relying on this same inference a second time.

**Two real migration bugs surfaced and were caught during development of
this part** — both against a fresh test environment, before either ever
reached a committed migration, never by editing one that had already run:

1. MySQL refuses to drop `specializations_track_id_code_unique` while it's
   the only index satisfying the `specializations.track_id` foreign key
   (which needs SOME index with `track_id` as its leftmost column at all
   times). Fixed by creating the replacement `(track_id, code,
   curriculum)` index first, then dropping the old one — see
   `2026_09_10_000004_add_curriculum_to_specializations_table.php`'s own
   `up()`, which does exactly this in that order.
2. The original migration made `curriculum` `NOT NULL`, which broke three
   live creation paths that have no curriculum concept in their form or
   file format at all (`Admin\SpecializationController::store()`, and the
   `SpecializationsImport`/`TracksImport` bulk-import classes) — they'd
   have to fabricate a value to satisfy the constraint. Fixed by leaving
   the column nullable, which is also the correct semantic choice (see
   "A new specialization can silently fall out of the curriculum split"
   below) — not merely a workaround for the bug.

Neither bug reached a committed migration; both were caught locally against
a fresh SQLite/test environment and fixed before commit `fc473c4`, via
rollback, exactly as this file's own database-safety rules require.

---

## Implementation — SUBJECT OFFERINGS per academic term ("Student identity and term-specific subject offerings" pass, 2026-09-18) — SUPERSEDED 2026-09-20

> The "term-managed" model this section describes (the first
> `section_subjects` row replaces the curriculum for its section; every
> subject assigned per section per term; the curriculum default as a
> fallback; `SubjectOfferingService::assign()/syncFromAcademicHistory()/
> isTermManaged()`) was REPLACED by the "Subject applicability refactor"
> below. The table, its schema, its FKs and its history protection are
> unchanged; what a row MEANS changed. Kept for the reasoning it records
> (design decision 4's join key, the migration that expanded old rows,
> the trend/same-subject work in STEP K, which is untouched).

`section_subjects` is now the **subject offering** table: one row per
(section, subject, **academic term**). A subject stays master/curriculum
data in `subjects` (Admin > Subjects is unchanged and is never duplicated
per term); *when and where* it is taught is a row here:

    SUBJECT MASTER + ACADEMIC YEAR + ACADEMIC TERM + SECTION = OFFERING

The table name was kept for compatibility. The PART 6 shape — keyed on
(section, subject, school_year) with a nullable `starting_term`/
`term_count` pair — is gone: `academic_term_id` (FK → `academic_terms`,
restrict on delete) replaced the pair, the unique index is now
`(section_id, subject_id, academic_term_id)`, `school_year` is kept as the
join key every academic table carries (design decision 4), and the two
original FKs went from cascade to **restrict** on delete. Migration
`2026_09_18_000002_make_section_subjects_term_specific`; every step is
guarded so a partial MySQL DDL failure can be re-run. Rehearsed forward,
rolled back, and forward again on a full copy of the live database before
being run live (backup `backups/backup_20260918_pre_offerings.sql`).

**Data migration — nothing was invented.** Pre-existing rows were expanded
into one row per term they covered, using `coversTerm()`'s exact old rule
(null pair = every term); a section that had any row was also implicitly
taking every core subject of its grade level under the old model, so those
were written as explicit rows for all three terms — preserving what the
section already resolved to, not changing it. The live pilot database had
zero rows, so both steps were no-ops there. **Nothing was copied into
terms from grades/assessments by the migration** — see the transition
rule below for how history is recorded, with an Admin in the loop.

**`Subject::forSection(Section $section, ?int $term = null)` — two paths.**
A section with ANY offering row for its school year is *term-managed*:
the answer is exactly its offering rows (core and elective alike; nothing
implied from grade level or track), for one term when `$term` is given or
the union of the year when it is null. A term with no rows resolves to an
EMPTY set — "No subjects are assigned to Narra for Term 2" is a real
answer, never silently filled from another term. A section with NO
offering rows keeps the **curriculum default**, byte-for-byte what it
resolved before this pass (core subjects of its grade level; k12_2013
specialization electives; nothing for sshs electives) and ignores
`$term`. This fallback exists so every section created before offerings
existed keeps working; Admin > Sections marks such a section "default",
the Admin dashboard's Data Health lists it (yellow), and Admin > Sections >
Subjects says so in words with the exact list.

**The transition rule (`SubjectOfferingService::assign()`).** The first
offering assigned to a section switches it to term-managed. At that moment
`syncFromAcademicHistory()` records, as offerings, every (subject, term)
the section already has grades, assessment items, or uploads for — read
from each record's own `grading_period`, never inferred — and the flash
message names them. On the live pilot this means Narra's first assignment
will also record General Mathematics (Term 1), because 23 Term 1
assessments exist for it; Oral Communication (no records) will NOT be
carried over — that is the Admin's decision to make, and the page shows
the current default list before they make it.

**Every consumer reads the term.** `SectionElectiveStatus::
expectedSubjectsForTerm()` is now literally `forSection($section, $term)`;
`isFullyConfigured()` is true for any term-managed section. Adviser
Assessments/Grades/Verify-all/Interventions, Principal Students and
Student detail, `computeInTermStatusCounts()`, `sectionCapacityBreakdown()`,
and the adviser dashboard's in-term rows all pass the term they are
showing. **Backend enforcement, not UI hiding:** every Adviser write path
(`detect`/`preview`/`import`/`storeItem`, `grades.store`, grade import,
`verify`, `verify-all`) resolves the subject through
`forSection($section, $gradingPeriod)` and refuses with
`SubjectOfferingService::notOfferedMessage()` — "Basic Calculus is not
assigned to Narra for Term 1." — the one wording, owned in one place.
`TermSpecificSubjectOfferingsTest` posts the crafted requests.

**Removal (STEP L).** `SectionSubject` uses `ProtectsAcademicHistory` with
`hasAcademicReferences()` overridden to the (section, subject, term, year)
tuple across grades, assessments, assessment_uploads, interventions, and
risk_results.weakest_subject_id. An offering with any of those cannot be
deleted (route AND model level, and the FKs agree); the page shows "Kept —
has records". "Stop offering it next term" is done by not assigning it
there — the Term 1 row and its history stay.

**Admin UI.** `Admin\SectionSubjectController` at
`/admin/sections/{section}/subjects?term=N` (Academic Year is the
section's own — sections are year-specific here — and is displayed, not
selected; the term is a tab). The Assign Subject form shows the selected
subject's **resolved** grading profile from
`GradingEngine::resolveWeightProfile()` (extracted from `computeGrade()`,
same resolution order: catalog row first, then `subject_group_weights`) —
display only; nothing typed here can change a weight. Subject-group
labels moved to `SubjectGroupWeight::LABELS`/`labelFor()` so Admin >
Subjects and this page share one map. **The bulk "Import Subject
Offerings" upload that used to sit here was REMOVED** — see "No custom
Subject Offerings spreadsheet" below.
`dss:check-integrity` now also reports an offering whose `school_year`
disagrees with its section/term, and academic records on a term-managed
section with no matching offering.

**Grades and assessments were NOT given an offering FK.** They already
carry `(student, subject, section, grading_period, school_year)` and their
unique indexes (`unique_grade_per_period`, `unique_assessment_per_period`)
already prevent a duplicate final grade / item per learner + subject +
period + year — verified, no schema change needed.

**Trend (STEP K) — two signals, never merged.** `DashboardAnalyticsService::
computeTrend()` is the OVERALL term trend (term averages, even when the
subject mix changed); `computeSameSubjectTrend()` compares only a subject
graded in both terms (`computeSubjectDeclines()` is now a filter over it);
`subjectCompositionBetween()` says exactly which subjects were shared and
which were not, and the Principal at-risk table and learner page say so in
words when the mix differs. `RiskFeatureExtractor` sends
`same_subject_trend_delta` and `subject_composition_changed` alongside the
existing `trend_delta` — `classify.py` ignores fields it does not read, so
the current model is untouched.

**Principal Subject Analysis** scopes by School Year + Academic Term +
Grade Level (new filter) + Section; the risk-result half now scopes by
`risk_results.section_id` (the section when classified), not
`students.section_id` (the current pointer). With one section and one term
selected it also lists subjects *offered* there with nothing recorded —
"offered, no evidence" is a different statement from "not offered this
term".

**ECR upload (STEP H).** `AssessmentUploadService::checkEcrMetadataMismatch()`
compares the workbook's INPUT DATA section name / course title / grade
level against the on-screen selection and shows a Verify-screen warning
naming both sides. A warning, not a re-route: those cells are
teacher-typed (the rule on conflicting evidence). The offering check
itself is a hard refusal.

**Birthdate (PART 1)** is gone from the learner record — model, both
StudentControllers, `StudentsImport`, the draft-roster export, both
Students views, `modal.js`, and the column
(`2026_09_18_000001_drop_birthdate_from_students_table`, guarded, no rows
touched). The learner format is `lrn,last_name,first_name,middle_name,
gender`; a legacy file still carrying a birthdate column imports fine (the
column is ignored). `StudentWithoutBirthdateTest` rolls the migration back,
seeds a learner with a birthdate and a grade, re-runs it, and proves both
survive.

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

The suite baseline is 1,071 passing, 0 skipped where Python is present
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
replacement coverage is named above). The Python suites are counted separately and run on their own:
`python analytics/test_classify.py` (6), `test_training_pipeline.py` (62),
and `test_inference_contract.py` (33) — 101 total, all passing, none wired
into `php artisan test`.

One conditional skip exists and is deliberate: `MlArchitectureBoundaryTest`'s
two end-to-end tests shell out to the real Python interpreter and skip if it
is not runnable, since a machine without Python can still run the PHP suite
honestly. Both RUN (and must pass) in any environment that has the analytics
dependencies installed, which is where the 0-skipped figure above is measured.

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
E-Class Record catalog assigns the split per subject.

**Corrected count** (an earlier version of this note said nine TE-only
subjects and separately implied eight with no Examination component at
all — both wrong, and the same error: Work Immersion for Academic Track
was being counted in the TE-only group when it actually has no
Examination component at all). Verified directly against the seeded
catalog via `DepedSubjectCatalog::rowsFromCsv()`, not assumed from either
count:

- **Eight subjects are TE-only** — Term Exam at 100 with no summative
  tests, but an Examination component still exists: Arts Apprenticeship
  (Music, Theater Arts, Traditional Cultural Expressions, Visual Arts),
  Field Exposure (Off Campus), In-Campus Field Exposure for Sports (six
  Field Experience cluster rows), plus Advanced Mathematics and Basic
  Calculus (two STEM rows).
- **Nine subjects have no Examination component at all** (`ex_weight`
  null, not just `st1_share`/`st2_share` null) — Design and Innovation,
  Research 1, Research 2, Work Immersion for Academic Track, and the five
  Work Immersion for Tech-Pro Track variants (320h, 540h/1 term, 640h/1
  term, 540h/2 terms, 640h/2 terms). Two further rows, one per track's
  `OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM` placeholder, also carry
  `ex_weight` null in the catalog — these are structurally different
  (the teacher-typed weight comes from `INPUT DATA` at import time, not
  the catalog row) and are counted separately, not folded into either
  group above.

A subject whose catalog row has a null `st1_share` must not expect ST1 or ST2
evidence. This is the same distinction a null `ex_weight` already makes, one
level further down: null means the item does not exist for this subject, never
that it is worth zero.

Because these are seeded rows rather than code, correcting a share is an
`UPDATE`, never a deployment. `GradingEngine::examinationPercentage()` still
falls back to an equal split among whichever roles are present, so a missing
share is wrong rather than fatal.

This amends `HANDOFF.md` design decision 5, which stated the split as universal.

## RESOLVED (mechanism) — elective selection can now return a subset; WHICH electives get assigned is still blocked on Q1

**This was an open item in Known Limitations from before the "ECR
alignment" work order started. The mechanism half is closed as of PART 6**
(`section_subject` pivot, below) — `Subject::forSection()` can now return a
genuine SUBSET of a track's electives instead of always the whole cluster,
and every consumer of "how many grades should exist" agrees with it. What
remains open is not a limitation of this codebase, it's a question only the
school can answer (Q1, below) — nothing here is waiting on more code.

Before PART 6, `Subject::forSection()` returned EVERY elective subject
matching a section's track and specialization — it had no way to return a
SUBSET. Under the Strengthened SHS curriculum a Grade 11 learner picks two
electives from a cluster, not the whole cluster (e.g. a STEM section
offering Pre-Calculus, General Biology 1, and Physics might have some
students taking Pre-Calc + Biology and others taking Pre-Calc + Physics).
There was no `section_subject` (or `student_subject`) pivot table anywhere
in the schema to record which electives a given section — let alone a given
student — actually takes, so `forSection()` could not distinguish "offered
to this track/specialization" from "actually taken."

**Correction, "ECR alignment" work order PART 3b — there is no SQL bug
here.** An earlier draft of `ECR_ALIGNMENT_WORK_ORDER.md` described this as
`specialization_id = NULL` silently comparing false, "which is never true
in SQL," for a section with no `specialization_id`. That description was
wrong and has been corrected in that file too. Tested directly against
this codebase's actual Laravel version, both in isolation and through
`forSection()` itself:

```
select * from `subjects` where `specialization_id` is null
select * from `subjects` where `grade_level` = ? and (`type` = ? or (`type` = ? and `track_id` = ? and (`specialization_id` is null or `specialization_id` is null)))
```

Laravel's query builder converts `where('col', null)` to `IS NULL`
automatically. The query runs exactly as written; nothing here is broken
at the SQL level.

**The real problem is semantic, not syntactic, and it is why a pivot table
is required rather than merely convenient.** `specialization_id` carries
two different meanings depending on which curriculum a row belongs to.
Under the old 2013 curriculum, a subject's `specialization_id` means "this
belongs to the ABM strand" — a strand a *section chose to be*, so comparing
`section.specialization_id` to `subject.specialization_id` is a legitimate
"did this section choose this strand" test. Under the Strengthened SHS
curriculum, a subject's cluster (Arts/Social Sciences and Humanities,
STEM, etc.) is a property of the subject itself — *Citizenship and Civic
Engagement* is in Arts/Social Sciences and Humanities because of what it
is, not because any section "chose" that cluster the way a section chooses
a strand. An SSHS section has no `specialization_id` at all (SSHS has no
strands), so the comparison `section.specialization_id` against
`subject.specialization_id` is not merely unmatched for these subjects —
it is asking a question that doesn't apply to them. **Under SSHS, the
section-to-subject specialization match cannot be the elective-selection
mechanism, full stop** — not because of a SQL defect, but because the two
curricula overload one column with two different meanings, the same
disease the `curriculum` column on `specializations`/`sections` exists to
treat one level up. This is still fully **blocked on Part 6/Q1** exactly as
below — nothing here is buildable until the school answers whether Grade
11 electives are chosen per section or per learner.

**Why this is currently invisible:** only two STEM electives
(Pre-Calculus, General Biology 1) have ever been imported in this
codebase's fixtures/demo data. With exactly two electives in the
cluster, "every elective in the cluster" and "the two electives this
section takes" happen to be the same set by coincidence — there is
nothing to distinguish because there is no third option to leave out.

**What used to break when a third elective was added:** `Subject::
forSection()` would return all three, so `AcademicTerm::completionStatus()`
(which expects a grade for every subject `forSection()` returns, for every
student in the section) would require grades for all three electives from
every student — including the one they didn't take. No student could ever
supply that third grade, so `expected` would permanently exceed what
`actual` could reach and the term could never be marked complete. The same
over-counting would have shown up in `ReportController::submit()`'s
`totalExpected` check (blocking Submit Report the same way) and in
`getSectionSubjects()`'s duplicate copy of this same query. This was never
observed live — only two STEM electives ever existed in this database's
fixtures/demo data, so "every elective in the cluster" and "the two
electives this section takes" happened to be the same set by coincidence.
It is now fixed by construction, not by continued luck: see PART 6 below.

**CLOSED, "ECR alignment" work order PART 6 — the mechanism.** A
`section_subject` pivot table now records exactly which electives a given
section has chosen for a school year, and `Subject::forSection()`,
`AcademicTerm::completionStatus()`, `TermReadinessService`, `Adviser\
ReportController`, and `Adviser\DashboardController` all read from it
instead of "every matching elective" — see the two sections below for how.
`tests/Feature/ElectiveClusterLimitationTest.php`, previously marked
skipped specifically to document the assertion this pivot would need to
satisfy, is un-skipped and passing for real as of PART 6 — the historical
gap it documented no longer exists.

**STILL OPEN — not a code limitation, a question for the school.** The
pivot ships empty on purpose: there is nothing to assign until the school
answers **Q1** (does every learner in a Grade 11 section take the same
electives, or does each learner choose individually?) and sends a real
roster with real elective choices. Nobody should read the empty pivot as
unfinished work — see "A zero from `Subject::forSection()` means two
different things," below, for why an empty pivot on a section whose track
has electives available is correctly reported as `NOT READY`, not silently
treated as complete, until that data arrives.

**Why `section_subject`, not `student_subject`: a choice made under
uncertainty, not a finding.** `section_subject` was chosen as the starting
build specifically because it is the smaller, extensible option — not
because anything observed about the school suggests it works that way. If
Q1's answer turns out to be "per learner," `section_subject` →
`student_subject` is an addition on top of what exists (a section-level
pivot narrows `forSection()`'s candidate set; a student-level pivot narrows
it further, per student), not a reversal of a wrong guess. Recorded here in
exactly these terms so a panel question about why this shape was picked has
an honest answer — a decision made under uncertainty, not a claim that this
is how the school actually assigns electives.

## A zero from Subject::forSection() means two different things, and only one of them is "complete"

`Subject::forSection()` returning zero electives for a section is
ambiguous on its own — it could mean either of two genuinely different
situations, and collapsing them into one "zero" was exactly the bug PART
6 exists to fix, one level up from where it started:

- **Correct-zero**: this section's track has no elective subjects at all
  to offer. There is nothing to assign, so zero `section_subject` rows is
  the right, final answer — the same way this pilot database reads zero
  today, because zero elective `Subject` rows exist in it at all.
- **Unconfigured-zero**: this section's track *does* have electives
  available, but nobody has assigned any of them to this section yet.
  Zero `section_subject` rows here is not an answer, it's an absence of
  one — every section will sit in exactly this state until the school
  uploads a real roster and its elective choices, so this is the ordinary
  condition for a genuinely real section, not an edge case.

`SectionElectiveStatus::isFullyConfigured(Section $section): bool`
(`app/Services/SectionElectiveStatus.php`) is the one place this
distinction is drawn — `k12_2013` sections are always considered
configured (their specialization-based elective match was never broken,
see above), an `sshs` section with no electives available in its track is
correct-zero, and an `sshs` section with electives available but zero
`section_subject` rows is unconfigured-zero. Every consumer of "how many
grades should exist" (below) treats unconfigured-zero as **NOT READY** —
never as a core-only "complete," which would silently repeat the original
bug at the completion-checking layer instead of the subject-listing layer.
A future session reading "this section shows 0 expected electives" should
check which of these two states it actually is before assuming either one.

## "How many grades should exist" is one shared computation now, not four independent copies

Before PART 6, four call sites each computed `students->count() *
subjects->count()` independently: `AcademicTerm::completionStatus()`,
`TermReadinessService::assessmentEvidenceStatus()`, `Adviser\
ReportController::show()`/`submit()` (which also carried its own
byte-identical THIRD copy of `Subject::forSection()`'s query in a private
`getSectionSubjects()` method — removed, not updated in parallel; it now
calls `Subject::forSection()` directly), and `Adviser\
DashboardController::index()` — this last one is the consumer a
first-draft list of "the four consumers" missed, found only by grepping
for every `forSection()`/`totalExpected` call site rather than trusting
the list. All four, plus the view template `adviser/dashboard.blade.php`
itself (which had grown a FIFTH copy of the same arithmetic, recomputing
`$isComplete` from a flat per-term figure passed in from the controller),
now call `SectionElectiveStatus::expectedSubjectsForTerm()`/
`expectedGradeCount()` — one shared source of truth, not four (or five)
parallel copies kept in sync by hand. A change to what "expected" means
happens once, the same reason `InTermStatusService::
overallStatusForSection()` and `Intervention::scopeUndecided()` exist
above.

**A warning about where duplicated logic actually hides.** Going in, three
consumers were named. Grepping `app/` for `forSection(`/`totalExpected`
found a fourth (`DashboardController::index()`). Neither of those two steps
found the fifth — it was sitting inside `resources/views/adviser/
dashboard.blade.php` itself, recomputing `$isComplete` from a flat figure
the controller had already handed it, rather than in any controller or
service at all. **A duplicate-logic sweep that only greps `app/` will miss
copies that live in `resources/views/`.** Blade files can carry their own
arithmetic on data a controller already computed differently, and nothing
about a `.blade.php` extension makes that less likely than a `.php`
controller file — if anything, `compact()`-passed raw figures make it
easier, since the view has direct access to the same inputs and no
enforced reason to call back into a shared service instead of just doing
the sum itself. The next duplicate-computation sweep in this codebase
should grep `resources/views/` too, not just `app/`.

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

## Attendance is not part of any grade

Attendance exists in this system only as an intervention type
(`attendance_monitoring`) that a Principal can record. It is never
collected as data, never a grading component, and never a classifier
feature. Under DO 8, s. 2015 and DO 015, s. 2026 the grade is computed
from Written Work, Performance Task, and Examination only.
Attendance-based promotion and retention rules are outside this
system's scope.

## "Failing" catches the two CURRICULA at different levels of mastery

The Failing signal is defined on the reported (transmuted) grade at 74 and
below, which is DepEd's failing mark. Because the two schemes transmute
differently, the same threshold corresponds to a different raw score under
each — by CURRICULUM, not by grade level ("SSHS ECR grading correction",
2026-09-20: an unset curriculum in SY 2026-2027 is DO 015 for Grade 11 and
Grade 12 alike; only an explicit `k12_2013` section is on DO 8):

- **DO 015, s. 2026 (Strengthened SHS):** an Initial Grade of 70.00
  transmutes to 75, so Failing corresponds to a computed grade below 70.00.
- **DO 8, s. 2015 (explicit `k12_2013` sections):** an Initial Grade of
  60.00 transmutes to 75, so Failing corresponds to a computed grade below
  60.00.

A 2013-curriculum learner with 62% raw mastery is therefore reported as
passing and is never Failing, while an SSHS learner with the same raw
mastery is.
This is a property of the DepEd transmutation tables themselves, not of this
system, and it disappears from SY 2027-2028 when transmutation is removed.

## The ML module: inference and training are separate, and the deployed model is a labelled prototype

**"ML architecture correction" pass, 2026-09-19.** `analytics/README.md` is
the operational reference; this is the standing shape and the decisions that
must not be silently reversed.

**`classify.py` is INFERENCE ONLY.** It contains no training data, no
`.fit()`, no cross-validation and no report writer — `analytics/
test_inference_contract.py` greps the file for each of those and fails if one
comes back. It resolves a model in one order: the registry's ACTIVE model,
then the legacy prototype (`model_cache.pkl`), then a CONTROLLED ERROR. It
never trains one. A classifier that quietly rebuilds itself from hand-typed
grade bands whenever its artifact is missing is a silent correctness failure
wearing the costume of resilience.

**`analytics/train_model.py` is the single authoritative training pipeline.**
The duplicate `train_from_real_data()` that lived in `classify.py` is gone —
it spoke a DIFFERENT schema (7 features, `low`/`moderate`/`high`) from the one
`train_model.py`/`schema.py` use (9 features, `intervention`/
`no_intervention`), so "the training contract" had two incompatible answers
depending on which file you opened. Training produces a CANDIDATE and never
activates anything; promotion and rollback are explicit commands a person
runs (`python analytics/model_registry.py promote|rollback <version>`), and
`promote` REFUSES a candidate tagged `dataset_type=synthetic`.

**`analytics/schema.py` is the single source of truth for feature names and
ORDER.** Order is positional and load-bearing: a fitted forest indexes its
splits by column, so reordering the tuple without bumping
`FEATURE_SCHEMA_VERSION` would feed `pt_mean` into the column the model
learned as `ww_mean`. Nothing may hard-code a feature list beside it, and
`schema.build_feature_vector()` is the only supported way to turn a payload
into a row — specifically so nothing ever depends on dict iteration order.
`classify.py` refuses to serve a model whose declared schema major version
differs from the running one.

**BLANK IS NOT ZERO, end to end.** A null feature crosses to Python as JSON
null and becomes NaN, handled natively by scikit-learn's tree splitter
(>=1.4; this project runs 1.9). It is never imputed to 0, a mean, or
anything else. Three distinct cases this protects, all of which a 0 would
corrupt: a grading profile with NO Examination component (DO 015 Work
Immersion/Research/Design and Innovation, `ex_weight` null) is not an exam
scored 0; no previous reporting period is not a previous average of 0; and an
assessment nobody recorded is not a recorded 0. That last one is why
`missing_assessment_count` counts ABSENT ROWS — `assessment_scores.score` is
NOT NULL and unique per (assessment, student), so an entered zero is a row
that exists and an unrecorded assessment is a row that does not.

**The binary target must not be silently mapped onto the three risk levels.**
The candidate pipeline predicts `intervention`/`no_intervention`; the DSS
stores and displays `low`/`moderate`/`high`. These are different questions,
and `classify.py` raises `prediction_domain_mismatch` rather than translating.
Mapping `intervention -> high` would fabricate a severity the model never
predicted and erase `moderate` outright. **This is a prerequisite, not a
detail: a real candidate cannot go to production until it is resolved with
the school, however well it trains.**

**The rule layer stays separate and stays after the model.**
`Adviser\ReportController::applyFailingSubjectOverride()` is PHP, is not part
of training, and only ever escalates severity. `ml_risk_level`, `risk_level`
and `was_overridden` remain three distinct stored figures; collapsing them
would make "what did the model actually say" unrecoverable.
`tests/Feature/MlArchitectureBoundaryTest.php` is the test of record, and also
asserts no grading weight, passing threshold or transmutation band ever
reaches the Python payload.

**Why the deployed prototype is retained rather than deleted.** It is what
every adviser and Principal is using today and there is nothing to replace it
with — no authorized historical dataset exists. It is labelled for exactly
what it is in its descriptor (`analytics/legacy/legacy_model.json`), in every
prediction it returns (`"dataset_type": "synthetic"`), and in
`analytics/README.md`. Its generator was MOVED, not deleted, to
`analytics/legacy/prototype_model.py`: keeping the code visible is what lets
"the labels were predetermined" be checked rather than taken on trust, and
that script cannot write `model_cache.pkl` — regenerating the deployed
artifact is a deliberate operator action with an explicit target.

**`analytics/model_accuracy.txt` is a GENERATED report.** No runtime code
reads it; it is git-ignored; regenerate with `python analytics/legacy/
prototype_model.py --report-only`. `model_cache.pkl` is deliberately NOT
ignored — a Git deployment needs it.

## The risk classifier's levels are calibrated thresholds, not a learned signal

Because the model is trained on a single feature, its risk levels are
effectively thresholds on average grade. Calibration determines the
boundaries between low, moderate, and high, and different cohorts may
require different boundaries. The system does not learn these boundaries
from outcomes.

As of the "correctness and interface pass," the boundaries are Low
85-100, Moderate 75-84.9, High 0-74.9 (`analytics/legacy/
prototype_model.py`'s `BANDS`, moved there from `classify.py` by the
2026-09-19 ML architecture pass) — recalibrated from the original 90/75/60
split, which put
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

## Term-over-Term Progress depends on the order a Principal acted in, permanently

`Intervention::risk_result_id` is resolved once, at creation
(`Principal\InterventionController::store()`/`storeBulk()`), by looking up a
`RiskResult` for the same student/term/school year at that moment. It is
never re-resolved afterward — no job, no term-submission hook, nothing
revisits it. If no `RiskResult` existed yet when the intervention was
recorded, the column stays null forever, and
`ProgressMonitoringService::compare()` returns `'not_applicable'` for that
intervention permanently, no matter how many term reports get submitted
after the fact.

This is deliberate, not an oversight — the same "captured once, never
recomputed" choice this codebase already made for `focus_component`, and for
the same reason: a value that could silently change out from under a
Principal's original decision is worse than one that stays fixed to what the
Principal actually had in front of them when they acted. Re-resolving
`risk_result_id` at read time was considered and rejected: it would collapse
the distinction `ProgressMonitoringService`'s `'not_applicable'` status was
built to preserve (`Part 1: Term-over-Term Progress says why, not just "not
yet"`) — "recorded from in-term evidence, never had a baseline" versus
"recorded from a term report" — and would make the compared component
silently drift the same way `focus_component` was deliberately frozen to
prevent.

**The consequence a Principal cannot see:** recording an intervention from
in-term evidence *before* submitting that term's report costs term-over-term
comparison for that intervention forever — not "not yet," permanently. The
Interventions page's Term-over-Term Progress column says so plainly once you
look ("Term-over-term comparison applies only to interventions raised from a
submitted term report — this one was raised from in-term evidence"), but
nothing says so at the moment of recording, before the choice is made.

**A record-time warning in the record-intervention modal was considered and
rejected.** The real pilot data settles it: every intervention ever recorded
in this database — 29 of 29 as of the commit that built the honest-status
column, 6 of 6 recorded the night this gap was found — has a null
`risk_result_id`. Recording from in-term evidence, before that term's report
exists, isn't the edge case such a note would be warning about; it is
effectively the only case that happens in practice, since Submit Report is an
end-of-term action and interventions get recorded during the term precisely
because a Principal doesn't want to wait. A note that fires on ~100% of
recordings stops functioning as information — it becomes wallpaper the
Principal stops reading within the first few uses, and the genuinely rare
case where waiting costs nothing gets buried in the same noise as the normal
case. Worse, even carefully worded, it reads as a nudge toward delay, which
fights the system's own stance that in-term evidence is already enough
reason to act (see `InterventionController::store()`'s own docblock). If this
is revisited, the one version that might be defensible is conditional, not
constant — show it only when a `RiskResult` doesn't yet exist for this
student/term but an earlier term this school year already has one, so it
appears only for a Principal who has demonstrably had the option, not
universally. Not built; flagged so a future session finds the reasoning
already made rather than re-deriving it.

## A new specialization can silently fall out of the curriculum split

`specializations.curriculum` (Part 3a) is nullable, deliberately — three live
creation paths, `Admin\SpecializationController::store()` and the
`SpecializationsImport`/`TracksImport` bulk-import classes, have no
curriculum concept in their form or file format at all, so making the column
`NOT NULL` would have meant fabricating a classification rather than honestly
recording "not yet known." That was the right call for the 13 existing rows,
which are all correctly backfilled. It has a cost going forward: **any
specialization created through any of those three paths from today onward
gets `curriculum = null` and silently falls out of the very split Part 3
exists to make** — nothing warns an admin that the row they just created
isn't classified, and nothing stops the same ambiguity (an SSHS cluster or a
2013 strand sharing a code) from recurring one row at a time.

This is the same shape `subjects.subject_group` used to have before the
"subject classification and grading weights cleanup" pass: a nullable column,
no UI forcing a value, quietly defaulting instead of asking. That pass went
further than "make it visible" for subjects — it removed the default outright
(`SubjectGroupWeight::classificationError()` now rejects a Grade 11 subject
with no `subject_group` rather than defaulting one), which is why an
unclassified Grade 11 subject can no longer exist at all going forward. This
same fix has not yet been applied to `curriculum` on newly-created
specializations — no equivalent rejection, no data-health check, no
import-panel notice, nothing surfacing it today. See
`ECR_ALIGNMENT_WORK_ORDER.md` Part 7, which requires `curriculum` to be set
explicitly on every specialization it touches, alongside `sections.curriculum`.

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

# UI DESIGN SYSTEM

## Empty-state hints are only reachable on a fresh install

`<x-empty-state>`'s `hint` prop only renders when the table it's inside has
zero rows. On a populated database — the pilot database included — no admin
ever sees it, which is exactly how a double-escaped-entities bug in six of
these hints (`&quot;` written into a `hint="..."` attribute, then re-escaped
a second time by the component's own `{{ $hint }}`) went unnoticed until a
dedicated test pass on a clean database caught it. Checking an empty-state
hint's actual rendered output requires testing against a fresh/empty
database, not the pilot one — a populated table will never exercise this
code path at all.

## The biggest first-time-reader question is already answered twice, deliberately, not duplicated a third time

"UI legibility pass" item 4 checked whether a first-time reader (a thesis
panel, someone new to the system) could tell what each dashboard is showing
them within about thirty seconds. The single most likely point of confusion
— what's the difference between In-Term Status and Risk Level, the two
numbers that look like they should be the same thing and aren't — is
already solved, in two different but deliberate ways, and neither needed
changing:

- **Principal dashboard**: `partials/in-term-vs-risk-help.blade.php` is
  included *always visible*, near the top of the page, not behind a
  `<details>` — two short paragraphs, one per signal, shared with the
  Adviser dashboard so the wording can never drift between them.
- **Adviser dashboard**: the same partial is included, but behind a
  collapsed `<details>` ("What's the difference between In-Term Status and
  Risk Level?") — deliberately different from the Principal's
  always-visible placement, because the Adviser dashboard leads with a
  "What Needs Your Attention Now" action list and inline explanatory
  sentences above its own status table; the terminology question is one
  click away rather than competing with what the adviser is there to do.

If a future legibility pass finds this question still unanswered somewhere,
the fix is almost certainly wiring in the existing shared partial, not
writing a third explanation — a fourth place explaining the same
distinction is exactly the kind of drift this partial exists to prevent.

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
## "UI modernization pass" (2026-09-14) — the design system lives in `resources/css/app.css`

Supersedes the raw-utility conventions above where they disagree; the
older notes are kept for their reasoning (status vs count colour, one
table component), which still holds.

- **Tokens** (`tailwind.config.js`): `brand` green rebased on `#1F6B2A`
  (800, primary) / `#3FAE4D` (500, accent); neutrals `ink #18212F`,
  `muted #667085`, `surface #F5F7FA`, `line #E6EAF0`; semantic
  `success/warning/danger/info` each with `.soft` and `.text` shades;
  `status.*` and `count.*` unchanged in meaning. Shadows `shadow-card`,
  `shadow-card-hover`, `shadow-modal`. Green is identity only; statuses use
  the semantic tokens; `status.*` stays reserved for the four DSS states.
- **Component classes** (`@layer components` in `app.css`) — use these,
  never a fresh utility string: `page-header/page-title/page-subtitle/
  page-eyebrow`, `section-title/section-subtitle`, `card/card-header/
  card-body/card-footer/card-title/card-subtitle/card-hover`, `stat-card/
  stat-label/stat-value/stat-note`, `icon-box(-sm) icon-box-{brand|info|
  success|warning|danger|slate|violet|teal|cyan}`, `btn btn-{primary|
  secondary|outline|ghost|danger|danger-outline|warning|link} btn-{xs|sm|lg}`,
  `badge badge-{success|warning|danger|info|gray|brand|outline}`, `pill`,
  `filter-bar/filter-field`, `form-label/form-input/form-select/
  form-select-sm/form-help/form-error/form-readonly`, `progress/progress-bar`,
  `alert alert-{info|success|warning|danger}` (+ `alert-row` for icon+text),
  `nav-group-label/nav-link/nav-link-active`, `empty-state*`, `.tbl*`.
- **Blade components**: `x-stat-card` (same API; accent now tints a soft
  icon box, no coloured border), `x-panel` (+ `:padded="false"`),
  `x-empty-state` (icon box + title + hint), and new `x-ui.status-badge`,
  `x-ui.progress-bar` (value is always a backend/view-computed figure —
  `auto` colours by value), `x-ui.filter-bar`, `x-ui.section-card`,
  `x-ui.action-card`, `x-ui.metric-bar` (value + bar, 75-target colour rule).
- **Button semantics**: primary = Add/Save/Submit/Import/Activate/Open;
  secondary = Edit/Manage; outline = View/Cancel/Close Term; danger ONLY for
  delete/deactivate. "Record interventions in bulk" and "Activate" were red
  before this pass and are not any more.
- **Modals**: every dialog box is `.modal-box` (viewport-safe, scrolls
  inside itself). `showModal()` moves focus into the dialog and returns it
  on close; Escape closes the top-most one. Confirmations: Delete (red,
  `data-confirm`), Import (`data-import-confirm`), Re-submit
  (`data-resubmit`), and the neutral action modal (`data-action-confirm` +
  `data-action-title/label/icon/loading`) — never the Delete modal for a
  non-delete action.
- **Processing state**: any submitting form gets its submit button disabled
  and relabelled ("Processing..." or the form's `data-loading`); forms that
  submit via fetch opt out with `data-no-loading`.
- **Sidebar**: navigation is a data array in `layouts/app.blade.php`,
  grouped per role (Overview / Management / Academic / System, etc.); the
  page header shows a greeting plus School Year / open-term pills read from
  `Section::activeSchoolYear()` and `AcademicTerm::currentOpenTerm()`.
- **Offline**: Bootstrap Icons and Chart.js are local (`public/vendor`); the
  guest layout no longer loads a remote font. The one dashboard chart file is
  `public/js/admin/dashboard.js` (semantic colours, horizontal section bars,
  component-performance bars fed by `Principal\DashboardController::
  componentPerformance()` — a mean of `SubjectAnalysisService` figures,
  never a JS-side calculation).
- Layout-structure strings asserted by `FixedSidebarLayoutTest` (`flex
  h-screen overflow-hidden`, the aside's drawer classes, `flex-1 p-6
  overflow-y-auto`) and the `confirmImportBtn`/`confirmDeleteBtn` colour
  classes must survive any future restyle.


## Pre-demo audit pass (2026-09-17) — standing conventions it added

Full findings and evidence are in `PRE_DEMO_FINAL_AUDIT.md`; these are the
rules that stay true after it.

- **One favicon source: `<x-app-favicon />`** (`resources/views/components/
  app-favicon.blade.php`). Every layout — `layouts/app`, `layouts/guest`,
  `auth/login`, `errors/layout` — includes it; none carries its own
  `<link rel="icon">`. The icon files (`public/images/favicon-32.png`,
  `favicon-192.png`, `apple-touch-icon.png`, and a real 32x32 PNG-in-ICO
  `public/favicon.ico`) are derived from `public/images/nagga-logo.png`;
  regenerate all four from that file if the logo ever changes. Before this
  pass only the login page declared an icon and `favicon.ico` was a
  zero-byte placeholder, so the tab icon vanished on sign-in.
  `FaviconConsistencyTest` pins one identical declaration on every layout.
- **Error pages live in `resources/views/errors/`** (`403`, `404`, `419`,
  `500`, `503`), all extending `errors/layout` — a self-contained shell
  that deliberately does NOT extend `layouts/app` (which needs
  `auth()->user()` for the sidebar). Add a new HTTP error page there, never
  by reaching for the app layout. `ErrorPagesTest` is the test of record.
- **Edit-modal form actions are route-derived, never hardcoded.** Every
  Edit form carries `data-update-url="{{ route('x.update', ['id' =>
  '__ID__']) }}"` and `resources/js/modal.js` resolves it through
  `window.updateUrlFor(form, id)`. The old ``form.action = `/admin/users/${id}` ``
  pattern posts to the wrong host root under the XAMPP sub-directory
  deployment (`http://localhost/naggasican-dss/public/`) and must not come
  back — `EditFormActionsAreRouteDerivedTest` greps `modal.js` for it.
- **Abandoned temp uploads are pruned on the next upload.**
  `App\Services\TempUploadPruner::prune($dir)` runs at the start of
  `Adviser\AssessmentController::detect()` and `Admin\StudentController::
  importFromEcrPreview()`, removing files older than 24 h from that flow's
  own `storage/app/private/temp_*` directory. No scheduler is involved on
  purpose (none exists in this deployment). A third multi-step upload flow
  must call it too.
- **`tests/TestCase.php` fakes the `local` disk for every test.** Upload
  tests used to write real files into `storage/app/private/temp_*` on each
  run (830 of them had accumulated). A test that genuinely needs the real
  disk must opt out explicitly and say why.
- **Classifier output is validated as a whole before any `RiskResult` is
  written** — `Adviser\ReportController::classifierOutputIsValid()`
  (public, like `buildPythonPayload()`/`applyFailingSubjectOverride()`,
  so it is unit-testable without a fake interpreter). `confidence`, when
  present, must be numeric in 0–100; any defect fails the whole analysis
  and the adviser sees the existing "risk analysis failed to generate"
  warning. Never persist a partial result set.
- **`exam_role_shares` 30/30/40 is the FALLBACK, not "provisional data
  nobody checked."** The per-subject shares live on `deped_subject_catalog`
  (from DepEd's own ECR workbook) and win first; the table is reached only
  for a `do015_2026` subject with no catalog link. The seeder's docblock
  now says exactly this — the older "PROVISIONAL" wording in git history
  is superseded. The remaining, honest limitation is that neither source
  has been checked against the signed PDF of DO 015, s. 2026.
- **No `{!! !!}` in `resources/views`** — the last one (Admin Data Health
  panel, static strings only) was converted to `{{ }}`; keep it at zero.
- **`public/robots.txt` disallows everything.** Nothing here is public
  content; error pages also carry `<meta name="robots" content="noindex">`.
- **Every long-running form names its own loading label** via
  `data-loading="…"` (Signing in…, Importing sections…, Validating E-Class
  Record…, Recording intervention…); the generic "Processing..." fallback
  is for forms nobody has thought about yet, not a target state.
- Removed as verified-dead (zero `<x-…>` usages anywhere in `resources/`,
  `app/`, `tests/`): the Breeze leftovers `components/{danger-button,
  dropdown, dropdown-link, modal, nav-link, responsive-nav-link,
  secondary-button, application-logo}.blade.php` and
  `app/View/Components/AppLayout.php` (every page uses
  `@extends('layouts.app')`, never `<x-app-layout>`). `GuestLayout.php`
  stays — four auth views use `<x-guest-layout>`.

## Grading policy display (2026-09-20) — one resolver, and what Admin > Subjects shows

**The resolver.** `GradingEngine::resolveWeightProfile(Section, Subject)` is
THE grading-policy resolver — the exact path `computeGrade()` takes:
linked `deped_subject_catalog` row first; else the scheme's
`subject_group_weights` row, where the scheme comes from the section
(`TransmutationService::schemeFor()`), the DO 015 key is the subject's
`subject_group`, and the DO 8 key is `resolveDo8GroupKey(section, subject)`
(section track → Academic/TVL branch; core → `do8_core`; elective → name
keyword `work immersion`/`research`/`business enterprise simulation` →
`*_work_immersion`, else `*_other`). Every consumer now reads it:
`computeGrade()`, `PerformanceAnalysisService` (Adviser Assessments),
`GradeController` (Encode Grades / verify), `RiskFeatureExtractor`,
`SectionSubjectController`, `AssessmentUploadService::
checkGrade12WeightMismatch()` (the Grade 12 ECR check — it used to call
`resolveDo8GroupKey()` + `SubjectGroupWeight::resolve()` itself),
`DashboardAnalyticsService::computeAssessmentEvidenceTrend()` (it used to
call `SubjectGroupWeight::resolve($scheme, $subject_group)` directly,
which skipped the catalog for Grade 11 and put EVERY Grade 12 bucket on
the DO 8 `all` row instead of its track bucket), and Admin > Subjects.
**Do not call `SubjectGroupWeight::resolve()` for a grading figure
anywhere else** — `Subject::withSuspectSubjectGroup()`'s catalog-vs-group
comparison is the one legitimate direct use (it is checking the stored
group, not grading).

**`GradingEngine::resolveSubjectProfile(Subject)`** is the subject-level
entry point Admin > Subjects uses (no section on that page). It is not a
second resolver: it calls `resolveWeightProfile()` once per section
context the subject can be graded in — an elective's own track; every
track in the system for a core subject; a null-track pseudo-section when
no track exists — and reports `resolved` only when all contexts agree.
The page then shows the figures with `data-grading-key` and a source
note (`From DepEd catalog match` / `Assigned by grading group` /
`Resolved from track and subject type`); when contexts disagree (a Grade
12 core subject once a TVL track exists) it shows **"Resolved by section
context"** with every variant, never one picked figure. The old
`do8_by_track` placeholder is gone. `SubjectGroupWeight::LABELS` now
names the five `do8_*` buckets and `all` so a DO 8 profile can be
labelled in words; `allGroups()` is still scoped to `do015_2026`, so none
of them reaches a dropdown.

**Root cause of the missing Grade 12 figures was display-only** — the
resolver was complete; `Admin\SubjectController::index()` carried its own
Grade 11-only copy and stamped Grade 12 rows with a placeholder. (The
figures that pass first produced for Grade 12 — 25/45/30 — came from the
DO 8 inference corrected in the next section.)

## SSHS ECR grading correction (2026-09-20) — Grade 12 in SY 2026-2027 is DO 015 unless a section says otherwise

**What the prescribed workbook actually says** (read from
`tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx`, byte-identical to
the file the school supplied): `INSTRUCTIONS!E99:I108` is the "Weight of
the Components for the SSHS" table — Core 20/50/30; Academic Electives /
All Other 20/50/30; Research and Design and Innovation 40/60/—; Arts,
Sports, Health and Wellness 20/60/20; Field Experience 15/70/*15; TechPro
All Other 15/65/20; Work Immersion 20/80/— — "pursuant to DepEd Order 015,
s. 2026" (`C89`). `INPUT DATA!F24` (GRADE LEVEL) validates `"11,12"`. The
Term sheets' weight cells (`Term 1!D12/Q12/AD12`) are `XLOOKUP`s into
`HELPER!W:Y` keyed on CLUSTER + COURSE TITLE (`HELPER!G13:G15`); the grade
level only filters which subjects are selectable (`HELPER!I`). **Weights
are per cluster and identical for Grade 11 and Grade 12.** The seeded
`do015_2026` rows equal the table cell for cell
(`GradingPolicyResolutionTest` reads the workbook as its oracle).

**Why the app said 25/45/30.** `TransmutationService::schemeFor()` inferred
`k12_2013`/DO 8 for any Grade 12 section with a NULL curriculum ("Grade 12
has NOT moved" — the client communication recorded above as not verified),
and every section-less Grade 12 context (Admin > Subjects, the ECR checks)
was NULL. `resolveDo8GroupKey()` then produced `do8_academic_other` =
25/45/30 from the `do8_*` rows, which are themselves from secondary
reproductions. No SY 2026-2027 artifact in the repository is a DO 8
(quarterly) record: the school's Grade 12 record
(`tests/Fixtures/GRADE-12-SANITIZED.xlsx`, 12-AGILA) is term-based with
SSHS component names and a 20/60/20 split for a HUMSS elective.

**The rule now.** `schemeFor($gradeLevel, $schoolYear, $curriculum)`:
explicit `sshs` → DO 015; explicit `k12_2013` → DO 8; NULL → by school
year only: 2026-2027 onward DO 015 for both grade levels, earlier DO 8.
Grade level no longer decides a scheme anywhere. DO 8 (`resolveDo8GroupKey()`,
the five `do8_*` rows, the DO 8 transmutation table) is fully preserved
for explicit `k12_2013` sections and pre-2026 years; the 31 Grade 12
sections in the DO 8-intent test files carry `'curriculum' => 'k12_2013'`
explicitly for that reason. `fallbackActiveFor()` takes the curriculum too.

**Admin > Subjects** (`GradingEngine::resolveSubjectProfile()`) evaluates
the DISTINCT curricula of the sections that exist at the subject's grade
level in the active year (NULL = inferred), times the tracks; one figure
when they agree, "Resolved by section context" with every variant when a
`k12_2013` section coexists with an SSHS/unset one. Business Finance
(`academic_other`) shows 20/50/30 and Philippine Politics and Governance
(`arts_sports_wellness`) 20/60/20 — both consistent with their catalog
clusters (BUSINESS AND ENTREPRENEURSHIP 20/50/30; ARTS, SOCIAL SCIENCES,
AND HUMANITIES 20/60/20), though neither is catalog-linked because their
names differ from the catalog titles ("Business 2 (Business Finance and
Income Taxation)", "Philippine Governance (Philippine Politics and
Governance)") — linking is a human decision.

**ECR weight conflicts now REFUSE the upload.** `EcrReaderService::
checkWeightMismatch()` resolves the configured subject through
`resolveWeightProfile()` on an `sshs` context (a prescribed SSHS ECR is
SSHS by definition) and compares it with the workbook's own catalog row
(cluster + title, exactly as the term sheets do). A contradiction, and a
Grade 12 (AGILA) template whose declared split disagrees, block at
`detect()` with both figures named and the temp file removed; the file
never reaches Verify. The OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM note
(DepEd publishes no weight) stays informational
(`AssessmentUploadService::ecrWeightMismatchBlocks()`). Master data is
never rewritten by an upload.

**Historical impact: none.** All 252 verified grades are Grade 11 (already
DO 015); zero Grade 12 grades/assessments/uploads exist;
`dss:recompute-grades 2026-2027 1` reports 0 of 252 affected. Backup
`backups/backup_20260920_pre_scheme_inference.sql` taken before the change.

**REQUIRES BUSINESS-RULE CONFIRMATION:** (1) whether ANY Grade 12 section
at this school in SY 2026-2027 is genuinely on the 2013 curriculum — if
so, set `curriculum = k12_2013` on it (Admin > Sections) before its
grades are entered; (2) the DO 8 `do8_*` figures and the keyword rule for
its Work-Immersion bucket (unchanged, used only by explicit `k12_2013`
sections); (3) the Grade 12 AGILA record's transmutation — its sanitized
IG→TG pairs match neither seeded table, so they are not usable evidence.

## Pre-demo full-system audit (2026-09-20) — standing conventions it added

Findings and evidence are in the audit report delivered with the pass;
these are the rules that stay true afterwards.

- **`RoleAccessMatrixTest` is the authorization test of record.** It walks
  the live route table: every GET route under `admin/`, `adviser/` or
  `principal/` must answer its own role (200/302/404), 403 to the other
  two, and redirect a guest AND a disabled session to login; a second
  test probes cross-object mutations (adviser B against adviser A's
  learner, assessment, intervention; adviser/principal against Admin
  mutations; unsigned access to Laravel's local-disk serve routes). A new
  role-prefixed route that forgets its middleware fails here, so do not
  hand-list routes in it — it reads `Route::getRoutes()`.
- **Every page that can receive a default-bag validation error renders
  `partials/validation-errors`** (Adviser Assessments/Grades, Admin
  Tracks/Specializations, Principal Interventions; Admin Subjects has its
  own equivalent). Before this, a rejected upload type, duplicate track
  or out-of-range grade bounced back with no message — the layout renders
  only the `deletion` key. A new page with a form includes the partial;
  named bags (`addItem`, `editItem`) stay with their modals.
- **An unreadable spreadsheet is a controlled refusal.** `spreadsheetFileRule`
  validates by extension on purpose (see the `mimes:` section), so a
  corrupt/truncated `.xlsx` reaches the reader; `Adviser\AssessmentController::
  detect()` catches `PhpOffice\PhpSpreadsheet\Exception` (and `ValueError`)
  around the identity check and column detection, deletes the temp file
  and flashes "could not be read as a spreadsheet". It used to be a 500.
- **Two invariants gained DB constraints** (`2026_09_20_000002`): unique
  `subjects (name, grade_level)` and unique `sections (name, grade_level,
  school_year)`; `Admin\SectionController` now validates the latter too
  (no rule existed — a duplicate "Curie" in one year was accepted). The
  migration refuses to run if duplicates exist rather than failing
  half-way. `tracks` keeps its form-only rule: `TrackFactory` uses a
  constant name/code and tests create several.
- **Breeze's email-verification, confirm-password and `PUT /password`
  routes are gone** (controllers, views, the two orphan
  `profile/partials/*` and their tests). `User` never implemented
  `MustVerifyEmail`; the profile modal (`profile.update`,
  `profile.password.update`) is the only account-editing surface. The
  forgot-password routes remain (guest-only, `MAIL_MAILER=log` locally —
  nothing links to them; an Admin resets a password from Users).
- **`PreventBackHistory` also sets `X-Frame-Options: SAMEORIGIN`,
  `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`.**
  The app embeds no frames.
- **Vite: `public/hot` must not exist when the demo runs.** With it
  present every page loads assets from the dev server (`[::1]:5173`) and
  renders unstyled if that server is down. Stop `npm run dev` and delete
  the file; `public/build` is what the demo serves.
- **The live Term 1 reports of 2026-09-19 had no risk results** — both
  Submit Report runs that evening logged `Analytics process failed
  {"exit_code":1}` while the ML refactor was in progress (the failure was
  logged before stderr capture existed). Re-submitted through the real
  workflow on 2026-09-20 after a backup: 84 rows. `RiskResult` being
  empty while `report_submissions` exist is the signature to look for.

## Final pre-demo audit (2026-09-20, evening) — standing conventions it added

Second full-system pass, run over real HTTP against Apache and the live
database (backup `backups/backup_20260920_final_audit_pre.sql` first).
These are the rules that stay true afterwards.

- **The test suite refuses to run against anything but sqlite `:memory:`.**
  `tests/TestCase::setUpTraits()` checks the connection BEFORE
  `RefreshDatabase` fires and exits 255 otherwise. Reason: a
  `vendor/bin/phpunit --no-configuration` invocation during this audit
  skipped `phpunit.xml`'s `<env>` block and `migrate:fresh` emptied the
  LIVE `naggasican_dss` database (restored losslessly from the backup
  above — every academic table matched the snapshot). Always run tests
  as `php artisan test` or `vendor/bin/phpunit -c phpunit.xml`, take a
  mysqldump first, and never remove that guard.
- **`profile/_modal.blade.php` reads NAMED error bags only** (`profile`,
  `profilePassword`; `ProfileController` uses `validateWithBag()`). It is
  included on every page and used to open on ANY default-bag error and
  call `$errors->only()` — not a `MessageBag` method — so a rejected
  Admin > Users or Admin > Students form (keys `last_name`/`first_name`/
  `email`/`password`) was a 500 (64 log entries since 09-08). `ProfileTest`
  pins both behaviours. A new shared partial must never key its
  open-state off the default bag.
- **A Blade `@json(...)` argument must not contain a comma.** Blade splits
  the directive on commas, so `@json($section->load(["track",
  "specialization"]))` compiled to `json_encode(..., 512)` — no
  `JSON_HEX_APOS`/`HEX_TAG` — and a section name with a quote broke out
  of the `onclick='...'` attribute (a real stored XSS on Admin > Sections;
  the other ten `onclick='fn(@json($x))'` sites are comma-free and safe).
  Eager-load in the controller and pass one variable.
  `XssEscapingAcrossRolePagesTest` renders a markup + quote-breakout
  payload on 19 role pages and statically rejects any comma-bearing
  `@json`.
- **A no-role Examination item is named, not silently dropped.**
  `GradingEngine::examinationPercentage()` excludes an item with no
  `exam_role` once any item carries one (unchanged — DO 015's Examination
  is ST1/ST2/TE). The live demo held 18 "Additional Practice EX" items
  whose scores counted for nothing; Adviser > Assessments now lists them
  in an amber notice (`$unroledExamItemNames`,
  `AssessmentPerformancePageTest`). Grading did not change.
- **A repeat upload says which items already exist.** `import()` was
  already idempotent (item matched by name, scores replaced, blank cells
  keep the old score); the Preview now names the existing items and says
  scores will be replaced (`$existingItemNames`,
  `AssessmentUploadWorkflowTest`).
- **Adviser > My Students renders `partials/validation-errors`** — the one
  form page the 2026-09-20 morning pass missed (a rejected Edit Student
  bounced back silently). `PreDemoAuditRegressionsTest`.
- **`LoginRequest`'s disabled-account refusal redirects to `route('login')`
  explicitly** — `session()->invalidate()` wipes the previous URL, so a
  plain `back()` depended on the browser's Referer header to land on
  /login with the message intact.
- The Admin dashboard's "Import Subjects" quick action (an upload removed
  with the applicability refactor) is now "Manage Subjects".

**Recorded, deliberately NOT changed (business-rule / data questions):**

- `RiskFeatureExtractor::missingAssessmentCount()` counts EVERY item in
  the section/term, including additional-support items only a subset of
  learners were meant to take — every non-remediated Curie learner reads
  27 "missing". The active single-feature prototype ignores it, so no
  live result is affected; a real candidate model would not. Whether an
  additional-support item is "expected" for a learner outside the
  intervention is the school's call. Feature definitions stay frozen
  (`PerformanceBatchEquivalenceTest`).
- The operator created "Philippine History and Society" (Grade 11 core,
  all three terms) at 15:33 on 2026-09-20, AFTER both Term 1 reports were
  submitted. Every Grade 11 section now expects 168 grades for Term 1,
  has 126, and Submit Report refuses a re-submit ("42 grade(s)
  remaining") — correct behaviour, wrong demo state. Upload its ECR for
  both sections, or narrow/remove the subject (it has zero records),
  before the demo. Its catalog title is "Pag-aaral ng Kasaysayan at
  Lipunang Pilipino", so it is not catalog-linked either.
- The two live Grade 11 sections carry `specialization_id` (HUMSS/STEM)
  with `curriculum = NULL`. SSHS has no strands; harmless today (no
  Grade 11 electives exist, and `usesTrackElectives()` only matters for
  electives) but it should be cleared or the curriculum set explicitly.

## Performance audit (2026-09-19) — standing conventions it added

Measured first (an in-process HTTP harness over a throwaway copy of the
live database, counting SQL, duplicate SQL, memory and Python launches per
request), then fixed. Full figures are in the pass's report; these are the
rules that stay true afterwards.

- **`GradingEngine` reads evidence ONCE per (section, term, school year)
  per instance, never once per `computeGrade()` call.** `evidenceFor()`
  loads every assessment item in that scope and every score against them
  (two queries) and casts `score`/`max_score` to float once at load time;
  `componentPercentage()`/`examinationPercentage()` filter that in memory
  with the exact predicates the per-call queries used. Before this,
  `computeGrade()` ran ~7 queries per call and the Principal dashboard
  made 1,076 calls — 7,715 queries and 14.9 s per page load; after, 67
  queries and 0.18 s. **Do not add a query inside `computeGrade()`'s call
  path.** A new evidence read belongs in `evidenceFor()`'s two loads and a
  new accessor beside `scoredItemCount()` / `scoredItems()`.
- **Staleness rule.** The caches (engine evidence, engine reference memo,
  `TransmutationService`'s per-scheme bands) are instance-scoped and
  checked against one process-wide generation counter. Every Eloquent
  save/delete on `Assessment`, `AssessmentScore`, `SubjectGroupWeight`,
  `ExamRoleShare`, `TransmutationRange` and `DepedSubjectCatalog` bumps it
  (`AppServiceProvider::boot()`). **A write to those tables that bypasses
  model events (query-builder `update`/`delete`/`insert`, raw SQL) must call
  `GradingEngine::invalidateEvidence()` itself** — the one such write in
  the app (`Adviser\AssessmentController::updateItem()`'s delete) does.
  `PerformanceBatchEquivalenceTest` proves a same-instance write is seen.
- **`PerformanceAnalysisService::analyzeStudent()` carries `item_count`**
  (from the engine's evidence); `InTermStatusService::fromAnalysis()` reads
  it instead of running its own per-row count, and still runs the query
  for a hand-built analysis array without it. `ProgressMonitoringService`
  reads its as-of (`created_at <= delivered_at`) rows through
  `GradingEngine::scoredItems()` on the SAME engine
  (`PerformanceAnalysisService::engine()`), so the interventions pages
  share one evidence load.
- **`RiskFeatureExtractor` answers every per-learner lookup from a
  per-batch map** (expected item count, recorded score counts grouped by
  student, the previous period's `risk_results.average_grade`, both
  periods' per-subject grades) filled by one query per scope — Submit
  Report went from 193 to 63 queries for 42 learners. Feature definitions
  are unchanged; `PerformanceBatchEquivalenceTest::test_risk_features_
  match_per_learner_queries` holds the original queries as its oracle.
  `runAnalytics()` now logs the classifier's structured error and stderr
  when the process fails — the two live failures earlier that day had left
  only `exit_code: 1` in the log, which was not diagnosable.
- **Python is launched exactly once per Submit Report** (it already was;
  now measured: `py=1`). Its ~4 s is `scikit-learn`'s own import chain
  (scipy.stats, pandas via `sklearn.utils.fixes`) triggered by unpickling
  the forest — library cost, not application code, and not per learner.
  Predicting 42 rows takes 45 ms. The only ways below that are a resident
  worker or a different artifact, both behaviour/architecture changes that
  were deliberately not made.
- **The prescribed ECR is never loaded whole.** A full seven-sheet
  `load()` of the 434 KB instrument costs ~1.7 s and tens of MB; `HELPER`
  alone is ~0.8 s. `EcrReaderService::toFlatRows()` loads `INPUT DATA` +
  the one term sheet it reads (~0.3 s), `checkWeightMismatch()` and
  `extractDraftRoster()` load `INPUT DATA` only (~50 ms), and
  `EcrProfileDetector` reads its two marker cells through a read filter
  and memoises the verdict by file content (md5), so the three services
  that ask per request share one answer. Values read are unchanged — every
  read is a raw `getValue()`, never a calculated one, so which other sheets
  are in memory cannot change a value. detect/preview/import went from
  8.7 s / 5.3 s / 6.5 s (177 MB peak) to ~0.5 s / 0.4 s / 0.5 s (18 MB).
  **When adding a workbook read, name the sheets it reads via
  `loadSheets()`; do not reach for `load()`.** The flat CSV/XLSX fallback
  in `AssessmentUploadService::readRows()` is untouched on purpose: its
  `toArray(..., formatData: true)` depends on cell styles, so
  `setReadDataOnly(true)` there would change values.
- **Imports preload the rows `updateOrCreate()` would look up one at a
  time** (`AssessmentUploadService::import()` for scores, `ReportController::
  runAnalytics()` for risk results) and then do exactly what
  `updateOrCreate()` did per row — `fill()` + `save()` on the existing
  model or `create()` — so model events, timestamps and the unique keys
  are unchanged. Writes still happen one row at a time and inside the same
  transaction; bulk `insert()` was rejected because it skips events and
  `created_at`.
- **Indexes are added only against an EXPLAIN'd pattern.**
  `2026_09_19_000001_add_indexes_to_activity_logs_table` adds
  `(created_at)` and `(action, user_id)` on the fastest-growing table,
  whose two live reads (`ORDER BY created_at DESC` on the log page and
  dashboard; `WHERE action = 'login'` in Data Health) were full scans with
  filesort. Every other hot query already used an index. Rehearsed
  forward/rollback/forward on the copy before running live; backup
  `backups/backup_20260919_pre_perf_indexes.sql`.
- **The remaining per-page cost is environmental, not application.** With
  OPcache disabled (XAMPP's `php.ini` ships `;zend_extension=opcache`
  commented out), every request compiles ~590 PHP files: a page that does
  26 ms of work in-process (Admin > Users) takes ~300 ms over HTTP, and
  the same code on PHP's built-in server measured ~360 ms without OPcache
  vs ~45 ms with it. `php artisan optimize` measured no gain here (cached
  config/routes are just more PHP to compile) and `config:cache` breaks
  `phpunit.xml`'s env overrides — leave it cleared in this checkout.
  Enabling OPcache is a php.ini change for the operator, recorded as a
  recommendation, not made by this pass.

## Subject applicability refactor (2026-09-20) — Admin > Subjects is the one configuration point

**The problem it removed.** Admin > Sections > Subjects asked the Admin
to assign every subject to every section, one term at a time, and the
first such assignment silently switched the section off the curriculum
("term-managed"). Two sections could drift; the same core subject was
typed in six times; and a validated ECR upload could add a
`section_subjects` row on its own.

**The architecture now.**

- **`subject_terms` — TERMS TAUGHT.** One row per (subject, term number),
  unique, FK cascade on subject delete; `Subject::terms()`,
  `termNumbers()`, `isTaughtIn()`, `termsLabel()`, `syncTerms()`. The
  term universe is `AcademicTerm::termNumbers()` (distinct
  `academic_terms.term`, falling back to `AcademicTerm::TERM_NUMBERS`),
  never a second constant. Term NUMBER, not a year-specific FK, on
  purpose: subjects are master data valid across years, and every
  academic table keys its term as `grading_period` 1..3. **Backfill
  invented nothing**: every existing subject got every term, which is
  exactly what each resolved to before (the default path ignored the
  term) and what DepEd's catalog says for all three live subjects
  (`g11_terms = 3`). A subject that runs in fewer terms is narrowed by
  the Admin.
- **`SubjectApplicabilityService` — THE resolver.** `Subject::
  forSection($section, $term)` delegates to `query()`; every screen and
  write guard already went through `forSection()`, so they all changed
  at once. The rule, from stored configuration only: grade level
  matches; AND the subject reaches the section as CORE (every core of
  the grade level), by TRACK (an elective whose track/specialization is
  the section's — the k12_2013 strand mechanism, never for an `sshs`
  section), or by SECTION CHOICE (a `section_subjects` row); AND it is
  taught in the term. `appliesTo()` is the same rule as a PHP predicate;
  `SubjectApplicabilityTest` holds the two against each other on every
  combination. `sectionsOffering()` is the inverse (Principal Students'
  section scope reads it — no second hand-written rule).
- **`section_subjects` = section ELECTIVE CHOICES.** Schema untouched.
  `SubjectOfferingService::chooseElective()` writes one row per term the
  elective is taught; `removeElectiveChoice()` is refused while records
  exist (`SectionSubject::academicReferenceCounts()`). A row for a core
  subject changes nothing. The resolver reads "chosen" and lets Terms
  Taught decide the terms, so widening a subject's terms later needs no
  row maintenance. `SubjectOfferingService::notOfferedMessage()` is still
  the one refusal wording: "X is not applicable to Section in Term N."
- **Admin > Subjects** carries Terms Taught (checkboxes, at least one,
  each validated against `termNumbers()`), a name+grade-level duplicate
  rule (the removed importer's rule, kept alive on the form), a
  specialization-belongs-to-track check, and the catalog auto-link by
  exact name for a subject that has none (never re-pointed on edit).
  **History protection** (`historyConflicts()`): an edit that would make
  any (section, term) holding grades / assessments / uploads /
  interventions / weakest-subject risk results for this subject stop
  resolving it — a term unchecked, a grade level changed, an elective
  moved to another track/specialization — is refused naming every pair.
  Records are never deleted or hidden.
- **Admin > Sections > Subjects** is a RESOLVED view per term tab
  ("Applies because": Core / Track-Specialization / Section choice, the
  grading profile, "has Term N records"), with one action left —
  choosing an elective for a section the curriculum cannot match one to.
  The Sections list's "default" badge and the Data Health "no
  term-specific assignments" item are gone; Data Health now flags an SSHS
  section with electives available but none chosen
  (`SectionElectiveStatus::isFullyConfigured()`, unchanged in meaning).
- **The prescribed ECR validates, never writes.** `Adviser\
  AssessmentController` refuses a subject not applicable to the section
  in the selected term BEFORE the file is opened (detect, preview and
  import alike); `EcrSubjectTermResolver` then refuses a workbook whose
  own TERMS AND UNITS block contradicts the selected term, exactly as
  before. `synchronize()` is gone; `PrescribedEcrMetadataValidationTest`
  proves a valid upload leaves `subject_terms` and `section_subjects`
  byte-identical and that a workbook cannot widen a narrowed subject.
- **Applicability is not term status.** "Taught in Term 2" and "Term 2
  is open for encoding" are different questions; `AcademicTerm::
  acceptsWrites()` still guards every write, and a closed term refuses
  a write for a subject that is applicable to it.
- **Submit Report / risk features** use the term's universe by
  construction: `SectionElectiveStatus::expectedGradeCount()`,
  `TermReadinessService`, `RiskFeatureExtractor` and `submit()`'s failing
  count all read `forSection($section, $term)` or the term's own
  records. A Term-2-only subject adds nothing to Term 1's expected,
  missing, weak or failing counts (`SubjectApplicabilityTest`, including
  an end-to-end submit through the real classifier). No ML feature
  definition, model, threshold or safeguard changed.
- **Removed as unsupported formats** (Part 24 of the work order): the
  Tracks, Specializations, Subjects and Sections bulk imports — routes,
  controller methods, `App\Imports\{Tracks,Specializations,Subjects,
  Sections}Import`, the modals and their `modal.js` handlers. No official
  or client file format exists for any of them. The learner roster import
  (`students.import`, `import-from-ecr`) and the prescribed ECR upload
  stay. `MimesFixSixMoreRoutesTest` asserts the four routes and classes
  are gone. Run `composer dump-autoload` after deleting classes — the
  classmap still listed them and `class_exists()` warned.
- **Adviser advisory details** (Part 12): Track shows always;
  Specialization only when set; an `sshs` section shows "Strengthened
  SHS" instead of a blank specialization, on the Adviser dashboard and
  Encode Grades headers.

**Open, deliberately.** Whether an SSHS section's electives are chosen
per section or per learner (Q1) is still the school's question; the
choice mechanism is per section, as before. The three live subjects are
all core and all three-term, so the live database's resolution is
unchanged by this refactor — `dss:check-integrity` was clean before and
after, and the new "records the configuration no longer resolves" check
it gained reports zero.
