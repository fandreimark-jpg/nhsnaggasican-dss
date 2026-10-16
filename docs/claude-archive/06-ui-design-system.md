# UI design system notes

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 2448-2670; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

---

# UI DESIGN SYSTEM

## Empty-state hints are only reachable on a fresh install

`<x-empty-state>`'s `hint` prop only renders when the table it's inside has
zero rows. On a populated database — the pilot database included — no admin
ever sees it, which is exactly how a double-escaped-entities bug in six of
these hints (`&quot;` written into a `hint="..."` attribute, then re-escaped
a second time by the component's own `{{ $hint }}`) went unnoticed until a
dedicated test pass on a clean database caught it. Checking an empty-state
hint's actual rendered output requires testing against a fresh/empty
database, not the pilot one — a populated table will never exercise this
code path at all.

## The biggest first-time-reader question is already answered twice, deliberately, not duplicated a third time

"UI legibility pass" item 4 checked whether a first-time reader (a thesis
panel, someone new to the system) could tell what each dashboard is showing
them within about thirty seconds. The single most likely point of confusion
— what's the difference between In-Term Status and Risk Level, the two
numbers that look like they should be the same thing and aren't — is
already solved, in two different but deliberate ways, and neither needed
changing:

- **Principal dashboard**: `partials/in-term-vs-risk-help.blade.php` is
  included *always visible*, near the top of the page, not behind a
  `<details>` — two short paragraphs, one per signal, shared with the
  Adviser dashboard so the wording can never drift between them.
- **Adviser dashboard**: the same partial is included, but behind a
  collapsed `<details>` ("What's the difference between In-Term Status and
  Risk Level?") — deliberately different from the Principal's
  always-visible placement, because the Adviser dashboard leads with a
  "What Needs Your Attention Now" action list and inline explanatory
  sentences above its own status table; the terminology question is one
  click away rather than competing with what the adviser is there to do.

If a future legibility pass finds this question still unanswered somewhere,
the fix is almost certainly wiring in the existing shared partial, not
writing a third explanation — a fourth place explaining the same
distinction is exactly the kind of drift this partial exists to prevent.

## Status colour is reserved for the four DSS states

`tailwind.config.js` defines a `status` colour scale — `status-ontrack`,
`status-attention`, `status-risk`, `status-failing` — used ONLY for On Track,
Needs Attention, At Risk, and Failing (and Risk Level's Low/Moderate/High,
which is the same green/amber/red gradient by design). Every genuine
DSS-state badge, stacked-bar segment, and card border across all three
roles reads from these four tokens instead of a literal `green-500` /
`yellow-400` / `red-600`, so "what colour is At Risk" is answered in one
place.

Do NOT reach for `status-*` outside these four states. Validation errors,
delete/danger buttons, workflow-stage badges (recommended/approved/
delivered), account active/inactive tags, trend arrows (improving/
declining), and data-quality caveats (Provisional/Not available) are
deliberately literal Tailwind classes — they are not one of the four DSS
states, and using a status token for them would blur the one thing this
token set exists to keep unambiguous. When in doubt, ask whether the
colour is describing a *student's or subject's* On Track/Needs Attention/
At Risk/Failing status — if not, it isn't a `status-*` case.

Card *identity* (which master-data type a card is — Users, Students,
Sections, and so on, on the Admin dashboard) uses its own literal colour
per card and must never resolve to one of the four status values; identity
and status are different questions and must stay visually distinguishable.

## One table component for every table in the app

`resources/css/app.css` defines `.tbl` / `.tbl-wrap` / `.tbl-scroll` /
`.tbl-sticky` / `.tbl-num` in a `@layer components` block. Every real data
table in the app (as of the "UI work order" pass) uses this set instead of
its own `thead`/`th`/`td` class string:

- `.tbl-wrap` — the table's own visual card (`bg-white rounded-lg shadow-sm
  overflow-hidden`), for a table with no other ancestor already providing
  that chrome.
- `.tbl-scroll` — `max-h-[32rem] overflow-y-auto`, the shared scroll
  container height. Put it on whichever div directly wraps the table.
- `.tbl` — on the `<table>` itself: base typography and the shared
  `thead`/`tbody tr`/`tbody td` styling.
- `.tbl-sticky` — add alongside `.tbl` on the `<table>` only when the
  header should pin on scroll (matches the file's PRE-EXISTING behaviour;
  don't add sticky where a table never had it).
- `.tbl-num` — on both the `th` and `td` of a numeric column (grades,
  percentages, counts, scores) so digits align down the column.

Plain `<th>`/`<td>` need no class at all — `.tbl` sets `text-align: left`
at the table level, so left alignment is the default. Only alignment
(`text-right`, `text-center`, or `tbl-num` for numeric), a genuine
emphasis (`font-medium`, `text-gray-500` for a de-emphasised field), or a
content-specific detail (a fixed `w-*`, `whitespace-nowrap`, a status
colour) should ever appear on a `th`/`td` beyond that.

**Three tables are deliberately NOT on `.tbl`**: the Adviser Interventions
page's two "awaiting decision" callout tables and the assessment
edit-summary table are themed (amber/blue) mini-tables embedded inside a
matching coloured notification banner, not ordinary data tables — forcing
them onto `.tbl`'s neutral grey chrome would fight the banner they live
inside rather than read as one thing with it.

Adding a table without this component set is how the app previously ended
up with eight different `thead` variants that drifted apart from each
other one Blade file at a time — reach for `.tbl` first.

## COMPLETE WORK ORDER, PART 4 — count colour, shared components, scale

### Warm colour is status, cool colour is a count

`tailwind.config.js` also defines a `count` colour scale —
`count-1` through `count-8`, all cool hues (blue/indigo/violet/purple/
cyan/teal/slate) — deliberately disjoint from the warm `status.*` family
above. Warm colour means DSS status and nothing else. Cool colour means a
count. Grey means structure. A plain number never takes a status colour,
and a status is never shown by colour alone — it always carries its word.

`<x-stat-card accent="count-N">` (Admin's eight master-data cards) and
`<x-stat-card accent="status-*">` (a genuine status figure) are the two
places `count.*`/`status.*` reach a border colour. Because the accent
class is built at runtime (`'border-'.$accent`), Tailwind's static
scanner cannot see it — `tailwind.config.js`'s `safelist` pattern
(`border-(count|status)-(1..8|ontrack|attention|risk|failing)`) exists
for exactly this, and is the first thing to check if a card border ever
renders grey instead of its accent after a build.

**The Part 4a colour audit and what it deliberately left alone.** A pass
over every `red-`/`amber-`/`yellow-`/`orange-`/`rose-` in
`resources/views` confirmed the exemption list above (validation errors,
danger buttons, workflow-stage badges, account tags, trend arrows,
data-quality caveats) is still the right call — recolouring a delete
button or an error banner to a cool or grey tone would read as less
urgent than it is, for no benefit. Two genuine misses were fixed: the
failing-subject list and the weakest-component gap indicator in
`principal/partials/at-risk-results.blade.php` were raw `red-600` /
`red-500`/`green-600` even though they render real DSS signals (a
subject-level Failing outcome, and an In-Term Status gap) — now
`status-failing` and `status-attention`/`status-ontrack` respectively.

### Two shared component shells

`resources/views/components/stat-card.blade.php` and `panel.blade.php`
are the two building blocks every dashboard card and section reads from
going forward, instead of each page hand-rolling its own `bg-white
rounded-lg shadow-sm ...` string:

- `<x-stat-card :label :value :accent :icon :href :note>` — one number
  with a label, an accent-coloured top border, and an optional icon and
  link. `accent` is `count-1`..`count-8` for a neutral figure, or
  `status-ontrack`/`attention`/`risk`/`failing` for a genuine status
  figure.
- `<x-panel :title :subtitle :action>` — the card shell for anything that
  is not a single stat: a table, a list, a chart. `$slot` is the body;
  `title`/`subtitle` render a header only when given.

### Type and spacing scale

Four sizes, and a page that invents a fifth is wrong: page title
(`text-xl font-bold text-gray-800`), section heading (`text-sm
font-semibold text-gray-800`), card label/table header (`text-xs
text-gray-500`), card number (`text-2xl font-bold text-gray-800`).
Spacing: `gap-3` between cards, `mb-4` between sections, `p-4` inside
cards.
## "UI modernization pass" (2026-09-14) — the design system lives in `resources/css/app.css`

Supersedes the raw-utility conventions above where they disagree; the
older notes are kept for their reasoning (status vs count colour, one
table component), which still holds.

- **Tokens** (`tailwind.config.js`): `brand` green rebased on `#1F6B2A`
  (800, primary) / `#3FAE4D` (500, accent); neutrals `ink #18212F`,
  `muted #667085`, `surface #F5F7FA`, `line #E6EAF0`; semantic
  `success/warning/danger/info` each with `.soft` and `.text` shades;
  `status.*` and `count.*` unchanged in meaning. Shadows `shadow-card`,
  `shadow-card-hover`, `shadow-modal`. Green is identity only; statuses use
  the semantic tokens; `status.*` stays reserved for the four DSS states.
- **Component classes** (`@layer components` in `app.css`) — use these,
  never a fresh utility string: `page-header/page-title/page-subtitle/
  page-eyebrow`, `section-title/section-subtitle`, `card/card-header/
  card-body/card-footer/card-title/card-subtitle/card-hover`, `stat-card/
  stat-label/stat-value/stat-note`, `icon-box(-sm) icon-box-{brand|info|
  success|warning|danger|slate|violet|teal|cyan}`, `btn btn-{primary|
  secondary|outline|ghost|danger|danger-outline|warning|link} btn-{xs|sm|lg}`,
  `badge badge-{success|warning|danger|info|gray|brand|outline}`, `pill`,
  `filter-bar/filter-field`, `form-label/form-input/form-select/
  form-select-sm/form-help/form-error/form-readonly`, `progress/progress-bar`,
  `alert alert-{info|success|warning|danger}` (+ `alert-row` for icon+text),
  `nav-group-label/nav-link/nav-link-active`, `empty-state*`, `.tbl*`.
- **Blade components**: `x-stat-card` (same API; accent now tints a soft
  icon box, no coloured border), `x-panel` (+ `:padded="false"`),
  `x-empty-state` (icon box + title + hint), and new `x-ui.status-badge`,
  `x-ui.progress-bar` (value is always a backend/view-computed figure —
  `auto` colours by value), `x-ui.filter-bar`, `x-ui.section-card`,
  `x-ui.action-card`, `x-ui.metric-bar` (value + bar, 75-target colour rule).
- **Button semantics**: primary = Add/Save/Submit/Import/Activate/Open;
  secondary = Edit/Manage; outline = View/Cancel/Close Term; danger ONLY for
  delete/deactivate. "Record interventions in bulk" and "Activate" were red
  before this pass and are not any more.
- **Modals**: every dialog box is `.modal-box` (viewport-safe, scrolls
  inside itself). `showModal()` moves focus into the dialog and returns it
  on close; Escape closes the top-most one. Confirmations: Delete (red,
  `data-confirm`), Import (`data-import-confirm`), Re-submit
  (`data-resubmit`), and the neutral action modal (`data-action-confirm` +
  `data-action-title/label/icon/loading`) — never the Delete modal for a
  non-delete action.
- **Processing state**: any submitting form gets its submit button disabled
  and relabelled ("Processing..." or the form's `data-loading`); forms that
  submit via fetch opt out with `data-no-loading`.
- **Sidebar**: navigation is a data array in `layouts/app.blade.php`,
  grouped per role (Overview / Management / Academic / System, etc.); the
  page header shows a greeting plus School Year / open-term pills read from
  `Section::activeSchoolYear()` and `AcademicTerm::currentOpenTerm()`.
- **Offline**: Bootstrap Icons and Chart.js are local (`public/vendor`); the
  guest layout no longer loads a remote font. The one dashboard chart file is
  `public/js/admin/dashboard.js` (semantic colours, horizontal section bars,
  component-performance bars fed by `Principal\DashboardController::
  componentPerformance()` — a mean of `SubjectAnalysisService` figures,
  never a JS-side calculation).
- Layout-structure strings asserted by `FixedSidebarLayoutTest` (`flex
  h-screen overflow-hidden`, the aside's drawer classes, `flex-1 p-6
  overflow-y-auto`) and the `confirmImportBtn`/`confirmDeleteBtn` colour
  classes must survive any future restyle.



