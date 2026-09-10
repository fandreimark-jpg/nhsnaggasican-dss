# NAGGASICAN NHS DSS — COMPLETE WORK ORDER

Everything outstanding, in one document, in the order it should be done.
Self-contained: nothing here refers to another file.

Save this in the project root next to `CLAUDE.md`, then tell Claude Code:

> Read WORK_ORDER.md and follow it in order. Start at Part 1. Stop at each
> STOP POINT and report before continuing.

---

## EXECUTION RULE — applies to every part

**Do not stop after generating code.** Run the required commands, fix errors,
verify migrations and seeders, and keep iterating until the application is
functional and the requested features are implemented.

After every part:

1. `php artisan migrate:status` — confirm only intended migrations applied.
2. `php artisan test` — the full suite, not a filtered subset.
3. `npm run build` — mandatory after any Blade, CSS, JS, or Tailwind config
   change. `php artisan view:clear` alone is not enough in this project.
4. Walk the affected workflow in the browser as the affected role.
5. On failure: read the error, find the root cause, fix it, re-run. Do not
   report the error and stop.

Never write "done" unless verified. If something is still broken, write
`STATUS: INCOMPLETE`, state the exact problem, and keep working.

**Database safety.** `php artisan migrate:fresh` and `db:wipe` are forbidden —
there is real pilot data that cannot be regenerated. Before any migration:

```
mysqldump -u root naggasican_dss > backup_$(date +%Y%m%d_%H%M).sql
```

**Scope guard.** No part of this changes a grade calculation, risk
classification rule, In-Term Status rule, authorization rule, or intervention
workflow unless that part explicitly says so. If a change appears to require
touching `GradingEngine`, `InTermStatusService`, `RiskFeatureExtractor`,
`TransmutationService`, or any policy or middleware without being told to —
stop and ask.

**Nine stop points.** Stop and report at each one.

---

## WHERE TO STOP

Parts 1 through 6 are needed for the defence.

Parts 7, 8, and 9 are only needed if the school will actually run this beyond
the pilot section. If the goal is the defence, stop after Part 6 and write those
three up as "Recommendations for future work" in the paper. A capstone that
names its own limits precisely reads as stronger than one that claims none.

---

## PART 1 — Get the tree clean

The user does this, not Claude Code.

Roughly 30 modified files and 27 unpushed commits exist with no backup
anywhere. Everything below adds more on top of them.

```
git status
git add -A
git commit -m "<describe the existing work>"
git push -u origin main
```

Check the `git status` output before `add -A`. `.env` must not appear in it.

Then record the baseline:

```
php artisan test
php artisan route:list > routes_before.txt
```

Also note the current Adviser and Principal dashboard numbers, and Molave's
completion counts for all three terms. Any of these that moves later in this
work order is a bug, not a redesign.

**STOP POINT 1.** Report the push result and the baseline test count.

---

## PART 2 — Intervention `origin`

### The problem

`interventions` has `recommended_type` and `recommendation_reason`, and both are
filled the same way whether the DSS generated the recommendation or the
Principal recorded a decision by hand. No column records which happened.

So the interface states that the DSS recommended things it never recommended.
`CLAUDE.md` says the DSS recommends and the Principal decides; right now the
record cannot tell them apart.

### 2a — Migration

```php
Schema::table('interventions', function (Blueprint $table) {
    // Which of the two paths in CLAUDE.md's "DSS recommends, Principal
    // decides" actually produced this row. Without it the interface
    // claimed DSS authorship for records a Principal entered by hand.
    $table->string('origin')->default('dss')->after('risk_result_id');
});
```

Backfill honestly in the same migration. A blanket `dss` default is wrong for
existing rows — it asserts something unverified about every one of them.

Use the evidence that exists: a row with a non-null `risk_result_id` came from
the classifier and is `dss`. A row without one was recorded by hand and is
`principal`. If a row is genuinely ambiguous, set `unknown` rather than
guessing. A third value that admits uncertainty beats a confident wrong one —
this whole part exists because of a confident wrong claim.

Report how many rows landed in each bucket.

### 2b — Model

Add `origin` to `$fillable` on `App\Models\Intervention`, plus:

```php
public const ORIGINS = ['dss', 'principal', 'unknown'];

public function isDssRecommended(): bool
{
    return $this->origin === 'dss';
}
```

### 2c — Set it at creation

Grep for `Intervention::create`. `InterventionRecommender` and the Principal's
record-intervention action are the two known callers — report anything else
found. The recommender sets `dss`; the manual path sets `principal`.

### 2d — Interface

Anywhere the interface attributes a recommendation to the DSS, it must check
`origin` first. A `principal` row reads "Recorded by the Principal" and does not
display `recommendation_reason` as though the system produced it. An `unknown`
row says the origin was not recorded.

Check at minimum `principal/interventions.blade.php`,
`adviser/interventions.blade.php`, `principal/student-detail.blade.php`.

### 2e — Tests

- A recommender-created intervention has `origin = 'dss'`.
- A Principal-created one has `origin = 'principal'`.
- The interface does not claim DSS authorship for a `principal` row.
- The backfill assigns by `risk_result_id` presence.

**STOP POINT 2.** Report the backfill counts per origin value.

---

## PART 3 — The six stuck interventions

Six interventions are recorded but not approved, and this blocks the adviser.

**Diagnose before changing anything. Do not mass-update rows.**

1. Query them: which students, which subjects, which term, what status, created
   when, by whom.
2. Trace what "blocked" means — which screen the adviser hits and what the code
   does at that point. Find the actual condition in a specific controller, not
   a guess.
3. Report the finding before proposing a fix.

Then decide which of these it is:

- **A workflow gap** — no route exists from `recommended` to `approved` for
  this case. A bug; the fix is code.
- **Correct behaviour** — the Principal genuinely has not decided, and the
  system is right to wait. The fix is the Principal deciding, and the interface
  should make plain what is waiting on whom.

These need opposite fixes. Adding an auto-approve path when the answer is the
second would break the rule that no intervention is ever approved
automatically — a core design decision in `CLAUDE.md`.

**STOP POINT 3.** Report the diagnosis and which of the two it is, before
writing any fix.

---

## PART 4 — Design tokens and shared components

Everything visual after this reads from here. Nothing later hardcodes a hex
value or picks an arbitrary Tailwind colour.

### 4a — The palette rule

This system uses colour to mean two different things, and they must never be
confused. The palette is split in two and the split is enforced by naming.

In `tailwind.config.js`, inside `theme.extend.colors`, alongside `brand` and
`gold`:

```js
// STATUS — warm family. These four colours mean one thing each and are
// used ONLY for the four DSS states. A plain count, a card border, a nav
// item, or a decorative accent must never take one of these, because a
// reader who has learned that amber means Needs Attention will read
// amber that way everywhere.
status: {
    ontrack:   '#3b892d', // brand-800 — the institutional green
    attention: '#b45309', // amber-700 — readable on white
    risk:      '#b91c1c', // red-700
    failing:   '#7f1d1d', // red-900 — an outcome, graver than At Risk
},

// COUNT — cool family. Plain master-data counts and other neutral
// figures. Deliberately cool so no count can be mistaken for a status at
// a glance. Eight hues so eight cards stay distinguishable.
count: {
    1: '#0284c7', 2: '#2563eb', 3: '#4f46e5', 4: '#7c3aed',
    5: '#9333ea', 6: '#0891b2', 7: '#0d9488', 8: '#475569',
},
```

Grep every `red-`, `amber-`, `yellow-`, `orange-`, `rose-` in
`resources/views/`. For each, decide: is this a DSS status? If yes, replace with
the `status.*` token. If no, it is in the wrong family — move it to a `count.*`
hue or to neutral grey. Do not change which state gets which colour; only change
where the colour is defined.

Expect at least `partials/in-term-status-badge.blade.php`,
`principal/dashboard.blade.php`, `principal/students.blade.php`,
`adviser/dashboard.blade.php`, and the at-risk partial. Grep for the rest.

Add to `CLAUDE.md` under a new "Design system" heading:

> Warm colour means DSS status and nothing else. Cool colour means a count.
> Grey means structure. A plain number never takes a status colour, and a
> status is never shown by colour alone — it always carries its word.

### 4b — Stat card

Create `resources/views/components/stat-card.blade.php`:

```blade
@props([
    'label',
    'value',
    'accent' => 'count-8',  // count-1..8, or status-ontrack etc.
    'icon'   => null,       // bootstrap-icon name without the "bi-" prefix
    'href'   => null,       // makes the whole card a link
    'note'   => null,
])

@php $tag = $href ? 'a' : 'div'; @endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif
   {{ $attributes->merge(['class' =>
      'block bg-white rounded-lg p-4 shadow-sm border-t-4 border-'.$accent.
      ($href ? ' hover:shadow-md hover:-translate-y-0.5 transition' : '')]) }}>
    <p class="text-xs text-gray-500 flex items-center gap-1.5">
        @if($icon)<i class="bi bi-{{ $icon }} text-gray-400"></i>@endif
        {{ $label }}
    </p>
    <p class="text-2xl font-bold text-gray-800 mt-1">{{ $value }}</p>
    @if($note)<p class="text-xs text-gray-400 mt-0.5">{{ $note }}</p>@endif
</{{ $tag }}>
```

The accent class is built at runtime, so Tailwind's scanner will not see it.
Add to `tailwind.config.js`:

```js
safelist: [
    { pattern: /border-(count|status)-(1|2|3|4|5|6|7|8|ontrack|attention|risk|failing)/ },
],
```

After `npm run build`, verify the borders actually render in colour. If they
come out grey the safelist did not match — fix it before moving on. This is the
single most likely thing in this work order to fail silently.

### 4c — Panel

Create `resources/views/components/panel.blade.php`:

```blade
@props(['title' => null, 'subtitle' => null, 'action' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg shadow-sm']) }}>
    @if($title)
        <div class="px-4 py-3 border-b border-gray-100 flex items-start justify-between gap-4">
            <div>
                <h3 class="font-semibold text-gray-800 text-sm">{{ $title }}</h3>
                @if($subtitle)<p class="text-xs text-gray-500 mt-0.5">{{ $subtitle }}</p>@endif
            </div>
            @if($action)<div class="text-xs shrink-0">{{ $action }}</div>@endif
        </div>
    @endif
    <div class="p-4">{{ $slot }}</div>
</div>
```

### 4d — Table classes

The app has roughly two dozen tables across 22 Blade files using several
different `<thead>` class strings. Define the look once, in
`resources/css/app.css`:

```css
@layer components {
    /* One definition for every table in the app. Each Blade file used to
       carry its own class string; the variants had drifted, so the same
       column read differently on two screens. */
    .tbl-wrap   { @apply bg-white rounded-lg shadow-sm overflow-hidden; }
    .tbl-scroll { @apply overflow-y-auto max-h-[32rem]; }
    .tbl        { @apply w-full text-sm text-left; }
    .tbl thead  { @apply bg-gray-50 text-gray-500 text-xs uppercase tracking-wide; }
    .tbl thead th       { @apply px-4 py-3 font-medium whitespace-nowrap; }
    .tbl tbody tr       { @apply border-t border-gray-100; }
    .tbl tbody tr:hover { @apply bg-brand-50; }
    .tbl tbody td       { @apply px-4 py-3 text-gray-700 align-middle; }
    .tbl-sticky thead   { @apply sticky top-0 z-10 shadow-[0_1px_0_rgb(229,231,235)]; }
    .tbl-num    { @apply text-right; }
}
```

Rewrite every table view to use them: `<div class="tbl-wrap">` around the table,
`class="tbl"` on it, plus `tbl-sticky` where the file already had
`sticky top-0`. Delete the per-file `thead`/`th`/`td` class strings. Numeric
columns get `tbl-num` on both `th` and `td`.

This is a class-attribute change. Keep every table's content, sort order,
pagination, badge partial, and action button exactly as they are.

### 4e — Type and spacing scale

Four sizes. Any page that invents a fifth is wrong.

| Role | Classes |
|---|---|
| Page title | `text-xl font-bold text-gray-800` |
| Section heading | `text-sm font-semibold text-gray-800` |
| Card label / table header | `text-xs text-gray-500` |
| Card number | `text-2xl font-bold text-gray-800` |

Spacing: `gap-3` between cards, `mb-4` between sections, `p-4` inside cards.
Grep for `gap-2` and `gap-4` in dashboard views and normalise.

**STOP POINT 4.** Show one rendered stat card in each accent family and confirm
the safelist works.

---

## PART 5 — Admin dashboard

A previous pass replaced the eight count cards with an inline text strip and
added a table. Both are wrong. This puts the cards back and takes the table out.

**Read `resources/views/admin/dashboard.blade.php` in full first** and report
what is actually in it before editing. Do not assume.

Admin's dashboard answers one question: *is the master data in a state that lets
the academic work proceed?* It carries no risk level, no at-risk count, no DSS
analytic — `CLAUDE.md` reserves those for the Principal.

### Layout, top to bottom

**Row 1 — eight count cards.** `grid grid-cols-2 md:grid-cols-4 gap-3`, each an
`<x-stat-card>` linking to its management page. A number the Admin cannot click
through to is a dead end.

| Label | accent | icon | href |
|---|---|---|---|
| Total Users | `count-1` | `people` | `admin.users` |
| Total Students | `count-2` | `mortarboard` | `admin.students` |
| Total Advisers | `count-3` | `person-badge` | `admin.users` |
| Total Principals | `count-4` | `person-workspace` | `admin.users` |
| Total Sections | `count-5` | `grid` | `admin.sections` |
| Total Subjects | `count-6` | `book` | `admin.subjects` |
| Total Tracks | `count-7` | `diagram-3` | `admin.tracks` |
| Total Specializations | `count-8` | `collection` | `admin.specializations` |

Remove any inline text strip of counts left from the previous pass. The counts
appear once, as cards, and nowhere else on the page.

**Row 2 — two context cards.** Active School Year and Active Academic Term, both
`<x-stat-card accent="count-8">`, `grid-cols-1 md:grid-cols-2 gap-3`. Keep the
"Manage academic terms →" link. With no term open the value reads "No term open"
in `text-gray-400`, not a blank.

**Row 3 — Data Health, collapsed.** Keep the checks, lose the wall of text. One
`<x-panel>` whose header shows "6 of 6 checks passing", with a `<details>`
inside listing the individual checks.

That summary line is the **one place on this dashboard where a status colour is
correct**, because it genuinely is a status: `text-status-ontrack` when all pass,
`text-status-risk` when any fails. When something fails the panel opens by
default and failing checks sort to the top. A health check nobody expands is a
health check nobody reads.

**Row 4 — Recent Activity.** `<x-panel>` with the five most recent entries and a
"View all →" link. Five, not fifteen. This is a glance, not the log.

**Row 5 — System Management.** Keep the existing quick-link grid unchanged.

### Remove

The **Open Term / per-section encoding table** (SECTION / ENCODED / SUBMITTED).
That is academic progress, which belongs to the Adviser and the Principal, and
it already appears on Adviser Submit Report and the Principal dashboard. A third
copy means three places to keep in sync.

If that count is genuinely useful to the Admin, it becomes one
`<x-stat-card label="Sections fully encoded" value="1 of 1">` in Row 2 — a
number, not a table.

### Do not touch

The tables on Admin **Students, Users, Subjects, Sections, Tracks,
Specializations, Academic Terms, Activity Logs**. Those are correctly tables —
they list records that need scanning, sorting, and acting on. Only the
*dashboard* is card-only.

**STOP POINT 5.** Screenshot the finished Admin dashboard.

---

## PART 6 — Adviser and Principal dashboards, cleanup, model honesty

### 6a — The two dashboards

Presentation only. Do **not** change what these dashboards show. Their content
is the result of decisions that took real work — In-Term Status kept separate
from Risk Level, Failing sitting among outcomes rather than beside the in-term
signals.

1. **Every stat becomes an `<x-stat-card>`.** Status cards take the `status.*`
   accent; neutral figures — Total Students, Grades Encoded, Terms Submitted,
   Assessment Completion — take `count-*`. This is the point of the split: a
   reader can tell a status from a count without reading the label.
2. **Every section becomes an `<x-panel>`** with its title and subtitle in the
   header instead of loose text above a white box.
3. **Every table adopts the Part 4d classes.**
4. **Filters get one consistent bar:** `bg-white rounded-lg shadow-sm p-3 mb-4`
   with controls in `flex flex-wrap gap-2`. Same on Principal Students and
   Subject Analysis.
5. **Every status colour carries its word.** Never colour alone — a red-only
   cell is unreadable to a colour-blind panelist and useless in a
   black-and-white printed appendix.
6. **The three-part Principal structure stays** — "Where the school stands now",
   "What needs a decision", "Trend and outcomes". That numbering is a real
   sequence and earns its numbers.
7. **Empty states use `<x-empty-state>`** and say what to do next.

Compare every number against the Part 1 note. A presentation change that moved a
count is a bug.

### 6b — Remove what is dead

Grep for each before deleting. If anything references it, stop and report.

Delete — broken and unreachable:

- `resources/views/welcome.blade.php` — unreachable (`/` redirects) and calls
  `route('register')`, which does not exist in `routes/auth.php`. It would throw
  if it ever rendered.
- `resources/views/auth/register.blade.php` — same problem. There is no
  registration route; Admin creates users.
- `app/Http/Controllers/Auth/RegisteredUserController.php` — no route points at
  it.

Delete — placeholder and orphan:

- `resources/views/dashboard.blade.php` — the stock Breeze "You're logged in!"
  page. `/dashboard` redirects and never renders it.
- `resources/views/profile/partials/delete-user-form.blade.php` —
  `ProfileController` has only `update()` and `updatePassword()`; no `destroy()`.
  Self-deletion would also contradict Admin owning user accounts.
- `tests/Unit/ExampleTest.php` — Laravel's stock placeholder.

Move — misfiled:

- `resources/views/admin/partials/at-risk-results.blade.php` →
  `resources/views/principal/partials/at-risk-results.blade.php`. It is included
  only by `principal/dashboard.blade.php` and returned only by
  `Principal\DashboardController`. A DSS partial in the Admin namespace
  contradicts the rule that Admin has no DSS analytics. Update both references,
  then run `php artisan test --filter=Principal` before anything else.

Ask before removing — report and wait:

- The email-verification stack (`EmailVerificationPromptController`,
  `VerifyEmailController`, `EmailVerificationNotificationController`,
  `auth/verify-email.blade.php`, and its routes). `MAIL_MAILER=log` and Admin
  creates every account, so verification mail is never delivered — but
  `users.email_verified_at` has its own migration, and dropping a column is a
  schema change, not a cleanup.
- `auth/confirm-password.blade.php` and `ConfirmablePasswordController` —
  reachable only via the `password.confirm` middleware. Grep; if nothing uses
  it, report that.

Diff `php artisan route:list` against `routes_before.txt`. Only routes named
above may have disappeared.

### 6c — Model honesty

The classifier trains on 90 synthetic samples using one feature,
`average_grade`, and scores near 100% because the training points were
constructed to be perfectly separable by the very thresholds the model is meant
to learn. Laravel sends seven features; `classify_students()` reads one.

All of this is already documented in `classify.py` and `model_accuracy.txt`,
which is correct. Add the one thing missing — something the panel can see:

1. Write a small command or script printing the trained model's
   `feature_importances_`, and write it into `model_accuracy.txt` as a new
   section.
2. With one feature it will read 100% on `average_grade`. That is the point:
   direct evidence of the documented limitation, in the model's own numbers
   rather than a comment.
3. Add one line explaining what a healthy multi-feature model would look like
   instead — importance spread across components and trend rather than
   concentrated in one column.

Do not retrain. Do not wire in `train_from_real_data()`. There is no real
outcome data to validate against, and a more complex model that cannot be
checked is worse than a simple one whose limits are written down.

**If retraining later:** delete `analytics/model_cache.pkl` first. It is loaded
in preference to training, so changes to `train_model()` otherwise never take
effect — the model silently stays the old one.

**STOP POINT 6.** Report the dashboard numbers before and after, what was
deleted, and what is awaiting a decision.

---

## PART 7 — The elective pivot

**Only if the school will run this beyond the pilot.** If the goal is the
defence, skip Parts 7–9 and write them up as future work.

### The problem

`Subject::forSection()` returns every elective matching a section's track and
specialization — the whole cluster. There is no pivot recording which electives
a section actually takes.

With only two STEM electives imported this is invisible, because "every elective
in the cluster" and "the two this section takes" happen to be the same set. Add
a third and `AcademicTerm::completionStatus()` will demand a grade for a subject
no learner took, so `expected` permanently exceeds what `actual` can reach and
**Submit Report can never complete**.

### 7a — The decision that must come first

**Does every learner in a section take the same subjects?**

- **Answer A — yes, the whole section takes the same load.** A `section_subject`
  pivot is enough. This is what most Philippine SHS schools do in practice: the
  section is formed around the subject load.
- **Answer B — no, learners in one section pick different electives.** The pivot
  must be `student_subject`, and every completion count, encoding screen, upload
  validation, and report submission becomes per learner. Roughly three times the
  work.

**This part builds A.** If the school confirms B, stop and say so — the plan
changes and this section has to be rewritten.

Also record: how many subjects does `forSection()` return for Molave right now?
Confirm it is exactly 2 electives.

**STOP POINT 7.** Confirm the answer before writing any code.

### 7b — Migration

```php
Schema::create('section_subject', function (Blueprint $table) {
    $table->id();
    $table->foreignId('section_id')->constrained()->cascadeOnDelete();
    $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
    $table->string('school_year');
    $table->timestamps();

    $table->unique(['section_id', 'subject_id', 'school_year']);
    $table->index(['section_id', 'school_year']);
});
```

`school_year` lives on the pivot, not derived from the section, because a
section name is reused each year with a different subject load. Without it, last
year's choices silently apply to this year.

### 7c — Backfill, the dangerous step

Every existing section must end up with exactly the subjects it has today. In
the same migration's `up()`, after creating the table, insert one pivot row per
section for every subject `forSection()` returns for it right now. With only two
electives imported this is exactly correct — the backfill freezes current
behaviour rather than changing it.

Write `down()` to drop the table. Verify immediately:

```
php artisan tinker
>>> Section::find(1)->subjects()->count()
```

That number must equal what 7a recorded. If not, roll back and find out why.

### 7d — Model

On `Section`:

```php
public function subjects()
{
    return $this->belongsToMany(Subject::class)
        ->wherePivot('school_year', $this->school_year);
}
```

On `Subject`, rename `forSection()` to `offeredToSection()` and update its
docblock. It becomes the **offered** list — what an Admin may choose from — no
longer what the section actually takes. Then find every caller and decide, one
at a time, which of the two it needs.

### 7e — Callers

`CLAUDE.md` names these. Grep to confirm the list is complete and report
anything found that is not on it:

- `AcademicTerm::completionStatus()` — count only enrolled subjects.
- `ReportController::submit()` — the `totalExpected` check.
- `ReportController::getSectionSubjects()` — a duplicate copy of the same query.
  Delete the duplicate and call the model rather than fixing one bug twice.
- The grade-encoding screen.
- The subjects import format.

Each switches from `forSection()` to `$section->subjects`.

### 7f — Admin screen

Admin owns master data, so assigning subjects to a section is Admin's job. On
the Sections page, add a "Subjects" action opening a checklist of everything
`offeredToSection()` returns, with assigned ones checked.

- Core subjects checked and disabled — every section takes all of them.
- Electives freely checkable.
- Saving syncs the pivot for the section's school year.
- **A subject with grades already encoded cannot be unchecked.** Show it
  disabled with the reason; removing it would orphan real grades.
- Log the change via `LogActivity`.

### 7g — Tests

Un-skip `tests/Feature/ElectiveClusterLimitationTest.php` and make it pass.

That test is written from a **per-learner** angle — its name says "a student who
takes only two of three cluster electives". Under Answer A the correct unit is
the section. Rewrite it to assign two of three electives **to the section**, keep
the completion assertion, and update the docblock to say what was actually
built. Then add a second test, marked skipped, for the per-learner case, noting
it needs a `student_subject` pivot.

Do not silently change a test's meaning. A test whose name no longer matches
what it checks is worse than a skipped one.

Add:

- A section with 3 electives assigned 2 reaches completion.
- A section with 3 electives assigned 3 expects all 3.
- Two sections in the same track with different elective sets do not affect each
  other.
- Unchecking a subject that has grades is rejected.
- Two school years on one section keep separate subject lists.

**STOP POINT 8.** Report Molave's completion count before and after. If it
moved, stop.

---

## PART 8 — Verify the provisional numbers

Not code. Research, and it needs doing before any real learner's grade is
computed by this system.

1. Download DO 015, s. 2026 from deped.gov.ph — the signed PDF, not a blog
   reproduction.
2. Check all 41 transmutation bands in `Do015TransmutationSeeder` against it. If
   they match, delete the `SOURCE NOTE` and record in `CLAUDE.md` what was
   checked and when. If any band differs, correct the seeder, re-run it, then
   run `php artisan dss:verify-transmutation` and
   `php artisan dss:recompute-grades`.
3. Check the Examination split. The seeded 30/30/40 across ST1, ST2, and Term
   Exam is described in `CLAUDE.md` as an estimate confirmed nowhere. If the
   order says otherwise it is three `UPDATE`s to `exam_role_shares` — data, not
   a deployment.
4. Write down the outcome either way. "Verified against the signed order on this
   date" and "still unverified" are both acceptable in a thesis. Silence is not.

If a band changes, every computed grade in the database changes with it. Back up
first and re-run the recompute afterward.

---

## PART 9 — Subject teachers: design only, build nothing

`sections.adviser_id` is a single user, and that user encodes every subject in
the section. Real SHS assigns a teacher per subject; the class adviser compiles.

**Do not build this.** It touches authorization, the encoding screen, upload
scoping, term readiness, and report submission at once. Building it badly weeks
before a defence risks the working pilot.

Write `docs/FUTURE_SUBJECT_TEACHERS.md` containing:

1. **The gap**, in two paragraphs: what the system assumes, what a real school
   does, and why the pilot did not expose it — one section, two subjects, one
   adviser.
2. **The schema**: a teacher per section-subject, most likely a nullable
   `teacher_id` folded into the Part 7 `section_subject` table, since the rows
   already exist.
3. **Every place authorization would change.** Grep `adviser_id` and list each
   hit with what it would become. Be exact; this list is the document's actual
   value.
4. **What Submit Report becomes** when eight teachers must each finish before a
   section can be submitted, including who chases whom.
5. **What stays the same** — grading engine, transmutation, risk classifier, and
   intervention workflow are untouched by this change. Saying what is *not*
   affected is what makes the estimate credible.

Add a "Recommendations for future work" section to `CLAUDE.md` pointing at that
file, alongside the per-learner elective case from 7g.

---

## PART 10 — Final regression

1. `php artisan test` — match or beat the Part 1 baseline.
2. `php artisan migrate:status` — only intended migrations applied.
3. `npm run build` clean, with no grey where colour was expected.
4. **Admin:** dashboard, users, tracks, specializations, subjects, sections,
   students, academic terms, reports, activity logs.
5. **Adviser:** dashboard, students, encode grades, assessments
   (upload → verify → preview), interventions, submit report.
6. **Principal:** dashboard, students, student drill-down, interventions,
   reports, subject analysis.
7. Molave, all three terms: completion counts unchanged from Part 1.
8. **Mobile:** narrow to 375px. Sidebar drawer, card grids, and every table must
   survive. Tables scroll horizontally inside `.tbl-wrap` rather than breaking
   the layout.
9. **Print:** print-preview the Principal dashboard. Every status must still be
   identifiable from its label alone.
10. The **At Risk learner with a passing report-card grade** still displays:
    computed 71.95, report card 76.00, two components below the 75 target. That
    screen answers the panel's "why not just use 74 and below" and must survive
    every part of this work order.
11. `CLAUDE.md` updated: the Design system section, the `.tbl` classes, the
    `<x-stat-card>` and `<x-panel>` components, the four-size type scale, and —
    if Part 7 ran — a rewritten "Elective selection is per-cluster" limitation
    describing what now exists and what remains. Do not delete that section; a
    limitation that was fixed should say so and say when.
12. Commit per part, separately, so any regression can be bisected.

**STOP POINT 9.** Final report: test counts before and after, Molave's
completion counts before and after, what changed in each part, and anything left
incomplete.

---

## NOT IN THIS WORK ORDER

Named so nobody assumes they were forgotten.

- **Oral Communication Term 3** (`09_term3_main_oralcomm.xlsx`) is not uploaded.
  Term 3 cannot be submitted until it is. A data task, not a code task.
- **Per-learner electives** — needs a `student_subject` pivot; out of scope
  until the school confirms learners in one section take different subjects.
- **Summer Remedial Class, RCM, RFG** — a separate module, out of scope until
  the school decides how it should work. Already recorded in `CLAUDE.md`.
- **The within-term additional support policy** — cap, average, or keep
  additive. A school policy decision, not an engineering one.
