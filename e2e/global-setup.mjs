import { acquireSharedInstanceLock } from './shared-instance-lock.mjs'

export default async function globalSetup() {
	await acquireSharedInstanceLock('whistlecheck')
}
