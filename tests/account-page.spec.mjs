#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-page.spec.mjs: the "My account" page (identity-profile-page
// T07-T10). Name, e-mail addresses, phone numbers, the contact channel and
// removing the account; the confirmation link is consumed once, and the
// prompt for a missing address lasts until it is dismissed.
//
// Usage:
//   node --test tests/account-page.spec.mjs

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { createPortalApi } from '../src/shared/portalApi.js'
import { buildNav, defaultNavKey } from '../src/shared/portalNav.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const { consumeConfirmEmail, refusalText, promptDismissed, dismissPrompt } =
	await import(pathToFileURL(join(ROOT, 'src', 'shared', 'account.js')).href)

const BASE = '/apps/portaliq/portal/api'
const nl = JSON.parse(
	readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'nl.json'), 'utf8'),
)
const en = JSON.parse(
	readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'en.json'), 'utf8'),
)

/**
 * An identity translator with {name} substitution.
 *
 * @param {string} key The English source key.
 * @param {object} vars The substitutions.
 * @return {string} The text.
 */
function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}

/**
 * Stub the browser: a stored bearer and a recording fetch.
 *
 * @param {Array<{status: number, body: object}>} answers The answers, in order.
 * @return {Array<object>} The recorded calls.
 */
function stubBrowser(answers) {
	const calls = []
	const queue = [...answers]
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		const next = queue.shift() || { status: 200, body: {} }
		return {
			ok: next.status >= 200 && next.status < 300,
			status: next.status,
			json: async () => next.body,
		}
	}
	return calls
}

const DETAILS = {
	displayName: 'Ans de Vries',
	email: 'a@example.nl',
	contactAddresses: [
		{ kind: 'email', value: 'a@example.nl', confirmed: true, preferred: true },
		{ kind: 'email', value: 'c@example.nl', confirmed: false, preferred: false },
		{ kind: 'phone', value: '+31612345678', confirmed: false, preferred: true },
	],
	pendingEmail: 'c***@example.nl',
	contactChannel: 'post',
}

test('the api calls the own-account routes with the bearer and keeps the refusal', async () => {
	const calls = stubBrowser([
		{ status: 200, body: DETAILS },
		{
			status: 200,
			body: {
				added: true,
				value: 'b@example.nl',
				confirmationPending: true,
				confirmationSent: true,
			},
		},
		{ status: 400, body: { error: 'confirm_first' } },
		{ status: 200, body: { removed: true } },
		{ status: 200, body: { channel: 'phone' } },
		{ status: 200, body: { confirmed: true } },
		{ status: 200, body: { removed: true } },
		{ status: 200, body: { updated: true } },
	])
	const api = createPortalApi({ apiBase: BASE })

	assert.deepEqual(await api.getDetails(), DETAILS)
	const added = await api.addContactAddress('email', 'b@example.nl')
	assert.equal(added.ok, true)
	assert.equal(added.data.confirmationPending, true)
	assert.deepEqual(await api.preferContactAddress('email', 'c@example.nl'), {
		ok: false,
		status: 400,
		error: 'confirm_first',
		data: { error: 'confirm_first' },
	})
	await api.removeContactAddress('phone', '+31612345678')
	await api.setContactChannel('phone')
	await api.confirmEmail('secret-1')
	await api.removeOwnAccount()
	await api.setDisplayName('Ans')

	assert.equal(calls[0].url, `${BASE}/identity/details`)
	assert.deepEqual(
		calls.slice(1).map((c) => [c.init.method, c.url.replace(BASE, '')]),
		[
			['POST', '/identity/addresses'],
			['POST', '/identity/addresses/preferred'],
			['POST', '/identity/addresses/remove'],
			['PUT', '/identity/contact-channel'],
			['POST', '/identity/email/confirm'],
			['POST', '/identity/remove'],
			['PATCH', '/identity/details'],
		],
	)
	assert.equal(calls[1].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(JSON.parse(calls[1].init.body), {
		kind: 'email',
		value: 'b@example.nl',
	})
	assert.deepEqual(JSON.parse(calls[5].init.body), { token: 'secret-1' })
	assert.deepEqual(JSON.parse(calls[7].init.body), { displayName: 'Ans' })
})

test('the confirmation link is read once and stripped from the address bar', () => {
	const replaced = []
	const location = {
		hash: '#confirm-email=abc123',
		href: 'https://gemeente.example/apps/portaliq/portal#confirm-email=abc123',
	}
	const history = {
		replaceState: (_s, _t, url) => {
			replaced.push(url)
			location.hash = ''
		},
	}

	assert.equal(consumeConfirmEmail(location, history), 'abc123')
	assert.deepEqual(replaced, ['https://gemeente.example/apps/portaliq/portal'])
	assert.equal(consumeConfirmEmail(location, history), '')
	assert.equal(consumeConfirmEmail({ hash: '#token=x', href: 'x' }, history), '')
})

test('each refusal has its own sentence', () => {
	assert.equal(refusalText('confirm_first'), 'Confirm this address first.')
	assert.equal(
		refusalText('choose_another_preferred'),
		'Choose another preferred address first.',
	)
	assert.equal(
		refusalText('too_many_pending'),
		'Five addresses are waiting for confirmation. Confirm or remove one first.',
	)
	assert.equal(refusalText('exists'), 'This address is already on your account.')
	assert.equal(refusalText('invalid'), 'Check the address and try again.')
	assert.equal(refusalText('link_not_valid'), 'This link is no longer valid.')
	assert.equal(refusalText('whatever'), 'That did not work. Try again later.')
})

test('a dismissed prompt for an address stays away this session', () => {
	const store = new Map()
	const session = {
		getItem: (k) => store.get(k) ?? null,
		setItem: (k, v) => store.set(k, v),
	}
	assert.equal(promptDismissed(session), false)
	dismissPrompt(session)
	assert.equal(promptDismissed(session), true)
	assert.equal(promptDismissed(null), false)
})

test('site: the shell offers "My account", consumes the link and shows the prompt', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	const registry = readFileSync(
		join(ROOT, 'src', 'site', 'pages', 'registry.js'),
		'utf8',
	)
	assert.match(registry, /account: accountPages\.__account__/)
	// The shared navigation offers it once signed in, and never as the first page.
	const nav = buildNav([{ app: 'a', pages: [{ id: 'p' }] }], (key) => key, {
		access: true,
	})
	assert.ok(
		nav.some(
			(entry) => entry.special === 'account' && entry.label === 'My account',
		),
	)
	assert.equal(defaultNavKey(nav), 'a:p')
	assert.ok('My account' in nl)
	assert.match(
		app,
		/import \{ confirmEmailFromLink, contactPromptWanted \} from '\.\/pages\/e\/index\.js'/,
	)
	assert.match(app, /this\.confirmMessage = await confirmEmailFromLink\(/)
	assert.match(
		app,
		/this\.contactPrompt = await contactPromptWanted\(this\.session\)/,
	)
	// On a `/mijn` page the prompt stands in the signed-in area's content
	// column, above the page heading; it used to sit above `main`, over the
	// menu column and half under the header. Elsewhere it stays above the page.
	assert.match(
		app,
		/<div\s+v-if="session && contactPrompt && !accountRoute"[^>]*>\s*<ContactPrompt/,
	)
	assert.match(
		app,
		/<template v-if="session && contactPrompt" #prompt>\s*<ContactPrompt/,
	)
	const area = readFileSync(
		join(ROOT, 'src', 'site', 'components', 'AccountArea.vue'),
		'utf8',
	)
	assert.match(
		area,
		/<div class="pq-account__content">[\s\S]*?<slot name="prompt" \/>[\s\S]*?<h1/,
	)
})

// The Vue port on the site (site-reaches-portal-parity T18, REQ-SRP-037).

const { renderSfc, loadSfc } = await import('./support/render-sfc.mjs')
const siteE = await import(
	pathToFileURL(join(ROOT, 'src', 'site', 'pages', 'e', 'index.js')).href
)
const { default: siteStrings } = await import(
	pathToFileURL(join(ROOT, 'src', 'site', 'pages', 'e', 'strings.js')).href
)

test('site: "My account" shows the name, both kinds of address with the preferred ones marked, and the channel', async () => {
	const html = await renderSfc('src/site/pages/e/AccountPage.vue', {
		api: {},
		t,
		initialDetails: DETAILS,
	})
	assert.match(html, /value="Ans de Vries"/)
	assert.match(html, /a@example\.nl/)
	assert.match(html, /c@example\.nl/)
	assert.match(html, /Waiting for confirmation/)
	assert.match(html, /\+31612345678/)
	assert.equal(
		(html.match(/\(Preferred\)/g) || []).length,
		2,
		'one preferred e-mail and one preferred phone',
	)
	assert.match(html, /value="post" checked/)
	assert.match(html, /Send the link again/)
	assert.equal((html.match(/Make preferred/g) || []).length, 0)
	assert.match(html, /<label for="pq-account-name"/)
	assert.doesNotMatch(
		html,
		/Your cases stay with the organisation\./,
		'the removal text shows in the confirmation step only',
	)
})

test('site: the removal step says the cases stay, and a failed read says so', async () => {
	const html = await renderSfc('src/site/pages/e/AccountPage.vue', {
		api: {},
		t,
		initialDetails: DETAILS,
		initialConfirmRemove: true,
	})
	assert.match(
		html,
		/Your portal account is removed\. Your cases stay with the organisation\./,
	)
	assert.match(html, /Yes, remove my account/)
	const failed = await renderSfc('src/site/pages/e/AccountPage.vue', {
		api: {},
		t,
		initialDetails: { failed: true },
	})
	assert.match(
		failed,
		/role="alert"[^>]*>Your account cannot be shown right now\./,
	)
})

test('site: an address action shows the outcome, reads the account again, and a refusal keeps its sentence', async () => {
	const page = await loadSfc('src/site/pages/e/AccountPage.vue')
	const calls = []
	const api = {
		addContactAddress: async (kind, value) => {
			calls.push(['add', kind, value])
			return { ok: true }
		},
		preferContactAddress: async () => ({ ok: false, error: 'confirm_first' }),
		removeOwnAccount: async () => ({ ok: true }),
		getDetails: async () => {
			calls.push(['read'])
			return DETAILS
		},
	}
	const emitted = []
	const vm = {
		api,
		t,
		notice: '',
		error: '',
		details: null,
		name: '',
		$emit: (e) => emitted.push(e),
	}
	vm.run = page.methods.run.bind(vm)
	vm.reload = page.methods.reload.bind(vm)

	assert.equal(
		await page.methods.act.call(vm, 'add', 'email', 'b@example.nl'),
		true,
	)
	assert.equal(
		vm.notice,
		'We sent a link to b@example.nl. Follow it to confirm the address.',
	)
	assert.deepEqual(calls, [['add', 'email', 'b@example.nl'], ['read']])
	assert.equal(vm.name, 'Ans de Vries')

	assert.equal(
		await page.methods.act.call(vm, 'prefer', 'email', 'c@example.nl'),
		false,
	)
	assert.equal(vm.error, 'Confirm this address first.')
	assert.equal(vm.notice, '')

	await page.methods.removeAccount.call(vm)
	assert.deepEqual(emitted, ['removed'], 'the shell signs out on `removed`')
})

test('site: the confirmation link is read once, posted, and answered with a sentence', async () => {
	const posted = []
	const api = {
		confirmEmail: async (secret) => {
			posted.push(secret)
			return { ok: secret === 'good' }
		},
	}
	const location = {
		hash: '#confirm-email=good',
		href: 'https://gemeente.example/apps/portaliq/site#confirm-email=good',
	}
	const replaced = []
	const history = {
		replaceState: (_s, _t, url) => {
			replaced.push(url)
			location.hash = ''
		},
	}

	assert.deepEqual(
		await siteE.confirmEmailFromLink({ api, t, location, history }),
		{ role: 'status', text: 'Your e-mail address is confirmed.' },
	)
	assert.deepEqual(replaced, ['https://gemeente.example/apps/portaliq/site'])
	assert.equal(
		await siteE.confirmEmailFromLink({ api, t, location, history }),
		null,
		'read once',
	)
	assert.deepEqual(
		await siteE.confirmEmailFromLink({
			api,
			t,
			location: { hash: '#confirm-email=bad', href: 'x#confirm-email=bad' },
			history: {},
		}),
		{ role: 'alert', text: 'This link is no longer valid.' },
	)
	assert.deepEqual(posted, ['good', 'bad'])
})

test('site: the prompt for an address opens My account and stays away once dismissed', async () => {
	const html = await renderSfc('src/site/components/e/ContactPrompt.vue', { t })
	assert.match(html, /role="status"/)
	assert.match(
		html,
		/Add an e-mail address so we can tell you when something changes\./,
	)
	assert.match(html, /Go to My account/)
	assert.match(html, /Not now/)

	const prompt = await loadSfc('src/site/components/e/ContactPrompt.vue')
	const went = []
	prompt.methods.open.call({ navigate: (key) => went.push(key) })
	assert.deepEqual(went, ['__account__'])

	const store = new Map()
	const session = {
		getItem: (k) => store.get(k) ?? null,
		setItem: (k, v) => store.set(k, v),
	}
	assert.equal(
		await siteE.contactPromptWanted({ contactPrompt: true }, session),
		true,
	)
	assert.equal(
		await siteE.contactPromptWanted({ contactPrompt: false }, session),
		false,
	)
	assert.equal(await siteE.contactPromptWanted(null, session), false)
	dismissPrompt(session)
	assert.equal(
		await siteE.contactPromptWanted({ contactPrompt: true }, session),
		false,
	)
})

test('site: the slice exports its pages by the React section keys, each a lazy chunk', () => {
	assert.deepEqual(Object.keys(siteE.pages).sort(), [
		'__access__',
		'__account__',
		'__cases__',
		'__contacts__',
		'__details__',
		'__plans__',
		'__theme__',
	])
	const index = readFileSync(
		join(ROOT, 'src', 'site', 'pages', 'e', 'index.js'),
		'utf8',
	)
	assert.doesNotMatch(index, /^import /m, 'the entry file imports nothing eagerly')
	assert.match(index, /@typedef \{object\} SitePageProps/)
	const parts = readFileSync(
		join(ROOT, 'src', 'site', 'components', 'e', 'index.js'),
		'utf8',
	)
	for (const name of ['CitizenCase', 'ActingForSwitcher', 'ContactPrompt']) {
		assert.match(
			parts,
			new RegExp(
				`export const ${name} = defineAsyncComponent\\(\\s*\\(\\) => import\\('\\./${name}\\.vue'\\),?\\s*\\)`,
			),
		)
	}
})

test('site: every string slice e uses is in strings.js in both languages, without em-dashes', () => {
	const dirs = [
		['pages', 'e'],
		['components', 'e'],
		['modals', 'e'],
	]
	const sources = dirs
		.flatMap((d) =>
			readdirSync(join(ROOT, 'src', 'site', ...d)).map((f) =>
				join(ROOT, 'src', 'site', ...d, f),
			),
		)
		.filter((f) => !f.endsWith('strings.js'))
		.concat([join(ROOT, 'src', 'shared', 'account.js')])
		.map((f) => readFileSync(f, 'utf8'))
		.join('\n')
	const keys = new Set(
		[...sources.matchAll(/\bt\(\s*'([^']+)'/g)].map((m) => m[1]),
	)
	for (const m of sources.matchAll(
		/(?:return |: |\? |\|\| )'([A-Z][^']+[.?])'/g,
	)) {
		keys.add(m[1])
	}
	assert.ok(keys.size > 80, `found ${keys.size} keys`)
	for (const key of keys) {
		assert.ok(key in siteStrings.nl, `strings.js nl lacks "${key}"`)
		assert.ok(key in siteStrings.en, `strings.js en lacks "${key}"`)
	}
	for (const locale of ['nl', 'en']) {
		for (const [key, text] of Object.entries(siteStrings[locale])) {
			assert.doesNotMatch(text, /—/, `${locale} "${key}" has an em-dash`)
			// Strings added after the React portal retired live only in strings.js,
			// so the shared catalogue, which is bundled into the first-load entry,
			// does not grow with them.
			const react = (locale === 'nl' ? nl : en)[key]
			if (react !== undefined) {
				assert.equal(text, react, `${locale} "${key}" is the React text`)
			}
		}
	}
})

test('the e-mail prompt brings its own alert look, so it is styled outside /mijn too', () => {
	// The Utrecht alert CSS used to arrive with another `/mijn` component, so
	// above the public home page the prompt stood unstyled: a transparent
	// ground and a black border.
	const prompt = readFileSync(
		join(ROOT, 'src', 'site', 'components', 'e', 'ContactPrompt.vue'),
		'utf8',
	)
	assert.match(prompt, /class="pq-contact-prompt utrecht-alert"/)
	assert.match(prompt, /^import '@utrecht\/alert-css\/dist\/index\.css'$/m)

	// Outside `/mijn` the prompt's container is a flex child of `.pq-site`,
	// where auto margins stop the stretch: it gets the full container width.
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(
		app,
		/v-if="session && contactPrompt && !accountRoute"\s+class="container pq-site__contact-prompt"/,
	)
	assert.match(app, /\.pq-site__contact-prompt \{[^}]*width: 100%;/)
})
