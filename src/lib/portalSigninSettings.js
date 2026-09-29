/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Sign-in widget on a portal's page, without the widget
 * (signin-integriq-broker-login T11).
 *
 * Per provider the route residents take: the organisation's own OIDC broker
 * or integriq. The integriq broker needs its start and exchange addresses,
 * the consumer id and a secret; the secret is write-only, so the screen only
 * learns whether one is stored. A broker route without every setting is
 * refused by the server, and this module says so in words.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The GET, the PUT and the URL generator are
 * handed in by `src/widgets/PortalSignin.vue`, so
 * `tests/portal-signin-settings.spec.mjs` runs it as a plain node script.
 *
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */

/**
 * The provider labels residents see on the login buttons.
 */
export const PROVIDER_LABELS = {
	digid: 'DigiD',
	eherkenning: 'eHerkenning',
	eidas: 'eIDAS',
}

/**
 * The body a save sends: the routes as a map, the broker fields, and the
 * secret only when one was typed.
 *
 * @param {Array<{provider: string, route: string}>} providers The rows.
 * @param {object} broker `startUrl`, `exchangeUrl`, `consumerId`.
 * @param {string} secret A newly typed secret, or ''.
 * @return {object}
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
export function saveBody(providers, broker, secret) {
	const routes = {}
	for (const row of providers || []) {
		routes[row.provider] = row.route === 'broker' ? 'broker' : 'oidc'
	}
	const body = {
		routes,
		broker: {
			startUrl: String(broker?.startUrl || '').trim(),
			exchangeUrl: String(broker?.exchangeUrl || '').trim(),
			consumerId: String(broker?.consumerId || '').trim(),
		},
	}
	if (String(secret || '').trim() !== '') {
		body.secret = String(secret).trim()
	}
	return body
}

/**
 * The widget's actions over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {(url: string) => Promise<{data: object}>} deps.get GETs JSON.
 * @param {(url: string, body: object) => Promise<{data: object}>} deps.put PUTs JSON.
 * @param {(path: string, params: object) => string} deps.url Builds an app url.
 * @return {object}
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
export function createPortalSigninSettings({ get, put, url }) {
	return {
		/**
		 * The portal's sign-in settings.
		 *
		 * @param {string} slug The portal slug.
		 * @return {Promise<object>} `{state, settings}`.
		 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
		 */
		async load(slug) {
			if (!slug) {
				return { state: 'error', settings: null }
			}
			try {
				const { data } = await get(
					url('/api/portals/{slug}/signin', { slug }),
				)
				return { state: 'ready', settings: data }
			} catch {
				return { state: 'error', settings: null }
			}
		},

		/**
		 * Save. Answers `saved` with the settings, `incomplete` when a broker
		 * route lacks a setting, or `failed`.
		 *
		 * @param {string} slug The portal slug.
		 * @param {object} body The body from `saveBody()`.
		 * @return {Promise<object>}
		 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
		 */
		async save(slug, body) {
			try {
				const { data } = await put(
					url('/api/portals/{slug}/signin', { slug }),
					body,
				)
				return { outcome: 'saved', settings: data }
			} catch (error) {
				if (error?.response?.data?.error === 'broker_incomplete') {
					return { outcome: 'incomplete' }
				}
				return { outcome: 'failed' }
			}
		},
	}
}
