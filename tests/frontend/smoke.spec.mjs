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
    await page.waitForSelector('.mr-row');
    expect(await page.locator('.mr-row').count()).toBeGreaterThanOrEqual(6);

    // A stale MR exists and the stale link renders.
    await page.waitForSelector('.stale-link');
    expect(await page.locator('.stale-link').textContent()).toContain('stale Merge Requests');

    // All nine metric cells render.
    expect(await page.locator('.cell').count()).toBe(9);

    // The pipeline indicator shows a running (spinner) pipeline on MR 204.
    expect(await page.locator('.pipe.spinner').count()).toBe(1);

    // The sync header shows a last-sync time.
    expect(await page.locator('#sync-status').textContent()).toContain('Last sync:');

    // Mean/median toggle re-renders without errors.
    await page.locator('.mm-toggle').first().click();
    expect(await page.locator('.cell').count()).toBe(9);

    expect(errors).toEqual([]);
});
