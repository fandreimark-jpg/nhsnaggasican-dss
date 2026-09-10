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

   **AMENDED.** The official SSHS E-Class Record assigns the split per subject.
   Nine Academic subjects — six Field Experience, two STEM, one Work Immersion —
   carry Term Exam at 100 with no summative tests. 30/30/40 is the common case,
   not the rule. See `CLAUDE.md`, "The Examination role split is per subject,
   not universal."

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
  deferrable — the client's Grade 11 sections have no specialization at all,
  which breaks `Subject::forSection()` silently. See `ECR_ALIGNMENT_WORK_ORDER.md`
  Parts 3 and 6.

---

## OPERATIONAL RULES

- **Always back up before any migration:**
  `mysqldump -u root naggasican_dss > backup_$(date +%Y%m%d_%H%M).sql`
  Data has been lost once already, and MySQL has crashed once.
- Run `npm run build` after JS or Blade-with-JS changes. `php artisan
  view:clear` alone is not enough in this project.
- Python is called via `exec()`, not Laravel's Process facade, because of a
  WinError 10106 bug on Windows/XAMPP.
- Excel import is restricted to sheet index 0 on the legacy path. The E-Class
  Record reader is the exception and selects sheets by name.

---

## THE CLIENT'S REAL STRUCTURE

Grade 11, Strengthened SHS under DO 015: sections **Shakespeare** and
**Curie**, 42 learners each, no specialization.

Grade 12, 2013 curriculum under DO 8: **ABM** 22, **HUMSS** 39, **STEM** 20.
Academic Track only.

165 learners, one school, two curricula, two grading orders at the same time.
Molave is not one of these sections.

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