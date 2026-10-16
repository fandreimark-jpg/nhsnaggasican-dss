# Paper Update Checklist — after the rule-based cut-over (2026-09-27)

The system is now a **Rule-Based Ongoing Academic Monitoring and Decision
Support System**. The Python Random Forest prototype (trained on synthetic
data) is retired and runs nowhere. This list says which parts of the
research paper and user manual describe the old design and need revising.
It does not edit the paper.

## Sections to revise

| Section | What to change |
|---|---|
| Title (if it names risk/prediction) | Use "Learner Academic Performance Decision Support System"; drop "risk prediction". |
| Abstract | Replace ML risk classification with rule-based monitoring of recorded evidence; Principal decides. |
| Introduction / system description | Describe Academic Concerns (two rules: explicitly Missing requirement; Subject Performance Concern from an official failing subject grade), review, intervention, delivery, evidence-driven resolution; the DSS Assessment Record (download → work offline → upload back, no double encoding). |
| Objectives | Remove "predict at-risk learners"; state monitoring, evidence display, intervention tracking. |
| Scope and limitations | Two approved rules — explicit Missing Requirement, and Subject Performance Concern from an OFFICIAL failing subject grade (existing DepEd failing mark, evaluated per subject so the overall average never hides it); no prediction; attendance RETIRED (no approved basis); In-Term Status is descriptive; thin-evidence readiness; legacy `risk_results` archived. |
| Conceptual framework | Evidence → rule → concern → Principal review → intervention → delivery → re-evaluation → resolution. |
| Methodology | Remove model training/validation; describe rule design, UAT, automated regression tests. |
| System architecture | Laravel + MariaDB only; no Python service, no model file, no process launch. |
| DSS logic | Assessment status (Completed / Missing / Not Yet Given); concern lifecycle (Open / Resolved); review (Unreviewed / Reviewed); In-Term Status labels. |
| Data analysis | No accuracy/precision/confusion matrix; report rule outcomes and UAT evidence instead. |
| Diagrams / flowcharts | Redraw the Submit Report flow (no classifier), add the concern lifecycle and intervention flow. |
| Database / ERD | Add `academic_concerns`, `assessment_learner_statuses`, `attendance_records`, `interventions.academic_concern_id`; note `academic_concerns.subject_id` and `interventions.subject_id` are null for attendance; mark `risk_results` legacy/archived. |
| Testing | Current suite: 1,214 automated tests, 0 failures, no Python (incl. 14 Assessment Record, 18 attendance); the GALANG lifecycle UAT (2026-09-26). |
| Results | Show the verified lifecycle: delivery did not resolve the concern; a real score did. |
| Conclusions | Rule-based, explainable monitoring; the Principal remains the decision-maker. |
| Recommendations | School-approved additional rules (e.g. an attendance threshold, only if the school adopts one); per-learner electives (Q1); subject teachers. |
| Technical stack | Remove Python, NumPy, scikit-learn, joblib, `model_cache.pkl`. |
| User manual | Remove Risk Level screens; add Academic Monitoring (Concern Type filter), Review, Record Intervention, Acknowledge, Deliver, No Evidence Yet, Open Concerns in Subject Analysis, Download Assessment Record, the all-or-nothing import (Preview shows blocking problems; Import unavailable until the file is fixed); Official Subject Results (Overall Average + Passed/Failed per subject) on Student Detail and Reports. |

## References and terms to remove or replace

Python ML · Random Forest · scikit-learn · joblib · `model_cache.pkl` ·
prediction / predictive analytics · risk confidence ·
Low / Moderate / High risk classification · training dataset ·
model accuracy · "DSS Status" (risk-based) · Term-over-Term Progress (risk-anchored).

Use instead: Academic Concern (Open / Resolved), For Review, Reviewed,
Missing requirement, No Evidence Yet, Below Target (descriptive), Open Concerns.

## Recommended defense explanation — subject first (supersedes the attendance note)

"The overall average is reported, but it never decides whether a learner
needs review. Each subject is evaluated on its own: if a subject's OFFICIAL
grade is failing (the DepEd failing mark the system already uses), the
learner is For Review with a Subject Performance Concern naming that
subject — however high the other subjects or the average are. An in-term
reading below target is shown as evidence but opens no concern, because no
in-term trigger has been approved. Attendance was retired from the DSS
because no approved rule defines how it should affect monitoring; its old
records are kept as history only."
