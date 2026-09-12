#!/usr/bin/env node

const productionURL = process.env.PROD_URL ?? 'https://www.armstrongthanksgiving.com/';

try {
  const response = await fetch(productionURL, {
    redirect: 'manual',
    signal: AbortSignal.timeout(15000),
  });
  console.log(`Public check: ${response.status} ${response.statusText}`);
  if (response.status === 404 || response.status >= 500) {
    throw new Error(`Public site returned ${response.status}.`);
  }
} catch (error) {
  console.error(`Production verification failed: ${error instanceof Error ? error.message : error}`);
  process.exit(1);
}
