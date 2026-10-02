import 'dotenv/config';
import fs from 'node:fs/promises';
import lighthouse from 'lighthouse';
import * as chromeLauncher from 'chrome-launcher';
import fsSync from 'node:fs';
import path from 'node:path';

let runtime = {};
try {
  runtime = JSON.parse(fsSync.readFileSync(path.resolve('.worktree/runtime.json'), 'utf8'));
} catch {
  // The environment has not started yet; retain the default URL for diagnostics.
}
const configuredBaseURL = process.env.BASE_URL?.trim();
const url = (process.env.USE_WORKTREE_RUNTIME === '0'
  ? configuredBaseURL
  : runtime.url || (configuredBaseURL && configuredBaseURL !== 'http://localhost:8888' ? configuredBaseURL : 'http://localhost:8888')) ?? 'http://localhost:8888/';
const outputDirectory = new URL('../lighthouse-results/', import.meta.url);
const runs = [];

await fs.mkdir(outputDirectory, { recursive: true });

for (let index = 1; index <= 3; index += 1) {
  const chrome = await chromeLauncher.launch({ chromeFlags: ['--headless', '--no-sandbox'] });
  try {
    const result = await lighthouse(url, {
      port: chrome.port,
      output: 'json',
      logLevel: 'error',
      onlyCategories: ['performance', 'accessibility', 'best-practices']
    });
    if (!result) throw new Error('Lighthouse returned no result');
    await fs.writeFile(new URL(`run-${index}.json`, outputDirectory), result.report);
    runs.push(result.lhr);
  } finally {
    await chrome.kill();
  }
}

const median = values => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)];
const metric = id => median(runs.map(run => run.audits[id].numericValue));
const score = category => median(runs.map(run => run.categories[category].score));
const results = {
  largestContentfulPaint: metric('largest-contentful-paint'),
  cumulativeLayoutShift: metric('cumulative-layout-shift'),
  totalBlockingTime: metric('total-blocking-time'),
  accessibility: score('accessibility'),
  bestPractices: score('best-practices')
};
const failures = [
  results.largestContentfulPaint > 2500 && `LCP ${Math.round(results.largestContentfulPaint)}ms > 2500ms`,
  results.cumulativeLayoutShift > 0.1 && `CLS ${results.cumulativeLayoutShift.toFixed(3)} > 0.1`,
  results.totalBlockingTime > 200 && `TBT ${Math.round(results.totalBlockingTime)}ms > 200ms`,
  results.accessibility < 0.95 && `Accessibility ${results.accessibility} < 0.95`,
  results.bestPractices < 0.9 && `Best practices ${results.bestPractices} < 0.9`
].filter(Boolean);
const warnings = [
  results.largestContentfulPaint > 1500
    && `LCP ${Math.round(results.largestContentfulPaint)}ms > 1500ms early-warning threshold (hard budget: 2500ms)`
].filter(Boolean);

console.table(results);
if (warnings.length) console.warn(`Performance warnings:\n${warnings.join('\n')}`);
if (failures.length) {
  console.error(failures.join('\n'));
  process.exitCode = 1;
}
