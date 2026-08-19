import { chromium } from '@playwright/test';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
await page.goto('http://127.0.0.1:8791/', { waitUntil: 'networkidle' });
await page.waitForSelector('.cell canvas');
await page.waitForTimeout(800);
// scroll to stats panel
await page.locator('.stats-panel').scrollIntoViewIfNeeded();
await page.waitForTimeout(500);
await page.screenshot({ path: '/tmp/dash-current.png' });
// zoom into first chart-wrap
const wrap = page.locator('.chart-wrap').first();
await wrap.screenshot({ path: '/tmp/chart-current.png' });
console.log('shot done');
await browser.close();
