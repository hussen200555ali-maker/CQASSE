const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });
  await page.goto('file:///C:/xamppp/htdocs/eqas/docs/gantt-only.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000); // wait for chart to render
  await page.screenshot({ path: 'C:/xamppp/htdocs/eqas/docs/gantt-chart.png', fullPage: true });
  await browser.close();
  console.log('Screenshot saved to gantt-chart.png');
})();
