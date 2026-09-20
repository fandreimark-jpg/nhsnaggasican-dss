"""
Generates `synthetic_pipeline_fixture.csv` — SYNTHETIC TEST DATA, for
exercising the training pipeline end to end. Not a dataset. Not evidence.
No learner in this file exists.

WHY A GENERATOR AND A COMMITTED CSV BOTH EXIST
-----------------------------------------------
The CSV is committed so tests are deterministic and a reviewer can read the
exact rows the pipeline was tested on. The generator is committed so the
same reviewer can see that the rows were fabricated by a seeded RNG and
exactly how — the fixture's provenance is checkable rather than asserted.

WHAT THIS DELIBERATELY DOES NOT DO
-----------------------------------
It does not label rows by a grade band. The legacy prototype's defining
flaw was that its labels WERE its features restated ("average >= 85 -> low"),
so the model could only rediscover the rule it was handed. A fixture built
that way would make the pipeline look like it works while testing nothing,
and would quietly smuggle the same circularity into the new architecture.

So `outcome` here is drawn from a noisy latent score that several features
contribute to unequally, with genuine label noise on top. That is still
fabricated — it is a made-up relationship, not a discovered one — but it is
fabricated in a shape that can actually exercise a held-out split, a
confusion matrix with all four cells populated, a baseline comparison that
the model does not trivially win, and feature importances that are not
[1.0, 0, 0, ...].

Regenerate with:
    python analytics/tests/fixtures/make_synthetic_fixture.py
"""

from __future__ import annotations

import csv
import os
import random

OUT_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'synthetic_pipeline_fixture.csv')

COLUMNS = [
    'anonymous_student_id', 'school_year', 'grade_level', 'curriculum', 'grading_policy',
    'reporting_system', 'period_index',
    'ww_mean', 'pt_mean', 'exam_mean', 'current_average', 'prev_period_average', 'trend_delta',
    'failing_subject_count', 'weak_component_count', 'missing_assessment_count',
    'outcome',
]

# Two school years so the cohort split has something to hold out, and both
# reporting systems so the quarterly/three-term distinction is exercised
# rather than assumed away.
YEARS = [
    ('2023-2024', 'quarterly', 4, '12', 'k12_2013', 'do8_2015'),
    ('2024-2025', 'three_term', 3, '11', 'sshs', 'do015_2026'),
]

LEARNERS_PER_YEAR = 40


def _clamp(value, low=0.0, high=100.0):
    return max(low, min(high, value))


def generate(seed: int = 20260919) -> list:
    rng = random.Random(seed)
    rows = []

    for year, reporting_system, periods, grade_level, curriculum, policy in YEARS:
        for learner in range(LEARNERS_PER_YEAR):
            learner_id = f'anon-{year}-{learner:03d}'

            # A per-learner baseline ability, so the learner's own periods
            # are correlated — this is what makes the grouped split
            # meaningful instead of decorative.
            ability = rng.gauss(80, 9)

            # Roughly one learner in eight is on a profile with no
            # Examination component at all (DO 015 Work Immersion /
            # Research / Design and Innovation). Their exam_mean is BLANK,
            # never 0 — the fixture has to contain the case the schema
            # spends a paragraph on, or the "blank is not zero" handling is
            # untested.
            has_exam = rng.random() > 0.125

            previous_average = None
            for period in range(1, periods + 1):
                ww = _clamp(rng.gauss(ability + 2, 6))
                pt = _clamp(rng.gauss(ability, 7))
                exam = _clamp(rng.gauss(ability - 3, 8)) if has_exam else None

                available = [v for v in (ww, pt, exam) if v is not None]
                current = _clamp(sum(available) / len(available) + rng.gauss(0, 1.5))

                weak = sum(1 for v in available if v < 75)
                failing = max(0, int(round((78 - current) / 6)) + (1 if rng.random() < 0.12 else 0))
                failing = max(0, min(failing, 8))
                missing = max(0, int(rng.gauss(1.2, 1.8)))

                delta = None if previous_average is None else round(current - previous_average, 2)

                # A noisy latent score, NOT a band on `current`. Several
                # features contribute, weighted differently, plus noise —
                # and then a flat 8% chance the label is simply wrong,
                # which is what stops any single feature from perfectly
                # separating the classes.
                latent = (
                    (78 - current) * 0.09
                    + failing * 0.55
                    + weak * 0.35
                    + missing * 0.12
                    + (0.0 if delta is None else max(0.0, -delta) * 0.10)
                    + rng.gauss(0, 0.55)
                )
                intervened = latent > 0.85
                if rng.random() < 0.08:
                    intervened = not intervened

                rows.append({
                    'anonymous_student_id': learner_id,
                    'school_year': year,
                    'grade_level': grade_level,
                    'curriculum': curriculum,
                    'grading_policy': policy,
                    'reporting_system': reporting_system,
                    'period_index': period,
                    'ww_mean': round(ww, 2),
                    'pt_mean': round(pt, 2),
                    'exam_mean': '' if exam is None else round(exam, 2),
                    'current_average': round(current, 2),
                    'prev_period_average': '' if previous_average is None else round(previous_average, 2),
                    'trend_delta': '' if delta is None else delta,
                    'failing_subject_count': failing,
                    'weak_component_count': weak,
                    'missing_assessment_count': missing,
                    'outcome': 'intervention' if intervened else 'no_intervention',
                })

                previous_average = current

    return rows


def main() -> None:
    rows = generate()
    with open(OUT_PATH, 'w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=COLUMNS)
        writer.writeheader()
        writer.writerows(rows)

    counts = {}
    for row in rows:
        counts[row['outcome']] = counts.get(row['outcome'], 0) + 1
    print(f'Wrote {len(rows)} SYNTHETIC rows to {OUT_PATH}')
    print(f'Class distribution: {counts}')


if __name__ == '__main__':
    main()
