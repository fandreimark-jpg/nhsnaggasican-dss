# Subject offerings, electives and the applicability refactor

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 865-1013 1958-2142 2388-2415 3189-3293; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

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


