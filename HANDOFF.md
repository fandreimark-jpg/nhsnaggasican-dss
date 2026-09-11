# PROJECT HANDOFF — Naggasican NHS DSS

Upload this at the start of a new chat, with the project zip.

---

## WHAT THIS IS

Capstone Decision Support System for a DepEd senior high school.
Laravel 12 + PHP 8.2 + MySQL + Python (scikit-learn Random Forest).
Local XAMPP on Windows. Architecture and decisions are in `CLAUDE.md`.

Three roles: **Admin** (master data), **Adviser** (assessments, grades, report
submission, intervention delivery), **Principal** (read-only academic data,
sole intervention decision-maker).

Pilot data: Section **Molave**, Grade 11, 40 learners, 2 subjects (General
Mathematics, Oral Communication), 3 terms.

---

## DESIGN DECISIONS — do not change without asking

1. **In-Term Status is separate from Risk Level.** The first comes from
   assessment evidence in one subject and term, available immediately. The
   second comes from the Random Forest after Submit Report, covers every
   subject, and factors in the trend across terms.

2. **In-Term Status is based on the computed grade, not the transmuted one.**
   Deliberate: transmutation lifts a failing raw score into a passing reported
   grade, so a status built on the transmuted grade would flag a learner far too
   late. It is component-based — 0 components below the 75% target is On Track,
   1 is Needs Attention, 2 or more is At Risk.

3. **Failing is a third, separate signal** — official grade 74 and below, only
   after verification. It is an outcome measure, not an early warning, and lives
   among outcomes on the dashboard rather than beside the in-term signals.

4. **Grade 11 uses DO 015, s. 2026** (core academic 20/50/30); **Grade 12 uses
   DO 8, s. 2015** (25/50/25). SY 2026-2027 is a transition year.

5. **Examinations:** ST1 30% / ST2 30% / Term Exam 40%. A fourth examination
   item onward carries no role.

   **AMENDED, then corrected 2026-09-12.** The official SSHS E-Class Record
   assigns the split per subject. An earlier version of this note said nine
   subjects carry Term Exam at 100 with no summative tests, folding Work
   Immersion in with the TE-only group — wrong: Work Immersion has no
   Examination component at all, not a TE-only one. Verified directly
   against the seeded catalog: **eight subjects are TE-only** (six Field
   Experience, two STEM), and a separate **nine subjects have no
   Examination component at all** (Design and Innovation, Research 1,
   Research 2, and six Work Immersion variants). 30/30/40 is the common
   case, not the rule. See `CLAUDE.md`, "The Examination role split is per
   subject, not universal," for the full corrected breakdown.

6. **The DSS recommends; the Principal decides.** No intervention is ever
   created, approved, or closed automatically.

7. **Delivery is per-learner or per-genuine-group, never blind bulk.** A written
   note describing what was actually done is always required. Group deliveries
   are labelled as such.

8. **Attendance is not part of any grade.** It exists only as an intervention
   type. This is in `Known limitations`.

---

## KNOWN LIMITATIONS — already documented, do not treat as bugs

- The Random Forest uses a **single feature** (`average_grade`) with
  synthetically generated training data. High accuracy is expected and is not
  evidence of predictive validity.
- The 41 DO 015 transmutation bands were cross-checked against **secondary
  reproductions**, not read from the signed PDF.

  **RESOLVED.** All 41 bands were checked against `HELPER!B7:D47` of the
  official DepEd SSHS Electronic Class Record for SY 2026-2027 (`ECRSHS2026`,
  `2026_v1.0`). 41 of 41 match exactly. Still DepEd's own instrument rather than
  the signed PDF, and the paper should say so.
- **Within-term additional support is uncapped** and additive: an item adds its
  points to both the earned and possible score, so a strong remedial result lifts
  the component by less than its own percentage. Whether to cap, average, or keep
  this is an open school policy decision.
- **Elective cluster limitation:** if a section has more than two electives,
  `totalExpected` over-counts and Submit Report can never complete. No longer
  deferrable — the client's Grade 11 sections have no specialization at all.

  **Correction, 2026-09-10 ("ECR alignment" PART 3b):** the previous line here
  said this "breaks `Subject::forSection()` silently" — that implied a SQL
  defect, and there isn't one. Tested directly: Laravel converts
  `where('specialization_id', null)` to `IS NULL` correctly, so
  `forSection()`'s query runs exactly as written for a null-specialization
  section. The real problem is semantic: `specialization_id` means "the
  strand a section chose" under the old 2013 curriculum but "an inherent
  property of the subject" under SSHS, where sections have no strand to
  choose at all — so the section-to-subject specialization match can't be
  the elective-selection mechanism under SSHS regardless of any SQL fix. See
  `CLAUDE.md`, "Elective selection is per-cluster, not per-learner," for the
  full correction. Still blocked on `ECR_ALIGNMENT_WORK_ORDER.md` Parts 3
  and 6 — same status, corrected reasoning.

---

## OPERATIONAL RULES

- **Always back up before any migration:**
  `mysqldump -u root naggasican_dss --result-file=backup_$(date +%Y%m%d_%H%M).sql`
  Data has been lost once already, and MySQL has crashed once.

  **Use `--result-file=`, never `>`.** `mysqldump ... > backup.sql` lets the
  shell decide the file's encoding, and PowerShell's `>` redirect defaults
  to UTF-16LE — unlike Bash's `>`, which defaults to UTF-8. A backup taken
  from PowerShell with `>` is silently unrestorable: `mysql < that_file.sql`
  reads UTF-16 bytes as UTF-8/Latin1, every statement comes through
  corrupted, and the import fails immediately on the first statement.
  `--result-file=` has mysqldump write the file itself in the correct
  encoding, sidestepping the shell entirely — it cannot be got wrong by
  whoever is holding the terminal, which `>` demonstrably can be. This cost
  a full day on 2026-09-11: a backup taken with PowerShell's `>` looked like
  a normal ~1MB dump, restored with exit code 0 and no errors, and loaded
  nothing — the database read as freshly truncated. Confirmed via `file` /
  `xxd` on the actual bytes: `fffe` (UTF-16LE BOM) where every other backup
  in this project starts with `-- MariaDB dump`.

  **A restore is not verified by the absence of an error.** `mysql < file.sql`
  exits 0 whether it loaded 240 grades or loaded nothing at all. Verify by
  counting rows in the tables that matter (students, grades, and whatever
  else the restore was supposed to bring back) against the number you expect
  — never by the command finishing cleanly. This is the same principle
  `dss:check-integrity` and `migrate:status` already apply to schema state;
  it applies to a restore's actual data too.

- Run `npm run build` after JS or Blade-with-JS changes. `php artisan
  view:clear` alone is not enough in this project.
- Python is called via `exec()`, not Laravel's Process facade, because of a
  WinError 10106 bug on Windows/XAMPP.
- Excel import is restricted to sheet index 0 on the legacy path. The E-Class
  Record reader is the exception and selects sheets by name.

---

## THE CLIENT'S REAL STRUCTURE — NOT CONFIRMED ROSTER DATA

**Correction, 2026-09-11.** Everything below — the section names Shakespeare
and Curie, and the Grade 12 counts 22/39/20 — came from a screenshot and
from sample files generated for testing, not from the school. The school
has not sent a real roster. Treat this section as a working assumption for
shaping the schema (curriculum split, two-grading-orders handling), never
as confirmed enrollment data. `ECR_ALIGNMENT_WORK_ORDER.md` Part 7 is
blocked on this exact gap — loading anything under these names now would
be fabrication, not data entry.

Grade 11, Strengthened SHS under DO 015: sections **Shakespeare** and
**Curie**, 42 learners each, no specialization — as communicated, not
verified.

Grade 12, 2013 curriculum under DO 8: **ABM** 22, **HUMSS** 39, **STEM** 20.
Academic Track only — as communicated, not verified; Q2 in
`ECR_ALIGNMENT_WORK_ORDER.md` (how many actual Grade 12 sections exist) is
still open specifically because these are specialization counts, not
section counts.

165 learners, one school, two curricula, two grading orders at the same
time — the SHAPE of the client's situation is real and drives the schema
(the `curriculum` column, DO 8 vs DO 015 handling). The specific names and
numbers above are not. Molave is not one of these sections.

---

## CURRENT STATE

**Working:**
- 41 DO 015 transmutation bands seeded; no provisional grades
- Terms 1 and 2 submitted for Molave; Term 3 partially encoded
- Risk distribution recalibrated: 32 low, 8 moderate, 0 high
- Interventions recorded and delivered for Terms 1 and 2
- Group delivery, within-term progress, and the Failing layer all built
- Intervention `origin` column landed; approve-pending route built
- Test suite at 740 passing, 1 skipped (ECR alignment Part 1 + Part 2)

**Outstanding:** see `ECR_ALIGNMENT_WORK_ORDER.md`, nine parts. Two are blocked
on questions only the school can answer — whether Grade 11 electives are chosen
per section or per learner, and how many Grade 12 sections exist.

**Not yet uploaded:** Oral Communication Term 3 (`09_term3_main_oralcomm.xlsx`).
Until that is in, Term 3 cannot be submitted.

---

## FILES THAT MATTER

| File | Purpose |
|---|---|
| `ECR_ALIGNMENT_WORK_ORDER.md` | Current outstanding work, in order, with stop points |
| `WORK_ORDER.md` | The UI and decision-flow pass, mostly landed |
| `MASTER_PROMPT.md` | Earlier order, largely superseded by the above |
| `PAPER_REVISION_WORK_ORDER.md` | Edits the documenter must make to the capstone paper |
| `TEST_PLAN.md`, `TEST_PLAN_2.md` | Manual acceptance testing |
| `UPLOAD_GUIDE.md` | Order and settings for the Term 1–3 assessment uploads |
| `export_existing_assessments.php` | Recovers assessment data already in the DB |

---

## THE ONE THING TO SCREENSHOT

A learner showing **At Risk with a passing report-card grade**. Example in the
data: Kenneth Valdez, Molave, General Mathematics, Term 1 — computed 71.90,
report card 76.00, all three components (Written Work, Performance Task,
Examination) below the 75 target. Screenshot captured from
`principal/students`, filtered to Molave / General Mathematics / Term 1, on
2026-09-09, against the restored post-UI-work-order backup.

It is the clearest single answer to "why not just use 74 and below", which the
panel will ask.

**Correction, 2026-09-09:** this section previously read "computed 71.95, two
components below the 75 target." That was stale — the live data said 71.90 and
three components, not two, at the time this note was written.

**Note, 2026-09-10:** "ECR alignment" Part 2 found `exam_role_shares` had
never been seeded on this database and corrected it (see CLAUDE.md's
"Implementation — `deped_subject_catalog`"). That correction moved this exact
figure again, by 0.03: computed 71.90 → **71.93**. Report card, all-three-
components-below-target, and At Risk status are all unchanged. The screenshot
already captured still tells the true story; only the second decimal place is
now stale. Recapture only if the exact figure needs to match a printed number
somewhere — otherwise not worth a re-shoot for 0.03.