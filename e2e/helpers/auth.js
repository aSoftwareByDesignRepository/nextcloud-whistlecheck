/**
 * Nextcloud 34 Vue login is async; wait for #user before fill.
 * Supports NC_*_PASS or NC_*_PASSWORD (and E2E_* aliases via caller env).
 * Fail-fast on wrong credentials so a11y does not hang for 30s on /login.
 * Prefer landing on WhistleCheck first so a valid storageState never hits /login
 * (admin sessions sometimes get the core "Update needed" interstitial there).
 *
 * Login strategy: try a request-context login first (real POST /login with
 * Origin + requesttoken). Chromium >=151 enforces form-action 'self' against
 * the login 303 redirect chain, so a configured overwritehost (e.g. the
 * emulator alias 10.0.2.2) makes the UI form submit abort in-browser while the
 * underlying endpoint keeps working. The request context shares cookies with
 * the page, so the session is identical. The classic form flow stays as a
 * fallback for environments where the request path fails for other reasons.
 */

/**
 * @param {import('@playwright/test').Page} page
 */
async function isServerUpdater(page) {
  const heading = page.getByRole('heading', { name: /Update needed/i })
  return heading.isVisible().catch(() => false)
}

/**
 * @param {import('@playwright/test').Page} page
 */
export async function assertNotServerUpdater(page) {
  if (await isServerUpdater(page)) {
    throw new Error('Nextcloud is showing the server updater. Run: docker compose exec -u www-data nextcloud php occ upgrade')
  }
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ username: string, password: string }} creds
 */
/**
 * POST /login through the browser context's APIRequestContext so the session
 * cookie lands in the same jar the page uses. Throws on rejected credentials
 * (same contract as the form flow). Returns true when a session was set up.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ username: string, password: string }} creds
 * @returns {Promise<boolean>}
 */
async function loginViaRequestContext(page, { username, password }) {
  const request = page.context().request
  const loginPage = await request.get('/login')
  if (!loginPage.ok()) {
    return false
  }
  const html = await loginPage.text()
  const tokenMatch = html.match(/data-requesttoken="([^"]+)"/)
  if (!tokenMatch) {
    return false
  }
  // The attribute ships URL-encoded; decode once so the form encoder can
  // re-encode exactly once (double-encoding fails the CSRF check).
  const requesttoken = decodeURIComponent(tokenMatch[1])
  const origin = new URL(page.url()).origin
  const res = await request.post('/login', {
    form: { user: username, password, requesttoken },
    headers: { Origin: origin },
    maxRedirects: 0,
  })
  const location = res.headers()['location'] || ''
  const target = location ? new URL(location, origin).pathname : ''
  if (res.status() !== 303 || target.startsWith('/login')) {
    throw new Error(`Login rejected for user "${username}" (Wrong login or password)`)
  }
  // Confirm the cookie jar actually authenticates before trusting it.
  const probe = await request.get('/apps/whistlecheck/', { maxRedirects: 0 })
  const probeLoc = probe.headers()['location'] || ''
  if (probe.status() === 401 || new URL(probeLoc || 'x', origin).pathname.startsWith('/login')) {
    throw new Error(`Login rejected for user "${username}" (Wrong login or password)`)
  }
  return true
}

export async function login(page, { username, password }) {
  await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
  if (await page.locator('.wc-public-main, #app-content .wc-app-wrap').isVisible().catch(() => false)) {
    return
  }
  if (await isServerUpdater(page)) {
    await assertNotServerUpdater(page)
  }

  try {
    if (await loginViaRequestContext(page, { username, password })) {
      await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
      return
    }
  } catch (err) {
    // Credential rejections keep the old contract so loginWithFallback can
    // advance to the next candidate; anything else falls through to the
    // form-driven flow below.
    if (/Wrong login or password/i.test(err instanceof Error ? err.message : String(err))) {
      throw err
    }
  }

  const maxAttempts = 3
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    await page.goto('/login', { waitUntil: 'domcontentloaded' })
    await assertNotServerUpdater(page)
    // Already authenticated (storageState / prior test).
    if (!page.url().includes('/login')) {
      return
    }
    const userInput = page.locator('input#user, input[name="user"]').first()
    const passInput = page.locator('input#password, input[name="password"]').first()
    try {
      await userInput.waitFor({ state: 'visible', timeout: 20_000 })
    } catch {
      await assertNotServerUpdater(page)
      if (!page.url().includes('/login')) {
        return
      }
      if (attempt < maxAttempts) {
        continue
      }
      throw new Error('Login form not ready (Vue #login)')
    }
    await userInput.fill(username)
    await passInput.fill(password)
    await page.locator('button[type="submit"], input[type="submit"]').first().click()

    const outcome = await Promise.race([
      page.waitForURL((url) => !url.pathname.includes('/login'), {
        timeout: 45_000,
        waitUntil: 'commit',
      }).then(() => 'ok'),
      page.getByText(/Wrong login or password|Falscher Benutzername oder Passwort/i)
        .waitFor({ state: 'visible', timeout: 45_000 })
        .then(() => 'bad'),
    ])
    if (outcome === 'bad') {
      throw new Error(`Login rejected for user "${username}" (Wrong login or password)`)
    }
    await page.waitForLoadState('domcontentloaded')
    await assertNotServerUpdater(page)
    return
  }
}

/**
 * Prefer E2E_* then NC_ADMIN_* then NC_EMPLOYEE_* so stale shell NC_ADMIN_*
 * does not shadow a working E2E password (common local-dev footgun).
 *
 * @returns {Array<{ username: string, password: string }>}
 */
export function plannerCredsCandidates() {
  /** @type {Array<{ username: string, password: string }>} */
  const out = []
  const push = (u, p) => {
    if (u && p) {
      out.push({ username: u, password: p })
    }
  }
  push(process.env.E2E_USER, process.env.E2E_PASSWORD || process.env.E2E_PASS)
  push(process.env.NC_ADMIN_USER, process.env.NC_ADMIN_PASS || process.env.NC_ADMIN_PASSWORD)
  push(process.env.NC_OFFICER_USER, process.env.NC_OFFICER_PASS || process.env.NC_OFFICER_PASSWORD)
  // Employee credentials are for employee-only specs. Including them in the
  // planner fallback chain turns a mid-suite admin throttle into a false
  // "e2e_employee wrong password" failure that aborts the theme matrix.
  if (process.env.DC_E2E_ALLOW_EMPLOYEE_PLANNER_FALLBACK === '1') {
    push(process.env.NC_EMPLOYEE_USER, process.env.NC_EMPLOYEE_PASS || process.env.NC_EMPLOYEE_PASSWORD)
  }
  // Deduplicate identical pairs while preserving order.
  const seen = new Set()
  return out.filter((c) => {
    const key = `${c.username}\0${c.password}`
    if (seen.has(key)) {
      return false
    }
    seen.add(key)
    return true
  })
}

/**
 * Try candidates until one logs in; skip silent failures only for wrong password.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Array<{ username: string, password: string }>} candidates
 */
export async function loginWithFallback(page, candidates) {
  if (!candidates.length) {
    throw new Error('No planner credentials configured (E2E_* / NC_ADMIN_* / NC_EMPLOYEE_*)')
  }
  /** @type {Error | null} */
  let last = null
  for (const creds of candidates) {
    try {
      await login(page, creds)
      return
    } catch (err) {
      last = err instanceof Error ? err : new Error(String(err))
      if (!/Wrong login or password/i.test(last.message)) {
        throw last
      }
    }
  }
  throw last || new Error('All planner credential candidates failed')
}

/**
 * @param {'ADMIN' | 'EMPLOYEE' | string} role
 */
export function credsFromEnv(role) {
  const u = process.env[`NC_${role}_USER`] || (role === 'ADMIN' ? process.env.E2E_USER : undefined)
  const p = process.env[`NC_${role}_PASS`]
    || process.env[`NC_${role}_PASSWORD`]
    || (role === 'ADMIN' ? (process.env.E2E_PASSWORD || process.env.E2E_PASS) : undefined)
  if (!u || !p) {
    throw new Error(`Missing env vars NC_${role}_USER / NC_${role}_PASS`)
  }
  return { username: u, password: p }
}
