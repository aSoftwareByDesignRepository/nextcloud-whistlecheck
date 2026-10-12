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
const AUTH_FILE = 'e2e/.auth/officer.json'

function hasOfficerCreds() {
  const pair = (user, pass) => Boolean(user && pass)
  return pair(process.env.E2E_USER, process.env.E2E_PASSWORD || process.env.E2E_PASS)
    || pair(process.env.NC_ADMIN_USER, process.env.NC_ADMIN_PASS || process.env.NC_ADMIN_PASSWORD)
    || pair(process.env.NC_OFFICER_USER, process.env.NC_OFFICER_PASS || process.env.NC_OFFICER_PASSWORD)
}

const chromiumUse = { ...devices['Desktop Chrome'] }
if (hasOfficerCreds()) {
  chromiumUse.storageState = AUTH_FILE
}

export default defineConfig({
  testDir: 'e2e',
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
  projects: hasOfficerCreds()
    ? [
      { name: 'setup', testMatch: /auth\.setup\.js/ },
      {
        name: 'chromium',
        dependencies: ['setup'],
        testIgnore: /auth\.setup\.js/,
        use: chromiumUse,
      },
    ]
    : [
      { name: 'chromium', testIgnore: /auth\.setup\.js/, use: chromiumUse },
    ],
})
