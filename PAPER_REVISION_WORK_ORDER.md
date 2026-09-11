# PAPER REVISION WORK ORDER

For the person revising the capstone paper, not the engineer. Each item
says what to change, where it likely appears in a paper describing this
system, and why the change is true — with enough context that you don't
need to have watched the work happen to trust it or check it yourself.

Every claim below was verified directly against this codebase (a real
query, a real test run, a real file inspection), not carried over from an
earlier draft or an instruction. Where a source is secondary rather than
the signed original, that is stated plainly — say so in the paper too,
rather than letting the distinction disappear in revision.

---

## 1. The DO 015 transmutation table verification — upgrade the claim

**If the paper currently says** the 41-band DO 015, s. 2026 transmutation
table is unverified, or cites only a secondary reproduction: that claim is
stale and should be strengthened.

**What actually happened**: all 41 bands seeded by `Do015TransmutationSeeder`
were checked band-by-band against `HELPER!B7:D47` of the official DepEd
Strengthened SHS Electronic Class Record for SY 2026-2027 (`ECRSHS2026`,
`2026_v1.0`) — DepEd's own operational instrument, not a secondary write-up
of it. 41 of 41 matched exactly: same minimum, same maximum, same
transmuted grade. A regression test (`Do015BandsMatchOfficialEcrTest`)
holds the extracted bands as a fixture and checks them against the seeder,
so this can't silently drift.

**What to write**: the table is confirmed against DepEd's own SY 2026-2027
instrument. It is still **not** the signed text of DO 015, s. 2026 itself —
say that distinction plainly rather than letting "verified against DepEd's
instrument" read as "verified against the signed order." The two are
different levels of authority and the paper should let a reader tell them
apart.

## 2. The DO 8, s. 2015 five-row weighting table — keep the caveat, don't drop it in revision

**Where this shows up**: any table or paragraph describing Grade 12's
DO 8, s. 2015 weighting scheme (Core / Academic-other / Academic-Work-
Immersion / TVL-Sports-Arts-other / TVL-Sports-Arts-Work-Immersion).

**Why it needs a caveat**: unlike the DO 015 catalog (verified against
DepEd's own instrument, item 1 above), these five rows were read from
secondary reproductions of the DO 8, s. 2015 table — not the signed PDF of
the order. If a revision pass tightens prose and this caveat looks like
hedging to be trimmed, don't trim it; it's a specific, checkable claim
about provenance. Confirm against the signed order before removing it.

## 3. A genuinely interesting result worth its own paragraph: one school, two curricula, two grading orders, at once

**What to write, if not already there**: the system's hardest design
problem wasn't a UI or database question — it was that the client school
runs Grade 11 under the newly-introduced Strengthened SHS curriculum
(DO 015, s. 2026) and Grade 12 under the outgoing 2013 curriculum
(DO 8, s. 2015) in the **same school year**, with different subject
weighting logic (DO 015 weights by subject *group*, six of them; DO 8
weights by *track*, a different axis entirely) and different transmutation
tables. This is not a hypothetical transition-year edge case — it is the
system's actual operating condition for its first year, and it's why the
schema carries a `curriculum` column on `sections`/`specializations`
distinct from grade level, rather than inferring the grading scheme from
grade level alone.

**Caution — what NOT to write**: do not cite specific section names or
enrollment counts as confirmed fact. See item 6.

## 4. The catalog vs. the silent subject-group default — a countable, citable result

**What to write**: `subjects.subject_group` has no cluster-aware assignment
logic anywhere in the codebase — every subject silently defaults to
`core_academic` (20% Written Work / 50% Performance Task / 30% Examination)
unless something explicitly overrides it. Checked against the official
141-row DepEd catalog directly (excluding the two teacher-supplied `OTHER
ELECTIVE` rows, which publish no fixed weight to compare against): of the
remaining 139 rows, only **38 actually agree** with that default — exactly
the Core, STEM, and Business & Entrepreneurship clusters, which genuinely
are 20/50/30. **101 of 139 disagree.** This is a real, systematic gap
between a common silent default and DepEd's own published weights, not an
edge case — it is the majority case.

**Where to cite it**: this is exactly the kind of finding a "results" or
"evaluation" section wants — it's a specific, countable, re-checkable claim
(`CatalogDisagreesWithSilentDefaultTest` pins the exact counts), not an
impression.

## 5. The Examination role split is per-subject — corrected counts, verify before citing the old numbers

**If an earlier draft says** "nine subjects carry Term Exam at 100 with no
summative tests" or similar: that number conflated two different things
and is wrong. Use the corrected breakdown below instead — verified directly
against the seeded catalog (`DepedSubjectCatalog::rowsFromCsv()`), not
assumed from an earlier count.

- **Eight subjects are TE-only** (an Examination component exists, but is
  100% Term Exam with no summative tests): four Arts Apprenticeship
  variants, Field Exposure (Off Campus), and In-Campus Field Exposure for
  Sports — six Field Experience-cluster rows — plus Advanced Mathematics
  and Basic Calculus, two STEM rows.
- **Nine subjects have no Examination component at all** — a materially
  different case from TE-only, not a rounding of it: Design and
  Innovation, Research 1, Research 2, and the six Work Immersion variants
  (one Academic Track, five Tech-Pro Track by hour/term-length variant).

The earlier "nine, six Field Experience/two STEM/one Work Immersion" count
had folded Work Immersion into the TE-only group. It doesn't belong there —
Work Immersion has no Examination component to be "TE-only" about. If the
paper states either number, use the corrected eight/nine split above.

## 6. Do not present Shakespeare, Curie, or the 22/39/20 counts as confirmed enrollment

**This is the most important correction in this document.** If any part of
the paper — methodology, system description, sample data description —
names the client's sections as "Shakespeare" and "Curie" (Grade 11) or
states Grade 12 section counts as ABM 22 / HUMSS 39 / STEM 20, **that data
is not confirmed**. It came from a screenshot and from sample files
generated for testing during development, not from the school. The school
has not sent an actual roster as of this writing.

**What is real and citable**: the *shape* of the client's situation — one
school, two curricula (Strengthened SHS for Grade 11, the outgoing 2013
curriculum for Grade 12), two grading orders, operating simultaneously —
is real and drove real design decisions in the system (see item 3). The
specific section names and per-section counts are not confirmed and should
either be removed, or explicitly marked as illustrative/as-communicated
rather than verified enrollment data, wherever they appear.

**If the paper needs a concrete example** of the DSS actually working
end-to-end, use the pilot section **Molave** instead (Grade 11, 40 real
learners, General Mathematics and Oral Communication, three real terms of
assessment evidence) — this is real, loaded, exercised data, not a
placeholder. Historical note, not a live caveat: Molave carried
`specialization = ABM`, a leftover value from an earlier curriculum
assumption that didn't correctly describe an SSHS section (SSHS has no
strands); this was corrected (set to `NULL`) on 2026-09-11, after the
"ECR alignment" work order's Part 6 elective-assignment mechanism made it
safe to do so. Nothing about Molave's setup needs a caveat if citing it now.
