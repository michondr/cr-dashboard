import { getMode, toggleMode } from './toggle.js';

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

let state = { bucket: 'day' };
let usersById = new Map();
let charts = [];
let chartData = null;

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

function renderMrRow(mr, dimmed) {
    const row = el('div', 'mr-row');
    if (dimmed) {
        row.classList.add('dim');
    }

    const jira = el('span', 'col-jira');
    if (mr.jira_ticket && state.jiraUrl) {
        const link = el('a');
        link.href = state.jiraUrl + mr.jira_ticket;
        link.target = '_blank';
        link.textContent = mr.jira_ticket;
        jira.appendChild(link);
    }
    row.appendChild(jira);

    const mrCell = el('span', 'col-mr');
    const mrLink = el('a');
    mrLink.href = mr.web_url;
    mrLink.target = '_blank';
    mrLink.textContent = `#${mr.iid}`;
    mrCell.appendChild(mrLink);
    row.appendChild(mrCell);

    const title = el('span', 'col-title');
    const titleLink = el('a');
    titleLink.href = mr.web_url;
    titleLink.target = '_blank';
    titleLink.textContent = mr.title;
    title.appendChild(titleLink);
    row.appendChild(title);

    const desc = el('div', 'col-desc desc');
    desc.textContent = mr.description || '';
    desc.title = 'Click to expand or collapse';
    desc.addEventListener('click', () => desc.classList.toggle('expanded'));
    row.appendChild(desc);

    const author = el('span', 'col-author');
    renderAvatar(author, mr.author.name, mr.author.avatar_url);
    const authorName = el('span');
    authorName.textContent = mr.author.name;
    author.appendChild(authorName);
    row.appendChild(author);

    const stateCell = el('span', 'col-state');
    const stateText = `${mr.state} ${mr.commit_count} commit${mr.commit_count === 1 ? '' : 's'}`;
    stateCell.textContent = stateText;
    if (mr.draft) {
        const badge = el('span', 'badge');
        badge.textContent = 'draft';
        stateCell.appendChild(badge);
    }
    if (mr.state === 'closed') {
        const badge = el('span', 'badge');
        badge.textContent = 'closed';
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

    node.textContent = `Last sync: ${formatRelative(lastAgo)} · next sync: ${formatUntil(until)}`;
}

function buildCellHeader(meta) {
    const header = el('div', 'cell-header');
    const title = el('span', 'cell-title');
    title.textContent = meta.title;
    header.appendChild(title);

    const tip = el('span', 'tip-icon');
    tip.textContent = '(?)';
    tip.dataset.tip = meta.tooltip.join('\n');
    header.appendChild(tip);

    if (meta.kind === 'meanmedian') {
        const toggle = el('button', 'mm-toggle');
        toggle.textContent = '⇄';
        toggle.title = 'Switch between mean and median';
        toggle.addEventListener('click', () => {
            toggleMode();
            rerenderCharts();
        });
        header.appendChild(toggle);
    }

    return header;
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

        const user = usersById.get(id) || {};
        const x = bucketToEpoch(series.buckets[lastIndex]);
        const y = values[lastIndex];
        const px = u.valToPos(x, 'x');
        const py = u.valToPos(y, 'y');

        const avatar = el('img', 'avatar-point');
        avatar.alt = user.name || id;
        avatar.src = user.avatar_url || '';
        avatar.style.left = `${px}px`;
        avatar.style.top = `${py}px`;
        overlay.appendChild(avatar);
    });
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

    const opts = {
        width: wrap.clientWidth || 320,
        height: 170,
        legend: { show: false },
        cursor: { drag: 'x' },
        select: { show: true },
        scales: { x: { time: true } },
        axes: [
            { stroke: '#8a94ab', grid: { stroke: '#2a3347' }, size: 48 },
            { stroke: '#8a94ab', grid: { stroke: '#2a3347' }, size: 34, values: yAxisValues(meta.unit) },
        ],
        series: [
            { label: 'date', stroke: '#8a94ab' },
            ...persons.map(([id], index) => ({
                label: (usersById.get(id) || {}).name || id,
                scale: 'y',
                stroke: COLORS[index % COLORS.length],
                width: 2,
                spanGaps: false,
                points: { show: false },
            })),
        ],
        hooks: {
            draw: [(u) => updateAvatars(u, wrap, persons, meta.unit)],
            setSelect: [(u) => handleZoom(u, wrap)],
        },
    };

    const u = new uPlot(opts, [xData, ...yData], wrap);

    const resizeObserver = new ResizeObserver(() => {
        const width = wrap.clientWidth;
        const height = wrap.clientHeight;
        if (width > 0 && height > 0) {
            u.setSize({ width, height });
        }
    });
    resizeObserver.observe(wrap);

    wrap.addEventListener('dblclick', () => {
        u.setScale('x', { min: xData[0], max: xData[xData.length - 1] });
        u.redraw();
        if (state.bucket !== 'day') {
            loadData('day');
        }
    });

    return u;
}

function handleZoom(u) {
    if (!u.select || u.select.width === 0) {
        return;
    }
    const left = u.select.left;
    const right = left + u.select.width;
    const t1 = u.posToVal(left, 'x');
    const t2 = u.posToVal(right, 'x');
    const spanDays = (t2 - t1) / 86_400_000;

    u.setScale('x', { min: t1, max: t2 });

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
        cell.appendChild(buildCellHeader(meta));
        const wrap = el('div', 'chart-wrap');
        cell.appendChild(wrap);
        panel.appendChild(cell);

        charts.push(createChart(wrap, key, meta, data));
    }
}

function renderAll(data) {
    renderHeader(data);
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
        const response = await fetch(`/api/data?bucket=${encodeURIComponent(bucket)}`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const data = await response.json();
        state.jiraUrl = data.meta.jira_url || '';
        usersById = new Map((data.users || []).map((user) => [String(user.id), user]));
        chartData = data;
        renderAll(data);
    } catch (error) {
        showError(`Failed to load data: ${error.message}`);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadData('day');
    setInterval(() => loadData(state.bucket), REFRESH_MS);
});
