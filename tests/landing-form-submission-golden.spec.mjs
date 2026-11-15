import assert from 'node:assert/strict'
// What a landing-page form sends and answers, pinned before `submitLandingPageForm` is split.
import { test } from 'node:test'
import { submitLandingPageForm } from '../src/site/lib/formSubmission.js'

function stubSite(apiBase, answer = { status: 200, body: { object: { id: 'new' } } }) {
	const calls = []
	const store = { getItem: () => null, setItem() {}, removeItem() {} }
	globalThis.document = {
		referrer: '',
		getElementById: (id) => (id === 'portaliq-site-config' ? { textContent: JSON.stringify({ apiBase }) } : null),
	}
	globalThis.window = { localStorage: store, sessionStorage: store, location: { origin: 'http://localhost', search: '', hash: '' } }
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), method: init.method, headers: init.headers, body: JSON.parse(init.body) })
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
	return calls
}

test('a bound form names its action, and only captured campaign parameters travel', async () => {
	const calls = stubSite('/apps/portaliq/api/content/site')
	const answer = await submitLandingPageForm(
		{ email: 'a@example.nl', utmFirstTouch: 'overwritten' },
		{ utmFirstTouch: { source: 'mail', medium: '', campaign: 3 }, utmLastTouch: { source: '' }, referrer: 'https://ref.example' },
		'form-b',
	)
	assert.deepEqual(answer, { id: 'new' })
	assert.deepEqual(calls, [{
		url: 'http://localhost/apps/portaliq/portal/api/collections/portaliq/landingPageSubmission?actionId=submit-form-b',
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
		body: { email: 'a@example.nl', utmFirstTouch: { source: 'mail' }, utmLastTouch: null, referrer: 'https://ref.example' },
	}])
})

test('without tracking or a form id the body carries empty touches and no action id is sent', async () => {
	const calls = stubSite('/apps/portaliq/api/content/site')
	await submitLandingPageForm({ name: 'B' })
	assert.equal(calls[0].url, 'http://localhost/apps/portaliq/portal/api/collections/portaliq/landingPageSubmission')
	assert.deepEqual(calls[0].body, { name: 'B', utmFirstTouch: null, utmLastTouch: null, referrer: '' })
})

test('an answer without an object envelope is returned as it is', async () => {
	stubSite('/apps/portaliq/api/content/site', { status: 201, body: { reference: 'R-1' } })
	assert.deepEqual(await submitLandingPageForm({}, {}, ''), { reference: 'R-1' })
})

test('a refused submission throws with its status', async () => {
	stubSite('/apps/portaliq/api/content/site', { status: 422, body: {} })
	await assert.rejects(submitLandingPageForm({}, {}, 'f'), (error) => {
		assert.equal(error.message, 'landing page form submission 422')
		assert.equal(error.status, 422)
		return true
	})
})
