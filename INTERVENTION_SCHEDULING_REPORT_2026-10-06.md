# Intervention scheduling, deadlines, completion and expiry — report

Date: 2026-10-06. Branch `rule-based-academic-monitoring`. Nothing was committed, pushed or deployed. The new migration has **not** been applied to the working database.

Three kinds of status are kept apart in this report:
- **Code:** written in this repository.
- **Tested:** verified by an automated test or the isolated browser harness.
- **Live:** steps still needed on the working machine or on hosting.

## 1. What changed and why

Interventions previously had a Principal decision status:

| Stored status | Meaning |
|---|---|
| `recommended` | awaiting decision |
| `approved` | decided |
| `in_review`, `in_progress`, `monitoring` | ongoing decision states |
| `completed` | closed by the Principal |

Around that status, the adviser acknowledged the intervention and recorded **delivery** once (`delivered_at` / `delivered_by` / `delivery_notes`). There were no dates, no responsible staff and no expiry. The Principal's status dropdown accepted any value.

**Mapping.** The existing system was extended, not replaced:

| Lifecycle (shown) | How it is determined (`Intervention::lifecycle()`) |
|---|---|
| Awaiting decision | `status = recommended` or no decider. It is never scheduled and never expires. |
| Schedule not set | Decided, with no deadline. This covers legacy rows. It never expires. |
| Scheduled | Server time is before `starts_at`. |
| Ongoing | `starts_at` ≤ server time ≤ `deadline_at`. |
| Completed | A completion was recorded (`delivered_at` / `completed_at`), or the Principal closed it as `completed`. |
| Expired — Not completed | `status = expired` (persisted by the job), **or** the deadline has passed with no completion. The second case shows before the job runs. |
| Cancelled | `status = cancelled`. |

- **Status values.** `expired` and `cancelled` are new values in the existing `status` column. They are outside `OPEN_STATUSES`, so the existing "one open intervention per learner, subject and term" guard is unchanged.
- **Completion** reuses the delivery fields:
  - `delivered_at`: when the completion was recorded;
  - `delivered_by`: who recorded it;
  - `delivery_notes`: notes and evidence;
  - new `completed_at`: when the activity was actually done.
- **Adviser delivery** remains only for **unscheduled** interventions. A scheduled intervention is completed by its responsible staff.

## 2. Files

**Migration:** `database/migrations/2026_10_06_000001_add_scheduling_to_interventions.php`. It is additive, and existing rows are not rewritten.
- **New `interventions` columns:**
  - `title`, `instructions`, `completion_criteria`;
  - `responsible_user_id`, `starts_at`, `deadline_at`;
  - `completed_at`, `expired_at`, `expiry_processed_at`, `status_before_expiry`;
  - `cancelled_at`, `cancelled_by`, `cancellation_reason`;
  - `schedule_version`;
  - an index on `(status, deadline_at)`.
- **New tables:**
  - `intervention_requests`: schedule, extension and late-completion requests;
  - `intervention_events`: history, with a unique `(intervention_id, event, dedupe_key)`.

**App:**
- `app/Models/Intervention.php`: statuses, `EDITABLE_STATUSES`, `lifecycle()`, `schedulingAvailable()`, relations.
- New models: `app/Models/InterventionRequest.php`, `app/Models/InterventionEvent.php`.
- `app/Services/InterventionScheduleService.php`: every transition, plus eligibility, scope, expiry and term notices.
- `app/Services/InterventionTransitionException.php`.
- `app/Console/Commands/ExpireInterventionsCommand.php` (`dss:expire-interventions [--dry-run]`).
- `routes/console.php`: scheduled every minute, `withoutOverlapping`.
- New controllers: `app/Http/Controllers/Principal/InterventionScheduleController.php`, `app/Http/Controllers/Teacher/InterventionController.php`.
- `app/Http/Controllers/Principal/InterventionController.php`:
  - the status edit is limited to `EDITABLE_STATUSES`;
  - a status edit cannot change an expired or cancelled intervention;
  - "Completed" is refused for a scheduled intervention with no recorded completion;
  - new filter: Schedule not set / Expired-overdue / Pending requests.
- `app/Http/Controllers/Adviser/InterventionController.php`: delivery is refused for scheduled, expired and cancelled interventions (individual and group).
- `routes/web.php`: 5 Principal routes and 4 staff routes.

**Views:**
- New: `principal/intervention-show`, `teacher/interventions`, `teacher/intervention-show`, `partials/intervention-lifecycle-badge`, `partials/intervention-schedule-summary`, `partials/intervention-history`.
- Edited: `principal/interventions` (schedule cell, filter, editable statuses), `adviser/interventions` (schedule state and link; delivery hidden for scheduled), `adviser/advisory-overview` (deadline/status indicator), `layouts/app` (nav: "Interventions" for subject teachers, "Schedules & Completion" for advisers).

**Tests:**
- New: `tests/Feature/InterventionSchedulingTest.php` (17 tests), `tests/Browser/intervention-flow.mjs` (19 checks), `tests/Browser/isolated-artisan.php` (an isolated-only helper).
- Changed: `tests/Feature/RoleAccessMatrixTest.php`. The subject teacher now probes an intervention in their own class, the same pattern already used for assessments. Nothing was weakened.

## 3. Role and permission matrix

All rules are enforced on the server. Direct requests with changed IDs were tested.

| Action | Principal | Responsible staff | Other in-scope staff¹ | Out-of-scope staff | Admin |
|---|---|---|---|---|---|
| View schedule and history | all | ✓ | ✓ | 403 | 403 |
| Set or edit schedule, assign responsible staff | ✓ | — | — | — | 403 |
| Propose schedule / request extension | — | ✓ | ✓ | 403 | 403 |
| Record completion (while Ongoing) | — | ✓ (active and still eligible) | 403 | 403 | 403 |
| Late-completion correction request | — | ✓ | 403 | 403 | 403 |
| Approve or reject requests; Extend and reopen; Cancel (with reason) | ✓ | — | — | — | 403 |
| Encode scores | unchanged: Class Record teaching assignment only | | | | |

¹ "In scope" means the section's adviser or the teacher assigned to that section, subject and term.

**Responsible-staff choices** are limited to **active** advisers of the section and teachers assigned to that class and term (`eligibleStaff()`).
- If that person is later disabled or loses the assignment, they can no longer complete the intervention.
- The Principal sees "no longer eligible — reassign"; the history is kept.
- Being responsible grants nothing else. Tested: the responsible teacher still gets 403 on another section's Class Record.

## 4. Transition rules

| From | Event | To | Who | Guard (re-checked under a row lock) |
|---|---|---|---|---|
| Decided, no schedule | Set schedule | Scheduled / Ongoing | Principal | The deadline is after the start and in the future; the responsible staff is eligible; the version matches |
| Scheduled / Ongoing | Edit schedule | same, new times | Principal | Same checks. `schedule_version` +1 |
| Ongoing | Record completion | Completed | Responsible staff | Server time is ≤ deadline; start ≤ `completed_at` ≤ now; recorded once |
| Scheduled / Ongoing | (time passes the deadline) | Expired | System job | No completion, not cancelled, deadline < now (re-checked) |
| Expired | Extend and reopen / approved extension | Ongoing / Scheduled | Principal | New future deadline and a reason; no other open intervention for the same learner, subject and term; the responsible staff is still eligible |
| Expired | Approved late-completion | Completed | Principal approves the responsible staff's request | start ≤ actual ≤ the **lapsed** deadline; actual ≤ now |
| Any non-terminal | Cancel | Cancelled | Principal | A reason is required; pending requests become `superseded` |
| Completed / Cancelled | anything | — | — | Refused. These states are never expired, edited or reopened. |

**Requests:**
- A pending request **never** changes the approved schedule.
- Approval re-validates against the **current** intervention.
- A request made before the schedule changed (`based_on_version` older than the current version) is marked `superseded` instead of overwriting the newer schedule.
- Deciding a request a second time is refused.

## 5. Time and deadline boundaries

- **Timezone:** stored as Asia/Manila wall-clock time in DATETIME columns. That is the app's existing convention (`app.timezone = Asia/Manila`); the global timezone setting was not changed. Times display in Manila time, with labels such as "Start (Manila time)".
- **Input:** `datetime-local` read as Manila. A date **and** a time are required; malformed values (`2026-02-30T10:00`, a date with no time, free text) are rejected, and no cutoff hour is assumed.
- **Boundaries** (tested at 09:59:59, 10:00:00, 11:59:59, 12:00:00 and 12:00:01 for a 10:00–12:00 window):
  - Scheduled: now < start.
  - Ongoing: start ≤ now ≤ deadline.
  - Expired: now > deadline.
  - Completion **at** the deadline is on time.
- **Clock:** the server clock enforces every action; no browser countdown is used.
- **Not added:** a maximum duration, a grace period, or a minimum number of days.

## 6. Expiry scheduler

- **Code:**
  - `dss:expire-interventions` processes overdue rows in batches of 100.
  - Each row is re-read under a lock and its status and deadline are re-checked, so a completion, extension or cancellation that won the race is left alone.
  - It writes `status = expired`, `status_before_expiry`, `expired_at` (the deadline that lapsed) and `expiry_processed_at` (when it ran).
  - It adds one `expired` event, unique per deadline. The event has no actor, meaning the system wrote it.
  - It is registered in `routes/console.php` as `everyMinute()->withoutOverlapping(10)`.
- **Tested:**
  - expiry with no logged-in user;
  - reruns (no second change and no duplicate event);
  - catch-up after days of downtime (several rows in one run);
  - completed, cancelled and legacy rows untouched;
  - `--dry-run` changes nothing;
  - the schedule entry is listed by `schedule:list`;
  - the job ran successfully against isolated SQLite and isolated MySQL.
- **Independent of the job:** an overdue intervention already **shows** as Expired, and normal completion is refused once the deadline passes. A delayed scheduler cannot allow late completion, and GET pages write nothing.
- **Live: NOT operational.** No scheduler process runs on this machine, and none was installed. Until one runs, overdue records still read Expired and are refused correctly, but `status` is not persisted as `expired`. The persisted status is what the "Expired / overdue" filter, history and reports rely on.
  - **Windows/XAMPP (manual test):** in a console in the project folder, run `php artisan schedule:work`. Leave it open; it runs due tasks every minute. To run one pass: `php artisan dss:expire-interventions` (or `--dry-run`).
  - **Windows, unattended (not installed, needs your authorization):** create a Task Scheduler task that runs every minute, indefinitely: `C:\newxampp\php\php.exe C:\newxampp\htdocs\naggasican-dss\artisan schedule:run`, with "Start in" set to the project folder.
  - **Linux hosting:** add the cron entry `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`. On Railway or Docker, use a separate scheduler/worker process running `php artisan schedule:work`.
  - **If the scheduler stops:** the display and enforcement stay correct. Persistence resumes and catches up on the next run.
- The command does nothing (and says so) on a database without the migration.

## 7. Migration impact and deployment

- **Working database (read-only check):** 0 interventions, so no existing row is affected. The migration status there is **Pending**, and it was not applied.
- **Isolated runs:**
  - SQLite (every test run).
  - MySQL `naggasican_dss_loadtest`: up, then `down()` (rollback of this migration only), then up again, all clean. The browser flow then passed 19/19.
- **Live code before the migration:** this code is already served by Apache against the working database. It is guarded by `Intervention::schedulingAvailable()`:
  - scheduling pages and actions say "not available on this database yet";
  - the existing intervention pages work as before (checked: the full PHP suite and the role page checks);
  - the expiry command is a no-op.
- **Deployment sequence:**
  1. `mysqldump` backup.
  2. Deploy the code. It is safe against the old schema because of the guard.
  3. `php artisan migrate --path=database/migrations/2026_10_06_000001_add_scheduling_to_interventions.php`.
  4. `php artisan migrate:status` shows it as Ran.
  5. Start the scheduler (§6).
  6. Run `php artisan dss:expire-interventions --dry-run`.
- **Rollback:** the migration's `down()` was tested on isolated MySQL. Rows already written to the new tables would be lost, so back up first.

## 8. Tests run (2026-10-06, actual results)

- **PHP**, run in suite chunks after the feature (`php artisan test tests/Unit`, then `--testsuite Feature` by name range):

  | Chunk | Passed |
  |---|---|
  | Unit | 101 |
  | `[A-D]` | 438 |
  | `[E-L]` | 248 |
  | `[M-R]` | 241 |
  | `[S-Z]` | 281 |
  | **Total** | **1,309** |

  `InterventionSchedulingTest` was then extended to 17 tests (all passing), so the suite now has **1,310** tests.
- **JS:** `node --test tests/js/*.mjs` → 19/19.
- **Browser, isolated:**
  - `intervention-flow.mjs`: **19/19** on SQLite and **19/19** on isolated MySQL. The first SQLite run reported 16/19 because the test helper read flash messages from `<main>`, while the toast renders outside it. That was a test fix, not an app fix.
  - `run.mjs` 165/165; `concern-flow.mjs` 30/30.
  - Crawl: §8a.
- **Controlled clock:** every time case uses `travelTo` in tests. In the browser, time passing is simulated by moving one isolated record's schedule back. The computer clock was never changed.
- **`InterventionSchedulingTest` covers:**
  - boundaries and the Manila display;
  - invalid and reversed schedules;
  - completion by the responsible staff only;
  - 403 for unassigned, out-of-scope, wrong-role and disabled users;
  - reassignment after an assignment is removed;
  - expiry with no login, reruns and catch-up;
  - "shows Expired before the job, and the GET writes nothing";
  - cancellation with a reason; no edit back from a terminal state;
  - pending, outdated and rejected extensions;
  - reopening only through Principal approval, with both expired and reopened in the history;
  - late recording with and without approval, and the refusal to backdate late work;
  - completion-versus-expiry and extension-versus-expiry races, ending in one state;
  - stale form versions;
  - legacy rows (unscheduled, the old delivery path, completed rows preserved);
  - concerns independent of the intervention;
  - closed terms still locking score writes, with the notice shown;
  - scheduler registration and dry run.

### 8a. Crawl

`crawl.mjs`, isolated SQLite, after `isolated-artisan.php seed-interventions`:

| Role | URLs | Statuses | Problems |
|---|---|---|---|
| admin | 36 | 200 | 0 |
| adviser | 90 | 200 | 0 |
| subject_teacher | 3 | 200 | 0 |
| principal | 90 | 200 | 0 |

No server errors, broken links, error text or console errors.

## 9. Academic evidence stays separate (tested)

- Completing, expiring, cancelling or reopening an intervention changes **no** score, marker, grade or concern.
- A completed activity whose requirement is still Missing leaves the concern **open** and the learner **For Review**.
- No intervention change creates a concern.
- Expired never means Failed.
- The Principal's follow-up filter shows expired interventions without creating any concern.

## 10. Policy questions (not decided)

1. **Terms.** May an activity cross a term boundary? Today nothing blocks it. The page warns when the term is closed for academic recording, or when the deadline is after the term's recorded end date. Working data has no term dates, so only the closed-term notice can appear. Terms are never reopened.
2. **Deadline in the past.** Should the Principal be allowed to set a deadline that has already passed? Today every new or changed deadline must be in the future; a past one is refused.
3. **Subject teachers.** Should they also see unscheduled (legacy) interventions in their classes? Today they see every decided intervention in scope, scheduled or not.
4. **Completion versus closure.** Recording completion does not close the Principal's decision status. The Principal still closes it ("Completed", "Monitoring", …) after reviewing the outcome, as before. Confirm this two-step meaning.
5. **Late-completion window.** The actual completion time must be between the start and the lapsed deadline. Confirm that rule.

**Not added:** notifications or email. Alerts exist only on in-app pages.

## 11. Walkthrough

- **Principal:**
  1. Interventions → a row → **Set schedule**.
  2. Enter the activity, the responsible staff (only eligible staff are listed), the start and deadline (date **and** time, Manila), the instructions and the completion criteria.
  3. The same page later shows completion evidence, pending requests (**Approve / Reject**), **Extend and reopen** (expired only, with a reason), **Cancel** (with a reason) and the full history.
  4. The list filter **Schedule → Expired / overdue** gives the follow-up view.
- **Subject teacher:**
  1. Sidebar → **Interventions**: your classes and your responsibilities.
  2. Open one to see the schedule and status.
  3. While it is Ongoing and you are responsible: **Record completion** (completed time and what was done).
  4. Otherwise: **Request an extension** / **Propose a schedule**.
  5. After the deadline, if the work was done on time: **Completed on time but not recorded**, which goes to the Principal for approval.
- **Adviser:**
  - Sidebar → **Schedules & Completion** (same page, covering your advisory section).
  - Advisory Overview shows each intervention's schedule state and due time.
  - You record completion only when you are the responsible staff.
  - Scores are still encoded only through an assigned Class Record.

## 12. Activation on the working database (2026-10-06, authorized)

- **Backup:** `C:\newxampp\db_backups\naggasican_dss-before-intervention-scheduling-20261006-152136.sql`. The dump completed.
- **Applied:** only `2026_10_06_000001_add_scheduling_to_interventions` (batch 32). `--pretend` showed only ADD COLUMN, ADD CONSTRAINT/INDEX and CREATE TABLE statements. 0 migrations are pending.
- **Schema check:** the 14 new interventions columns, `intervention_requests`, `intervention_events`, their 7 foreign keys, the `(status, deadline_at)` index and the unique dedupe key are all present.
- **Preservation check:** checksums and row counts were identical before and after for 19 tables:

  | Table | Rows |
  |---|---|
  | students | 50 |
  | student_enrollments | 50 |
  | assessments | 1 |
  | assessment_scores | 0 |
  | assessment_learner_statuses | 0 |
  | grades | 0 |
  | academic_concerns | 0 |
  | interventions | 0 |
  | users | 7 |
  | sections | 4 |
  | subjects | 2 |
  | teaching_assignments | 4 |
  | teaching_assignment_history | 10 |
  | academic_years | 2 |
  | academic_terms | 6 |
  | transmutation_ranges | 82 |
  | activity_logs | 2,344 |
  | deped_subject_catalog | 141 |
  | subject_group_weights | 13 |

  `dss:check-integrity` reported nothing. The working database has **no** interventions, so there are no legacy rows to display.
- **Dry run:** `0 intervention(s) would expire.`
- **Live access (read-only GETs, nothing written):**
  - **Allowed (200):** Principal list and filters; Adviser "Schedules & Completion" (all and "mine"), Interventions and Advisory Overview; Subject Teacher "Interventions".
  - **Refused (403):** adviser → Principal interventions; Principal or admin → teacher interventions; admin → Principal interventions; subject teacher → Principal/adviser interventions.
  - **Not found (404):** unknown intervention ids.
- **Scheduler:** **not running**. There is no `php.exe` scheduler process and no Windows scheduled task. Run it yourself in a separate terminal:
  `cd /d C:\newxampp\htdocs\naggasican-dss && C:\newxampp\php\php.exe artisan schedule:work`

### Follow-up defects found and fixed

1. **Two open interventions possible (concurrency).**
   - *Cause:* approving an extension on an expired intervention while a new intervention for the same learner, subject and term was being created could leave **two open interventions**. Reproduced on isolated MySQL with two real processes: "open for learner/subject/term: 2". The guard's plain SELECT used the transaction's earlier REPEATABLE READ snapshot.
   - *Fix:* the guard now uses a locking read.
   - *Result:* 3 of 3 runs refused the approval and left exactly 1 open intervention; the reverse order also left 1.
2. **Activity log needed a web login.** Service calls without one (CLI, or a future job) failed on the activity-log insert, because `LogActivity::log` used only `auth()->id()`.
   - *Fix:* an optional `$userId` parameter; the service passes its explicit actor. The 60 other callers are unchanged.
3. **Stale requests after reopening.** A pending request made against an expired schedule (for example a late-completion claim) stayed "pending" after a reopen, although it could no longer be approved.
   - *Fix:* reopening now marks pending requests superseded, as completion and cancellation already did. The approved late-completion event now records the deadline it was judged against and the schedule version.

### Tests after activation

- **Full suite, run after all fixes:** `php artisan test tests/Unit`, then `--testsuite Feature` by name range:

  | Chunk | Passed |
  |---|---|
  | Unit | 101 |
  | `[A-D]` | 438 |
  | `[E-L]` | 253 |
  | `[M-R]` | 241 |
  | `[S-Z]` | 281 |
  | **Total** | **1,314** |

  JS: 19/19. The earlier full run (1,309) predates these changes.
- **Targeted:**
  - `InterventionSchedulingTest`: 21/21, 4 of them new for this step: refusal while another intervention is open; late completion judged against the lapsed deadline; scores, grades and concerns untouched; disabled or replaced staff.
  - `intervention-flow.mjs`: 19/19 on isolated MySQL and 19/19 on SQLite.
  - The MySQL race reproduction (`isolated-artisan.php race-*`) was run before and after the fix.

## 13. Integrated into the existing Interventions module (2026-10-06)

**Before**
- Advisers had two sidebar items:
  - "Interventions": acknowledge and deliver;
  - "Schedules & Completion": a separate list, plus a detail page under `/teacher/interventions`.
- The Principal's list showed the decision and the schedule without labels saying which was which.
- Legacy deliveries were labelled "Completed".
- The adviser page said "only the Principal moves an intervention's status forward".

**Now: one Interventions item per role, one record**
- **Principal.** Interventions shows:
  - a **Decision / Schedule** column: the decision badge with its own edit, then the schedule status, start–deadline, responsible staff and **Open**;
  - schedule filters: Schedule not set, Scheduled, Ongoing, Completed, Expired/overdue, Cancelled and Pending requests.

  **Open** goes to the same record's page (`principal/interventions/{id}`), which holds:
  - learner, type and reason, the decision (with a **Decision** form), acknowledgement and the concern;
  - **Set/Edit schedule**, completion details, requests (Approve/Reject), **Extend and reopen**, **Cancel**, and history.

  Student detail now links each intervention to it.
- **Adviser.** Interventions (`adviser/interventions`) shows, for each row:
  - Decision / Schedule, responsible staff, start–deadline and **Open** (`adviser/interventions/{id}`);
  - a Schedule-status filter, including "I am responsible";
  - delivery for unscheduled rows only;
  - an "Other classes you teach or are responsible for" table on the same page.

  Advisory Overview links to the same detail page.
- **Subject teacher.** Interventions (`teacher/interventions`) has decision and schedule status per row; **Open** goes to `teacher/interventions/{id}`.

**Labels**
- **Decision status:** the Principal's.
- **Schedule status:** Scheduled, Ongoing, Completed, Expired or Cancelled; legacy rows show "Schedule not set".
- **Adviser acknowledgement / delivery:** shown separately.
- **Academic concern:** shown separately.
- An unscheduled row that was delivered still reads "Schedule not set" (`Intervention::scheduleState()`), with "Delivered (adviser record — not scheduled)". It is never called a schedule completion.
- Effective expiry ("deadline has passed", shown from the server clock) is shown separately from the expiry the job records.

**Compatibility routes**
- Adviser `GET teacher/interventions` (filters mapped) redirects to `adviser/interventions`.
- Adviser `GET teacher/interventions/{id}` redirects to `adviser/interventions/{id}`. This happens only after the scope check: an out-of-scope id gets 403, not a redirect.
- POST endpoints are unchanged.
- Nothing was removed: the teacher list view is still the subject teacher's page.

**Schema and working data:** no schema change and no data change. The live check was read-only:
- 46 role pages returned 200, plus the 1 intended redirect;
- the new adviser URLs returned 200 / 403 / 404 as expected;
- checksums were unchanged.

**Tests run after the final change**
- **PHPUnit:** **1,320 passed** (Unit 101, `[A-D]` 438, `[E-L]` 259, `[M-R]` 241, `[S-Z]` 281).
- **JS:** 19/19.
- **New:** `InterventionWorkflowIntegrationTest` (6) covers:
  - one nav item per role;
  - old URLs redirect, with authorization kept;
  - adviser list: decision/schedule, filters, other scope;
  - Principal decision, schedule and history on one record, with each filter checked against the model;
  - legacy delivery is not relabelled;
  - group delivery cannot cover a scheduled intervention.
- **Assertions changed with the UI:**
  - `AdviserInterventionDeliveryTest`: "Ack. by" became "Acknowledged by", the label spelled out.
  - `InterventionSchedulingTest`: advisers use `adviser.interventions.show`; added: out-of-scope adviser gets 403 on the new route.
  - `AdviserInterventionTest`: unchanged. It had passed only because the old column header contained "Acknowledged"; it now passes on the row cell itself.
- **Browser:**
  - `intervention-flow.mjs` **24/24** on SQLite and on isolated MySQL (0 server errors). Added checks: adviser nav, old-URL redirect, the integrated list row, Open, other-scope row, and 390 px on the adviser and Principal lists.
  - `run.mjs` 165/165; `concern-flow.mjs` 30/30.
  - Crawl: 219 URLs, 0 problems.
- **Not tested:** physical phones (viewport emulation only).

**Scheduler:** not running. No `schedule:` process was found. This is separate from code correctness: overdue rows still display as Expired and refuse completion.

**Process note:** while debugging, I restored `tests/Feature/AdviserInterventionTest.php` with `git checkout --`, which takes the file from git's index. The test count in that chunk is unchanged (438) and the test body matches what I had read earlier. It cannot be fully proven that the file had no other uncommitted edits; its last commit is 8de48e8 (Sep 7).


## 14. Schedule authority moved to the responsible staff (2026-10-07)

Work order: the designated responsible teacher/adviser manages the schedule directly, with no Principal approval. This **supersedes** the request/approval and Principal-scheduling parts of sections 3, 4, 11 and 13. Everything else stands (expiry, boundaries, history, one-open rule, academic independence).

### 14.1 Authority — before and after

| Action | Before (2026-10-06) | Now |
|---|---|---|
| Decide (approve/reject) | Principal | Principal (unchanged) |
| Name or replace the responsible staff | Principal, inside the schedule form | Principal, through its own **Responsible staff** form (`principal.interventions.responsible`) |
| Set the first schedule | Principal | **Responsible staff**, directly |
| Reschedule before the start | Principal | **Responsible staff**, directly; a reason is required |
| Extend the deadline | Staff request → Principal approval | **Responsible staff**, directly; a reason is required; the new deadline must be later than the current one and than now; the start is kept |
| Reopen an expired intervention | Principal ("Extend and reopen"), or an approved extension | **Responsible staff**, directly; needs a new start and deadline plus a reason |
| Record completion | Responsible staff | Responsible staff (unchanged) |
| On-time completion recorded late | Staff request → Principal approval | Unchanged: staff send a correction, the Principal reviews it |
| Cancel | Principal | Principal (unchanged) |
| Monitoring, filters, history | Principal | Principal (unchanged) |

Reopen rules:
- The new deadline must be later than now and than the deadline that passed. The start cannot be in the past.
- The one-open rule is checked inside the transaction with a locking read.
- If the job has not yet recorded the expiry, reopening records it first, in the same transaction and with the job's dedupe key. The expiry is therefore never lost and never doubled (tested).

A direct change takes effect when saved. Every change writes an `intervention_events` row with:
- actor;
- recorded time;
- event;
- old and new start/deadline;
- reason;
- `schedule_version`.

It also writes an activity log entry: `schedule_intervention`, `reschedule_intervention`, `extend_intervention`, `reopen_intervention` or `assign_intervention_staff`. The new event label is "Deadline extended"; "reassigned" now reads "Responsible staff named".

**No backdating (new rule):** a start set by staff may not be before the current minute (server clock). This rule is my choice, not part of the work order.

### 14.2 Permission matrix (all enforced server-side; every write re-checks under a row lock)

| Who | View | Set / reschedule / extend / reopen | Complete | Correction | Name staff / cancel / review |
|---|---|---|---|---|---|
| Principal | all | **no**: retired endpoints refuse; staff routes return 403 by role | no | reviews | **yes** |
| Responsible staff (active, still the section adviser or the class's assigned teacher) | yes | **yes** | yes, while Ongoing | yes, when Expired | no (403) |
| Other in-scope staff (section adviser / class teacher, not responsible) | yes, with "You are not the responsible staff (X is)" | 403 | 403 | 403 | no |
| Replaced responsible staff | view only if still in scope | 403 at once: the version also moves, so an open form is refused | 403 | 403 | no |
| Disabled account | signed out (`EnsureAccountIsActive`); the service also refuses | refused | refused | refused | — |
| Out of scope | 403 | 403 | 403 | 403 | — |
| Admin | no route | — | — | — | — |

- Staff cannot self-assign: a `responsible_user_id` in a staff schedule payload is ignored (tested).
- Only the Principal route sets the responsible staff, and only to an eligible person.

Reasons shown when an action is unavailable (`InterventionScheduleService::availability()`, display only):
- "Not approved yet";
- "No responsible staff yet";
- "You are not the responsible staff (X is)";
- "Your responsibility is no longer valid…";
- "Completed / Cancelled: the schedule can no longer change";
- "Another open intervention exists…" (on an expired one).

A scheduled intervention not yet started says when completion becomes possible.

The Principal's **Completed** decision option is disabled, with the label "needs a recorded completion", while a scheduled intervention has no completion. The server already refused it.

### 14.3 Old requests and endpoints

- **Legacy schedule/extension requests:**
  - labelled "(previous approval workflow)" and kept;
  - they never block a direct change;
  - a direct schedule, extension or reopen marks every pending one `superseded` in the same transaction (note and `request_superseded` event);
  - they are never executed: Approve is refused with 410 and the message "…can only be dismissed (Reject)". The Principal sees only **Dismiss (Reject)**.
  - Staff can no longer create them: the controller and the service (`submitRequest`, 410) refuse them.
- **Retired Principal write endpoints:**
  - `POST principal/interventions/{id}/schedule` and `/reopen` remain as routes so that old forms get a clear refusal;
  - HTML requests are redirected to the record with the message "…set, extended and reopened by the responsible staff… Nothing was changed.";
  - JSON requests get **410**;
  - nothing is written (attributes compared before and after).
- **GET redirects** from section 13 are unchanged.
- **Kept:** the on-time-completion correction and its Principal review (Approve/Reject), judged against the deadline that actually lapsed.
- **Working DB:** checked read-only on 2026-10-07. It has 0 pending legacy requests: 1 intervention, scheduled, with responsible staff, 4 events, and 1 old extension request already *approved*.

### 14.4 UI

**Staff detail** (`adviser/interventions/{id}`, `teacher/interventions/{id}`) — forms appear only when allowed:
- **Set schedule / Reschedule** (activity, start, deadline, instructions, criteria, and a reason when rescheduling);
- **Extend deadline**;
- **Record completion**;
- **Reopen and set schedule**;
- **Completed on time but not recorded**.

The request/extension form is gone.

**Principal detail:**
- the Schedule and "Extend and reopen" forms are removed;
- a **Responsible staff** form is added, with "Waiting for X to set the schedule";
- the Principal list says "Open · name responsible staff" or "X sets the schedule".

**Errors shown once:**
- each form has its own error bag and a `_form` marker, so a rejected form shows its field errors and keeps its input only in itself;
- a refused action is shown **inside the submitted form**, persistently, instead of in the 4-second toast (`partials/intervention-form-error`, plus `error_inline` in the layout toast);
- if that form is no longer offered (the state changed), the message appears at the top of the page;
- direct POSTs without the marker keep the toast.

This also fixed an intermittent browser-check failure: the toast had already vanished by the time the page text was read.

### 14.5 Files changed

**Code:**
- `app/Services/InterventionScheduleService.php`:
  - `assignResponsible`, staff `schedule`, `extend`, staff `reopen`, `availability`;
  - `persistExpiry` (shared with `expireOverdue`), `assertNotBackdated`, `supersedeLegacyScheduleRequests`;
  - `assertResponsible($action)` with a no-staff message;
  - `submitRequest` and `decideLocked` refuse legacy types;
  - the legacy approve branches are removed.
- Controllers:
  - `app/Http/Controllers/Principal/InterventionScheduleController.php`: `responsible`, `retired`; schedule and reopen removed; error bags.
  - `app/Http/Controllers/Teacher/InterventionController.php`: `schedule`, `extend`, `reopen`; `request` handles the correction only; `guard`, `times`, error bags, inline errors, term notices.
- Models:
  - `app/Models/InterventionRequest.php`: legacy labels, `isLegacy()`, doc;
  - `app/Models/InterventionEvent.php`: labels.
- `routes/web.php`:
  - `principal.interventions.responsible`;
  - `principal.interventions.schedule` and `.reopen` mapped to `retired`;
  - `teacher.interventions.schedule`, `.extend`, `.reopen`.

**Views:**
- `principal/intervention-show`, `principal/interventions`, `teacher/intervention-show`, `adviser/interventions` (wording);
- partials `intervention-history` (legacy = Dismiss only), `intervention-schedule-summary` (comment) and `intervention-form-error` (new);
- `layouts/app` (toast skips inline errors).

**Tests:**
- `tests/Feature/InterventionSchedulingTest.php` (rewritten for staff authority, 21 → 30);
- `tests/Feature/InterventionWorkflowIntegrationTest.php`;
- `tests/Browser/intervention-flow.mjs` (24 → 33 checks);
- `tests/Browser/isolated-artisan.php` (race uses the staff reopen).

**Build:** `public/build` (npm run build).

**Schema/data:** **no migration, no schema change.** No working-DB write: the working DB was only read (SELECT counts, migrate:status: 0 pending). No test or demo records were created there.

### 14.6 Tests run (2026-10-07, actual results)

**PHPUnit:** 1,329 tests.
- Unit: 101 passed.
- `[A-D]`: 438 passed.
- `[E-L]`: 268 passed.
- `[M-R]`: 241 passed.
- `[S-Z]`: 280 passed, **1 failed**.

The failure is `SeederCredentialsTest::test_local_without_a_configured_password_gets_a_random_one_never_the_default`:
- It is pre-existing and intermittent. Alone it failed 1 of 12 runs; in the S–Z chunk it failed 2 of 2.
- Cause: the seeder prints a random `Str::password(20)` through the console formatter, which rewrites some symbol sequences (verified: `ab\<x` prints as `ab<x`). The shown password then differs from the stored one.
- Neither the seeder nor the test was touched by this work. **Not fixed** (outside this work order). Fix suggestion: escape the password with `OutputFormatter::escape()` or generate it without symbols.

**New or rewritten coverage in `InterventionSchedulingTest`:**
- the responsible subject teacher and adviser set schedules directly;
- reschedule needs a reason; once Ongoing, rescheduling is refused;
- direct extension: old/new/actor/reason/version recorded, start kept, boundary at the deadline vs 1 s after;
- unapproved and no-staff interventions are refused;
- in-scope non-responsible, out-of-scope, disabled and replaced staff are refused, with an immediate version bump;
- old Principal endpoints cannot bypass (attributes unchanged, JSON 410, Principal gets 403 on staff routes, self-assign ignored);
- invalid dates and missing reasons are rejected;
- reopen keeps history, including an expiry not yet recorded by the job (no duplicate when the job runs later);
- reopen is refused while another intervention is open;
- completed and cancelled interventions refuse edits;
- legacy requests are superseded atomically, Approve is refused and Reject allowed, staff cannot create new ones;
- a correction is judged against the deadline that lapsed;
- stale-version conflicts on schedule and extend;
- the Completed option is disabled without a completion;
- each error is shown once (field errors and refused actions, plus the fallback when the form is gone);
- scores, markers, grades and concerns are unchanged after reschedule, extend, expire, reopen, expire, correction and cancel.

**Other suites:**
- `RoleAccessMatrixTest` passes against the live route table, with the new routes.
- **JS:** 19/19.

**Browser** (isolated harness only):
- `intervention-flow.mjs` **33/33** on SQLite (4 runs) and isolated MySQL (3 runs, after the inline-error fix). Two earlier runs had one failing check (the toast timing above); it was fixed and rerun.
- The flow: Principal names the staff → staff sets the schedule (reversed schedule shows its error once) → staff extends → Ongoing → staff completes → Principal sees history; the retired endpoint returns 410 → Principal names Darien → Darien schedules → expired (shown before the job, then persisted by the job) → concern stays open → the in-scope non-responsible adviser can only view and gets 403 on a direct POST → reopen without a reason is refused (server) → Darien reopens → Principal sees the expiry and the reopening with the reason; 390 px, labels, keyboard focus, no JS errors.
- `run.mjs` 165/165 and `concern-flow.mjs` 30/30 on both SQLite and MySQL.
- Crawl: 183 URLs, all 200, 0 problems.

**Concurrency** (isolated MySQL, `naggasican_dss_loadtest`):
- A staff reopen while a new open intervention is being stored under the learner lock is refused ("Another open intervention…").
- In the reverse order, the store is refused.
- Open count is 1 in both cases.

**Not tested:** physical phones.

### 14.7 Blockers / open items

- None for this change.
- Pre-existing `SeederCredentialsTest` flake: see 14.6.
- Policy: the "no backdated start" rule is my choice, not specified by the work order. Confirm or relax it.

### 14.8 Scheduler status

**Not running.** No `schedule:work` or `schedule:run` process was found, and there is no Windows task. Nothing was started. Overdue interventions still display as Expired and refuse completion; staff can reopen them, and reopening records the expiry itself.

### 14.9 Demo walkthrough

1. **Principal** › Interventions: the row shows "Open · name responsible staff". Open it, choose the adviser or class teacher in **Responsible staff**, and save. The page then reads "Waiting for X to set the schedule".
2. **That staff member** › Interventions › Open: **Set schedule** (start, deadline, criteria) takes effect at once. **Extend deadline** needs a later deadline and a reason.
3. The **Principal** reopens the record: the history shows "Schedule set" and "Deadline extended" by that staff member, with the reason.
4. Once Ongoing, the staff member uses **Record completion**.
5. Expired case: the staff member sees **Reopen and set schedule** (new start, deadline, reason). They can use "Completed on time but not recorded" instead; that one goes to the Principal for review.
6. Another adviser or teacher of the class sees "You are not the responsible staff (X is)" and no forms.

### 14.10 `AdviserInterventionTest.php` — possible loss of uncommitted edits

Investigated without overwriting anything:
- The editor history snapshot (`.claude/file-history`, v2, 2026-09-06) is byte-identical to the current file and to HEAD `8de48e8` (2026-09-07).
- No other backup or snapshot of the file was found.

Conclusion: no tracked uncommitted edit was lost. The only uncertainty is an edit made after 2026-09-07 that no tool recorded; it cannot be proven either way. Its 438-test chunk count was unchanged at the time.
