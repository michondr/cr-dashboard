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

    // Leaving the chart hides the toolbar again.
    await page.mouse.move(10, 10);
    await page.waitForTimeout(200);
    expect(await tip.evaluate((el) => el.hidden)).toBe(true);
});
