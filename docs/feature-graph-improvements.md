# Feature request: metric-cell & graph improvements

Status: approved for implementation
Scope: frontend only (`public/app.js`, `public/style.css`, `public/index.html`, `public/toggle.js`). No backend/API changes.

## Background

The stats panel renders ten uPlot line charts (one per metric in `METRICS`), 5×2 grid on
desktop, stacked on ≤1024px. Charts are fully destroyed and rebuilt on every 60s data
poll and on every mean/median toggle. Colors come from `COLORS[index]` where index is the
per-chart object-key order of `metric.persons`, so the same person can have different
colors in different charts.

## Requirements

Each numbered item is one feature point = one commit (baseline-first where a test can be
written first). Suggested order below is dependency order.

### 1. Stable per-user colors across all charts (must)

Assign each user a color from a single global mapping, identical in every chart and in
the hover tooltip row borders. Mapping: order users by `mr_count DESC, name ASC` (same
ordering as the user dropdown) and index into `COLORS` (mod length). A person present in
only some charts keeps their color everywhere.

### 2. Skip rerender when data is unchanged (must)

The 60s poll currently rebuilds all charts and the MR list even when nothing changed,
which yanks tooltips/zoom from under the cursor. Keep polling, but before rendering
compare a cheap fingerprint (e.g. `meta.last_sync_at` + bucket + user filter) with the
previously rendered one; skip `renderAll` when equal. A changed user filter or bucket
always rerenders.

### 3. Current value in cell headers (must)

Show the latest team-level value next to each metric title, e.g.
`Time to review · 4h 12m`. "Latest team value": for the newest bucket that has any data,
average (mean-mode) or median (median-mode) across persons' values at that bucket;
formatted with the existing `formatMetricValue`. Style: same row as the title,
`--text-dim`, tabular numerals. Updates when the mean/median mode toggles.

### 4. Explicit mean | median segmented toggle (must)

Replace the `⇄` button with a two-segment control labeled `mean | median`, active
segment highlighted (accent border/background), so current mode is visible at a glance.
Keep the shared-mode behavior from `toggle.js` (one mode for all charts). This
segmented-control styling should be a reusable CSS class — the SSE feature will use the
same pattern for the refresh-interval control, so name it generically (e.g.
`.seg-control` / `.seg-option` / `.seg-option.active`).

### 5. Synced zoom across charts (should)

All charts share the same x time axis. A drag-zoom on one chart applies the same x-range
to all charts (and triggers the existing bucket-granularity switch once, not per chart).
Double-click resets all charts. Keep the existing zoom→bucket mapping thresholds.

### 6. Mobile tap support for tooltips (should)

On touch devices hover is dead. Make:
- tap/drag on a chart show the chart tooltip (uPlot cursor already tracks touch; verify
  and wire `updateChartTooltip`), tap outside the chart hides it;
- tap on the `(?)` tip icon toggle its tooltip (currently `:hover::after` CSS only —
  add a `.open` class toggled by click, dismissed by tap-outside);
- tap on a truncated MR description toggle the description tip, tap-outside dismisses.

### 7. Click-to-expand focus mode (should, last)

Clicking a cell's title expands that cell to span the full stats-panel width and ~2×
row height (grid `grid-column: 1 / -1` + larger `grid-auto-rows` for that cell);
the other cells reflow below. Clicking again (or Escape) collapses. Only one cell
focused at a time. Charts must resize via the existing `ResizeObserver`. On mobile
(stacked layout) focus mode just doubles the cell height.

## Constraints

- Plain ES modules, no build step, no new dependencies. uPlot stays.
- Dark theme variables from `:root` in `style.css`; follow existing naming.
- Verify with the Playwright-based checks used in this repo (DOM assertions, not
  screenshot reading). Local quirks: PHP at `/home/paseo/tools/php/php8.5`; Playwright
  needs `LD_LIBRARY_PATH=/tmp/cr-libs` and fontconfig; beware orphaned fixture servers
  serving stale data.
- One commit per feature point, commit message style: `feat(dashboard): …` /
  `fix(dashboard): …` matching git history.
