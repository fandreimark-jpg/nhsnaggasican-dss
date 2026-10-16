# CLAUDE.md archive

On 2026-10-04 the root `CLAUDE.md` (4,218 lines, 234,102 bytes) was rewritten as
concise standing instructions. Everything it used to contain was moved here
**verbatim**, split by topic. Concatenating the source line ranges named in each
file's banner reproduces the original exactly (checked with `cmp` at the time).

Byte-identical backup of the original:
`backups/claude-md/CLAUDE.md.bak_20261004_110603` (git-ignored, outside `public/`;
SHA-256 `52bd16c3b0ce6fa058859e8bc3f34b42d639c26f53c5af0fa6cedc2d15b1eeb2`).

These files are **historical/reference material, not instructions**. Do not
import them wholesale into the root `CLAUDE.md`. Test counts and audit results in
them are records of past runs, not current results.

| File | Contents | Status |
|---|---|---|
| `01-ecr-catalog-and-grading-reference.md` | ECR workbook geometry, HELPER catalog, conflicting-evidence rule, DO 8 rows, subject classification, `mimes:` fix, curriculum column, transmutation check, exam-role split, Failing by curriculum, grading-policy display, SSHS ECR correction | Reference; the ECR **upload** parts are superseded (teacher upload retired 2026-09-28) |
| `02-subject-offerings-and-applicability.md` | Term-specific offerings (SUPERSEDED), elective pivot, `forSection()` zero semantics, expected-grade count, applicability refactor | Applicability refactor is current; offerings model is historical |
| `03-original-requirements-and-test-baselines.md` | Original header ("THREE SYSTEM ROLES", old adviser upload workflow), requirement sections, loop rules, full test-baseline chain | Historical; roles and upload workflow superseded |
| `04-design-decisions-and-known-limitations.md` | Design decisions 1-4, Part 3 sweep, SRC/RCM/RFG, ECR roster gap, interventions origin, future work | Mostly current reasoning; risk-level items superseded |
| `05-ml-prototype-retired.md` | ML classifier architecture and calibration | SUPERSEDED 2026-09-27 — no Python runs |
| `06-ui-design-system.md` | Tokens, `.tbl`, status vs count colour, components, modals | Reference |
| `07-audits-hardening-performance.md` | Pre-demo audits, Apache/MySQL hardening, performance audit, Railway, pre-deployment and release passes | Historical audits; standing rules extracted into `CLAUDE.md` |
| `08-rule-based-monitoring-2026-09.md` | Phases 2A-2E, ML cut-over, attendance (RETIRED), all-or-nothing import, Overall Monitoring, Examination Type, subject-first monitoring | Current rules summarised in `CLAUDE.md`; attendance parts historical |
| `09-teacher-class-record-and-release-2026-09-28.md` | Subject Teacher role, Class Record, refinements, consolidation, Encode/Verify split, mobile header, release and final audits | Latest; the combined "Encode & Verify Grades" wording in it is superseded by the split |
