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

    // An open MR with enough approvals and a green pipeline is "ready".
    expect(await page.locator('.col-state.state-ready').count()).toBeGreaterThanOrEqual(1);

    // All nine metric cells render.
    expect(await page.locator('.cell').count()).toBe(9);

    // The pipeline indicator shows a running (spinner) pipeline on MR 204.
    expect(await page.locator('.pipe.spinner').count()).toBe(1);

    // The sync header shows a last-sync time.
    expect(await page.locator('#sync-status').textContent()).toContain('Last sync:');

    // The "my view" user filter is present and defaults to Everyone.
    expect(await page.locator('#user-filter-select').count()).toBe(1);
    expect(await page.locator('#user-filter-select').inputValue()).toBe('');

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
});
