# Naggasican NHS DSS --- System Fixes, Hardcoded Elements, Grading, and ML Audit

**Purpose:** Central checklist and implementation guide for making the
DSS maintainable for future school years, aligning imports and grading
with the school's actual data, and documenting the current
academic-risk/ML implementation.

**Project audited:** `naggasican-dss(2).zip`\
**Priority rule:** Do not hardcode school year, academic term,
curriculum, grading weights, sections, tracks, or subject
classifications when these can be resolved from database/configuration
records.

------------------------------------------------------------------------

## 1. Priority Status

  ----------------------------------------------------------------------------------------------
  Priority          Item              Current Finding             Required Action
  ----------------- ----------------- --------------------------- ------------------------------
  P0                Academic year     Confirmed in UI/seed/test   Replace runtime hardcoding
                    hardcoded         areas;                      with active Academic Year/Term
                                      `resources/js/modal.js`     data
                                      sets `2026-2027`            

  P0                Risk/ML validity  ML exists, but production   Do not claim real-world
                                      classifier is trained on    predictive validity; define
                                      synthetic single-feature    school risk rules and obtain
                                      data                        authorized historical data
                                                                  before retraining

  P0                Core/elective     Subject grouping has        Require explicit valid subject
                    mapping           safeguards, but import can  group or verified catalog
                                      default blank               mapping
                                      `subject_group` to          
                                      `core_academic`             

  P0                Grade computation Central grading services    Verify all
                                      exist                       weights/transmutation against
                                                                  applicable DepEd policy and
                                                                  curriculum/year

  P1                School Excel      Importers exist, but exact  Obtain official school files,
                    compatibility     school workbook alignment   map columns/sheets, validate
                                      must be verified            before import

  P1                Sections          Separate model exists;      Reconcile against official
                                      school data match still     section list and keep sections
                                      needs verification          distinct

  P1                Subject upload by `grade_level` exists in     Add Grade 11/12 selector to
                    year level        import structure            upload UI and validate
                                                                  uploaded rows against
                                                                  selection

  P1                Multiple grade    Multiple assessment items   Preserve many assessment
                    entries           are supported; final grade  entries → one computed/final
                                      should remain unique per    grade
                                      student/subject/term/year   

  P2                Track description Not clearly displayed in    Add/display description field
                                      Tracks view                 

  P2                Scrollbar         Reusable table scrolling    Make one reusable scroll/table
                                      exists but should be        wrapper component/module
                                      standardized                

  P2                Footer            Shared footer missing       Add footer to main
                                                                  authenticated layout

  P2                Grading details   Backend has grading         Show scheme and WW/PT/Exam
                    per subject       group/weight logic          weights in Subject UI

  P2                ML dependencies   Python imports identified   Add pinned
                                                                  `analytics/requirements.txt`
  ----------------------------------------------------------------------------------------------

------------------------------------------------------------------------

# 2. UI and Master-Data Fixes

## 2.1 Tracks --- Show Description in the View

### Requirement

The Track description must be visible in the Admin Tracks page.

### Action

1.  Confirm `tracks` table has a `description` column. If absent, create
    a migration.
2.  Add `description` to the Track model's fillable fields.
3.  Add Description to Create/Edit Track forms.
4.  Add Description column/card text to the Tracks view.
5.  Validate length and allow nullable description if school data does
    not provide one.
6.  Add feature tests for create/update/display.

### Acceptance criteria

-   Admin can enter and update a description.
-   Description is visible without opening the database.
-   Existing tracks without a description do not break the page.

------------------------------------------------------------------------

## 2.2 Separate Scrollbar Module

### Finding

The project already uses reusable table scrolling styles such as
`.tbl-scroll`, but scrolling behavior should be standardized instead of
implemented independently on pages.

### Action

Create a reusable Blade component or shared CSS module, for example:

`resources/views/components/table-scroll.blade.php`

All long tables should use the same wrapper. Avoid page-specific
horizontal-scroll hacks.

### Acceptance criteria

-   Track, Subject, Section, Student, Grade, Risk, and Intervention
    tables can scroll horizontally on smaller screens.
-   The sidebar/page itself does not unnecessarily scroll sideways.
-   Table headers and actions remain usable.

------------------------------------------------------------------------

## 2.3 Add Shared Footer

### Finding

A complete shared application footer is missing from the main
authenticated layout.

### Action

Add the footer to the common layout, not individually to
Admin/Adviser/Principal pages.

Suggested content: - School/system name - Current year generated
dynamically - DSS version if desired - Optional policy/privacy link

Do not hardcode the copyright year.

------------------------------------------------------------------------

# 3. Subjects, Year Levels, and Sections

## 3.1 Subject Upload Must Select Year Level

### Current finding

The subject import format already recognizes `grade_level`, but the
upload workflow should explicitly ask the Admin which year level is
being imported.

### Required workflow

1.  Admin selects **Grade 11** or **Grade 12**.
2.  Admin selects/identifies applicable curriculum if needed.
3.  Admin uploads XLSX/CSV.
4.  System validates every row.
5.  If a row has a conflicting `grade_level`, reject the row/file with a
    clear error.
6.  Preview valid/invalid rows before committing.

### Important curriculum rule

For SY 2026--2027, DepEd states that incoming Grade 11 learners
implement the Strengthened SHS Curriculum, while Grade 12 learners in
non-pilot schools continue the existing SHS curriculum. Pilot-school
cases must be handled according to applicable issuance.

Therefore, **do not assume Grade 11 and Grade 12 always use the same
curriculum or subject catalog.**

------------------------------------------------------------------------

## 3.2 Sections Must Be Separate and Must Match School Data

### Rule

A section is its own master-data record. Do not merge section identity
with Track or Specialization.

Recommended relationship:

`Grade Level → Section → Track/Pathway/Specialization/Curriculum`

Track/specialization may be derived from the selected Section where the
school's structure requires it.

### Required verification

Reconcile the system's Section records against the official school
Excel/list: - exact section name - grade level - school year -
curriculum - adviser - track/pathway - specialization, when applicable

Do not invent section names during import.

------------------------------------------------------------------------

## 3.3 Core and Electives Must Not Be Swapped

### Finding

The project has `subject_group` and `subject_group_weights`, but
`SubjectsImport` currently allows a blank/absent `subject_group` to fall
back to `core_academic`. This can silently misclassify an elective.

### Required fix

For production school imports: - Prefer an explicit, validated
`subject_group`. - Or resolve it from a verified DepEd subject
catalog. - Do **not** silently convert an unknown elective into Core. -
Show import errors/warnings before saving.

For Strengthened SHS, use the applicable official classifications (Core
Subjects, Academic Electives, and Technical-Professional/TechPro
Electives) rather than assuming legacy Applied/Specialized categories.

------------------------------------------------------------------------

# 4. Grade Entries and Grade Computation

## 4.1 Multiple Entries Must Be Allowed

Multiple **assessment entries** should be allowed, for example:

-   WW1, WW2, Quiz, Activity
-   PT1, PT2, Project, Performance
-   Exam/Term Assessment entries as allowed by the applicable grading
    policy

However, the final computed grade should remain uniquely identified by:

`student + subject + grading period/term + school year`

Do not create duplicate final grades for the same combination.

------------------------------------------------------------------------

## 4.2 Grading Details Must Be Visible Per Subject

The Subject page should display:

-   Subject name/code
-   Grade level
-   Curriculum
-   Subject classification/group
-   Written Work weight
-   Performance Task weight
-   Examination/term-assessment weight
-   Applicable grading scheme/policy
-   Effective school year, where needed

Do not make users guess which weights are being applied.

------------------------------------------------------------------------

## 4.3 Grade Computation

### Required pipeline

1.  Validate assessment entries.
2.  Compute component percentage scores.
3.  Apply the subject's applicable WW/PT/Exam weights.
4.  Produce initial/weighted grade.
5.  Apply the applicable transmutation/grading rule.
6.  Determine remark/status.
7.  Preserve enough computation detail for audit/explanation.
8.  Prevent final posting when required review/term conditions are not
    satisfied.

### Important

Do not use one universal weighting for every subject unless the current
DepEd rule explicitly requires it. Resolve weights by curriculum and
subject group from authoritative configuration/database records.

------------------------------------------------------------------------

# 5. Imported File Requirements

## 5.1 School Excel Must Match the System

Before production use, collect the actual school-maintained files for: -
students - sections - subjects - assessment/ECR data

Create an import mapping document for every supported file.

### Import validation checklist

-   file extension/type
-   exact required headers
-   grade level
-   school year
-   section
-   subject
-   subject group
-   learner identifier
-   duplicate detection
-   numeric score ranges
-   missing required fields
-   unknown tracks/sections/subjects
-   curriculum mismatch

### Recommended UX

Provide: 1. **Download Template** 2. **Upload** 3. **Preview** 4.
**Validation Results** 5. **Confirm Import** 6. **Download Error
Report**

Never silently import rows that do not match the required schema.

------------------------------------------------------------------------

# 6. Remove Hardcoded Academic Year / Academic Term

## Confirmed runtime hardcode

Review:

`resources/js/modal.js`

The audit found a runtime assignment of:

`2026-2027`

This must not be the source of truth.

There are also occurrences in factories, seeders, tests, comments,
fixtures, and examples. Test/fixture values can remain fixed when
intentionally testing a specific policy/year, but **runtime application
logic must not depend on a fixed school year.**

## Required design

Use the database as the source of truth, e.g.:

``` text
academic_years
- id
- name / school_year
- start_date
- end_date
- is_active

academic_terms
- id
- academic_year_id
- term_number
- start_date
- end_date
- status (open/closed)
```

All runtime modules should resolve the active year/term: - Sections -
Subjects/offerings - Assessments - Grade computation - Reports - Risk
results - Interventions - Imports

### Rule

Never derive a school year only from the browser date if the school can
explicitly configure the active year.

------------------------------------------------------------------------

# 7. Student Risk Rules

## Current situation

The project contains system-defined academic risk classifications, but
the current ML thresholds are not proof of an official school risk
policy.

### Required action before production

Obtain and document the **school-approved operational rules** for
academic risk. Ask the school/authorized academic personnel to
approve: - What outcome counts as "at risk"? - When is a learner Low,
Moderate, or High risk? - Does one failing subject trigger risk? - How
are missing assessments treated? - Is declining performance
considered? - Is attendance allowed as an input? - What interventions
correspond to each risk level? - Who can override a risk
classification? - How is an override audited?

### Do not do this

Do not label a custom threshold as "DepEd's official ML risk rule"
unless an applicable official issuance explicitly defines it.

DepEd grading policy can provide academic evidence and passing/grading
rules; the school's DSS risk categories still need an approved
operational definition.

------------------------------------------------------------------------

# 8. Machine Learning --- Current Implementation

## Confirmed: Machine Learning IS being used

File:

`analytics/classify.py`

The project imports:

``` python
from sklearn.ensemble import RandomForestClassifier
```

The current production classification function is:

``` python
def classify_students(grades_data, model):
```

and prediction currently uses:

``` python
model.predict([[average_grade]])
```

Therefore the current trained production model uses **`average_grade` as
its actual prediction feature**.

## Current Random Forest hyperparameters

The audited code contains settings including:

``` text
n_estimators = 200
max_depth = 5
min_samples_split = 2
min_samples_leaf = 1
random_state = 42
```

These are **model hyperparameters**, not student features.

------------------------------------------------------------------------

# 9. Current Training Data --- Critical Limitation

The source code itself documents that the current model is trained on:

-   **90 synthetic samples**
-   **one feature: `average_grade`**
-   manually constructed risk boundaries
-   no validated real student outcome dataset

Therefore:

> The current Random Forest is a prototype classifier and must not be
> described as a model trained on an official DepEd student-risk
> dataset.

The code also states that its perfect/very high synthetic validation
result is not evidence of real-world predictive validity.

------------------------------------------------------------------------

# 10. Parameters / Features for a Future Validated Model

Laravel already prepares richer academic features. Candidate features
include:

  -----------------------------------------------------------------------
  Feature                             Meaning
  ----------------------------------- -----------------------------------
  `average_grade`                     Current overall/subject-average
                                      academic performance

  `ww_mean`                           Written Work performance

  `pt_mean`                           Performance Task performance

  `exam_mean`                         Examination/term-assessment
                                      performance

  `failing_subject_count`             Number of failing subjects

  `weak_component_count`              Number of weak assessment
                                      components

  `prev_term_average`                 Previous term performance

  `trend_delta`                       Change from previous term
  -----------------------------------------------------------------------

Possible additional inputs should only be added if the school has
reliable, authorized data and a documented educational reason,
e.g. attendance or missing-assessment counts.

### Important

Do not train a multi-feature model simply because features are
available. First define the target label and obtain historical outcomes
that can validate the prediction.

------------------------------------------------------------------------

# 11. Required Training Dataset

## Do not invent a "DepEd ML dataset"

The Strengthened SHS curriculum and DepEd grading policies provide
curriculum and assessment rules; they do not automatically constitute a
labeled ML training dataset for `Low/Moderate/High Risk`.

## Preferred training-data source

Use authorized, de-identified historical school data, subject to school
approval and applicable privacy requirements.

A useful training record could contain:

``` text
learner_key (de-identified)
school_year
term
average_grade
ww_mean
pt_mean
exam_mean
failing_subject_count
weak_component_count
prev_term_average
trend_delta
approved_outcome_label
```

The **outcome label** must be objectively defined and approved. Examples
may include an approved risk category or a measurable later academic
outcome. Do not manufacture labels solely to make the model train.

### Dataset documentation must record

-   data owner/source
-   covered school years
-   number of learners/records
-   inclusion/exclusion criteria
-   de-identification process
-   feature definitions
-   label definition
-   missing-data handling
-   train/validation/test split
-   class balance
-   limitations/bias
-   approval for use

------------------------------------------------------------------------

# 12. ML Training and Evaluation Parameters

When real data becomes available, define and tune rather than blindly
hardcode:

### Random Forest hyperparameters

-   `n_estimators`
-   `max_depth`
-   `min_samples_split`
-   `min_samples_leaf`
-   `max_features`
-   `class_weight`
-   `random_state`

### Evaluation

Do not rely on accuracy alone. Report: - confusion matrix - precision -
recall - F1-score - per-class performance - cross-validation or a
held-out test set

Avoid data leakage: records from the same learner/time sequence must be
split carefully.

------------------------------------------------------------------------

# 13. Required ML Libraries

Current Python code uses:

``` text
numpy
scikit-learn
joblib
```

Also uses Python standard-library modules such as `json`, `sys`, `os`,
`argparse`, and `datetime`.

## Required fix

Create:

`analytics/requirements.txt`

Pin tested versions after verifying the deployment environment, for
example:

``` text
numpy==<tested-version>
scikit-learn==<tested-version>
joblib==<tested-version>
```

Do not guess version numbers. Generate them from the working/tested
environment.

Laravel's Excel import package is separate from ML; it should not be
described as an ML library.

------------------------------------------------------------------------

# 14. Applicable DepEd Rules / Sources to Verify

Use official DepEd sources as the primary references.

1.  **DepEd Order No. 015, s. 2026 --- Revised Guidelines on Classroom
    Assessment, Grading System, and Awards and Recognition for the K to
    12 Basic Education Program**
    -   Official page:
        https://www.deped.gov.ph/2026/06/04/june-4-2026-do-015-s-2026-revised-guidelines-on-classroom-assessment-grading-system-and-awards-and-recognition-for-the-k-to-12-basic-education-program/
2.  **DepEd Order No. 017, s. 2026 --- Strengthened Senior High School
    Curriculum**
    -   Official issuances listing:
        https://www.deped.gov.ph/2026/06/?cat=8
3.  **DepEd Memorandum No. 012, s. 2026 --- Full Implementation of the
    Strengthened Senior High School Curriculum in SY 2026--2027**
    -   Official PDF:
        https://www.deped.gov.ph/wp-content/uploads/DM-12-s.-2026_Full-Implementation-of-the-Strengthened-Senior-High-School-Curriculum-in-School-Year-2026-2027.pdf
4.  **Official Strengthened SHS Program page**
    -   Curriculum guides, FAQs, and current issuances:
    -   https://www.deped.gov.ph/strengthened-shs-program/
5.  **DM 074, s. 2025 --- Interim Guidelines for Assessment and Grading
    for the SSHS pilot**
    -   Use only where applicable to the relevant pilot/year context:
    -   https://www.deped.gov.ph/wp-content/uploads/DM_s2025_074r.pdf

### Policy maintenance rule

Policy references must be documented with: - issuance number - title -
effective school year - affected grade level/curriculum - implementation
date - superseded rule, if any

Do not bury policy assumptions inside controllers, JavaScript, or import
classes.

------------------------------------------------------------------------

# 15. Recommended Implementation Order

## Phase 1 --- Data integrity

-   [ ] Obtain official school Excel files.
-   [ ] Reconcile Tracks, Sections, Subjects, Grade Levels, and
    curriculum.
-   [ ] Add Track description.
-   [ ] Separate/standardize Sections.
-   [ ] Fix Core/Academic Elective/TechPro mapping.
-   [ ] Add strict import templates and validation.

## Phase 2 --- Future-year usability

-   [ ] Remove runtime hardcoded `2026-2027`.
-   [ ] Implement active Academic Year.
-   [ ] Implement configurable Academic Terms.
-   [ ] Make all modules resolve active year/term.
-   [ ] Keep year-specific fixtures/tests explicitly year-scoped.

## Phase 3 --- Grading

-   [ ] Verify subject-group weights against applicable policy.
-   [ ] Show grading details per subject.
-   [ ] Verify transmutation.
-   [ ] Verify multiple assessment entries.
-   [ ] Preserve one final grade per learner/subject/term/year.
-   [ ] Add computation/audit tests.

## Phase 4 --- Risk policy

-   [ ] Obtain school-approved risk definition.
-   [ ] Document Low/Moderate/High criteria.
-   [ ] Document intervention rules.
-   [ ] Add override/audit process.
-   [ ] Separate policy/rule-based risk from ML prediction in
    UI/database.

## Phase 5 --- ML validation

-   [ ] Preserve current model as prototype only.
-   [ ] Obtain authorized de-identified historical data.
-   [ ] Define objective labels.
-   [ ] Train using approved features.
-   [ ] Tune Random Forest parameters.
-   [ ] Evaluate on unseen data.
-   [ ] Document metrics and limitations.
-   [ ] Version model + dataset schema + feature list.
-   [ ] Add `requirements.txt`.

## Phase 6 --- UI completion

-   [ ] Shared footer.
-   [ ] Shared table-scroll component.
-   [ ] Grade-level selector for subject upload.
-   [ ] Import preview/error report.
-   [ ] Grading details on Subject view.
-   [ ] Clear curriculum/year/term indicators on relevant pages.

------------------------------------------------------------------------

# 16. Definition of Done

The system is ready for future school years only when:

-   No production workflow assumes `2026-2027`.
-   Admin can activate/configure a new school year and its terms without
    code edits.
-   Sections and subjects match official school master data.
-   Grade 11/12 curriculum differences are handled correctly.
-   Imports reject incompatible files with understandable errors.
-   Subject grading rules are visible and traceable to
    configuration/policy.
-   Grade computation is covered by automated tests.
-   Risk rules are approved and documented.
-   ML and rule-based risk are clearly distinguished.
-   The ML model's dataset, features, parameters, metrics, and
    limitations are documented.
-   No claim is made that synthetic training data is official DepEd
    learner-risk data.
-   Python dependencies are reproducible.
-   Footer and reusable scrolling behavior are present.

------------------------------------------------------------------------

## Developer Note

When implementing these fixes, do not stop after generating code. Run
the required migrations, seeders, tests, import checks, frontend build,
and Python tests. Fix errors and continue iterating until the requested
feature is functional and verified. Do not change a grading/risk policy
merely to make a test pass; verify the applicable school/DepEd rule
first.
