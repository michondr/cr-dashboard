# Code Review Dashboard — Specification

## 1. Problem Statement

The team creates merge requests (MRs) in GitLab. Reviewers approve MRs or leave discussions. Nobody tracks how long reviews take. Nobody knows who reviews fast or slow. Nobody knows which MRs wait long for approval. The team wants data about review behavior. The team wants to see the data on one dashboard.

The dashboard must show review metrics over time. The dashboard must show a list of MRs. The dashboard must show per-person graphs. The dashboard must run as one Docker image. The image must build itself in the GitLab pipeline.

## 2. Solution

A web dashboard shows review metrics. The dashboard runs as one Docker image. The image reads data from GitLab. The image caches data in SQLite. The dashboard shows a list of MRs. The dashboard shows graphs of per-person metrics. The dashboard refreshes data automatically. The dashboard pings Slack when new MRs appear (phase 2).

The dashboard has no login. The VPN is the security boundary. The GitLab token lives in the server environment. The token never reaches the browser.

### 2.1 Page layout

The page does not scroll. The MR list takes the top 55% of the viewport. The user stats take the bottom 45%. The MR list scrolls internally.

```
+------------------------------------------------------------------+
|  Code Review Dashboard                                           |
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
|  | First try| | Coverage | |Time to   | | Stale    | |Approvals ||
|  | %    (?) | | %    (?) | |review(?)⇄| | MRs  (?) | |given  (?)||
|  |  graph   | |  graph   | |  graph   | |  graph   | |  graph   ||
|  |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)||
|  +----------+ +----------+ +----------+ +----------+ +----------+|
|  +----------+ +----------+ +----------+ +----------+ +----------+|
|  |Time to   | |Time to   | |First     | |MR size   | |Merged    ||
|  |first app | |merge     | |response  | |    (?)⇄  | |MR count  ||
|  |    (?)⇄  | |    (?)⇄  | |time  (?)⇄| |  graph   | |   (?)    ||
|  |  graph   | |  graph   | |  graph   | |  graph   | |  graph   ||
|  |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)||
|  +----------+ +----------+ +----------+ +----------+ +----------+|
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
| 6 | State | `open 2 commits` | `open`/`merged`/`closed`/`draft` plus commit count. `merged` and `closed` rows are grayed; `draft` shows a draft badge. |
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
|        | #1239 | Fix bug Y          | Consectetur adipiscing…     | (av) JR | closed 1c  | 5d02:00  | 0d05:30 | [ok]   | [1comm]|
|        | #1238 | Refactor Z         | Dolor sit amet consectetur… | (av) AB | merged 4c  | 2d01:00  | 1d00:10 | [!!]   | [4comm]|
+--------+-------+--------------------+-----------------------------+---------+------------+----------+---------+--------+--------+
```

The Jira ticket links to the Jira issue. The title and the MR number link to the MR in a new tab. The description collapses after 50 pixels of height and expands on click. The commits link opens one new tab per current commit diff (the user grants popup permission to the dashboard URL once). Merged and closed rows render grayed out; closed rows show a "closed" badge, drafts show a "draft" badge. The row never scrolls horizontally; on narrower screens the description column shrinks first.

### 2.3 Metric cell

```
+----------------------+
| First try %      (?) |
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
17. As a team member, I want a graph of first-try percentage per person, so that I know review outcomes.
18. As a team member, I want a graph of coverage percentage per person, so that I know who reviews.
19. As a team member, I want a graph of time to review per person, so that I know review speed.
20. As a team member, I want a graph of stale MR count per person, so that I know who leaves MRs open.
21. As a team member, I want a graph of approvals given per person, so that I know the review load.
22. As a team member, I want a graph of time to merge per person, so that I know shipping speed.
23. As a team member, I want a graph of first response time per person, so that I know first reactions.
24. As a team member, I want a graph of MR size per person, so that I know review difficulty.
25. As a team member, I want the median alongside the mean, so that outliers do not mislead me.
26. As a team member, I want to zoom the graphs, so that I can see daily or hourly detail.
27. As a team member, I want the median toggle saved, so that my choice persists.
28. As a team member, I want each person's line to end at their avatar, so that I can read the graph.
29. As a team member, I want a tooltip on each metric, so that I understand the score.
30. As a team member, I want the page to fit the viewport, so that I do not scroll.
31. As a team member, I want fresh data on page load, so that I see current state.
32. As a team member, I want a Slack message when new MRs appear, so that I know to review them (phase 2).
33. As a team member, I want the Slack message to state how many approvals are needed, so that I know the target (phase 2).
34. As a team member, I want the dashboard to run as one Docker image, so that deployment is simple.
35. As a team member, I want the image built in the GitLab pipeline, so that the build is automatic.
36. As a team member, I want to run the same image locally, so that I can test changes.
37. As a team member, I want the GitLab URL, group, and token in environment variables, so that I can point the tool at any instance.
38. As a team member, I want the required approval count in an environment variable, so that the team can change it.
39. As a team member, I want the Jira URL in an environment variable, so that the team can change it.
40. As a team member, I want the Slack token and channel in environment variables, so that the team can change them (phase 2).
41. As a team member, I want closed MRs shown with a "closed" indicator, so that I can see what was abandoned.
42. As a team member, I want a graph of merged MR count per person, so that I know who ships.
43. As a team member, I want a mean/median switch on each duration cell, shared across all cells and persisted, so that I can compare averages and medians.
44. As a team member, I want the pipeline spinner red when a job already failed and orange when a job warned, so that I see trouble while the build runs.
45. As a team member, I want MR size to reflect the latest commits, so that added commits are counted.
46. As a team member, I want Slack to send one bundled list of new MRs and a nudge for stale MRs, so that notifications stay useful (phase 2).
47. As a team member, I want the Slack message to link to the dashboard, so that I can open it directly (phase 2).

## 4. Implementation Decisions

### 4.1 Architecture

The app is a Symfony application in PHP 8.5. The app has one container. The container runs nginx, php-fpm, and cron. Supervisor starts all three processes.

The app has three entry points:

- A web app. It serves the dashboard page and the JSON API. *(phase 1)*
- A CLI command `app:sync`. It fetches data from GitLab and caches it. It takes two flags: `--full` (one-time backfill) and `--refresh-open` (re-fetch sub-resources for all open MRs). *(phase 1)*
- A CLI command `app:notify-slack`. It syncs and pings Slack for new and stale MRs. *(phase 2)*

Cron:

- Every 15 minutes: `app:sync` (incremental). *(phase 1)*
- Nightly: `app:sync --refresh-open`. *(phase 1)*
- Every 15 minutes: `app:notify-slack`. *(phase 2)*

### 4.2 Environment variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `GITLAB_URL` | GitLab base URL | none |
| `GITLAB_GROUP` | Group path, for example `company` | none |
| `GITLAB_TOKEN` | Personal access token, `read_api` scope | none |
| `GITLAB_RPS` | Max GitLab requests per second (throttle) | `10` |
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
- `discussions` — `id`, `mr_id`, `user_id`, `created_at`. One row per **discussion thread** (not per note). `user_id` is the author of the first non-system, non-author note; `created_at` is that note's time. Wipe-and-reinsert per MR.
- `commits` — `id`, `mr_id`, `sha`, `message`, `committed_date`, `current`, `additions`, `deletions`. **Append-only by `(mr_id, sha)`**; never deleted. `current` is set on each sync for shas still present and unset for force-pushed-away shas. `additions`/`deletions` are fetched once per sha (immutable) and cached.
- `pipelines` — `id`, `mr_id`, `status`, `created_at`, `updated_at`. Wipe-and-reinsert per MR.
- `jobs` — `id`, `pipeline_id`, `mr_id`, `status`. Wipe-and-reinsert per MR with its pipelines.
- `sync_state` — `key`, `value`. Stores `last_sync`, `last_notify` (phase 2), and the sync lock.

Wipe-and-reinsert handles unapprovals, deleted comments, and force-pushed pipelines/jobs automatically. Commits are the exception: they are append-only so rework can be detected from superseded commits (see Metric 3).

### 4.4 GitLab API usage

The app uses these endpoints:

- `GET /groups/:id/projects?include_subgroups=true` — list projects.
- `GET /groups/:id/merge_requests?state=all&per_page=100` — list MRs.
- `GET /projects/:id/merge_requests/:iid/approvals` — approvals with `approved_at` timestamps.
- `GET /projects/:id/merge_requests/:iid/discussions` — discussion threads with notes and timestamps.
- `GET /projects/:id/merge_requests/:iid/pipelines` — pipelines for the MR.
- `GET /projects/:id/pipelines/:pipeline_id/jobs` — jobs for the latest pipeline (for the tinted-spinner feature).
- `GET /projects/:id/merge_requests/:iid/commits` — commits.
- `GET /projects/:id/repository/commits/:sha?stats=true` — per-commit `additions`/`deletions`, fetched once per sha.

The group MR list returns MRs across the group. Approvals, discussions, pipelines, jobs, and commits need one or more calls per MR. The sync throttles requests to `GITLAB_RPS` (default 10/s). Approval timestamps depend on GitLab providing `approved_at`; if it is missing for a MR, that MR's approval-based metrics are empty.

Every list endpoint is fetched with `per_page=100`. If a response has more than one page (`x-total-pages > 1` or a `Link: rel="next"`), the app throws a runtime exception so the operator notices, rather than silently truncating. Sub-resources are not expected to exceed 100 items.

### 4.5 Sync algorithm

There are three sync modes.

Full backfill (`app:sync --full`), run once at deploy:

1. Fetch all projects in the group.
2. Fetch all MRs in the group, all states, all pages.
3. For each MR, fetch approvals, discussions, pipelines, jobs, and commits; for each new commit sha, fetch commit stats.
4. Store everything in SQLite (wipe-and-reinsert approvals/discussions/pipelines/jobs per MR; append commits).
5. Set `last_sync` to now.

Incremental (`app:sync`, also the on-load and 15-minute cron path):

1. If `last_sync` is null, fetch only MRs updated in the last 1 hour (bounded so the first-ever load is fast). Otherwise fetch MRs with `updated_after = last_sync - 60s` (small overlap margin for clock skew).
2. For each changed MR, re-fetch approvals, discussions, pipelines, jobs, and commits; fetch stats for any new commit sha.
3. Upsert the MR. Wipe-and-reinsert approvals/discussions/pipelines/jobs for that MR. Append commits; set `current` on shas present, unset on shas that vanished.
4. Set `last_sync` to now.

Open-MR refresh (`app:sync --refresh-open`, nightly):

1. For every currently open MR, re-fetch approvals, discussions, pipelines, jobs, and commits.
2. Wipe-and-reinsert approvals/discussions/pipelines/jobs; append commits.
3. This catches approvals and discussions that did not bump the MR's `updated_at`. Merged/closed MRs are frozen and are skipped.

The sync sleeps between requests to stay under `GITLAB_RPS`. A sync lock (stored in `sync_state`) prevents two syncs from running at once; a holder is allowed a generous timeout, after which the lock is considered stale and can be taken over.

### 4.6 Refresh on page load

The web app checks `last_sync` on every request to `/api/data`.

- If the cache is newer than 5 seconds, serve the cached data.
- If the cache is older than 5 seconds, try to acquire the sync lock:
  - If the lock is held by another process, serve the stale cache immediately (stale-while-revalidate).
  - If acquired, run an incremental sync, release the lock, then serve fresh data.
- If GitLab is unreachable, serve the stale cache.

The first load ever (before `app:sync --full` has run) uses the bounded 1-hour incremental path, so it does not block on full history. After backfill, the 15-minute cron keeps `last_sync` fresh, so on-load deltas are small. Concurrent users share one cache window and one sync.

SQLite runs in WAL mode with a `busy_timeout` so the surviving writer and readers do not error under concurrent access.

### 4.7 Slack notification (phase 2)

The `app:notify-slack` command runs every 15 minutes.

1. Run an incremental sync.
2. Find MRs with `created_at` after `last_notify`. Bundle them into one message.
3. For each new MR, count approvals and compute `X = max(0, REQUIRED_APPROVALS - approvals)`.
4. Find MRs that turned stale (open and crossed the 60-day threshold) since `last_notify`. For each, prepare a nudge naming the author.
5. Post the bundled new-MR list and the stale nudges to the Slack channel.
6. Set `last_notify` to now.

On first enablement, initialize `last_notify = now` so only MRs created after Slack was enabled are notified.

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

`GET /api/data` returns JSON. The backend sends **raw events only** (plus `jira_ticket`, which is a cheap backend regex). The frontend computes every per-MR and per-person value from the raw arrays, joined by `mr_id`.

```json
{
  "meta": {
    "required_approvals": 2,
    "stale_days": 60,
    "window_days": 60,
    "coverage_window_days": 30,
    "generated_at": "2026-08-11T12:00:00Z",
    "cache_age_seconds": 3
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
      "created_at": "2026-08-08T09:00:00Z",
      "merged_at": null,
      "closed_at": null,
      "web_url": "https://gitlab.company.io/company/app/-/merge_requests/1240"
    }
  ],
  "approvals": [
    {"mr_id": 1240, "user_id": 2, "created_at": "2026-08-09T09:00:00Z"}
  ],
  "discussions": [
    {"mr_id": 1240, "user_id": 3, "created_at": "2026-08-08T10:00:00Z"}
  ],
  "commits": [
    {"mr_id": 1240, "sha": "abc123", "message": "Add feature X", "committed_date": "2026-08-08T08:00:00Z", "current": true, "additions": 120, "deletions": 30}
  ],
  "pipelines": [
    {"mr_id": 1240, "id": 99, "status": "running", "created_at": "2026-08-08T09:05:00Z", "updated_at": "2026-08-08T09:20:00Z"}
  ],
  "jobs": [
    {"mr_id": 1240, "pipeline_id": 99, "id": 501, "status": "failed"}
  ]
}
```

The frontend computes all per-person series from `mrs`, `approvals`, `discussions`, `commits`, `pipelines`, and `jobs`. The frontend re-buckets on zoom. The backend sends raw events, not pre-aggregated series.

### 4.9 Metrics

Each metric cell plots a time series (one line per person) over the `window_days` window, bucketed by zoom (weekly/daily/hourly). The bucketing dimension differs per metric:

| Metric | Bucket by |
|--------|-----------|
| 1 Time to first approve | activity date (approval) |
| 3 First try % | `merged_at` |
| 4 Time to review | activity date (first approval or discussion) |
| 5 Coverage % | rolling 30-day window ending at the plotted day |
| 6 Stale MR count | the plotted day (snapshot reconstructed from timestamps) |
| 7 Time to merge | `merged_at` |
| 9 MR size | `created_at` |
| 10 Approvals given | activity date (approval) |
| 11 First response time | activity date (first discussion) |
| 12 Merged MR count | `merged_at` (snapshot of last 30 days) |

Time series are reconstructed from event timestamps (`created_at`, `merged_at`, `closed_at`, approval/discussion times), not from stored snapshots. An MR was open at day D if `created_at <= D` and (`merged_at` is null or `merged_at > D`) and (`closed_at` is null or `closed_at > D`).

#### Metric 1 — Time to first approve (per MR, reviewer-centric)

`min(approval.created_at) - mr.created_at` over all approvals. If the MR has no approval, the value is empty. The **first approver** owns this MR's value in the per-person graph. Bucket by the approval date.

#### Metric 2 — Age of MR (per MR, shown in the MR row)

- Open MR: `now - created_at`.
- Merged MR: `merged_at - created_at`.
- Closed MR: `closed_at - created_at`.

Format: `Xd HH:MM:SS`, for example `12d 23:59:59`.

#### Metric 3 — First try % (per person, author-centric)

For each MR authored by the person that is no longer open:

- Score 1 if the MR reached `REQUIRED_APPROVALS` and was merged with **no rework**: no commit identity has `earliest_committed_date > first_approval.created_at`. A commit identity is its message within the MR; `earliest_committed_date` is the minimum `committed_date` across all shas seen for that message, so rebases that preserve the message do not count as rework.
- Score 0 if the MR reached the threshold but a new-message commit appeared after the first approval (rework), or if it was closed/merged without reaching the threshold.
- Still open → excluded.

The person's score is the average of their MR scores. Bucket by `merged_at`. `committed_date` is a proxy for push time (GitLab's polling API does not expose exact push timestamps); sub-15-minute ordering is approximate.

#### Metric 4 — Time to review (per person, reviewer-centric)

For each MR where the person approved or left a discussion thread: `first activity time - mr.created_at`. First activity is the earlier of the person's first approval and their first discussion thread. Average across their reviewed MRs. Bucket by the activity date. Format: days + hours + minutes.

#### Metric 5 — Coverage % (per person)

For a rolling 30-day window ending at each plotted day: `MRs opened in the window that the person reviewed / MRs opened in the window`. A review is an approval or a discussion thread, at any time (even after the window closes). If no MRs were opened in the window, the value is empty.

#### Metric 6 — Stale MR count (per person, author-centric, snapshot time series)

For each plotted day: count of the person's MRs that were open on that day and older than 60 days (`day - created_at > 60 days`, and the MR was open on that day).

#### Metric 7 — Time to merge (per person, author-centric)

For each merged MR authored by the person: `merged_at - created_at`. Average. Bucket by `merged_at`.

#### Metric 8 — Mean/median toggle (control, not a metric cell)

A `⇄` switch in the header of each duration/size cell (metrics 1, 4, 7, 9, 11). When on, the cell shows a dashed median line beside the mean line. The switch is one shared state across all such cells, and the value lives in a cookie.

#### Metric 9 — MR size (per person, author-centric)

For each MR authored by the person: sum of `additions + deletions` over its **current** commits (last-fetched size, reflecting added/changed commits). Averaged per person. Bucket by `created_at`.

#### Metric 10 — Approvals given (per person)

Count of approvals given by the person, over the displayed window. Bucket by the approval date.

#### Metric 11 — First response time (per person, reviewer-centric)

For each MR where the person left a discussion thread: `first discussion time - mr.created_at`. Average. Bucket by the discussion date.

#### Metric 12 — Merged MR count (per person, author-centric, snapshot)

Count of MRs authored by the person that were merged within the last 30 days. Snapshot value over the `merged_at` axis.

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

The page loads `/api/data` once. The frontend computes all series from the raw events. It re-buckets on zoom:

- Zoomed out: weekly buckets.
- Zoomed in: daily buckets.
- Fully zoomed: hourly buckets.

Zoom is drag-to-select a range on the x-axis; double-click resets to full. Bucketing follows the per-metric dimension in §4.9.

Each cell draws one line per person. The line ends at the person's avatar. uPlot custom point rendering draws the avatar image at the last point. Each cell header shows a `(?)` tooltip; duration/size cells also show the shared `⇄` mean/median switch (cookie-persisted).

The MR list renders from the `mrs` array. The list shows open and closed MRs from the last 60 days, newest open MR at the bottom. The last 5 merged MRs pin to the top, grayed. Closed MRs (within 60 days) appear grayed in the body with a "closed" badge. Drafts show a "draft" badge. MRs opened more than 60 days ago collapse into one link:

```
5 stale Merge Requests belonging to Author A (3), Author B (2)
```

The link sits in the middle of the list (below the pinned merged MRs, above the open MRs) and expands the stale MRs on click.

The commits link opens one new tab per current commit diff. The link format:

```
{GITLAB_URL}/{project_path}/-/merge_requests/{iid}/diffs?commit_id={sha}
```

Opening multiple tabs requires popup permission for the dashboard URL, granted once by the user.

The pipeline indicator shows:

- Spinner while the pipeline runs. The spinner is red if any finished job has status `failed`, orange if any finished job has status `warning`, otherwise neutral.
- Green checkmark when the latest pipeline finished with `success`.
- Red indicator when the latest pipeline finished with `failed`.
- Neutral indicator for `canceled`/`skipped`/`manual`. No indicator if there is no pipeline.

The latest pipeline is the one with the highest `id` for the MR.

The Jira ticket column shows the ticket extracted from the title by the backend. The regex is `^([A-Z][A-Z0-9]+-\d+)`. The ticket links to `{JIRA_URL}{ticket}`.

All thresholds and bucketing use the browser's local time. The backend stores timestamps in UTC; the frontend converts for display and for "last 60 days" / "stale 60 days" calculations.

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

Phase 2 adds:

```
*/15 * * * * php /app/bin/console app:notify-slack
```

### 4.13 GitLab CI

The pipeline has one stage: build.

The build uses kaniko. Kaniko builds the image without a privileged runner. The pipeline logs in to the GitLab Container Registry. The pipeline pushes the image to `$CI_REGISTRY_IMAGE`.

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

Phase 2 adds `SLACK_TOKEN`, `SLACK_CHANNEL`, and `APP_URL`. The personal access token needs the `read_api` scope.

## 5. Testing Decisions

The tests cover external behavior, not implementation details.

Backend tests (PHPUnit):

- Metric computation tests. Each metric is a pure function. Tests feed MR, approval, discussion, commit, pipeline, and job fixtures and assert the result.
- First-try rework detection tests, including the rebase-preserves-message case.
- Pipeline indicator tests, including the running-with-failed-job and running-with-warning cases.
- Jira extraction test. Tests the regex on sample titles.
- Sync logic tests. A mock GitLab client returns fixtures. Tests assert the SQLite state after sync, including wipe-and-reinsert and append-only commit behavior.
- Pagination guard test. A fixture with `x-total-pages > 1` triggers the exception.
- API contract test. Tests that `/api/data` returns the documented raw shape.

Frontend tests:

- Bucket and aggregation functions (Vitest). Tests feed raw events and assert the series.
- A smoke test (Playwright). The page loads and renders without console errors.

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
- Exact push timestamps for first-try detection. The app uses `committed_date` as a proxy; webhooks/events are not in scope.

## 7. Further Notes

- The app stores timestamps in UTC. The frontend displays them in the browser local time and buckets/calculates thresholds in local time.
- The 5-second cache window means concurrent users share one sync; a sync in progress serves stale cache to new requests.
- The mean/median switch value lives in a cookie and is shared across all duration/size cells.
- The initial backfill (`app:sync --full`) may take several minutes. The sync throttles to `GITLAB_RPS`. The first page load before backfill is bounded to the last 1 hour.
- Approvals/discussions/pipelines/jobs are wipe-and-reinserted per MR on re-fetch. Commits are append-only by sha, with a `current` flag, so rework is detectable.
- The nightly `app:sync --refresh-open` guarantees approvals and discussions on open MRs are never more than ~24 hours stale.
- The stale threshold is 60 days. The MR list window is 60 days. The coverage window is 30 days. The merged-count window is 30 days. All are constants in the code.
- The SQLite database lives on a mounted volume.
- Slack notifications (new-MR bundle and stale nudges, with a dashboard link) are phase 2.
- The dashboard shows data only. It does not rank or highlight winners or losers.