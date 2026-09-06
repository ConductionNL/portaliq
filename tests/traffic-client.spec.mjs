#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-client.spec.mjs — the pure decisions of the traffic client.
//
// Usage:
//   node --test tests/traffic-client.spec.mjs
//
// The client's browser half (listeners, storage, sendBeacon) is exercised
// by the e2e suite against a real page. What lives here is every decision
// that needs no browser: which events may be sent, how a link is
// classified, where the search term is, how a batch is cut, and what the
// script tag tells the client about where to post.

import assert from 'node:assert/strict'
import { existsSync, statSync } from 'node:fs'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	allowedEvent,
	chunk,
	classifyLink,
	dimensionParams,
	envelope,
	errorParams,
	experimentFor,
	fieldIdOf,
	formIdOf,
	heatClickParams,
	KNOWN_EVENTS,
	mayPersist,
	mayRecord,
	optedOut,
	pickVariant,
	randomId,
	readConfig,
	safeSelector,
	scrollDepth,
	scrollPercent,
	searchTermFrom,
	siteRoute,
	variantUrl,
	widthBucket,
} from '../src/traffic/helpers.js'

describe('readConfig', () => {
	it('takes the data attributes the Docusaurus plugin stamps', () => {
		const tag = {
			src: 'https://portaal.example/index.php/apps/portaliq/api/traffic-client.js',
			dataset: {
				origin: 'https://portaal.example/',
				portal: 'demo',
				appPath: '/index.php/apps/portaliq/',
			},
		}
		assert.deepEqual(readConfig(tag, null), {
			origin: 'https://portaal.example',
			appPath: '/index.php/apps/portaliq',
			portal: 'demo',
		})
	})

	it('derives origin and app path from the src when the tag carries no data attributes', () => {
		// The built-in renderer emits the tag through Nextcloud's nonce
		// helper, which takes no attributes; the src is all there is.
		const tag = {
			src: 'https://portaal.example/index.php/apps/portaliq/api/traffic-client.js?v=17',
			dataset: {},
		}
		assert.deepEqual(readConfig(tag, { portal: 'open-tilburg' }), {
			origin: 'https://portaal.example',
			appPath: '/index.php/apps/portaliq',
			portal: 'open-tilburg',
		})
	})

	it('names no portal when neither the tag nor the site config does, so the host resolves it', () => {
		const tag = {
			src: 'https://open-tilburg.nl/index.php/apps/portaliq/api/traffic-client.js',
			dataset: {},
		}
		assert.equal(readConfig(tag, { apiBase: '/x' }).portal, '')
	})

	it('disables itself rather than guess when there is no origin', () => {
		assert.equal(readConfig(null, null), null)
		assert.equal(
			readConfig({ src: '/js/portaliq-traffic.js', dataset: {} }, null),
			null,
		)
	})
})

describe('optedOut', () => {
	it('honours Do Not Track and Global Privacy Control', () => {
		assert.equal(optedOut({ doNotTrack: '1' }), true)
		assert.equal(optedOut({ globalPrivacyControl: true }), true)
		assert.equal(
			optedOut({ doNotTrack: 'unspecified', globalPrivacyControl: false }),
			false,
		)
		assert.equal(optedOut({}), false)
	})
})

describe('allowedEvent', () => {
	const traffic = {
		enabled: true,
		events: ['page_view', 'scroll'],
		consentRequired: false,
	}

	it('sends only what the portal enabled', () => {
		assert.equal(allowedEvent(traffic, 'page_view', false), true)
		assert.equal(allowedEvent(traffic, 'search', false), false)
		assert.equal(allowedEvent(traffic, 'email_open', false), false)
	})

	it('sends nothing for a portal that is not measuring', () => {
		assert.equal(
			allowedEvent(
				{ enabled: false, events: ['page_view'] },
				'page_view',
				true,
			),
			false,
		)
		assert.equal(allowedEvent(null, 'page_view', true), false)
	})

	it('sends only the pre-consent events until consent is given', () => {
		const gated = {
			enabled: true,
			events: ['page_view', 'scroll'],
			consentRequired: true,
			preConsentEvents: ['page_view'],
		}
		assert.equal(allowedEvent(gated, 'page_view', false), true)
		assert.equal(allowedEvent(gated, 'scroll', false), false)
		assert.equal(allowedEvent(gated, 'scroll', true), true)
	})
})

describe('mayPersist', () => {
	it('never persists in the default mode, whatever the consent', () => {
		assert.equal(mayPersist({ persistClientId: false }, true), false)
		assert.equal(mayPersist({}, true), false)
	})

	it('persists only with the switch on and consent given', () => {
		assert.equal(
			mayPersist({ persistClientId: true, consentRequired: true }, false),
			false,
		)
		assert.equal(
			mayPersist({ persistClientId: true, consentRequired: true }, true),
			true,
		)
		assert.equal(
			mayPersist({ persistClientId: true, consentRequired: false }, false),
			true,
		)
	})
})

describe('classifyLink', () => {
	it('is an outbound click when the host differs', () => {
		assert.deepEqual(
			classifyLink('https://www.rijksoverheid.nl/woo', 'open-tilburg.nl'),
			{
				name: 'outbound_click',
				linkUrl: 'https://www.rijksoverheid.nl/woo',
			},
		)
	})

	it('is a download when the path ends in a document extension, on any host', () => {
		assert.deepEqual(
			classifyLink(
				'https://open-tilburg.nl/docs/Besluit%202026.PDF?x=1',
				'open-tilburg.nl',
			),
			{
				name: 'file_download',
				linkUrl: 'https://open-tilburg.nl/docs/Besluit%202026.PDF?x=1',
				fileName: 'Besluit 2026.PDF',
			},
		)
		assert.equal(
			classifyLink('https://elders.example/a.zip', 'open-tilburg.nl').name,
			'file_download',
		)
	})

	it('is nothing for an internal page link or a non-http link', () => {
		assert.equal(
			classifyLink('https://open-tilburg.nl/over-ons', 'open-tilburg.nl'),
			null,
		)
		assert.equal(classifyLink('mailto:info@tilburg.nl', 'open-tilburg.nl'), null)
		assert.equal(classifyLink('', 'open-tilburg.nl'), null)
	})
})

describe('searchTermFrom', () => {
	it('reads q, zoek and search', () => {
		assert.equal(searchTermFrom('https://x.nl/zoeken?q=parkeren'), 'parkeren')
		assert.equal(
			searchTermFrom('https://x.nl/?zoek=woo+verzoek#top'),
			'woo verzoek',
		)
		assert.equal(
			searchTermFrom('https://x.nl/?page=2&search=afval%20kalender'),
			'afval kalender',
		)
	})

	it('is empty without a term', () => {
		assert.equal(searchTermFrom('https://x.nl/zoeken'), '')
		assert.equal(searchTermFrom('https://x.nl/zoeken?q='), '')
		assert.equal(searchTermFrom('https://x.nl/?quality=1'), '')
	})
})

describe('scrollPercent', () => {
	it('is 100 on a page that does not scroll and proportional otherwise', () => {
		assert.equal(scrollPercent(0, 800, 600), 100)
		assert.equal(scrollPercent(450, 500, 1000), 90)
		assert.equal(scrollPercent(0, 500, 1000), 0)
	})
})

describe('batching', () => {
	it('cuts a queue into batches of at most fifty', () => {
		const events = []
		for (let i = 0; i < 120; i++) {
			events.push({ sequence: i })
		}
		const batches = chunk(events)
		assert.deepEqual(
			batches.map((b) => b.length),
			[50, 50, 20],
		)
		assert.equal(batches[2][0].sequence, 100)
	})

	it('builds the envelope the collector reads, naming the portal only when known', () => {
		assert.deepEqual(
			JSON.parse(envelope('demo', true, [{ name: 'page_view' }])),
			{
				consent: true,
				events: [{ name: 'page_view' }],
				portal: 'demo',
			},
		)
		assert.deepEqual(JSON.parse(envelope('', undefined, [])), {
			consent: false,
			events: [],
		})
	})
})

describe('randomId', () => {
	it('is 32 hex characters with or without crypto', () => {
		const fake = { getRandomValues: (bytes) => bytes.fill(171) }
		assert.equal(randomId(fake), 'ab'.repeat(16))
		assert.match(randomId(undefined), /^[0-9a-f]{32}$/)
	})
})

describe('script errors (portal-traffic-reporting)', () => {
	it('keeps the message, the source without its query, the position and a stack hash, never the stack', () => {
		const params = errorParams({
			message: 'boom',
			filename: 'https://portaal.example/js/site.js?v=123&token=secret#x',
			lineno: 12,
			colno: 4,
			error: {
				stack: 'Error: boom\n at https://portaal.example/js/site.js?token=secret:12:4',
			},
		})
		assert.deepEqual(params, {
			message: 'boom',
			source: 'portaal.example/js/site.js',
			line: 12,
			column: 4,
			stackHash: params.stackHash,
		})
		assert.match(params.stackHash, /^[0-9a-f]{8}$/)
		assert.ok(!JSON.stringify(params).includes('secret'))
		assert.equal(
			errorParams({ message: 'boom', filename: '', error: null }).stackHash,
			undefined,
		)
		assert.equal(errorParams({ message: '', filename: 'x.js' }), null)
		assert.equal(errorParams(null), null)
		assert.equal(KNOWN_EVENTS.includes('js_error'), true)
	})
})

describe('form analytics (portal-traffic-outcomes)', () => {
	const form = (attributes, id = '') => ({
		id,
		getAttribute: (name) => attributes[name] || null,
	})

	it('names a form by data-portaliq-form first, then id, name, action', () => {
		assert.equal(
			formIdOf(form({ 'data-portaliq-form': 'aanmelden' }, 'x')),
			'aanmelden',
		)
		assert.equal(formIdOf(form({}, 'contact')), 'contact')
		assert.equal(formIdOf(form({ name: 'n' })), 'n')
		assert.equal(formIdOf(form({ action: '/post' })), '/post')
		assert.equal(formIdOf(form({})), '')
		assert.equal(formIdOf(null), '')
	})

	it('names a field by id or name and never reads its value', () => {
		const field = {
			tagName: 'input',
			type: 'email',
			id: 'email',
			name: 'e',
			value: 'jan@example.org',
		}
		assert.equal(fieldIdOf(field), 'email')
		assert.equal(
			fieldIdOf({ tagName: 'TEXTAREA', name: 'msg', value: 'x' }),
			'msg',
		)
		assert.equal(fieldIdOf({ tagName: 'INPUT', type: 'hidden', id: 'csrf' }), '')
		assert.equal(fieldIdOf({ tagName: 'INPUT', type: 'submit', id: 'go' }), '')
		assert.equal(fieldIdOf({ tagName: 'BUTTON', id: 'b' }), '')
		assert.equal(fieldIdOf(null), '')
	})
})

describe('custom dimensions (portal-traffic-outcomes)', () => {
	const traffic = {
		customDimensions: [
			{ id: 'audience', scope: 'session' },
			{ id: 'lang', scope: 'event' },
		],
	}

	it('attaches cd_<id> for declared ids with a value and nothing for the rest', () => {
		assert.deepEqual(
			dimensionParams(traffic, {
				audience: 'inwoner',
				lang: '',
				secret: 'bsn',
			}),
			{ cd_audience: 'inwoner' },
		)
		assert.deepEqual(dimensionParams(traffic, {}), {})
		assert.deepEqual(dimensionParams({}, { audience: 'x' }), {})
		assert.deepEqual(dimensionParams(null, null), {})
	})

	it('bounds a value to 256 characters and stringifies it', () => {
		const out = dimensionParams(traffic, { lang: 'x'.repeat(300), audience: 7 })
		assert.equal(out.cd_lang.length, 256)
		assert.equal(out.cd_audience, '7')
	})
})

describe('page experiments (portal-traffic-experiments)', () => {
	const traffic = {
		experiments: [
			{
				id: 'draft',
				status: 'draft',
				page: '/',
				variants: [{ id: 'a' }, { id: 'b' }],
			},
			{
				id: 'hero',
				status: 'running',
				page: '/over-ons/',
				variants: [
					{ id: 'a', weight: 1 },
					{ id: 'b', weight: 3 },
				],
			},
		],
	}

	it('reads the in-site route from the route parameter, else the path, without a trailing slash', () => {
		assert.equal(
			siteRoute(
				'http://h/index.php/apps/portaliq/site?portal=x&route=%2Fover-ons%2F',
			),
			'/over-ons',
		)
		assert.equal(
			siteRoute('https://open-tilburg.nl/contact/?utm_source=x'),
			'/contact',
		)
		assert.equal(siteRoute('https://open-tilburg.nl/'), '/')
		assert.equal(siteRoute('https://open-tilburg.nl'), '/')
	})

	it('finds the running experiment on a route and ignores a draft', () => {
		assert.equal(experimentFor(traffic, '/over-ons').id, 'hero')
		assert.equal(experimentFor(traffic, '/'), null, 'a draft is not served')
		assert.equal(experimentFor(traffic, '/contact'), null)
		assert.equal(experimentFor({}, '/'), null)
	})

	it('splits by weight: over many seeds the 3:1 variant gets about three quarters', () => {
		const variants = traffic.experiments[1].variants
		let b = 0
		for (let i = 0; i < 2000; i++) {
			if (pickVariant(variants, 'hero:' + randomId(undefined)).id === 'b') {
				b++
			}
		}
		assert.ok(b > 1350 && b < 1650, 'b got ' + b + ' of 2000')
	})

	it('is sticky: the same seed always lands on the same variant', () => {
		const variants = traffic.experiments[1].variants
		for (let i = 0; i < 50; i++) {
			const seed = 'hero:' + randomId(undefined)
			const first = pickVariant(variants, seed).id
			for (let j = 0; j < 5; j++) {
				assert.equal(pickVariant(variants, seed).id, first)
			}
		}
		assert.equal(pickVariant([], 'x'), null)
	})

	it('builds the variant URL in the shape the location uses', () => {
		assert.equal(
			variantUrl(
				'http://h/index.php/apps/portaliq/site?portal=x&route=%2Fover-ons',
				'/contact',
			),
			'http://h/index.php/apps/portaliq/site?portal=x&route=%2Fcontact',
		)
		assert.equal(
			variantUrl('https://open-tilburg.nl/over-ons?utm_source=x', '/contact'),
			'https://open-tilburg.nl/contact?utm_source=x',
		)
		assert.equal(
			variantUrl('https://open-tilburg.nl/over-ons', '/contact'),
			'https://open-tilburg.nl/contact',
		)
	})
})

describe('heatmaps and recording (portal-traffic-experiments)', () => {
	it('records a click as fractions, a width bucket, a tag and a safe selector', () => {
		const target = {
			nodeType: 1,
			tagName: 'BUTTON',
			className: 'cta btn-7 primary',
			parentNode: {
				nodeType: 1,
				tagName: 'FORM',
				className: '',
				id: 'bsn-123',
				parentNode: {
					nodeType: 1,
					tagName: 'MAIN',
					className: 'content',
					parentNode: { nodeType: 1, tagName: 'BODY', parentNode: null },
				},
			},
		}
		assert.deepEqual(
			heatClickParams(
				{ pageX: 250, pageY: 400, target },
				{ width: 1000, height: 2000, viewport: 1300 },
			),
			{
				x: 0.25,
				y: 0.2,
				vw: 1600,
				tag: 'button',
				selector: 'main.content > form > button.cta.primary',
			},
		)
		assert.equal(
			heatClickParams(
				{ pageX: 1200, pageY: 1, target },
				{ width: 1000, height: 2000, viewport: 800 },
			),
			null,
			'off the page',
		)
		assert.equal(heatClickParams(null, null), null)
	})

	it('never puts an id or an attribute into a selector', () => {
		const selector = safeSelector({
			nodeType: 1,
			tagName: 'A',
			id: 'jan-jansen',
			className: 'x1',
			parentNode: null,
		})
		assert.equal(selector, 'a')
		assert.equal(safeSelector(null), '')
	})

	it('buckets a width and computes a scroll depth', () => {
		assert.equal(widthBucket(320), 480)
		assert.equal(widthBucket(1024), 1024)
		assert.equal(widthBucket(2560), 0)
		assert.equal(scrollDepth(0, 500, 2000), 0.25)
		assert.equal(scrollDepth(1500, 500, 2000), 1)
		assert.equal(scrollDepth(0, 500, 0), 1)
	})

	it('records only when the switch is on, on a site this app serves, and with consent where required', () => {
		const on = {
			enabled: true,
			kind: 'site',
			sensitive: { sessionRecording: true },
		}
		assert.equal(mayRecord(on, false), true)
		assert.equal(mayRecord({ ...on, consentRequired: true }, false), false)
		assert.equal(mayRecord({ ...on, consentRequired: true }, true), true)
		assert.equal(mayRecord({ ...on, kind: 'external' }, true), false)
		assert.equal(
			mayRecord({ ...on, sensitive: { sessionRecording: 'true' } }, true),
			false,
		)
		assert.equal(mayRecord({ ...on, enabled: false }, true), false)
		assert.equal(mayRecord(null, true), false)
	})
})

describe('the built files (when built)', () => {
	const here = fileURLToPath(new URL('.', import.meta.url))

	it('holds the client under 20 KB and the recorder under 30 KB', () => {
		const client = here + '../js/portaliq-traffic.js'
		const recorder = here + '../js/portaliq-traffic-recorder.js'
		if (!existsSync(client) || !existsSync(recorder)) {
			return
		}
		assert.ok(
			statSync(client).size <= 20 * 1024,
			'client is ' + statSync(client).size + ' bytes',
		)
		assert.ok(
			statSync(recorder).size <= 30 * 1024,
			'recorder is ' + statSync(recorder).size + ' bytes',
		)
	})
})
