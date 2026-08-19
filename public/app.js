import { getMode, setMode, MODE_MEAN, MODE_MEDIAN } from './toggle.js';
import { renderMarkdown } from './markdown.js';

const REFRESH_MS = 60_000;

const COLORS = [
    '#5b9bd5',
    '#3fb950',
    '#f0883e',
    '#d29922',
    '#bc8cff',
    '#f778ba',
    '#76e3ea',
    '#7ee787',
    '#ffa198',
    '#e3b341',
];

const METRICS = {
    coverage: {
        title: 'Coverage %',
        kind: 'values',
        unit: 'percent',
        lowerBetter: false,
        tooltip: [
            'The share of MRs opened in the rolling 30-day window that this person reviewed (approval or discussion).',
            'A high value means the person reviews a large share of the team\'s MRs.',
            'Higher is better.',
        ],
    },
    time_to_review: {
        title: 'Time to review',
        kind: 'meanmedian',
        unit: 'seconds',
        lowerBetter: true,
        tooltip: [
            'The time from MR creation until the reviewer\'s first activity (approval or discussion).',
            'A low value means the reviewer responds fast.',
            'Lower is better.',
        ],
    },
    stale_count: {
        title: 'Stale MRs',
        kind: 'values',
        unit: 'count',
        lowerBetter: true,
        tooltip: [
            'How many of the person\'s MRs are open and older than 60 days on each plotted day.',
            'A high count means the person leaves MRs open for a long time.',
            'Lower is better.',
        ],
    },
    approvals_given: {
        title: 'Approvals given',
        kind: 'values',
        unit: 'count',
        lowerBetter: false,
        tooltip: [
            'The number of approvals the person gave, bucketed by the approval date.',
            'Shows the review load each person carries.',
            'Neither higher nor lower is better by itself; it reflects the team\'s division of work.',
        ],
    },
    time_to_first_approve: {
        title: 'Time to first approve',
        kind: 'meanmedian',
        unit: 'seconds',
        lowerBetter: true,
        tooltip: [
            'The time from MR creation until the first approval, owned by the first approver.',
            'A low value means the MR gets its first approval quickly.',
            'Lower is better.',
        ],
    },
    time_to_merge: {
        title: 'Time to merge',
        kind: 'meanmedian',
        unit: 'seconds',
        lowerBetter: true,
        tooltip: [
            'The time from MR creation until it is merged, per author.',
            'A low value means the person ships quickly.',
            'Lower is better.',
        ],
    },
    first_response_time: {
        title: 'First response time',
        kind: 'meanmedian',
        unit: 'seconds',
        lowerBetter: true,
        tooltip: [
            'The time from MR creation until the person\'s first discussion thread on that MR.',
            'A low value means the person reacts quickly.',
            'Lower is better.',
        ],
    },
    mr_size: {
        title: 'MR size',
        kind: 'meanmedian',
        unit: 'lines',
        lowerBetter: false,
        tooltip: [
            'The sum of additions and deletions over the MR\'s latest commits, per author.',
            'Large MRs are harder to review; the value reflects review difficulty.',
            'Neither higher nor lower is strictly better; smaller MRs are usually easier to review.',
        ],
    },
    merged_count: {
        title: 'Merged MR count',
        kind: 'values',
        unit: 'count',
        lowerBetter: false,
        tooltip: [
            'How many of the person\'s MRs were merged in the rolling 30-day window.',
            'Shows how much the person ships.',
            'Higher is generally better for throughput.',
        ],
    },
};

let state = { bucket: 'day', userId: readUserIdFromUrl() };
let usersById = new Map();
let charts = [];
let chartData = null;
let colorByUserId = new Map();
let lastRenderedFingerprint = null;

// A single global color mapping, assigned once per data load from the same
// ordering as the user dropdown (mr_count DESC, name ASC). Keeping the
// mapping keyed by user id (not by per-chart series index) means the same
// person gets the same color in every chart and in the hover tooltip, even
// when they are absent from some charts.
function buildColorMap(users) {
    colorByUserId = new Map();
    (users || []).forEach((user, index) => {
        colorByUserId.set(String(user.id), COLORS[index % COLORS.length]);
    });
}

function colorForUser(id) {
    return colorByUserId.get(String(id)) || COLORS[0];
}

function readUserIdFromUrl() {
    const raw = new URLSearchParams(window.location.search).get('user');
    return raw && /^\d+$/.test(raw) ? raw : null;
}

function setUrlUser(userId) {
    const url = new URL(window.location.href);
    if (userId) {
        url.searchParams.set('user', userId);
    } else {
        url.searchParams.delete('user');
    }
    history.replaceState(null, '', url.toString());
}

function el(tag, className) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }

    return node;
}

function bucketToEpoch(key) {
    const [datePart, timePart] = key.split(' ');
    const [y, m, d] = datePart.split('-').map(Number);
    const hour = timePart ? Number(timePart.split(':')[0]) : 0;

    return Date.UTC(y, m - 1, d, hour);
}

function formatAge(seconds) {
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    return `${d}d ${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function formatShortDuration(seconds) {
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);

    return `${d}d ${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function formatDurationAxis(seconds) {
    if (seconds < 60) {
        return `${Math.round(seconds)}s`;
    }
    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }
    if (seconds < 86400) {
        return `${Math.round(seconds / 3600)}h`;
    }

    return `${Math.round(seconds / 86400)}d`;
}

function formatRelative(secondsAgo) {
    if (secondsAgo < 60) {
        return `${secondsAgo}s ago`;
    }
    if (secondsAgo < 3600) {
        return `${Math.floor(secondsAgo / 60)}m ago`;
    }
    if (secondsAgo < 86400) {
        return `${Math.floor(secondsAgo / 3600)}h ago`;
    }

    return `${Math.floor(secondsAgo / 86400)}d ago`;
}

function formatUntil(seconds) {
    if (seconds <= 0) {
        return 'now';
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }
    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }
    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h`;
    }

    return `${Math.floor(seconds / 86400)}d`;
}

function yAxisValues(unit) {
    return (_u, splits) => splits.map((value) => {
        if (unit === 'seconds') {
            return formatDurationAxis(value);
        }
        if (unit === 'percent') {
            return `${Math.round(value)}%`;
        }

        return String(Math.round(value));
    });
}

// uPlot's built-in time formatter assumes epoch seconds; our x data is epoch
// milliseconds, so the built-in renders dates ~58,000 years in the future
// (e.g. "58486" as a year-only tick). The tick positions themselves land on
// day/hour boundaries in ms, so the labels are formatted here from those ms
// values instead. Day/week ticks show the date; hour ticks add the hour.
function formatXAxisValues(_u, splits, _axisIdx, _foundSpace, foundIncr) {
    const step = foundIncr || (splits.length > 1 ? splits[1] - splits[0] : 0);
    const showTime = step > 0 && step < 86_400_000;
    return splits.map((value) => {
        const d = new Date(value);
        const y = d.getUTCFullYear();
        const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
        const day = String(d.getUTCDate()).padStart(2, '0');
        let label = `${y}-${mo}-${day}`;
        if (showTime) {
            label += ` ${String(d.getUTCHours()).padStart(2, '0')}:00`;
        }

        return label;
    });
}

function renderAvatar(node, name, avatarUrl) {
    if (avatarUrl) {
        const img = el('img', 'avatar');
        img.alt = name;
        img.src = avatarUrl;
        node.appendChild(img);
    } else {
        const fallback = el('span', 'avatar-fallback');
        fallback.textContent = (name || '?').charAt(0).toUpperCase();
        node.appendChild(fallback);
    }
}

// An avatar element that can live anywhere (on a line point, in a tooltip row).
// Falls back to an initial when the user has no avatar_url, so an avatarless
// person still gets a visible marker instead of a broken <img src="">.
function avatarImage(user, className) {
    if (user && user.avatar_url) {
        const img = el('img', className);
        img.alt = user.name || '';
        img.src = user.avatar_url;

        return img;
    }
    const fallback = el('span', `${className} ${className}-fallback`);
    fallback.textContent = ((user && user.name) || '?').charAt(0).toUpperCase();

    return fallback;
}

function formatMetricValue(unit, value) {
    if (value == null) {
        return '—';
    }
    if (unit === 'seconds') {
        return formatShortDuration(value);
    }
    if (unit === 'percent') {
        return `${Number(value.toFixed(1))}%`;
    }

    return String(Math.round(value));
}

// Bucket keys are already human-readable ("2026-08-19" or "2026-08-19 14:00").
function formatBucketLabel(key) {
    return key.replace(' ', ' · ');
}

// A single shared tooltip for the description column. It lives on the body and
// is positioned fixed (clamped to the viewport) so it escapes the list's
// overflow-hidden cells and clipped scroll area. Content is the MR description
// rendered as markdown; the renderer HTML-escapes everything, so innerHTML is
// safe here.
let descTip = null;

function getDescTip() {
    if (!descTip) {
        descTip = el('div', 'desc-tip');
        descTip.hidden = true;
        document.body.appendChild(descTip);
    }

    return descTip;
}

function showDescTip(anchor, description) {
    const tip = getDescTip();
    tip.textContent = '';
    tip.innerHTML = renderMarkdown(description);
    tip.hidden = false;

    const rect = anchor.getBoundingClientRect();
    const pad = 8;
    let left = rect.left;
    if (left + tip.offsetWidth > window.innerWidth - pad) {
        left = Math.max(pad, window.innerWidth - tip.offsetWidth - pad);
    }
    let top = rect.bottom + 6;
    if (top + tip.offsetHeight > window.innerHeight - pad) {
        top = Math.max(pad, rect.top - tip.offsetHeight - 6);
    }
    tip.style.left = `${left}px`;
    tip.style.top = `${top}px`;
}

function hideDescTip() {
    if (descTip) {
        descTip.hidden = true;
    }
}

function renderPipeline(p) {
    const cell = el('span', 'pipe-cell');
    if (!p || p.indicator === 'none') {
        return cell;
    }

    const mark = el('span', `pipe ${p.indicator}`);
    if (p.indicator === 'check') {
        mark.textContent = '✓';
    } else if (p.indicator === 'fail') {
        mark.textContent = '✗';
    } else if (p.indicator === 'neutral') {
        mark.textContent = '–';
    } else if (p.indicator === 'spinner') {
        mark.classList.add('spinner');
        if (p.tint === 'fail') {
            mark.classList.add('tint-fail');
        } else if (p.tint === 'warn') {
            mark.classList.add('tint-warn');
        }
    }
    cell.appendChild(mark);

    return cell;
}

function titleWithoutTicket(mr) {
    const title = mr.title || '';
    const ticket = mr.jira_ticket;
    if (!ticket || !title.startsWith(ticket)) {
        return title;
    }
    const rest = title.slice(ticket.length).replace(/^\s*[-–:]\s*/, '');

    return rest || title;
}

function renderMrRow(mr, dimmed) {
    const row = el('div', 'mr-row');
    if (dimmed) {
        row.classList.add('dim');
    }

    const project = el('span', 'col-project');
    if (mr.project) {
        // Hovering the avatar shows the project name (falls back to the path
        // when an old cache has no display name yet). Only set the tooltip text
        // when there is something to show, so no empty bubble appears.
        const projectName = mr.project.name || mr.project.path_with_namespace || '';
        if (projectName) {
            project.dataset.tip = projectName;
        }
        project.appendChild(avatarImage(mr.project, 'project-avatar'));
    }
    row.appendChild(project);

    const jira = el('span', 'col-jira');
    if (mr.jira_ticket && state.jiraUrl) {
        const link = el('a');
        link.href = state.jiraUrl + mr.jira_ticket;
        link.target = '_blank';
        link.textContent = mr.jira_ticket;
        jira.appendChild(link);
    }
    row.appendChild(jira);

    const title = el('span', 'col-title');
    const titleLink = el('a');
    titleLink.href = mr.web_url;
    titleLink.target = '_blank';
    titleLink.textContent = titleWithoutTicket(mr);
    title.appendChild(titleLink);
    row.appendChild(title);

    const desc = el('div', 'col-desc desc');
    desc.textContent = mr.description || '';
    // The description collapses to one ellipsized line; hovering a truncated
    // description shows the full text as markdown in the shared tooltip.
    desc.addEventListener('mouseenter', () => {
        if (desc.scrollWidth > desc.clientWidth + 1) {
            showDescTip(desc, mr.description);
        }
    });
    desc.addEventListener('mouseleave', hideDescTip);
    // Touch devices have no hover; a tap toggles the same tooltip, and a tap
    // outside (wired globally below) dismisses it.
    desc.addEventListener('click', (e) => {
        if (desc.scrollWidth <= desc.clientWidth + 1) {
            return;
        }
        e.stopPropagation();
        const isOpen = descTip && !descTip.hidden && descTip.ownerDesc === desc;
        if (isOpen) {
            hideDescTip();
        } else {
            showDescTip(desc, mr.description);
            getDescTip().ownerDesc = desc;
        }
    });
    row.appendChild(desc);

    const author = el('span', 'col-author');
    renderAvatar(author, mr.author.name, mr.author.avatar_url);
    const authorName = el('span');
    authorName.textContent = mr.author.name;
    author.appendChild(authorName);
    row.appendChild(author);

    const stateCell = el('span', 'col-state');
    const badges = [
        mr.draft && ['draft', 'status-draft'],
        mr.needs_rebase && ['needs rebase', 'status-needs-rebase'],
        mr.unresolved_discussions > 0 && ['unresolved discussion 📝', 'status-unresolved'],
        mr.stale && ['stale 💩', 'status-stale'],
        mr.approved && !mr.ready && ['approved', 'status-approved'],
        mr.ready && ['ready ✅', 'status-ready'],
    ].filter(Boolean);
    for (const [label, cls] of badges) {
        const badge = el('span', `badge ${cls}`);
        badge.textContent = label;
        stateCell.appendChild(badge);
    }
    row.appendChild(stateCell);

    const age = el('span', 'col-age');
    age.textContent = formatAge(mr.age_seconds);
    row.appendChild(age);

    const first = el('span', 'col-first');
    if (mr.approvers && mr.approvers.length > 0) {
        const firstApprover = mr.approvers[0];
        renderAvatar(first, firstApprover.name, firstApprover.avatar_url);
    }
    first.appendChild(document.createTextNode(
        mr.time_to_first_approval_seconds == null
            ? ''
            : formatShortDuration(mr.time_to_first_approval_seconds)
    ));
    row.appendChild(first);

    const approvers = el('span', 'col-approvers');
    if (mr.approvers) {
        for (const approver of mr.approvers) {
            renderAvatar(approvers, approver.name, approver.avatar_url);
        }
    }
    row.appendChild(approvers);

    const pipe = el('span', 'col-pipe');
    pipe.appendChild(renderPipeline(mr.pipeline));
    row.appendChild(pipe);

    const commits = el('span', 'col-commits');
    const commitButton = el('button', 'commits-btn');
    commitButton.textContent = `[${mr.commit_count} commit${mr.commit_count === 1 ? '' : 's'}]`;
    commitButton.addEventListener('click', () => {
        for (const url of mr.commit_diff_urls) {
            window.open(url, '_blank');
        }
    });
    commits.appendChild(commitButton);
    row.appendChild(commits);

    return row;
}

function renderStaleLink(staleMrs) {
    const byAuthor = new Map();
    for (const mr of staleMrs) {
        const name = mr.author.name;
        byAuthor.set(name, (byAuthor.get(name) || 0) + 1);
    }
    const parts = [];
    for (const [name, count] of byAuthor) {
        parts.push(`${name} (${count})`);
    }

    const link = el('button', 'stale-link');
    link.textContent = `${staleMrs.length} stale Merge Requests belonging to ${parts.join(', ')}`;

    return link;
}

function renderMrList(data) {
    const container = document.getElementById('mr-list');
    container.textContent = '';

    if (state.userId) {
        const mine = [];
        const toReview = [];
        for (const mr of data.mrs) {
            if (String(mr.author.id) === state.userId) {
                mine.push(mr);
            } else if (!mr.stale) {
                // Stale MRs are not waiting on anyone's review; keep them out
                // of the "awaiting my review" list.
                toReview.push(mr);
            }
        }
        mine.sort((a, b) => (a.created_at < b.created_at ? -1 : 1));
        toReview.sort((a, b) => (a.created_at < b.created_at ? -1 : 1));
        container.appendChild(renderMrSection('Authored by me', mine));
        container.appendChild(renderMrSection('Awaiting my review', toReview));
        return;
    }

    const stale = [];
    const body = [];
    for (const mr of data.mrs) {
        if (mr.stale) {
            stale.push(mr);
        } else {
            body.push(mr);
        }
    }
    body.sort((a, b) => (a.created_at < b.created_at ? -1 : 1));

    if (stale.length > 0) {
        const link = renderStaleLink(stale);
        container.appendChild(link);
        const wrapper = el('div');
        wrapper.hidden = true;
        for (const mr of stale) {
            wrapper.appendChild(renderMrRow(mr, true));
        }
        link.addEventListener('click', () => {
            wrapper.hidden = !wrapper.hidden;
        });
        container.appendChild(wrapper);
    }

    for (const mr of body) {
        container.appendChild(renderMrRow(mr, false));
    }
}

function renderMrSection(label, mrs) {
    const section = el('div', 'mr-section');
    const heading = el('div', 'mr-section-heading');
    heading.textContent = `${label} (${mrs.length})`;
    section.appendChild(heading);
    for (const mr of mrs) {
        section.appendChild(renderMrRow(mr, mr.stale === true));
    }
    return section;
}

function renderHeader(data) {
    const node = document.getElementById('sync-status');
    const meta = data.meta;
    if (!meta.last_sync_at) {
        node.textContent = 'Last sync: never';
        return;
    }

    const now = Date.now();
    const lastSync = Date.parse(meta.last_sync_at);
    const nextSync = Date.parse(meta.next_sync_at);
    const lastAgo = Math.max(0, Math.floor((now - lastSync) / 1000));
    const until = Math.max(0, Math.floor((nextSync - now) / 1000));

    let text = `Last sync: ${formatRelative(lastAgo)} · next sync: ${formatUntil(until)}`;

    if (meta.last_rank_at) {
        const lastRank = Date.parse(meta.last_rank_at);
        const rankAgo = Math.max(0, Math.floor((now - lastRank) / 1000));
        text += ` · ranks: ${formatRelative(rankAgo)}`;
    }

    node.textContent = text;
}

function median(nums) {
    const sorted = [...nums].sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 1 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

// The latest team-level value for a metric: for the newest bucket that has
// any data, the mean (mean-mode) or median (median-mode) across persons'
// values at that bucket.
function latestTeamValue(metric, mode) {
    const persons = Object.entries((metric && metric.persons) || {});
    if (persons.length === 0) {
        return null;
    }
    const buckets = persons[0][1].buckets;
    for (let i = buckets.length - 1; i >= 0; i--) {
        const values = persons
            .map(([, series]) => pickSeries(series, mode)[i])
            .filter((v) => v != null);
        if (values.length > 0) {
            return mode === 'median' ? median(values) : values.reduce((a, b) => a + b, 0) / values.length;
        }
    }

    return null;
}

function buildCellHeader(meta, metric) {
    const header = el('div', 'cell-header');
    const title = el('span', 'cell-title');
    title.textContent = meta.title;
    header.appendChild(title);

    const teamValue = latestTeamValue(metric, getMode());
    if (teamValue != null) {
        const value = el('span', 'cell-value');
        value.textContent = `· ${formatMetricValue(meta.unit, teamValue)}`;
        header.appendChild(value);
    }

    const tip = el('span', 'tip-icon');
    tip.textContent = '(?)';
    tip.dataset.tip = meta.tooltip.join('\n');
    // Desktop shows the tooltip on hover (CSS only); touch devices have no
    // hover, so a tap toggles an .open class that CSS renders the same way.
    tip.addEventListener('click', (e) => {
        e.stopPropagation();
        const wasOpen = tip.classList.contains('open');
        document.querySelectorAll('.tip-icon.open').forEach((t) => t.classList.remove('open'));
        tip.classList.toggle('open', !wasOpen);
    });
    header.appendChild(tip);

    if (meta.kind === 'meanmedian') {
        header.appendChild(buildModeSegControl());
    }

    return header;
}

function buildModeSegControl() {
    const control = el('div', 'seg-control');
    const mode = getMode();

    for (const [value, label] of [[MODE_MEAN, 'mean'], [MODE_MEDIAN, 'median']]) {
        const option = el('button', 'seg-option');
        option.type = 'button';
        option.textContent = label;
        if (value === mode) {
            option.classList.add('active');
        }
        option.addEventListener('click', () => {
            if (getMode() !== value) {
                setMode(value);
                rerenderCharts();
            }
        });
        control.appendChild(option);
    }

    return control;
}

function pickSeries(series, mode) {
    if (mode === 'median' && Array.isArray(series.median)) {
        return series.median;
    }
    if (Array.isArray(series.mean)) {
        return series.mean;
    }

    return series.values;
}

function updateAvatars(u, wrap, persons, unit) {
    let overlay = wrap.querySelector('.avatar-overlay');
    if (!overlay) {
        overlay = el('div', 'avatar-overlay');
        wrap.appendChild(overlay);
    }
    overlay.textContent = '';

    persons.forEach(([id, series]) => {
        const values = pickSeries(series, getMode());
        let lastIndex = -1;
        for (let i = values.length - 1; i >= 0; i--) {
            if (values[i] != null) {
                lastIndex = i;
                break;
            }
        }
        if (lastIndex < 0) {
            return;
        }

        const user = usersById.get(id) || { name: id };
        const x = bucketToEpoch(series.buckets[lastIndex]);
        const y = values[lastIndex];
        // valToPos returns plot-area-relative coords (0 at the grid's left/top
        // edge, excluding the axes); the overlay spans the whole wrap, so add
        // the plot area's offset to land on the actual line point.
        const px = u.bbox.left + u.valToPos(x, 'x');
        const py = u.bbox.top + u.valToPos(y, 'y');

        const avatar = avatarImage(user, 'avatar-point');
        avatar.style.left = `${px}px`;
        avatar.style.top = `${py}px`;
        overlay.appendChild(avatar);
    });
}

// Hover toolbar: for the bucket under the cursor, list every person's avatar,
// name, and value at that point in time. Positioned near the cursor and
// clamped inside the chart so it never spills out of the cell.
function updateChartTooltip(u, wrap, tip, persons, unit) {
    const idx = u.cursor && u.cursor.idx;

    if (idx == null || u.cursor.left < 0) {
        tip.hidden = true;

        return;
    }
    tip.hidden = false;
    tip.textContent = '';

    const header = el('div', 'tip-head');
    header.textContent = formatBucketLabel(persons[0][1].buckets[idx]);
    tip.appendChild(header);

    // Order rows by all-time MR count (matching the user dropdown: mr_count DESC,
    // then name ASC), while keeping each row's border colour tied to its line via
    // the original series index.
    const ordered = persons
        .map(([id, series]) => {
            const user = usersById.get(id) || { name: id, mr_count: 0 };
            return { id, series, user };
        })
        .sort(
            (a, b) =>
                (b.user.mr_count ?? 0) - (a.user.mr_count ?? 0) ||
                String(a.user.name).localeCompare(String(b.user.name)),
        );

    ordered.forEach(({ id, series, user }) => {
        const values = pickSeries(series, getMode());

        const row = el('div', 'tip-row');
        row.style.borderLeftColor = colorForUser(id);
        row.appendChild(avatarImage(user, 'tip-avatar'));
        const name = el('span', 'tip-name');
        name.textContent = user.name || id;
        row.appendChild(name);
        const val = el('span', 'tip-val');
        val.textContent = formatMetricValue(unit, values[idx]);
        row.appendChild(val);
        tip.appendChild(row);
    });

    const pad = 12;
    const ww = wrap.clientWidth;
    const wh = wrap.clientHeight;
    let left = u.cursor.left + pad;
    let top = u.cursor.top + pad;
    const tw = tip.offsetWidth;
    const th = tip.offsetHeight;
    if (left + tw > ww) {
        left = u.cursor.left - pad - tw;
    }
    if (top + th > wh) {
        top = wh - th - 2;
    }
    tip.style.left = `${Math.max(2, left)}px`;
    tip.style.top = `${Math.max(2, top)}px`;
}

function createChart(wrap, key, meta, data) {
    const metric = data.metrics[key];
    if (!metric) {
        return null;
    }
    const persons = Object.entries(metric.persons || {});
    if (persons.length === 0) {
        const empty = el('div', 'no-data');
        empty.textContent = 'No data yet';
        wrap.appendChild(empty);

        return null;
    }

    const bucketKeys = persons[0][1].buckets;
    const xData = bucketKeys.map(bucketToEpoch);
    const mode = getMode();
    const yData = persons.map(([, series]) => pickSeries(series, mode));

    const tip = el('div', 'chart-tip');
    tip.hidden = true;

    const opts = {
        width: wrap.clientWidth || 320,
        height: 170,
        legend: { show: false },
        cursor: { drag: 'x', points: { show: false } },
        select: { show: true },
        // x is a time scale over UTC epoch milliseconds; the axis labels are
        // formatted by formatXAxisValues (the built-in formatter assumes seconds).
        scales: { x: { time: true } },
        axes: [
            { stroke: '#8a94ab', grid: { stroke: '#2a3347' }, size: 48, values: formatXAxisValues },
            { stroke: '#8a94ab', grid: { stroke: '#2a3347' }, size: 34, values: yAxisValues(meta.unit) },
        ],
        series: [
            { label: 'date', stroke: '#8a94ab' },
            ...persons.map(([id]) => ({
                label: (usersById.get(id) || {}).name || id,
                scale: 'y',
                stroke: colorForUser(id),
                width: 2,
                spanGaps: false,
                points: { show: false },
            })),
        ],
        hooks: {
            draw: [(u) => updateAvatars(u, wrap, persons, meta.unit)],
            setSelect: [(u) => handleZoom(u, wrap)],
            setCursor: [(u) => updateChartTooltip(u, wrap, tip, persons, meta.unit)],
        },
    };

    const u = new uPlot(opts, [xData, ...yData], wrap);
    wrap.appendChild(tip);

    const resizeObserver = new ResizeObserver(() => {
        const width = wrap.clientWidth;
        const height = wrap.clientHeight;
        if (width > 0 && height > 0) {
            u.setSize({ width, height });
        }
    });
    resizeObserver.observe(wrap);

    wrap.addEventListener('dblclick', () => {
        resetZoom();
    });

    // uPlot only tracks the mouse; bridge touch tap/drag into the same
    // cursor so updateChartTooltip shows the toolbar on touch devices too.
    const moveTouchCursor = (e) => {
        const touch = e.touches && e.touches[0];
        if (!touch) {
            return;
        }
        const rect = wrap.getBoundingClientRect();
        u.setCursor({ left: touch.clientX - rect.left, top: touch.clientY - rect.top });
    };
    wrap.addEventListener('touchstart', moveTouchCursor, { passive: true });
    wrap.addEventListener('touchmove', moveTouchCursor, { passive: true });

    return u;
}

// All charts share the same x time axis: a drag-zoom on one chart applies
// the same x-range to every other chart, and the bucket-granularity switch
// (if any) happens once for the whole panel, not per chart.
function handleZoom(u) {
    if (!u.select || u.select.width === 0) {
        return;
    }
    const left = u.select.left;
    const right = left + u.select.width;
    const t1 = u.posToVal(left, 'x');
    const t2 = u.posToVal(right, 'x');
    const spanDays = (t2 - t1) / 86_400_000;

    for (const chart of charts) {
        if (chart) {
            chart.setScale('x', { min: t1, max: t2 });
        }
    }

    let bucket;
    if (spanDays >= 45) {
        bucket = 'week';
    } else if (spanDays >= 4) {
        bucket = 'day';
    } else {
        bucket = 'hour';
    }

    if (bucket !== state.bucket) {
        loadData(bucket);
    }
}

// Double-click on any chart resets the zoom on every chart; if the zoom drag
// had switched the bucket, this also switches back to the default day bucket.
function resetZoom() {
    for (const chart of charts) {
        if (!chart || !chart.data || !chart.data[0] || !chart.data[0].length) {
            continue;
        }
        const xd = chart.data[0];
        chart.setScale('x', { min: xd[0], max: xd[xd.length - 1] });
    }

    if (state.bucket !== 'day') {
        loadData('day');
    }
}

function renderStats(data) {
    for (const chart of charts) {
        if (chart) {
            chart.destroy();
        }
    }
    charts = [];

    const panel = document.getElementById('stats-panel');
    panel.textContent = '';

    for (const [key, meta] of Object.entries(METRICS)) {
        const cell = el('section', 'cell');
        cell.appendChild(buildCellHeader(meta, data.metrics[key]));
        const wrap = el('div', 'chart-wrap');
        cell.appendChild(wrap);
        panel.appendChild(cell);

        charts.push(createChart(wrap, key, meta, data));
    }
}

function renderAll(data) {
    renderHeader(data);
    renderUserFilter(data);
    renderMrList(data);
    renderStats(data);
}

function rerenderCharts() {
    renderStats(chartData);
}

function showError(message) {
    const banner = el('div', 'error-banner');
    banner.textContent = message;
    document.querySelector('.layout').prepend(banner);
}

async function loadData(bucket) {
    state.bucket = bucket;
    try {
        const params = new URLSearchParams({ bucket });
        if (state.userId) {
            params.set('user', state.userId);
        }
        const response = await fetch(`/api/data?${params.toString()}`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const data = await response.json();
        state.jiraUrl = data.meta.jira_url || '';

        // The 60s poll re-fetches on the same bucket/user filter; when nothing
        // changed server-side, skip the rebuild so open tooltips/zoom aren't
        // yanked out from under the cursor. A changed bucket or user filter
        // always has a different fingerprint, so it always rerenders.
        const fingerprint = `${data.meta.last_sync_at}|${bucket}|${state.userId || ''}`;
        if (fingerprint === lastRenderedFingerprint) {
            chartData = data;
            return;
        }
        lastRenderedFingerprint = fingerprint;

        usersById = new Map((data.users || []).map((user) => [String(user.id), user]));
        buildColorMap(data.users);
        chartData = data;
        renderAll(data);
    } catch (error) {
        showError(`Failed to load data: ${error.message}`);
    }
}

function renderUserFilter(data) {
    const select = document.getElementById('user-filter-select');
    const clear = document.getElementById('user-clear');
    if (!select) {
        return;
    }
    const current = state.userId || '';
    select.textContent = '';
    const everyone = el('option');
    everyone.value = '';
    everyone.textContent = 'Everyone';
    select.appendChild(everyone);
    for (const user of data.users || []) {
        const opt = el('option');
        opt.value = String(user.id);
        opt.textContent = `${user.name} (${user.mr_count ?? 0}) @${user.username}`;
        select.appendChild(opt);
    }
    select.value = current;
    if (clear) {
        clear.hidden = !state.userId;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('user-filter-select');
    const clear = document.getElementById('user-clear');
    if (select) {
        select.addEventListener('change', () => {
            state.userId = select.value || null;
            setUrlUser(state.userId);
            loadData(state.bucket);
        });
    }
    if (clear) {
        clear.addEventListener('click', () => {
            state.userId = null;
            if (select) {
                select.value = '';
            }
            clear.hidden = true;
            setUrlUser(null);
            loadData(state.bucket);
        });
    }

    loadData('day');
    setInterval(() => loadData(state.bucket), REFRESH_MS);

    // Tapping outside an open touch-driven tooltip (chart cursor, tip-icon,
    // or description) dismisses it.
    document.addEventListener(
        'touchstart',
        (e) => {
            if (!e.target.closest('.chart-wrap')) {
                for (const chart of charts) {
                    if (chart) {
                        chart.setCursor({ left: -10, top: -10 });
                    }
                }
            }
            if (!e.target.closest('.tip-icon')) {
                document.querySelectorAll('.tip-icon.open').forEach((t) => t.classList.remove('open'));
            }
            if (!e.target.closest('.desc')) {
                hideDescTip();
            }
        },
        { passive: true },
    );

    // Exposed for the Playwright test that verifies unchanged polls skip the
    // rebuild; not used by any runtime code path.
    window.__crDashboardReload = () => loadData(state.bucket);

    // Exposed for the Playwright test that verifies zoom stays in sync across
    // charts; not used by any runtime code path.
    window.__crChartXScales = () => charts.map((c) => (c ? { min: c.scales.x.min, max: c.scales.x.max } : null));
});
