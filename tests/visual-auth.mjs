import { chromium } from 'playwright';
import { mkdir, stat } from 'node:fs/promises';

const base = 'http://127.0.0.1:8099';
await mkdir('design/qa/authenticated', { recursive: true });
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--proxy-server=direct://', '--proxy-bypass-list=*'] });
const targets = [
  { route: '/app', name: 'dashboard', expected: 'YOUR INTELLIGENCE WORKSPACE' },
  { route: '/app/team', name: 'team', expected: 'Your team.' },
  { route: '/app/privacy', name: 'privacy', expected: 'Your data.' },
  { route: '/app/billing', name: 'billing', expected: 'Choose your' },
  { route: '/app/security', name: 'security', expected: 'Secure your' },
];
try {
 for (const screen of [{ width: 390, height: 844, label: 'mobile' }, { width: 1440, height: 900, label: 'desktop' }]) {
  const context = await browser.newContext({ viewport: { width: screen.width, height: screen.height }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name=email]').fill('colleague@example.com');
  await page.locator('input[name=password]').fill('Colleague_Password_2026!');
  await Promise.all([page.waitForURL('**/app'), page.locator('button[type=submit]').click()]);
  for (const target of targets) {
   const response = await page.goto(base + target.route, { waitUntil: 'domcontentloaded' });
   if (!response || response.status() !== 200) throw new Error(target.name + ' returned non-success HTTP ' + response?.status());
   await page.waitForTimeout(250);
   const state = await page.evaluate(() => ({
    text: document.body.innerText,
    actualWidth: document.documentElement.scrollWidth,
    background: getComputedStyle(document.body).backgroundColor,
   }));
   if (!state.text.includes(target.expected)) throw new Error(target.name + ' lacks expected meaningful text');
   if (state.actualWidth > screen.width + 5) throw new Error(target.name + ' horizontally overflows: ' + state.actualWidth + ' > ' + screen.width);
   if (state.background !== 'rgb(7, 19, 29)') throw new Error(target.name + ' stylesheet is unavailable: ' + state.background);
   const name = 'design/qa/authenticated/' + target.name + '-' + screen.label + '.png';
   await page.screenshot({ path: name, fullPage: true });
   const bytes = (await stat(name)).size;
   if (bytes < 20000) throw new Error(target.name + ' image appears empty: ' + bytes + ' bytes');
   console.log('PASS', target.name, screen.label, bytes);
  }
  await context.close();
 }
} finally { await browser.close(); }
