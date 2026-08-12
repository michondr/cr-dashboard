# Code Review Dashboard — Specification

## 1. Problem Statement

The team creates merge requests (MRs) in GitLab. Reviewers approve MRs or leave discussions. Nobody tracks how long reviews take. Nobody knows who reviews fast or slow. Nobody knows which MRs wait long for approval. The team wants data about review behavior. The team wants to see the data on one dashboard.

The dashboard must show review metrics over time. The dashboard must show a list of MRs. The dashboard must show per-person graphs. The dashboard must run as one Docker image. The image must build itself in the GitLab pipeline.

## 2. Solution

A web dashboard shows review metrics. The dashboard runs as one Docker image. The image reads data from GitLab. The image caches data in SQLite. The dashboard shows a list of MRs. The dashboard shows graphs of per-person metrics. The dashboard refreshes data automatically. The dashboard pings Slack when a new MR appears.

The dashboard has no login. The VPN is the security boundary. The GitLab token lives in the server environment. The token never reaches the browser.

### 2.1 Page layout

The page does not scroll. The MR list takes the top 55% of the viewport. The user stats take the bottom 45%. The MR list scrolls internally.

```
+------------------------------------------------------------------+
|  Code Review Dashboard                                           |
+------------------------------------------------------------------+
|  MR LIST (55% height, internal scroll)                           |
|  +------------------------------------------------------------+  |
|  | [gray] #1234  REC-1234  Title...........  [open 3 commits] |  |
|  | [gray] #1233  REC-1220  Title...........  [open commit]    |  |
|  | +--------------------------------------------------------+ |  |
|  | | 5 stale MRs: Author A (3), Author B (2)                | |  |
|  | +--------------------------------------------------------+ |  |
|  | #1240  REC-1241  Title...........  [open 2 commits]  [sp] |  |
|  | #1239  REC-1235  Title...........  [open commit]   [ok]   |  |
|  | #1238  REC-1230  Title...........  [open 4 commits]  [!!] |  |
|  | ...                                                       |  |
|  | #1200  REC-1100  Title...........  [open commit]    [ok]  |  |
|  +------------------------------------------------------------+  |
+------------------------------------------------------------------+
|  USER STATS (45% height)                                          |
|  +----------+ +----------+ +----------+ +----------+ +----------+|
|  | First try| | Coverage | |Time to   | | Stale    | |Approvals ||
|  | %    (?) | | %    (?) | |review (?)| | MRs  (?) | |given  (?)||
|  |  graph   | |  graph   | |  graph   | |  graph   | |  graph   ||
|  |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)| |  (avatar)||
|  +----------+ +----------+ +----------+ +----------+ +----------+|
|  +----------+ +----------+ +----------+ +----------+ +----------+|
|  |Time to   | |Time to   | |First     | |MR size  | |Median    ||
|  |first app | |merge     | |response  | |    (?)  | |toggle    ||
|  |    (?)   | |    (?)   | |time  (?) | |  graph   | |[on/off]  ||
|  |  graph   | |  graph   | |  graph   |  (avatar)| |          ||
|  |  (avatar)| |  (avatar)| |  (avatar)| |          | |          ||
|  +----------+ +----------+ +----------+ +----------+ +----------+|
+------------------------------------------------------------------+
```

Legend: `[sp]` = pipeline spinner, `[ok]` = green checkmark, `[!!]` = red failed pipeline.

### 2.2 MR row

```
+------------------------------------------------------------------+
| REC-1234 | #1240 | Title: Add feature X          [open 2 commits]|
|          |       | Description: Lorem ipsum dolor sit amet, con- |
|          |       | sectetur adipiscing elit... (collapsed 50px)  |
|          |       | Author: J. Doe   Age: 3d 04:12:33             |
|          |       | First approve: 1d 02:11   Pipeline: [spinner]  |
+------------------------------------------------------------------+
```

The Jira ticket is a separate column. The ticket links to the Jira issue. The title links to the MR in a new tab. The description collapses after 50 pixels. The commits button opens all commit diffs in new tabs.

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

The `(?)` icon shows a tooltip. The tooltip explains the metric. The tooltip explains what the score means. The tooltip states if lower or higher is better.

## 3. User Stories

1. As a team member, I want to see all open MRs, so that I know what waits for review.
2. As a team member, I want to see the last 5 merged MRs, so that I know what shipped recently.
3. As a team member, I want the newest MRs at the bottom, so that the list reads in creation order.
4. As a team member, I want merged MRs grayed out, so that I can tell them from open MRs.
5. As a team member, I want the MR title to open the MR in a new tab, so that I can review it.
6. As a team member, I want the Jira ticket extracted from the title, so that I can open the Jira issue.
7. As a team member, I want the MR description collapsed after 50 pixels, so that the list stays dense.
8. As a team member, I want a button to open all commit diffs, so that I can review each commit.
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
32. As a team member, I want a Slack message when a new MR appears, so that I know to review it.
33. As a team member, I want the Slack message to state how many approvals are needed, so that I know the target.
34. As a team member, I want the dashboard to run as one Docker image, so that deployment is simple.
35. As a team member, I want the image built in the GitLab pipeline, so that the build is automatic.
36. As a team member, I want to run the same image locally, so that I can test changes.
37. As a team member, I want the GitLab URL, group, and token in environment variables, so that I can point the tool at any instance.
38. As a team member, I want the required approval count in an environment variable, so that the team can change it.
39. As a team member, I want the Jira URL in an environment variable, so that the team can change it.
40. As a team member, I want the Slack token and channel in environment variables, so that the team can change them.

## 4. Implementation Decisions

### 4.1 Architecture

The app is a Symfony application in PHP 8.3. The app has one container. The container runs nginx, php-fpm, and cron. Supervisor starts all three processes.

The app has two entry points:

- A web app. It serves the dashboard page and the JSON API.
- A CLI command `app:sync`. It fetches data from GitLab and caches it.
- A CLI command `app:notify-slack`. It syncs and pings Slack for new MRs.

Cron runs `app:notify-slack` every 15 minutes.

### 4.2 Environment variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `GITLAB_URL` | GitLab base URL | none |
| `GITLAB_GROUP` | Group path, for example `company` | none |
| `GITLAB_TOKEN` | Personal access token, `read_api` scope | none |
| `SLACK_TOKEN` | Slack bot token | none |
| `SLACK_CHANNEL` | Slack channel ID | none |
| `REQUIRED_APPROVALS` | Approval target | `2` |
| `JIRA_URL` | Jira browse base URL | none |

### 4.3 Data model (SQLite)

The cache stores current state only. The app computes all history from current state. No historical snapshots are stored. This keeps the database small.

Tables:

- `users` — `id`, `name`, `username`, `avatar_url`.
- `projects` — `id`, `path_with_namespace`.
- `merge_requests` — `id`, `iid`, `project_id`, `title`, `description`, `author_id`, `state`, `draft`, `created_at`, `merged_at`, `updated_at`, `web_url`, `additions`, `deletions`.
- `approvals` — `id`, `mr_id`, `user_id`, `created_at`.
- `discussions` — `id`, `mr_id`, `user_id`, `created_at`.
- `commits` — `id`, `mr_id`, `sha`, `title`, `created_at`.
- `pipelines` — `id`, `mr_id`, `status`, `created_at`, `updated_at`.
- `sync_state` — `key`, `value`. Stores the last sync time and the last notify time.

### 4.4 GitLab API usage

The app uses these endpoints:

- `GET /groups/:id/projects?include_subgroups=true` — list projects.
- `GET /groups/:id/merge_requests?state=all&per_page=100` — list MRs.
- `GET /projects/:id/merge_requests/:iid/approvals` — approvals with timestamps.
- `GET /projects/:id/merge_requests/:iid/discussions` — discussions with timestamps.
- `GET /projects/:id/merge_requests/:iid/pipelines` — pipeline status.
- `GET /projects/:id/merge_requests/:iid/commits` — commits.

The group MR list returns MRs across the group. Approvals, discussions, pipelines, and commits need one call per MR. The sync throttles requests to respect the rate limit. The default self-hosted limit is 600 requests per minute.

### 4.5 Sync algorithm

The sync runs in two modes.

First run (full backfill):

1. Fetch all projects in the group.
2. Fetch all MRs in the group, all states, all pages.
3. For each MR, fetch approvals, discussions, pipelines, and commits.
4. Store everything in SQLite.
5. Set `last_sync` to now.

Later runs (incremental):

1. Fetch MRs with `updated_after` equal to `last_sync`.
2. For each changed MR, re-fetch approvals, discussions, pipelines, and commits.
3. Upsert into SQLite.
4. Set `last_sync` to now.

The sync sleeps between requests. This keeps the request rate under the limit.

### 4.6 Refresh on page load

The web app checks `last_sync` on every request to `/api/data`.

- If the cache is older than 5 seconds, the app runs the sync before serving.
- If the cache is newer than 5 seconds, the app serves the cached data.

This means concurrent users share one cache window. The first user after a gap waits for the sync. Later users get instant data.

### 4.7 Slack notification

The `app:notify-slack` command runs every 15 minutes.

1. Run the sync.
2. Find MRs with `created_at` after `last_notify`.
3. For each new MR, count approvals.
4. Compute `X = max(0, REQUIRED_APPROVALS - approvals)`.
5. Post the message to the Slack channel.
6. Set `last_notify` to now.

Message format:

```
New MR: [title] by [author] — [link] — needs X more approvals
```

The command uses the Slack Web API `chat.postMessage` with the bot token.

### 4.8 API contract

`GET /api/data` returns JSON.

```json
{
  "meta": {
    "required_approvals": 2,
    "stale_days": 60,
    "window_days": 60,
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
      "age_seconds": 123456,
      "time_to_first_approve_seconds": 86400,
      "first_approver": {"id": 2, "name": "John Roe"},
      "first_response_seconds": 3600,
      "pipeline": {"status": "running", "id": 99},
      "commits": [{"sha": "abc123", "title": "Add feature X"}],
      "web_url": "https://gitlab.company.io/company/app/-/merge_requests/1240",
      "additions": 120,
      "deletions": 30
    }
  ],
  "approvals": [
    {"mr_id": 1240, "user_id": 2, "created_at": "2026-08-09T09:00:00Z"}
  ],
  "discussions": [
    {"mr_id": 1240, "user_id": 3, "created_at": "2026-08-08T10:00:00Z"}
  ]
}
```

The frontend computes all per-person series from `mrs`, `approvals`, and `discussions`. The frontend re-buckets on zoom. The backend sends raw events, not pre-aggregated series.

### 4.9 Metrics

#### Metric 1 — Time to first approve (per MR)

`min(approval.created_at) - mr.created_at` over all approvals.

If the MR has no approval, the value is empty.

The first approver owns this MR's value in the per-person graph.

#### Metric 2 — Age of MR (per MR)

- Open MR: `now - created_at`.
- Merged MR: `merged_at - created_at`.

Format: `Xd HH:MM:SS`, for example `12d 23:59:59`.

#### Metric 3 — First try % (per person, author-centric)

For each MR authored by the person:

- Approved with at least `REQUIRED_APPROVALS` approvals → score 1.
- Not approved → score 0.
- Still open → excluded.

The person's score is the average of their MR scores. The graph plots the average per time bucket.

#### Metric 4 — Time to review (per person, reviewer-centric)

For each MR where the person approved or left a discussion:

`first activity time - mr.created_at`.

First activity is the earlier of the person's first approval and first discussion. The person's value is the average across their reviewed MRs. Format: days + hours + minutes.

#### Metric 5 — Coverage % (per person)

For a rolling 30-day window:

`MRs opened in the window that the person reviewed / MRs opened in the window`.

A review is an approval or a discussion. The graph plots the window value for each day.

#### Metric 6 — Stale MR count (per person, author-centric)

Count of open MRs opened more than 60 days ago, per author.

#### Metric 7 — Time to merge (per person, author-centric)

For each merged MR authored by the person: `merged_at - created_at`. Average.

#### Metric 8 — Median alongside mean

A toggle in the time-based cells. When on, the cell shows a dashed median line beside the mean line. The toggle value lives in a cookie.

#### Metric 9 — MR size (per person, author-centric)

`additions + deletions` per MR, averaged per person.

#### Metric 10 — Approvals given (per person)

Count of approvals given, over the displayed time window.

#### Metric 11 — First response time (per person, reviewer-centric)

For each MR where the person left a discussion: `first discussion time - mr.created_at`. Average.

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

The page loads `/api/data` once. The frontend computes all series from the raw events. The frontend re-buckets on zoom:

- Zoomed out: weekly buckets.
- Zoomed in: daily buckets.
- Fully zoomed: hourly buckets.

Each cell draws one line per person. The line ends at the person's avatar. uPlot custom point rendering draws the avatar image at the last point.

The MR list renders from the `mrs` array. The list shows MRs opened in the last 60 days. The list sorts newest at the bottom. The last 5 merged MRs pin to the top, grayed out. MRs opened more than 60 days ago collapse into one link:

```
5 stale Merge Requests belonging to Author A (3), Author B (2)
```

The link expands the stale MRs on click.

The commits button opens all commit diff links in new tabs. The link format:

```
{GITLAB_URL}/{project_path}/-/merge_requests/{iid}/diffs?commit_id={sha}
```

The pipeline indicator shows:

- Spinner while the pipeline runs.
- Red indicator when a job failed.
- Green checkmark when the pipeline finished.

The Jira ticket column shows the ticket extracted from the title. The regex is `^([A-Z][A-Z0-9]+-\d+)`. The ticket links to `{JIRA_URL}{ticket}`.

### 4.12 Docker

The Dockerfile is multi-stage.

Stage 1 — build:

- Base: `composer:2` image.
- Run `composer install --no-dev`.
- Copy the vendor directory.

Stage 2 — runtime:

- Base: `php:8.3-fpm-alpine`.
- Install `pdo_sqlite`, `curl`, `openssl`, nginx, cron, supervisor.
- Copy the app and the vendor directory.
- Copy the entrypoint script.

The entrypoint starts supervisor. Supervisor runs nginx, php-fpm, and cron.

Cron line:

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
  -e GITLAB_URL=https://gitlab.company.io \
  -e GITLAB_GROUP=company \
  -e GITLAB_TOKEN=<personal access token> \
  -e SLACK_TOKEN=<bot token> \
  -e SLACK_CHANNEL=<channel id> \
  -e JIRA_URL=https://company.atlassian.net/browse/ \
  <image>
```

The personal access token needs the `read_api` scope.

## 5. Testing Decisions

The tests cover external behavior, not implementation details.

Backend tests (PHPUnit):

- Metric computation tests. Each metric is a pure function. Tests feed MR, approval, and discussion fixtures and assert the result.
- Jira extraction test. Tests the regex on sample titles.
- Sync logic tests. A mock GitLab client returns fixtures. Tests assert the SQLite state after sync.
- API contract test. Tests that `/api/data` returns the documented shape.

Frontend tests:

- Bucket and aggregation functions. Tests feed raw events and assert the series.
- A smoke test. The page loads and renders without console errors.

Prior art: the metric functions are pure and deterministic. This makes the tests simple and fast.

## 6. Out of Scope

- Dashboard login or user accounts.
- Multiple GitLab groups.
- GitHub or Bitbucket support.
- Mobile layout.
- Win or lose highlighting.
- WebSocket real-time updates.
- Historical snapshots before first deploy. The app computes history from current state.
- Jira API integration. The app only links to Jira, it does not read Jira data.
- Slack notifications for approval milestones. The app notifies only on new MRs.

## 7. Further Notes

- The app stores timestamps in UTC. The frontend displays them in the browser local time.
- The 5-second cache window means concurrent users share one sync.
- The median toggle value lives in a cookie.
- The initial backfill may take several minutes. The sync throttles to respect the rate limit.
- The stale threshold is 60 days. The MR list window is 60 days. Both are constants in the code.
- The dashboard shows data only. It does not rank or highlight winners or losers.