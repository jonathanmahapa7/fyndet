import { chromium } from 'playwright';
import { stat, mkdir } from 'node:fs/promises';

await mkdir('design/qa', { recursive: true });
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--proxy-server=direct://', '--proxy-bypass-list=*'] });
const pages = [
  { name: 'home-desktop', route: '/', width: 1440, height: 900, expected: 'Every address' },
  { name: 'home-mobile', route: '/', width: 390, height: 844, expected: 'Every address' },
  { name: 'pricing-desktop', route: '/pricing', width: 1440, height: 900, expected: 'Find your depth' },
  { name: 'register-mobile', route: '/register', width: 390, height: 844, expected: 'Your account starts here' },
];

try {
  for (const item of pages) {
    const page = await browser.newPage({ viewport: { width: item.width, height: item.height }, deviceScaleFactor: 1 });
    const response = await page.goto('http://127.0.0.1:8099' + item.route, { waitUntil: 'domcontentloaded', timeout: 20000 });
    if (!response || response.status() !== 200) throw new Error(item.name + ': expected HTTP 200, got ' + response?.status());
    await page.locator('h1').first().waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(1100);
    const { title, text, bodyBg, width } = await page.evaluate(() => ({
      title: document.title,
      text: document.body.innerText,
      bodyBg: getComputedStyle(document.body).backgroundColor,
      width: document.documentElement.scrollWidth,
    }));
    if (!text.includes(item.expected)) throw new Error(item.name + ': meaningful page text missing; got ' + JSON.stringify(text.slice(0, 250)));
    if (!/rgb\(7, 19, 29\)/.test(bodyBg)) throw new Error(item.name + ': premium stylesheet not loaded: ' + bodyBg);
    if (width > item.width + 5) throw new Error(item.name + ': horizontal overflow detected: ' + width + ' > ' + item.width);
    const path = 'design/qa/' + item.name + '.png';
    await page.screenshot({ path, fullPage: true });
    const bytes = (await stat(path)).size;
    if (bytes < 20000) throw new Error(item.name + ': screenshot unexpectedly small / potentially blank (' + bytes + ' bytes)');
    console.log('PASS', item.name, title, bodyBg, bytes + ' bytes');
    await page.close();
  }
} finally {
  await browser.close();
}
