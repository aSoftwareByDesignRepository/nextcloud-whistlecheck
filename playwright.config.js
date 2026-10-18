import { defineConfig, devices } from '@playwright/test'
import { existsSync, readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const configDir = dirname(fileURLToPath(import.meta.url))
const envFile = resolve(configDir, 'e2e/.env')
if (existsSync(envFile)) {
  for (const line of readFileSync(envFile, 'utf8').split('\n')) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) {
      continue
    }
    const eq = trimmed.indexOf('=')
    if (eq <= 0) {
      continue
    }
    const key = trimmed.slice(0, eq).trim()
    let value = trimmed.slice(eq + 1).trim()
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1)
    }
    if (process.env[key] === undefined) {
      process.env[key] = value
    }
  }
}

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081'

export default defineConfig({
  testDir: 'e2e',
  globalTeardown: './e2e/global-teardown.mjs',

  globalSetup: './e2e/global-setup.mjs',

  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 1,
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  // No auth setup project / shared storageState: every officer spec
  // provisions a unique throwaway officer (helpers/officer.js) — a shared
  // uid is unsafe on a machine where a parallel run can rotate the key or
  // reset the password mid-test and invalidate the session.
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
})
