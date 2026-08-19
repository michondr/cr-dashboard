# Code Review Dashboard — Specification

## 1. Problem Statement

The team creates merge requests (MRs) in GitLab. Reviewers approve MRs or leave discussions. Nobody tracks how long reviews take. Nobody knows who reviews fast or slow. Nobody knows which MRs wait long for approval. The team wants data about review behavior. The team wants to see the data on one dashboard.

The dashboard must show review metrics over time. The dashboard must show a list of MRs. The dashboard must show per-person graphs. The dashboard must run as one Docker image. The image must build itself in the GitLab pipeline.

## 2. Solution

A web dashboard shows review metrics. The dashboard runs as one Docker image. The image reads data from GitLab. The image caches data in SQLite. The dashboard shows a list of MRs. The dashboard shows graphs of per-person metrics. The dashboard refreshes data automatically. The dashboard pings Slack when new MRs appear (phase 2).

The dashboard has no login. The VPN is the security boundary. The GitLab token lives in the server environment. The token never reaches the browser.

### 2.1 Page layout

The page does not scroll. The MR list takes the top 55% of the viewport. The user stats take the bottom 45%. The MR list scrolls internally. Minimum supported viewport: 1920×1080 (Full HD).

```
+------------------------------------------------------------------+
|  Code Review Dashboard      Last sync: 2m ago · next sync: 13m    |
+------------------------------------------------------------------+
|  MR LIST (55% height, internal scroll)                           |
|  +------------------------------------------------------------+  |
|  | [gray] #1234  REC-1234  Title..........  [merged 3 commits] |  |
|  | [gray] #1233  REC-1220  Title..........  [merged 1 commit]  |  |
|  | +--------------------------------------------------------+ |  |
|  | | 5 stale Merge Requests belonging to Author A (3), Aut B(2)| |  |
|  | +--------------------------------------------------------+ |  |
|  | #1240  REC-1241  Title..........  [open 2 commits]   [sp]  |  |
|  | #1239  REC-1235  Title..........  [closed 1 commit]         |  |
|  | #1238  REC-1230  Title..........  [open 4 commits]   [!!]   |  |
|  | ...                                                        |  |
|  | #1200  REC-1100  Title..........  [open 1 commit]    [ok]   |  |
|  +------------------------------------------------------------+  |
+------------------------------------------------------------------+
|  USER STATS (45% height)                                          |
|  +----------+ +----------+ +----------+ +----------+ +----------+|
|  | Coverage | |Time to   | | Stale    | |Approvals | |Time to   ||
|  |   %  (?) | |review(?)⇄| | MRs  (?) | |given  (?)| |first app ||
|  |  graph   | |  graph   | |  graph   | |  graph   | |    (?)⇄  ||
|  |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)| |  graph   ||
|  +----------+ +----------+ +----------+ +----------+ +----------+|
|  +----------+ +----------+ +----------+ +----------+            |
|  |Time to   | |First     | |MR size   | |Merged    |            |
|  |merge     | |response  | |    (?)⇄  | |MR count  |            |
|  |    (?)⇄  | |time  (?)⇄| |  graph   | |   (?)    |            |
|  |  graph   | |  graph   | |  graph   | |  graph   |            |
|  |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)|            |
|  +----------+ +----------+ +----------+ +----------+            |
+------------------------------------------------------------------+
```

Legend: `[sp]` = pipeline spinner, `[ok]` = green checkmark, `[!!]` = red failed pipeline. `⇄` = avg/median switch (present only on duration/size cells; one shared state across all of them).

The MR list, top to bottom: the last 5 merged MRs grayed out (pinned to the top), then the collapsed stale-MR link, then the open and closed MRs from the last 60 days with the newest open MR at the very bottom.

### 2.2 MR row

The row is a single horizontal strip that fills the widescreen width. Each field is its own column. Columns do not wrap; the row grows wider on larger screens and the description column absorbs the spare width. A row is one line tall for open MRs and two lines tall once the description expands.

Columns, left to right:

| # | Column | Content | Behavior |
|---|--------|---------|----------|
| 1 | Jira | `REC-1234` | Link to `{JIRA_URL}{ticket}`. Empty if no ticket in title. |
| 2 | MR | `#1240` | Link to the MR in a new tab. |
| 3 | Title | `Add feature X` | Link to the MR in a new tab. Truncates with ellipsis when long. |
| 4 | Description | `Lorem ipsum dolor…` | Collapses after 50 pixels of height. Click expands in place. Absorbs spare width on widescreen. |
| 5 | Author | avatar + `J. Doe` | Avatar then name. |
| 6 | State | `open 2 commits` | `open`/`draft` plus commit count. The list shows open MRs only — merged/closed MRs are kept in the cache for the metrics but hidden from the list. `draft` shows a draft badge. |
| 7 | Age | `3d 04:12:33` | `now - created_at` for open, `merged_at - created_at` for merged, `closed_at - created_at` for closed. |
| 8 | First approve | `1d 02:11` | Time to first approval. Empty if none. |
| 9 | Pipeline | `[sp]` / `[ok]` / `[!!]` | Spinner while running (red if a finished job failed, orange if a job warned), green check on success, red on failure, neutral on canceled/skipped/manual. |
| 10 | Commits | `[3 commits]` | Link opens one new tab per current commit diff. |

Widescreen layout (top header row, then a sample open MR, a closed MR, and a merged MR):

```
+--------+-------+--------------------+-----------------------------+---------+------------+----------+---------+--------+--------+
| Jira   | MR    | Title              | Description (coll. 50px)    | Author  | State      | Age      | 1st App | Pipe   | Commits|
+--------+-------+--------------------+-----------------------------+---------+------------+----------+---------+--------+--------+
| REC-…  | #1240 | Add feature X      | Lorem ipsum dolor sit amet… | (av) JD | open 2comm | 3d04:12  | 1d02:11 | [sp]   | [2comm]|
|        | #1239 | Fix bug Y          | Consectetur adipiscing…     | (av) JR | open 1comm | 5d02:00  | 0d05:30 | [ok]   | [1comm]|
|        | #1238 | Refactor Z         | Dolor sit amet consectetur… | (av) AB | open 4comm | 2d01:00  | 1d00:10 | [!!]   | [4comm]|
+--------+-------+--------------------+-----------------------------+---------+------------+----------+---------+--------+--------+
```

The Jira ticket links to the Jira issue. The title and the MR number link to the MR in a new tab. The description collapses after 50 pixels of height and expands on click. The commits link opens one new tab per current commit diff (the user grants popup permission to the dashboard URL once). The list shows open MRs only; drafts show a "draft" badge. The row never scrolls horizontally; on narrower screens the description column shrinks first.

### 2.3 Metric cell

```
+----------------------+
| Time to review (?)⇄ |
| 100%                 |
|  \  o  o  o  o  o  o |
|   \ o  o  o  o  o  o |   one line per person
|    \o  o  o  o  o  o |   avatar at the right end
|     o  o  o  o  o  o |
| 0%                   |
|  Jan  Feb  Mar  Apr  |
+----------------------+
```

Each cell header shows a `(?)` tooltip icon. Duration/size cells also show a `⇄` switch that toggles between mean and median; the switch is one shared state across every cell that has it, and the choice is persisted in a cookie. The `(?)` tooltip explains the metric, what the score means, and whether lower or higher is better.

## 3. User Stories

1. As a team member, I want to see all open MRs from the last 60 days, so that I know what waits for review.
2. As a team member, I want to see the last 5 merged MRs, so that I know what shipped recently.
3. As a team member, I want the newest MRs at the bottom, so that the list reads in creation order.
4. As a team member, I want merged and closed MRs grayed out, so that I can tell them from open MRs.
5. As a team member, I want the MR title to open the MR in a new tab, so that I can review it.
6. As a team member, I want the Jira ticket extracted from the title, so that I can open the Jira issue.
7. As a team member, I want the MR description collapsed after 50 pixels, so that the list stays dense.
8. As a team member, I want a link to open all commit diffs, so that I can review each commit.
9. As a team member, I want the pipeline status on each MR, so that I know if the build passes.
10. As a team member, I want a spinner while the pipeline runs, so that I know it is in progress.
11. As a team member, I want a red indicator when a job failed, so that I know the build is broken.
12. As a team member, I want a green checkmark when the pipeline finished, so that I know it passed.
13. As a team member, I want the age of each MR, so that I know which MRs wait long.
14. As a team member, I want the time to first approval of each MR, so that I know review speed.
15. As a team member, I want stale MRs collapsed into one link, so that the list stays short.
16. As a team member, I want the stale link to show author names and counts, so that I know who owns them.
17. As a team member, I want a graph of coverage percentage per person, so that I know who reviews.
18. As a team member, I want a graph of time to review per person, so that I know review speed.
19. As a team member, I want a graph of stale MR count per person, so that I know who leaves MRs open.
20. As a team member, I want a graph of approvals given per person, so that I know the review load.
21. As a team member, I want a graph of time to merge per person, so that I know shipping speed.
22. As a team member, I want a graph of first response time per person, so that I know first reactions.
23. As a team member, I want a graph of MR size per person, so that I know review difficulty.
24. As a team member, I want the median alongside the mean, so that outliers do not mislead me.
25. As a team member, I want to zoom the graphs, so that I can see daily or hourly detail.
26. As a team member, I want the median toggle saved, so that my choice persists.
27. As a team member, I want each person's line to end at their avatar, so that I can read the graph.
28. As a team member, I want a tooltip on each metric, so that I understand the score.
29. As a team member, I want the page to fit the viewport, so that I do not scroll.
30. As a team member, I want fresh data on page load, so that I see current state.
31. As a team member, I want a Slack message when new MRs appear, so that I know to review them (phase 2).
32. As a team member, I want the Slack message to state how many approvals are needed, so that I know the target (phase 2).
33. As a team member, I want the dashboard to run as one Docker image, so that deployment is simple.
34. As a team member, I want the image built in the GitLab pipeline, so that the build is automatic.
35. As a team member, I want to run the same image locally, so that I can test changes.
36. As a team member, I want the GitLab URL, group, and token in environment variables, so that I can point the tool at any instance.
37. As a team member, I want the required approval count in an environment variable, so that the team can change it.
38. As a team member, I want the Jira URL in an environment variable, so that the team can change it.
39. As a team member, I want the Slack token and channel in environment variables, so that the team can change them (phase 2).
40. As a team member, I want closed MRs shown with a "closed" indicator, so that I can see what was abandoned.
41. As a team member, I want a graph of merged MR count per person, so that I know who ships.
42. As a team member, I want a mean/median switch on each duration cell, shared across all cells and persisted, so that I can compare averages and medians.
43. As a team member, I want the pipeline spinner red when a job already failed and orange when a job warned, so that I see trouble while the build runs.
44. As a team member, I want MR size to reflect the latest commits, so that added commits are counted.
45. As a team member, I want Slack to send one bundled list of new MRs and a nudge for stale MRs, so that notifications stay useful (phase 2).
46. As a team member, I want the Slack message to link to the dashboard, so that I can open it directly (phase 2).
47. As a team member, I want to see when the data was last synced, so that I know how fresh it is.
48. As a team member, I want to see when the next sync will run, so that I know when the data will refresh.

## 4. Implementation Decisions

### 4.1 Architecture

The app is a Symfony application in PHP 8.5. The app has one container. The container runs nginx, php-fpm, and cron. Supervisor starts all three processes.

The app has two entry points:

- A web app. It serves the dashboard page and the JSON API. *(phase 1)*
- A CLI command `app:sync`. It fetches data from GitLab and caches it. Flags: `--full` (one-time backfill), `--refresh-open` (re-fetch sub-resources for all open MRs), and `--notify-slack` (after the sync, post Slack notifications for new and stale MRs — phase 2). *(phase 1)*

Cron:

- Every 15 minutes: `app:sync` (incremental). *(phase 1)*
- Nightly at 03:00: `app:sync --refresh-open`. *(phase 1)*
- Phase 2 replaces the 15-minute line with `app:sync --notify-slack`.

### 4.2 Environment variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `GITLAB_URL` | GitLab base URL | none |
| `GITLAB_GROUP` | Group path, for example `company` | none |
| `GITLAB_TOKEN` | Personal access token, `read_api` scope | none |
| `GITLAB_RPS` | Max GitLab requests per second (throttle) | `8` |
| `GITLAB_PROJECTS` | Comma-separated project paths to sync, for example `company/app,company/lib` | all projects in the group |
| `RETENTION_DAYS` | MRs merged/closed longer ago than this are pruned | `90` |
| `REQUIRED_APPROVALS` | Approval target | `2` |
| `JIRA_URL` | Jira browse base URL | none |
| `SLACK_TOKEN` | Slack bot token | none *(phase 2)* |
| `SLACK_CHANNEL` | Slack channel ID | none *(phase 2)* |
| `APP_URL` | Dashboard base URL, for the Slack link | none *(phase 2)* |

### 4.3 Data model (SQLite)

The cache stores current state only. The app computes all history from current state plus event timestamps. No historical snapshots are stored. This keeps the database small.

Tables:

- `users` — `id`, `name`, `username`, `avatar_url`.
- `projects` — `id`, `path_with_namespace`.
- `merge_requests` — `id`, `iid`, `project_id`, `title`, `description`, `author_id`, `state`, `draft`, `created_at`, `merged_at`, `closed_at`, `updated_at`, `web_url`.
- `approvals` — `id`, `mr_id`, `user_id`, `created_at`. Wipe-and-reinsert per MR on every re-fetch.
- `discussions` — `id`, `mr_id`, `user_id`, `created_at`. One row per **discussion thread** (not per note). `user_id` is the author of the first non-system, non-author note; `created_at` is that note's time. Reviewers who only reply to an existing thread are not counted — a known simplification. Wipe-and-reinsert per MR.
- `commits` — `id`, `mr_id`, `sha`, `message`, `committed_date`, `current`, `additions`, `deletions`. **Append-only by `(mr_id, sha)`**; never deleted. `current` is set on each sync for shas still present and unset for force-pushed-away shas. `additions`/`deletions` are fetched once per sha (immutable) and cached, so MR size always reflects the latest commit set without re-fetching unchanged stats.
- `pipelines` — `id`, `mr_id`, `status`, `created_at`, `updated_at`. Wipe-and-reinsert per MR.
- `jobs` — `id`, `pipeline_id`, `mr_id`, `status`. Wipe-and-reinsert per MR with its pipelines.
- `sync_state` — `key`, `value`. Stores `last_sync`, `last_notify` (phase 2), and the sync lock.

Wipe-and-reinsert handles unapprovals, deleted comments, and force-pushed pipelines/jobs automatically. Commits are the exception: they are append-only so commit stats are fetched once per sha and the MR size metric can tell present from superseded commits. Retention pruning removes both when an MR falls out of retention (see §4.5).

### 4.4 GitLab API usage

The app uses these endpoints:

- `GET /groups/:id/projects?include_subgroups=true` — list projects (filtered to `GITLAB_PROJECTS` when set).
- `GET /groups/:id/merge_requests?state=all&per_page=100` — list MRs.
- `GET /projects/:id/merge_requests/:iid/approvals` — approvals with `approved_at` timestamps.
- `GET /projects/:id/merge_requests/:iid/discussions` — discussion threads with notes and timestamps.
- `GET /projects/:id/merge_requests/:iid/pipelines` — pipelines for the MR.
- `GET /projects/:id/pipelines/:pipeline_id/jobs` — jobs for the latest pipeline (for the tinted-spinner feature).
- `GET /projects/:id/merge_requests/:iid/commits` — commits.
- `GET /projects/:id/repository/commits/:sha?stats=true` — per-commit `additions`/`deletions`, fetched once per sha.

The group MR list returns MRs across the group (restricted to `GITLAB_PROJECTS` when set). Approvals, discussions, pipelines, jobs, and commits need one or more calls per MR. The sync throttles requests to `GITLAB_RPS` (default 8/s). Approval timestamps depend on GitLab providing `approved_at`; if it is missing for a MR, that MR's approval-based metrics are empty.

Every list endpoint is fetched with `per_page=100` and all pages are followed via the `Link: rel="next"` header. Pagination is followed for the project list, the MR list, and every sub-resource; there is no page cap and no truncation.

### 4.5 Sync algorithm

There are three sync modes.

Full backfill (`app:sync --full`), run once at deploy:

1. Fetch all projects in the group (filtered to `GITLAB_PROJECTS` when set).
2. Fetch every open MR (any age) and every MR merged within `RETENTION_DAYS` (kept for the merge metrics). Closed MRs are never fetched — no metric uses them and the list shows only open MRs.
3. For each MR, fetch approvals, discussions, pipelines, jobs, and commits; for each new commit sha, fetch commit stats.
4. Store everything in SQLite (wipe-and-reinsert approvals/discussions/pipelines/jobs per MR; append commits).
5. Reconcile: drop any cached MR no longer open or recently merged (e.g. an MR that has since been closed), so the cache mirrors GitLab.
6. Set `last_sync` to now.

Incremental (`app:sync`, also the background on-load path):

1. If `last_sync` is null, fetch only MRs updated in the last 1 hour (bounded so the first-ever load is fast). Otherwise fetch MRs with `updated_after = last_sync - 60s` (small overlap margin for clock skew).
2. For each changed MR: if it is `closed`, drop it from the cache (closed MRs are not stored); otherwise re-fetch approvals, discussions, pipelines, jobs, and commits, and fetch stats for any new commit sha.
3. Additionally, re-fetch pipelines and jobs for any MR whose latest cached pipeline is `running` or `pending`, even if the MR itself was not updated, so the pipeline indicator resolves within the sync cadence.
4. Upsert the MR. Wipe-and-reinsert approvals/discussions/pipelines/jobs for that MR. Append commits; set `current` on shas present, unset on shas that vanished.
5. Set `last_sync` to now.

Open-MR refresh (`app:sync --refresh-open`, nightly):

1. For every currently open MR, re-fetch approvals, discussions, pipelines, jobs, and commits.
2. Wipe-and-reinsert approvals/discussions/pipelines/jobs; append commits.
3. This catches approvals and discussions that did not bump the MR's `updated_at`. Merged/closed MRs are frozen and are skipped.
4. Retention: delete merged MRs older than `RETENTION_DAYS` (default 90), together with their approvals, discussions, commits, pipelines, and jobs. (Closed MRs are dropped at fetch and never stored.) MRs pruned this way were not open in the last 60 days, so they cannot affect the displayed windows.

The sync sleeps between requests to stay under `GITLAB_RPS`. A sync lock (stored in `sync_state`) prevents two syncs from running at once; a holder is allowed a generous timeout, after which the lock is considered stale and can be taken over.

### 4.6 Refresh on page load

The web app checks `last_sync` on every request to `/api/data`.

- If the cache is newer than 60 seconds, serve the cached data.
- If the cache is older than 60 seconds, serve the stale cache immediately and spawn a detached `app:sync` process (guarded by the sync lock) so the next request is fresh. The page request never blocks on a sync.
- If GitLab is unreachable, serve the stale cache.

This is stale-while-revalidate: the browser always gets an immediate answer, and a background sync refreshes the cache at most once per minute. Concurrent users share one sync — the sync lock serializes the background workers, and the web process never acquires the lock itself.

The first load before `app:sync --full` has run uses the bounded 1-hour incremental path, so it does not block on full history.

SQLite runs in WAL mode with a `busy_timeout` so the surviving writer and readers do not error under concurrent access.

### 4.7 Slack notification (phase 2)

Slack notifications are a flag on the sync command: `app:sync --notify-slack`. The cron runs it every 15 minutes (phase 2). Without the flag the command only syncs; with it the command syncs and then notifies, so one command covers both modes.

1. Run an incremental sync (the same steps as `app:sync`).
2. Find MRs with `created_at` after `last_notify`. Bundle them into one message.
3. For each new MR, count approvals and compute `X = max(0, REQUIRED_APPROVALS - approvals)`.
4. Find MRs that turned stale (open and crossed the 60-day threshold) since `last_notify`. For each, prepare a nudge naming the author.
5. Post the bundled new-MR list and the stale nudges to the Slack channel.
6. Set `last_notify` to now.

On first enablement, initialize `last_notify = now` so only MRs created after Slack was enabled are notified.

If the incremental sync fails, the notifications are still posted from the cached data, and the message states that the sync failed and why.

New-MR message format:

```
New MRs since last check:
- [title] by [author] — [link] — needs X more approvals
- [title] by [author] — [link] — needs X more approvals
Dashboard: [APP_URL]
```

Stale nudge format:

```
[title] by [author] turned stale — [link] — Dashboard: [APP_URL]
```

The command uses the Slack Web API `chat.postMessage` with the bot token.

### 4.8 API contract

`GET /api/data?bucket=week|day|hour` returns JSON. The backend computes every per-MR and per-person value from the cache; the frontend renders them. `bucket` selects the chart granularity (default `day`) — the frontend re-requests with a different `bucket` when the user zooms. Duration/size metrics carry both a `mean` and a `median` series; the frontend picks which to draw from the mean/median cookie.

```json
{
  "meta": {
    "required_approvals": 2,
    "stale_days": 60,
    "window_days": 60,
    "coverage_window_days": 30,
    "generated_at": "2026-08-11T12:00:00Z",
    "cache_age_seconds": 3,
    "last_sync_at": "2026-08-11T11:45:00Z",
    "next_sync_at": "2026-08-11T12:00:00Z"
  },
  "users": [
    {"id": 1, "name": "Jane Doe", "username": "jdoe", "avatar_url": "..."}
  ],
  "mrs": [
    {
      "id": 1240,
      "iid": 1240,
      "project_path": "company/app",
      "title": "REC-1234 - Add feature X",
      "jira_ticket": "REC-1234",
      "description": "...",
      "author": {"id": 1, "name": "Jane Doe", "avatar_url": "..."},
      "state": "opened",
      "draft": false,
      "stale": false,
      "created_at": "2026-08-08T09:00:00Z",
      "merged_at": null,
      "closed_at": null,
      "web_url": "https://gitlab.company.io/company/app/-/merge_requests/1240",
      "age_seconds": 345600,
      "time_to_first_approval_seconds": 86400,
      "commit_count": 2,
      "pipeline": {"status": "running", "indicator": "spinner"}
    }
  ],
  "metrics": {
    "time_to_first_approve": {
      "bucket": "day",
      "unit": "seconds",
      "persons": {
        "1": {"buckets": ["2026-08-04", "2026-08-05"], "mean": [3600, 4100], "median": [3400, 4000]}
      }
    },
    "coverage": {
      "bucket": "day",
      "unit": "percent",
      "persons": {
        "1": {"buckets": ["2026-08-04", "2026-08-05"], "values": [25, 33]}
      }
    }
  }
}
```

The `mrs` array holds the rows the MR list renders — the last 5 merged MRs, the open and closed MRs from the last 60 days, and stale open MRs (with `stale: true`) that collapse into the stale link. The frontend sorts and renders; all values arrive pre-computed. `pipeline.indicator` is one of `spinner`, `check`, `fail`, `neutral`, or `none` (see Metric 12).

`metrics` holds one object per metric cell (§4.9), keyed by user id. Non-duration metrics have `values`; duration/size metrics have both `mean` and `median`.

### 4.9 Metrics

Each metric cell plots a time series (one line per person) over the `window_days` window, bucketed by zoom (weekly/daily/hourly). All metric values are computed by the backend from the cached tables; the frontend renders them. Zoom re-requests the API with a different `bucket`.

The bucketing dimension differs per metric:

| Metric | Bucket by |
|--------|-----------|
| 1 Time to first approve | activity date (approval) |
| 2 Coverage % | rolling 30-day window ending at the plotted day |
| 3 Time to review | activity date (first approval or discussion) |
| 4 Stale MR count | the plotted day (snapshot reconstructed from timestamps) |
| 5 Time to merge | `merged_at` |
| 6 MR size | `created_at` |
| 7 Approvals given | activity date (approval) |
| 8 First response time | activity date (first discussion) |
| 9 Merged MR count | `merged_at` (snapshot of last 30 days) |

Time series are reconstructed from event timestamps (`created_at`, `merged_at`, `closed_at`, approval/discussion times), not from stored snapshots. An MR was open at day D if `created_at <= D` and (`merged_at` is null or `merged_at > D`) and (`closed_at` is null or `closed_at > D`).

#### Metric 1 — Time to first approve (per MR, reviewer-centric)

`min(approval.created_at) - mr.created_at` over all approvals. If the MR has no approval, the value is empty. The **first approver** owns this MR's value in the per-person graph. Bucket by the approval date.

#### Metric 2 — Age of MR (per MR, shown in the MR row)

- Open MR: `now - created_at`.
- Merged MR: `merged_at - created_at`.
- Closed MR: `closed_at - created_at`.

Format: `Xd HH:MM:SS`, for example `12d 23:59:59`.

#### Metric 3 — Time to review (per person, reviewer-centric)

For each MR where the person approved or left a discussion thread: `first activity time - mr.created_at`. First activity is the earlier of the person's first approval and their first discussion thread. Average across their reviewed MRs. Bucket by the activity date. Format: days + hours + minutes.

#### Metric 4 — Coverage % (per person)

For a rolling 30-day window ending at each plotted day: `MRs opened in the window that the person reviewed / MRs opened in the window`. A review is an approval or a discussion thread, at any time (even after the window closes). If no MRs were opened in the window, the value is empty.

#### Metric 5 — Stale MR count (per person, author-centric, snapshot time series)

For each plotted day: count of the person's MRs that were open on that day and older than 60 days (`day - created_at > 60 days`, and the MR was open on that day).

#### Metric 6 — Time to merge (per person, author-centric)

For each merged MR authored by the person: `merged_at - created_at`. Average. Bucket by `merged_at`.

#### Metric 7 — Mean/median toggle (control, not a metric cell)

A `⇄` switch in the header of each duration/size cell (metrics 1, 3, 6, 8, 10). The backend sends both a `mean` and a `median` line for these metrics; when the switch is on, the frontend draws the median line instead of the mean. The switch is one shared state across all such cells, and the value lives in a cookie (frontend-only, no re-request).

#### Metric 8 — MR size (per person, author-centric)

For each MR authored by the person: sum of `additions + deletions` over its **current** commits (last-fetched size, reflecting added/changed commits). Averaged per person. Bucket by `created_at`.

#### Metric 9 — Approvals given (per person)

Count of approvals given by the person, over the displayed window. Bucket by the approval date.

#### Metric 10 — First response time (per person, reviewer-centric)

For each MR where the person left a discussion thread: `first discussion time - mr.created_at`. Average. Bucket by the discussion date.

#### Metric 11 — Merged MR count (per person, author-centric, snapshot)

Count of MRs authored by the person that were merged within the last 30 days. Snapshot value over the `merged_at` axis.

#### Metric 12 — Pipeline indicator (per MR, shown in the MR row)

Computed from the latest pipeline (the one with the highest `id`) and its jobs:

- Spinner while the latest pipeline runs. The spinner is red if any finished job has status `failed`, orange if any finished job has status `warning`, otherwise neutral.
- Green check when the latest pipeline finished with `success`.
- Red indicator when the latest pipeline finished with `failed`.
- Neutral indicator for `canceled`/`skipped`/`manual`. No indicator if there is no pipeline.

### 4.10 Metric cell tooltip

Each cell header has a `(?)` icon. Hovering shows a tooltip. The tooltip has three parts:

- Description of the metric.
- What the score means.
- Whether lower or higher is better.

Example for time to review:

```
Time to review
The time from MR creation until the reviewer's first
activity (approval or discussion).
A low value means the reviewer responds fast.
Lower is better.
```

### 4.11 Frontend

The frontend is one HTML page with vanilla JavaScript. It uses uPlot for charts.

The page loads `/api/data` and re-fetches it every 60 seconds, so the dashboard stays fresh without a reload. The backend computes all series; the frontend renders them.

Zoom is drag-to-select a range on the x-axis; double-click resets to full. On zoom the frontend re-requests `/api/data?bucket=week|day|hour` — zoomed out: weekly buckets, zoomed in: daily, fully zoomed: hourly. Bucketing follows the per-metric dimension in §4.9.

Each cell draws one line per person from the `metrics` payload. The line ends at the person's avatar. uPlot custom point rendering draws the avatar image at the last point. Each cell header shows a `(?)` tooltip; duration/size cells also show the shared `⇄` mean/median switch. The switch is cookie-persisted and only picks which of the two backend-computed series (`mean` or `median`) is drawn — no re-request.

The MR list renders the `mrs` array. The list shows open and closed MRs from the last 60 days, newest open MR at the bottom. The last 5 merged MRs pin to the top, grayed. Closed MRs (within 60 days) appear grayed in the body with a "closed" badge. Drafts show a "draft" badge. MRs with `stale: true` collapse into one link:

```
5 stale Merge Requests belonging to Author A (3), Author B (2)
```

The link sits in the middle of the list (below the pinned merged MRs, above the open MRs) and expands the stale MRs on click.

The commits link opens one new tab per current commit diff. The link format:

```
{GITLAB_URL}/{project_path}/-/merge_requests/{iid}/diffs?commit_id={sha}
```

Opening multiple tabs requires popup permission for the dashboard URL, granted once by the user.

The pipeline indicator comes from `mrs[].pipeline.indicator` (see Metric 12).

The Jira ticket column shows `mrs[].jira_ticket`, extracted by the backend with the regex `^([A-Z][A-Z0-9]+-\d+)`. The ticket links to `{JIRA_URL}{ticket}`.

The header shows the sync status from `meta`: `last_sync_at` and `next_sync_at`, formatted in the browser's local time.

The backend computes all thresholds and day boundaries in UTC; the frontend renders timestamps in the browser's local time.

All content that originates from GitLab (MR titles, descriptions, commit messages, author names) is rendered as text via `textContent`, never `innerHTML`, to avoid stored XSS from MR content.

### 4.12 Docker

The Dockerfile is multi-stage.

Stage 1 — build:

- Base: `composer:2` image.
- Run `composer install --no-dev`.
- Copy the vendor directory.

Stage 2 — runtime:

- Base: `php:8.5-fpm-alpine`.
- Install `pdo_sqlite`, `curl`, `openssl`, nginx, cron, supervisor.
- Copy the app and the vendor directory.
- Copy the entrypoint script.

The entrypoint writes the runtime environment variables to `/app/.env.local` (Symfony auto-loads it for `bin/console`, so cron-run commands see `GITLAB_TOKEN` and the rest; the container env still feeds php-fpm directly). It then starts supervisor. Supervisor runs nginx, php-fpm, and cron. SQLite is opened in WAL mode with a `busy_timeout`.

The SQLite database file lives on a mounted volume so the backfill survives container restarts.

Cron lines (phase 1):

```
*/15 * * * * php /app/bin/console app:sync
0 3 * * * php /app/bin/console app:sync --refresh-open
```

Phase 2 replaces the 15-minute line with:

```
*/15 * * * * php /app/bin/console app:sync --notify-slack
```

### 4.13 GitLab CI

The pipeline has two stages: test and build.

The test stage runs PHPUnit, PHPStan (level 10), and phpcs on the backend, and the frontend smoke test. It creates `var/cache` first, because phpcs writes its cache file (`var/cache/phpcs-cache`) there and does not create missing parent directories. The build stage uses kaniko. Kaniko builds the image without a privileged runner. The pipeline logs in to the GitLab Container Registry. The pipeline pushes the image to `$CI_REGISTRY_IMAGE`.

Deployment is manual. The operator runs `docker compose up` on the target host. The same image runs locally and in production.

### 4.14 Local testing

The operator runs the image locally:

```
docker run -p 8080:80 \
  -v cr-dashboard-data:/var/lib/cr-dashboard \
  -e GITLAB_URL=https://gitlab.company.io \
  -e GITLAB_GROUP=company \
  -e GITLAB_TOKEN=<personal access token> \
  -e JIRA_URL=https://company.atlassian.net/browse/ \
  <image>
```

Then run the one-time backfill:

```
docker exec <container> php /app/bin/console app:sync --full
```

Phase 2 adds `SLACK_TOKEN`, `SLACK_CHANNEL`, and `APP_URL`. The personal access token needs the `read_api` scope. `GITLAB_PROJECTS` is optional; omit it, the sync covers the whole group.

## 5. Testing Decisions

The tests cover external behavior, not implementation details.

Backend tests (PHPUnit):

- Metric computation tests. Each metric is a pure function. Tests feed MR, approval, discussion, commit, pipeline, and job fixtures and assert the result.
- Pipeline indicator tests; including the running-with-failed-job and running-with-warning cases.
- Jira extraction test. Tests the regex on sample titles.
- Sync logic tests. A mock GitLab client returns fixtures. Tests assert the SQLite state after sync, including wipe-and-reinsert, append-only commits, and the running-pipeline refresh.
- Pagination test. A fixture with multiple pages is followed to completion and all rows are stored.
- Retention test. MRs merged/closed longer than `RETENTION_DAYS` are pruned together with their sub-resources.
- API contract test. Tests that `/api/data` returns the documented computed shape, including the `mean`/`median` pair on duration metrics and `last_sync_at`/`next_sync_at`.

Frontend tests:

- A smoke test (Playwright). The page loads and renders without console errors.
- A unit test for the mean/median toggle. The cookie switches which of the two backend series is drawn.

Static analysis and code style:

- PHPStan at level 10.
- phpcs with the team's `phpcs.xml`, based on `slevomat/coding-standard` (installed via composer). The file is committed to the repository before implementation begins.

Prior art: the metric functions are pure and deterministic. This makes the tests simple and fast.

## 6. Out of Scope

- Dashboard login or user accounts.
- Multiple GitLab groups.
- GitHub or Bitbucket support.
- Mobile layout.
- Win or lose highlighting.
- WebSocket real-time updates.
- Historical snapshots before first deploy. The app computes history from current state and event timestamps.
- Jira API integration. The app only links to Jira, it does not read Jira data.
- Slack notifications for approval milestones. The app notifies on new MRs and on MRs turning stale (phase 2).
- Per-project approval rules. The app uses the single `REQUIRED_APPROVALS` env var; real per-project GitLab rules are ignored (known simplification).

## 7. Further Notes

- The app stores timestamps in UTC. The backend computes all thresholds and bucketing in UTC; the frontend renders in the browser's local time.
- The on-load refresh is asynchronous: the page request never blocks on a sync, and a background sync serves stale data in the interim.
- The mean/median switch value lives in a cookie and is shared between all duration/size cells. The backend returns both series; the switch only picks which is drawn.
- The initial backfill (`app:sync --full`) may take several minutes to an hour for large groups. The sync throttles to `GITLAB_RPS`. The first page load before backfill is bounded to the last 1 hour.
- Approvals/discussions/pipelines/jobs are wipe-and-reinserted per MR on re-fetch. Commits are append-only by sha, with a `current` flag, so MR size always reflects the latest commit set.
- The nightly `app:sync --refresh-open` guarantees approvals and discussions on open MRs are never more than ~24 hours stale, and it prunes MRs that fall outside `RETENTION_DAYS`.
- The stale threshold is 60 days. The MR list window is 60 days. The coverage window is 30 days. The merged-count window is 30 days. Retention is 90 days. All are constants in the code.
- The SQLite database lives on a mounted volume.
- Slack notifications (new-MR bundle and stale nudges, with a dashboard link) are phase 2 and run as `app:sync --notify-slack`.
- The dashboard shows data only. It does not rank or highlight winners or losers.