import { expect, test } from '@playwright/test';

test('dashboard renders MRs, stale link and metric cells without console errors', async ({ page }) => {
    const errors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    page.on('pageerror', (error) => {
        errors.push(String(error));
    });

    await page.goto('/');

    // The MR list renders rows (open MRs only; merged/closed are hidden).
    // Wait on a body row (a direct child of the list); stale rows live in a
    // hidden wrapper and would not satisfy a visibility wait.
    await page.waitForSelector('#mr-list > .mr-row');
    expect(await page.locator('.mr-row').count()).toBeGreaterThanOrEqual(6);

    // A stale MR exists and the stale link renders.
    await page.waitForSelector('.stale-link');
    expect(await page.locator('.stale-link').textContent()).toContain('stale Merge Requests');

    // The Approvers column renders approver avatars.
    expect(await page.locator('.mr-header .col-approvers').count()).toBe(1);
    expect(
        await page.locator('.col-approvers .avatar, .col-approvers .avatar-fallback').count()
    ).toBeGreaterThanOrEqual(1);

    // The leftmost project column shows the project's avatar (or its initial
    // when the project has none) and hovers to a project-name tooltip.
    expect(await page.locator('.mr-header .col-project').count()).toBe(1);
    const visibleProjects = page.locator('.mr-row .col-project:visible');
    expect(await visibleProjects.count()).toBeGreaterThanOrEqual(1);
    expect(
        await page.locator('.mr-row .col-project .project-avatar, .mr-row .col-project .project-avatar-fallback').count()
    ).toBeGreaterThanOrEqual(1);
    expect(
        await page.locator('.mr-row .col-project .project-avatar-fallback:visible').first().textContent()
    ).toBe('A');
    const firstProject = visibleProjects.first();
    expect(await firstProject.getAttribute('data-tip')).toBe('App');
    await firstProject.hover();
    await page.waitForTimeout(200);
    expect(await firstProject.evaluate((el) => getComputedStyle(el, '::after').content)).toContain('App');
    // The tooltip is anchored to the right of the avatar cell (the leftmost
    // column) so it never clips off the left edge, and it stays on-screen.
    const placement = await firstProject.evaluate((el) => {
        const style = getComputedStyle(el, '::after');
        const cell = el.getBoundingClientRect();
        const leftPx = parseFloat(style.left);
        return {
            leftPx: Number.isFinite(leftPx) ? leftPx : null,
            cellLeft: cell.left,
            cellRight: cell.right,
            viewport: window.innerWidth,
        };
    });
    expect(placement.leftPx).not.toBeNull();
    const tooltipLeftAbs = placement.cellLeft + placement.leftPx;
    expect(tooltipLeftAbs).toBeGreaterThan(placement.cellRight);
    expect(tooltipLeftAbs).toBeLessThan(placement.viewport);

    // The MR number column is gone (the title still links to the MR).
    expect(await page.locator('.mr-header .col-mr').count()).toBe(0);

    // Every status badge renders on at least one MR row.
    expect(await page.locator('.col-state .status-ready').count()).toBeGreaterThanOrEqual(1);
    expect(await page.locator('.col-state .status-stale').count()).toBeGreaterThanOrEqual(1);
    expect(await page.locator('.col-state .status-needs-rebase').count()).toBeGreaterThanOrEqual(1);
    expect(await page.locator('.col-state .status-unresolved').count()).toBeGreaterThanOrEqual(1);
    expect(await page.locator('.col-state .status-approved').count()).toBeGreaterThanOrEqual(1);
    expect(await page.locator('.col-state .status-draft').count()).toBeGreaterThanOrEqual(1);

    // A cell can stack two badges (MR 204 is approved and has an unresolved
    // discussion), wrapping onto a second row instead of clipping.
    const badgeCounts = await page.locator('.col-state').evaluateAll((cells) =>
        cells.map((cell) => cell.querySelectorAll('.badge').length)
    );
    expect(badgeCounts.some((n) => n >= 2)).toBe(true);

    // The Jira ticket prefix is stripped from the title text (it stays in the
    // Jira column).
    const titles = await page.locator('.col-title a').allTextContents();
    expect(titles.every((t) => !/^[A-Z][A-Z0-9]+-\d+/.test(t))).toBe(true);

    // All nine metric cells render.
    expect(await page.locator('.cell').count()).toBe(9);

    // The pipeline indicator shows a running (spinner) pipeline on MR 204.
    expect(await page.locator('.pipe.spinner').count()).toBe(1);

    // The sync header shows a last-sync time and the daily rank refresh time.
    expect(await page.locator('#sync-status').textContent()).toContain('Last sync:');
    expect(await page.locator('#sync-status').textContent()).toContain('ranks:');

    // The "my view" user filter is present and defaults to Everyone, and each
    // user option shows their all-time MR count (ordered by it, desc).
    expect(await page.locator('#user-filter-select').count()).toBe(1);
    expect(await page.locator('#user-filter-select').inputValue()).toBe('');
    const userOptions = await page.locator('#user-filter-select option').allTextContents();
    expect(userOptions.some((t) => /\(\d+\)/.test(t))).toBe(true);
    // The highest-count user (John Roe, 7) is listed first after "Everyone".
    expect(userOptions[1]).toContain('John Roe');

    // Mean/median toggle re-renders without errors.
    await page.locator('.mm-toggle').first().click();
    expect(await page.locator('.cell').count()).toBe(9);

    expect(errors).toEqual([]);
});

test('description stays collapsed and hovers to a markdown-rendered tooltip', async ({ page }) => {
    await page.goto('/');
    await page.waitForSelector('#mr-list > .mr-row');

    // Descriptions are one ellipsized line, never click-expanded.
    expect(await page.locator('.desc.expanded').count()).toBe(0);
    const cellStyle = await page.locator('.mr-row .desc:visible').first().evaluate((el) => {
        const style = getComputedStyle(el);
        return {
            whiteSpace: style.whiteSpace,
            overflow: style.overflow,
            textOverflow: style.textOverflow,
            truncated: el.scrollWidth > el.clientWidth + 1,
        };
    });
    expect(cellStyle.whiteSpace).toBe('nowrap');
    expect(cellStyle.overflow).toBe('hidden');
    expect(cellStyle.textOverflow).toBe('ellipsis');
    expect(cellStyle.truncated).toBe(true);

    // MR 204 has a markdown description; the cell still shows raw text.
    const row = page.locator('.mr-row', { hasText: 'Fix login redirect' }).first();
    const cell = row.locator('.desc');
    expect(await cell.textContent()).toContain('**the redirect loop**');

    // Hovering the truncated description opens the shared tooltip with the
    // description rendered as markdown.
    await cell.hover();
    const tip = page.locator('.desc-tip');
    await expect(tip).toBeVisible();
    await expect(tip.locator('h2')).toHaveText('Summary');
    expect(await tip.locator('strong').count()).toBeGreaterThanOrEqual(1);
    expect(await tip.locator('code').count()).toBeGreaterThanOrEqual(1);
    expect(await tip.locator('pre code').count()).toBe(1);
    expect(await tip.locator('blockquote').count()).toBe(1);
    expect(await tip.textContent()).toContain('$client->getRedirect($request);');

    // Leaving the cell hides the tooltip again.
    await page.mouse.move(10, 10);
    await expect(tip).toBeHidden();
});

test('my view filter splits the list into authored and awaiting review', async ({ page }) => {
    await page.goto('/?user=1');
    await page.waitForSelector('.mr-section');

    const headings = await page.locator('.mr-section-heading').allTextContents();
    expect(headings.length).toBe(2);
    expect(headings.join(' | ')).toContain('Authored by me');
    expect(headings.join(' | ')).toContain('Awaiting my review');

    // The bookmarked filter is reflected in the select, and Clear is visible.
    expect(await page.locator('#user-filter-select').inputValue()).toBe('1');
    await expect(page.locator('#user-clear')).toBeVisible();

    // Stale MRs are not waiting on anyone's review: none appear in the
    // "awaiting my review" list.
    expect(await page.locator('.mr-section').nth(1).locator('.status-stale').count()).toBe(0);
});

test('chart x-axis labels show dates, not bogus year numbers', async ({ page }) => {
    // Capture the text uPlot paints onto the chart canvases.
    await page.addInitScript(() => {
        window.__axisTexts = [];
        const orig = CanvasRenderingContext2D.prototype.fillText;
        CanvasRenderingContext2D.prototype.fillText = function (text, x, y, ...rest) {
            if (text != null && String(text).length <= 40) {
                const canvas = this.canvas;
                const r = canvas.getBoundingClientRect();
                window.__axisTexts.push({ text: String(text), pageX: r.left + x, pageY: r.top + y });
            }
            return orig.call(this, text, x, y, ...rest);
        };
    });

    await page.goto('/');
    await page.waitForSelector('.cell');
    await page.waitForTimeout(500);

    // The bottom strip of a metric cell is the x-axis. It must show dates
    // ("2026-07-08") and never the bogus ~58,000-year tick numbers uPlot's
    // built-in time formatter produced for millisecond data.
    const coverage = page.locator('.cell', { hasText: 'Coverage %' }).first();
    const box = await coverage.locator('.chart-wrap').boundingBox();
    const labels = await page.evaluate((bottom) => (
        window.__axisTexts
            .filter((d) => d.pageY > bottom - 42 && d.pageY < bottom)
            .map((d) => d.text)
    ), box.y + box.height);

    expect(labels.length).toBeGreaterThanOrEqual(2);
    expect(labels.some((t) => /^\d{4}-\d{2}-\d{2}$/.test(t))).toBe(true);
    expect(labels.every((t) => !/^\d{5}$/.test(t))).toBe(true);
});

test('chart cells mark each line with an avatar on its last point', async ({ page }) => {
    await page.goto('/');
    await page.waitForSelector('.cell');
    await page.waitForTimeout(500);

    // Each metric cell with data shows one avatar marker per person at the last
    // drawn point of its line. Markers fall back to an initial for avatarless
    // users instead of a broken <img src="">.
    const coverage = page.locator('.cell', { hasText: 'Coverage %' }).first();
    const markers = coverage.locator('.avatar-point');
    expect(await markers.count()).toBeGreaterThanOrEqual(1);
    // Every marker is either an <img> with a src or an initials fallback span.
    const markerKinds = await markers.evaluateAll((els) =>
        els.map((el) => ({ tag: el.tagName, hasSrc: Boolean(el.getAttribute('src')) }))
    );
    for (const kind of markerKinds) {
        expect(kind.tag === 'IMG' ? kind.hasSrc : true).toBe(true);
    }
});

test('hovering a chart shows a toolbar with each avatar and its value at that time', async ({ page }) => {
    await page.goto('/');
    await page.waitForSelector('.cell');
    await page.waitForTimeout(500);

    const coverage = page.locator('.cell', { hasText: 'Coverage %' }).first();
    const tip = coverage.locator('.chart-tip');
    expect(await tip.evaluate((el) => el.hidden)).toBe(true);

    // Move the cursor onto the chart: a toolbar appears with a date header and
    // one row per person, each carrying an avatar and a value.
    const box = await coverage.locator('.chart-wrap').boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.waitForTimeout(200);
    expect(await tip.evaluate((el) => el.hidden)).toBe(false);
    expect(await tip.locator('.tip-head').textContent()).toMatch(/^\d{4}-\d{2}-\d{2}/);
    const rows = tip.locator('.tip-row');
    expect(await rows.count()).toBeGreaterThanOrEqual(1);
    for (let i = 0; i < await rows.count(); i++) {
        const row = rows.nth(i);
        expect(await row.locator('.tip-avatar, .tip-avatar-fallback').count()).toBe(1);
        expect(await row.locator('.tip-name').textContent()).toBeTruthy();
        expect(await row.locator('.tip-val').textContent()).toBeTruthy();
    }

    // Rows are ordered by all-time MR count (descending), matching the user
    // dropdown: John Roe (7), Jane Doe (4), Ann Lee (2).
    const names = await rows.locator('.tip-name').allTextContents();
    expect(names).toEqual(['John Roe', 'Jane Doe', 'Ann Lee']);

    // Leaving the chart hides the toolbar again.
    await page.mouse.move(10, 10);
    await page.waitForTimeout(200);
    expect(await tip.evaluate((el) => el.hidden)).toBe(true);
});

test('on a phone the MR table scrolls as one unit and row borders span every column', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.waitForSelector('#mr-list > .mr-row');

    // The table is wider than the viewport and scrolls at the panel level,
    // with the header sticking to the top while rows scroll vertically.
    const panel = page.locator('.mr-panel');
    const dims = await panel.evaluate((el) => ({
        overflowX: getComputedStyle(el).overflowX,
        scrollable: el.scrollWidth > el.clientWidth,
    }));
    expect(dims.overflowX).toBe('auto');
    expect(dims.scrollable).toBe(true);
    expect(await page.locator('.mr-header').evaluate((el) => getComputedStyle(el).position)).toBe('sticky');

    // Rows and the header span the full table width, so a row's bottom border
    // reaches the last column instead of stopping at the screen edge.
    const widths = await page.locator('.mr-row:visible').first().evaluate((row) => ({
        row: row.getBoundingClientRect().width,
        header: document.querySelector('.mr-header').getBoundingClientRect().width,
    }));
    expect(widths.row).toBeGreaterThan(600);
    expect(widths.row).toBe(widths.header);

    // Scrolling the panel right keeps the header and rows aligned as one table.
    await panel.evaluate((el) => {
        el.scrollLeft = 800;
    });
    await page.waitForTimeout(50);
    const aligned = await page.evaluate(() => {
        const header = document.querySelector('.mr-header').getBoundingClientRect().left;
        const row = [...document.querySelectorAll('.mr-row')]
            .find((el) => el.getBoundingClientRect().width > 0)
            .getBoundingClientRect().left;

        return { header, row };
    });
    expect(aligned.row).toBe(aligned.header);
});

test('on a phone the metrics stack one per row and scroll vertically', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.waitForSelector('.cell canvas');

    const panel = page.locator('.stats-panel');
    const dims = await panel.evaluate((el) => ({
        overflowY: getComputedStyle(el).overflowY,
        scrollable: el.scrollHeight > el.clientHeight,
        cols: getComputedStyle(el).gridTemplateColumns,
    }));
    expect(dims.overflowY).toBe('auto');
    expect(dims.scrollable).toBe(true);
    expect(dims.cols.split(' ').length).toBe(1);

    // Every metric cell spans the full panel width (one per line).
    const widths = await panel.locator('.cell').evaluateAll((cells) =>
        cells.map((cell) => Math.round(cell.getBoundingClientRect().width))
    );
    expect(widths.length).toBeGreaterThanOrEqual(9);
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);
    expect(widths[0]).toBeGreaterThan(300);

    // The charts still render.
    expect(await panel.locator('canvas').count()).toBeGreaterThanOrEqual(1);
});
