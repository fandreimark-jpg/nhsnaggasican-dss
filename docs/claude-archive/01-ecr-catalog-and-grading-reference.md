# Strengthened SHS ECR, DepEd catalog and grading-policy reference

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 107-864 1901-1957 2172-2192 2739-2860; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

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


