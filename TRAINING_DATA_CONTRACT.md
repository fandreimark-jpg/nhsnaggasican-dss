# Training Data Contract — Future Historical ML Training

This is the schema a real historical dataset must satisfy before
`analytics/train_model.py` will train a candidate risk model from it. It is
implemented in `analytics/schema.py` and enforced by
`analytics/dataset_validator.py` — this document explains the *why*; those
two files are the source of truth for the exact column names and rules.

`python analytics/schema.py` prints the live contract (every feature, its
definition, its allowed range, whether it may be blank, the target and the
forbidden columns) straight from the code, which is the version to trust if
this document and that output ever disagree. `analytics/README.md` covers
the operational side: validating a file, training, evaluating, promoting.

**Update, 2026-09-19 (ML architecture correction pass).** Three additions to
what is below: `missing_assessment_count` counts ABSENT RECORDS, never zeros
(a recorded 0 is a score); which features may legitimately be blank is now
declared in code (`schema.OPTIONAL_FEATURE_COLUMNS`) and enforced per row;
and each feature has an allowed numeric range (`schema.FEATURE_RANGES`), so
an impossible value such as `current_average = 145` is a rejected row with a
stated reason rather than a training sample.

**Nothing described here has been run against real data.** No school-
authorized historical dataset exists in this repository as of this writing.
This document and the code behind it exist so that when one arrives, using
it is a validated CLI run, not a from-scratch design exercise.

## One row = one learner-period record

Each row is one student's academic evidence for one reporting period
(a quarter, or a term) in one school year — not one student per school year,
and not one row per subject.

## Why Q1-Q4 and Term 1-3 are NOT the same column

The school previously reported grades quarterly (Q1-Q4) and has since moved
to a three-term system (Term 1-3), which is what this codebase's live
grading path (`GradingEngine`, `Assessment`, `Grade`) is built around today.

Mapping Q1->Term 1, Q2->Term 2, Q3->Term 3, and discarding Q4 would be wrong
in two ways: it throws away a quarter of every historical student-year, and
it treats two structurally different reporting systems as if they were the
same thing wearing different labels. There is no principled reason Q4 is the
"extra" one to discard rather than, say, treating Q1+Q2 as first-half
evidence.

Instead, two columns say what a row actually is:

| Column | Values | Meaning |
|---|---|---|
| `reporting_system` | `quarterly`, `three_term` | which system produced this row |
| `period_index` | 1-4 for quarterly, 1-3 for three_term | which period within that system |

A three-term row with `period_index=4` is invalid. A quarterly row with
`period_index=4` is valid. The two are validated against each other, not
against one shared range — see `schema.MAX_PERIOD_INDEX`.

Any feature-engineering step that wants a single comparable "how did this
student do overall" figure (e.g. combining Q1-Q4 into something comparable
to a three-term average) is a **deliberate, later decision**, built on top
of this honest representation — not an assumption baked into ingestion.

## Required columns

### Context (never fed to the model as a feature)

| Column | Notes |
|---|---|
| `anonymous_student_id` | An anonymized identifier. **Never the real LRN.** |
| `school_year` | `YYYY-YYYY`, e.g. `2023-2024`. |
| `grade_level` | `11` or `12`. |
| `curriculum` | e.g. `sshs`, `k12_2013` — informational; not required to be present for the model to train, but used for cohort/grouping context. |
| `grading_policy` | e.g. `do015_2026`, `do8_2015` — which grading order produced the academic features below. |
| `reporting_system` | `quarterly` or `three_term`. |
| `period_index` | See table above. |

### Features (numeric, fed to the model)

| Column | Meaning |
|---|---|
| `ww_mean` | Mean Written Work percentage across subjects this period. |
| `pt_mean` | Mean Performance Task percentage. |
| `exam_mean` | Mean Examination percentage. |
| `current_average` | This period's overall average grade. |
| `prev_period_average` | The immediately preceding period's average, if any. |
| `trend_delta` | `current_average - prev_period_average`. |
| `failing_subject_count` | Number of subjects below the passing mark this period. |
| `weak_component_count` | Number of WW/PT/Exam components below target this period. |
| `missing_assessment_count` | Number of expected assessment records not found. |

These are **computed outcomes** of the academic grading rules (GradingEngine
/ SubjectGroupWeight), never the grading policy itself — a mean percentage,
not a WW/PT/Exam weight. See "Academic rules vs. ML" below.

### Optional

| Column | Notes |
|---|---|
| `attendance_absences` | **Not implemented, not required, not assumed.** This system does not collect attendance data anywhere today (see `CLAUDE.md`, "Attendance is not part of any grade"). Only include this column if a future dataset genuinely has authorized attendance data — never fabricate it. |

### Target

| Column | Values | Notes |
|---|---|---|
| `outcome` | `intervention`, `no_intervention` | **Must come from a school-approved historical definition** — e.g. a documented, real intervention or outcome record for that student-period. This pipeline does not invent, infer, or guess this value. A dataset with no valid `outcome` column is rejected outright by `dataset_validator.py`. |

## Forbidden columns

A dataset containing any of these is rejected outright, not silently
stripped: `name`, `student_name`, `full_name`, `first_name`, `last_name`,
`middle_name`, `address`, `phone`, `mobile`, `email`, `contact_number`,
`parent_name`, `guardian_name`, `guardian_contact`, `parent_contact`,
`birthdate`, `birth_date`, `date_of_birth`, `lrn`.

Birthdate is on that list for two reasons: it was removed from the learner
record entirely (see CLAUDE.md, PART 1 of the term-specific subject
offerings pass) and it is not an ML feature.

## Privacy

Do not include: student name, address, phone, email, birthdate, or
parent/guardian information. The real LRN must never appear as a feature — the
`anonymous_student_id` column exists specifically so a dataset can be built
and reasoned about without carrying real identifying information end to end.

## Validation summary

`dataset_validator.validate_dataset()` produces a report in this shape:

```
Dataset: Historical SHS 2023-2026
Records: 1,250
Valid: 1,210
Rejected: 40
Reporting systems:
  - Quarterly: 950
  - Three Term: 300
Target:
  - Intervention: 280
  - No Intervention: 930
```

Non-blocking findings (class imbalance, a feature that looks like it might
be leaking the outcome) are reported as **warnings**, distinct from
rejections — a human decides what to do with a warning; `train_model.py`
only ever hard-refuses when there are zero valid records or fewer than two
target classes.

## Workflow

```
UPLOAD -> DETECT FORMAT -> PARSE -> VALIDATE -> PREVIEW -> NORMALIZE
    -> BUILD DATASET -> TRAIN CANDIDATE -> EVALUATE -> ACTIVATE
```

Training never starts automatically after upload. See `ML_ARCHITECTURE.md`
for the full pipeline and model-versioning design.
