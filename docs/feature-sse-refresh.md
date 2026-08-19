# Feature request: SSE live refresh via Mercure, prioritized per-MR refresh queue

Status: approved for implementation
Scope: backend (Symfony) + worker + docker + frontend header/rows.

## Background

Today the frontend polls `/api/data` every 60s; the backend runs a 15-minute incremental
sync cron plus a stale-while-revalidate detached spawn (`SyncTrigger`, triggered from
`ApiController::data()`). Slack notifications for new/stale MRs are posted by that
15-minute cron from a DB diff since `sync_state.last_notify`.

GitLab API budget: `GITLAB_RPS` (default 8/s), enforced by a blocking throttle in
`App\GitLab\Client`, single process. A cached MR refresh costs 4 requests (approvals,
discussions, pipelines, commits) + 1 per not-yet-cached commit sha + 1 jobs call per
running/pending pipeline. GitLab webhooks are NOT available (the app is not reachable
from the GitLab instance) — new-MR detection must come from the refresh cycle itself.

## Architecture decisions (settled, do not relitigate)

- **Mercure** (symfony/mercure bundle + the Mercure hub binary) for server→browser push.
  Hub runs in the same container as a new supervisord program; nginx reverse-proxies
  `/.well-known/mercure` so no new port is exposed. Dev compose gets the same setup.
- **Refresh worker**: a long-running `app:refresh-worker` console command under
  supervisord, polling a SQLite queue table. No doctrine, no messenger — raw PDO like
  the rest of the codebase. This single process owns the GitLab RPS budget.
- The 15-minute incremental cron and the `SyncTrigger` stale-while-revalidate spawn are
  **removed**. Nightly `full` sync (03:00) and rank cron (04:33) stay. Stale-MR Slack
  notifications move to the nightly full run.
- Identity for prioritization = the existing "My view" user-filter selection (query
  param sent by the client), no auth.

## Requirements

One feature point = one commit (baseline-first: tests where testable). Suggested order:

### 1. Mercure infrastructure

Add the Mercure hub binary to the Docker image, a supervisord program, nginx location
for `/.well-known/mercure`, JWT secret via env (`MERCURE_JWT_SECRET` etc.), and the
symfony/mercure bundle wired for publishing from PHP. Anonymous subscribing is allowed
(the dashboard has no auth); publishing requires the JWT. Topics are public, e.g.
`refresh` for cycle/status events and `data` for "rows changed" events.

### 2. Refresh queue + worker

- New table(s) for the queue: per cycle, per-MR jobs with `priority`, `state`
  (`queued|fetching|done|failed`), `requests_done`, `requests_expected`.
- `POST /api/refresh?user=<id>` enqueues a cycle:
  1. Worker's first action per cycle is ONE cheap
     `groupMergeRequests(state=opened, updated_after=lastSync-60)` list call. MR ids not
     yet cached go to the FRONT of the queue (new MRs first). Closed transitions are
     dropped as in the current incremental sync.
  2. Per-MR ordering for user u: (a) authored by u, (b) open MRs u has not approved,
     (c) MRs u approved, (d) the rest; ties `updated_at DESC`. With no user: new MRs
     first, then `updated_at DESC`.
- Per-MR refresh = existing `Synchronizer::syncMergeRequest()` sub-resource fetches;
  reuse it (extract/refactor as needed) rather than duplicating.
- Dedupe: an MR already fetched in the current cycle is not fetched again. A trigger
  arriving mid-cycle merges its ordering into the pending remainder (no restart).
  Cooldown: a completed cycle blocks new cycles for 30s (triggers during cooldown are
  ignored; the client shows the cooldown).
- `requests_expected` starts at 4 and is corrected after the commits list returns
  (+uncached shas, +jobs calls). Every completed sub-request publishes a Mercure
  progress event `{mr_id, requests_done, requests_expected}`; MR completion publishes
  `{mr_id, state: done}` so clients refetch that row's data (or the event carries the
  fresh row payload — implementer's choice, document it).

### 3. Slack notification on new-MR discovery

When the worker's cycle list call discovers an MR id not in the cache, post the existing
"new MR" Slack message immediately after that MR's row is stored (reuse
`SlackNotifier`'s formatting; keep the `last_notify` bookkeeping consistent so the
nightly run does not re-notify). Stale-MR notifications: remove from the (deleted)
15-min path, invoke from the nightly full sync instead.

### 4. Cron/trigger removal

Delete the `*/15` crontab line and the `SyncTrigger` spawn from `ApiController::data()`
(remove `SyncTrigger` entirely if nothing else uses it). Update `docker/crontab`,
`docker/crontab.dev`, tests, and SPEC references.

### 5. Header: countdown segmented control + auto-trigger

Segmented control in the topbar: `Off · 1m · 5m · 15m`, default 5m, persisted in
localStorage. Live `mm:ss` countdown next to it; reaching zero POSTs `/api/refresh`
with the current "My view" user and resets. Clicking the countdown = refresh now.
While a cycle runs, this area shows overall progress (`refreshing 7/23`). The sync
status line ticks every second. Use the reusable segmented-control CSS class
(`.seg-control`) introduced by the graph-improvements feature; if it does not exist
yet, create it with that name.

### 6. Frontend SSE subscription + per-row progress fill

Subscribe with native `EventSource` to the Mercure topics. Per MR row:
- queued: faint accent left border (queue-order visibility);
- fetching: lighter background filling left→right, driven by
  `requests_done/requests_expected` via a CSS custom property with a short linear
  transition between events;
- done: fill clears and the row's data updates IN PLACE (no full list rebuild, no
  scroll-position loss).
When SSE is connected, the 60s `/api/data` polling loop is replaced by refetching only
on `data`-topic events (keep one initial load). Fall back to the 60s poll if the
EventSource errors persistently.

### 7. Connected-users indicator

Count active subscribers via the Mercure hub's subscription API (enable it in hub
config), expose in a small `/api/presence` endpoint or embed in the periodic status
event, and show `● N online` in the topbar with a tooltip: "All connected dashboards
share the GitLab API rate limit; refreshes take longer when more people are online."

## Constraints

- PHP 8.5 at `/home/paseo/tools/php/php8.5`; run phpunit, phpstan, phpcs before each
  commit. Frontend verified via Playwright DOM assertions (needs
  `LD_LIBRARY_PATH=/tmp/cr-libs` + fontconfig; never analyze screenshots; beware
  orphaned fixture servers).
- Tests must not require a real Mercure hub: abstract publishing behind an interface
  (symfony/mercure's `HubInterface` already is one) and fake it in tests, as done for
  `GitLabClientInterface`.
- Commit style: `feat(refresh): …`, `feat(docker): …` etc., matching history; one
  commit per feature point, baseline-first.
