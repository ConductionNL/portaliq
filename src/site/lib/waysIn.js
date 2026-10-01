// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The ways into the site for a visitor without an account
// (identity-ways-in-screens): the mailed links' fragments, the proof of work
// the registration form solves in the browser, the identity routes, and the
// sentence for each outcome. Framework-free: tests/ways-in-screens.spec.mjs
// imports it in node, and every network call takes its `fetch` as a
// parameter.

import strings from './waysInStrings.js'

const LINK = /^#(activate|invitation|reference)=([^&]+)$/

/**
 * The translator the ways in use: the site's own `t` first, then
 * waysInStrings.js for a key the site's bundle does not carry yet, as the
 * site's slices do (pages/collections/translate.js).
 *
 * @param {Function} t The site's translator.
 * @param {string} locale The page language.
 * @return {Function} `(key, vars) => text`.
 *
 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T08
 */
export function waysInTranslator(t, locale) {
	const lang = String(locale || 'nl')
		.toLowerCase()
		.split(/[-_]/)[0]
	const own = strings[lang] || strings.en
	return function translate(key, vars) {
		const site = typeof t === 'function' ? t(key) : key
		let text = site !== key || !Object.hasOwn(own, key) ? site : own[key]
		for (const [name, value] of Object.entries(vars || {})) {
			text = String(text).split(`{${name}}`).join(String(value))
		}
		return text
	}
}

/**
 * The doors the site config opens, closed when absent (REQ-IWI-005).
 *
 * @param {object} signin The site config's `signin` block.
 * @return {{register: boolean, reference: boolean, emailSignIn: string, referenceCaseTypes: Array<object>}}
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-the-sign-in-screen-shows-only-the-doors-that-lead-somewhere-req-iwi-005
 */
export function waysInFrom(signin) {
	const ways = (signin && signin.waysIn) || {}
	const types = Array.isArray(ways.referenceCaseTypes)
		? ways.referenceCaseTypes.filter(
				(type) =>
					type
					&& typeof type.register === 'string'
					&& typeof type.schema === 'string'
					&& typeof type.caseType === 'string',
			)
		: []
	return {
		register: ways.register === true,
		reference: ways.reference === true && types.length > 0,
		emailSignIn: typeof ways.emailSignIn === 'string' ? ways.emailSignIn : '',
		referenceCaseTypes: types,
	}
}

/**
 * Read a mailed link's secret once and strip it from the address bar:
 * `#activate=`, `#invitation=` or `#reference=`. A fragment never reaches a
 * server access log.
 *
 * @param {Location|object} location The window location.
 * @param {History|object} history The window history.
 * @return {{kind: string, token: string}|null} The link, or null when the page was not opened from one.
 *
 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T06
 */
export function takeWayInLink(location, history) {
	const match = String((location && location.hash) || '').match(LINK)
	if (!match) {
		return null
	}

	const url = String(location.href || '').replace(/#.*$/, '')
	if (history && typeof history.replaceState === 'function') {
		history.replaceState(null, '', url)
	}
	return { kind: match[1], token: decodeURIComponent(match[2]) }
}

/**
 * Whether the address carries a mailed link, without consuming it.
 *
 * @param {Location|object} location The window location.
 * @return {boolean}
 */
export function hasWayInLink(location) {
	return LINK.test(String((location && location.hash) || ''))
}

/**
 * How many leading zero bits a digest carries.
 *
 * @param {Uint8Array} bytes The digest.
 * @return {number}
 */
export function leadingZeroBits(bytes) {
	let bits = 0
	for (const byte of bytes) {
		if (byte === 0) {
			bits += 8
			continue
		}
		return bits + Math.clz32(byte) - 24
	}
	return bits
}

/**
 * Solve the portal's challenge: the first counter whose
 * `sha256(nonce + ':' + counter)` carries `difficulty` leading zero bits, the
 * check PortalChallengeService::solves() makes on the server.
 *
 * @param {string} nonce The issued nonce.
 * @param {number} difficulty The leading zero bits required.
 * @param {SubtleCrypto} subtle The browser's digest.
 * @return {Promise<string>} The solution, or '' when none was found in reach.
 *
 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T02
 */
export async function solveChallenge(
	nonce,
	difficulty,
	subtle = globalThis.crypto?.subtle,
) {
	if (!subtle || !nonce) {
		return ''
	}

	const encoder = new TextEncoder()
	const limit = 2 ** Math.min(30, Math.max(1, difficulty) + 8)
	for (let counter = 0; counter < limit; counter++) {
		const digest = new Uint8Array(
			await subtle.digest('SHA-256', encoder.encode(`${nonce}:${counter}`)),
		)
		if (leadingZeroBits(digest) >= difficulty) {
			return String(counter)
		}
	}
	return ''
}

/**
 * The sentence for a refusal the ways-in routes answer, as an English
 * source key.
 *
 * @param {string} code The refusal code.
 * @return {string} The sentence key.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */
export function wayInRefusalText(code) {
	const texts = {
		registration_off: 'This portal does not take new accounts.',
		domain_not_allowed:
			'This portal takes accounts for some e-mail domains only, and yours is not one of them.',
		invalid_email: 'Check the address and try again.',
		challenge_failed:
			'The check against automated sign-ups did not pass. Try again.',
		activation_not_valid: 'This link is no longer valid.',
		link_not_valid: 'This link is no longer valid.',
		invitation_not_valid: 'This invitation is no longer valid.',
		route_not_offered:
			'Cases of this kind cannot be followed with a case number.',
	}
	return texts[code] || 'That did not work. Try again later.'
}

/**
 * What a visitor reads after a registration was accepted.
 *
 * @param {string} awaiting The policy the server answered: `activation` or `approval`.
 * @return {string} The sentence key.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */
export function registrationOutcomeText(awaiting) {
	if (awaiting === 'activation') {
		return 'We sent you an e-mail. Follow the link in it to activate your account.'
	}
	return 'We will let you know when your account is ready.'
}

/**
 * The sentence after an activation or an accepted invitation (design D5).
 *
 * @param {Function} t The translator.
 * @param {string} emailSignIn The label of the e-mail sign-in, or ''.
 * @return {string}
 */
export function readyText(t, emailSignIn) {
	return emailSignIn
		? t('Your account is ready. Sign in with {provider}.', {
				provider: emailSignIn,
			})
		: t('Your account is ready. You can sign in now.')
}

/**
 * The fields of a case a reference session may show: the projected ones,
 * without the record's own bookkeeping.
 *
 * @param {object} record The case as the case app projects it.
 * @return {Array<{key: string, value: string}>}
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-a-case-number-and-an-e-mail-address-open-one-case-req-iwi-003
 */
export function referenceCaseFields(record) {
	return Object.entries(record || {})
		.filter(
			([key]) =>
				!key.startsWith('@')
				&& !key.startsWith('_')
				&& key !== 'id'
				&& key !== 'uuid',
		)
		.map(([key, value]) => ({ key, value: shown(value) }))
}

/**
 * One value as a line of text.
 *
 * @param {*} value The value.
 * @return {string}
 */
function shown(value) {
	if (Array.isArray(value)) {
		return value.map(shown).join(', ')
	}
	if (value !== null && typeof value === 'object') {
		return Object.values(value)
			.filter((part) => part === null || typeof part !== 'object')
			.map((part) => String(part ?? ''))
			.join(' ')
	}
	return String(value ?? '')
}

/**
 * POST a JSON body and read the answer as `{ok, status, error, data}`.
 *
 * @param {string} url The route.
 * @param {object} body The body.
 * @param {Function} fetchImpl `fetch`.
 * @return {Promise<object>}
 */
async function post(url, body, fetchImpl) {
	try {
		const response = await fetchImpl(url, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(body),
		})
		const data = await response.json().catch(() => ({}))
		return {
			ok: response.ok,
			status: response.status,
			error: (data && data.error) || '',
			data: data || {},
		}
	} catch {
		return { ok: false, status: 0, error: '', data: {} }
	}
}

/**
 * The identity routes for one portal.
 *
 * @param {string} authBase The portal API base (`.../portal/api`).
 * @param {string} portal The serving portal's slug, or ''.
 * @param {Function} fetchImpl `fetch`, replaceable in tests.
 * @return {object} The calls.
 *
 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T02
 */
export function waysInApi(
	authBase,
	portal,
	fetchImpl = (...args) => fetch(...args),
) {
	return {
		async challenge(surface) {
			const query = new URLSearchParams({ surface, portal: portal || '' })
			try {
				const response = await fetchImpl(
					`${authBase}/identity/challenge?${query.toString()}`,
					{
						headers: { Accept: 'application/json' },
					},
				)
				return response.ok ? await response.json() : null
			} catch {
				return null
			}
		},
		registerAccount({
			email,
			displayName = '',
			challenge = {},
			solution = '',
			honeypot = null,
		}) {
			const body = {
				portal: portal || '',
				email,
				displayName,
				nonce: challenge.nonce || '',
				expiresAt: challenge.expiresAt || 0,
				signature: challenge.signature || '',
				solution,
			}
			if (honeypot && honeypot.field) {
				body[honeypot.field] = honeypot.value || ''
			}
			return post(`${authBase}/identity/register`, body, fetchImpl)
		},
		activateAccount(token) {
			return post(`${authBase}/identity/activate`, { token }, fetchImpl)
		},
		requestReferenceLink({ register, schema, caseType, caseReference, email }) {
			return post(
				`${authBase}/identity/reference-link`,
				{
					portal: portal || '',
					register,
					schema,
					caseType,
					caseReference,
					email,
				},
				fetchImpl,
			)
		},
		redeemReferenceLink(token) {
			return post(
				`${authBase}/identity/reference-link/redeem`,
				{ token },
				fetchImpl,
			)
		},
		async referenceCase(bearer) {
			try {
				const response = await fetchImpl(
					`${authBase}/identity/reference-case`,
					{
						headers: {
							Accept: 'application/json',
							Authorization: `Bearer ${bearer}`,
						},
					},
				)
				return response.ok ? await response.json() : null
			} catch {
				return null
			}
		},
		acceptInvitation(token) {
			return post(
				`${authBase}/identity/invitation/accept`,
				{ token },
				fetchImpl,
			)
		},
	}
}
