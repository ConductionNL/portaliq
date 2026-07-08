// SPDX-License-Identifier: EUPL-1.2
//
// Portal data + auth adapter (Phase 3 — ADR-063 frontend merge).
//
// The single seam between the portal frontend and the SUBJECT-SCOPED
// `/portal/api/*` surface. It mirrors the shape of the tilburg-woo `object.store`
// operations the schema-driven engine needs — fetchCollection / fetchObject /
// createObject / updateObject — but every call goes to Portaliq's per-subject,
// server-authorised endpoints instead of the unscoped `/openregister/api/*`, and
// the response shapes are normalised to plain arrays/objects the renderers use.
//
// Auth: the portal session is a bearer minted at the auth edge (`/portal/api/
// session`), stored in localStorage. The server derives subjectRef/audience/
// organisation from the bearer — the client never sends them. Every method fails
// closed: a non-2xx or a network error yields an empty/`null` result, never a
// throw the UI has to guard.

const TOKEN_KEY = 'portaliq_token'

export function getToken() {
	try {
		return window.localStorage.getItem(TOKEN_KEY) || null
	} catch (e) {
		return null
	}
}

export function setToken(token) {
	try {
		if (token) {
			window.localStorage.setItem(TOKEN_KEY, token)
		} else {
			window.localStorage.removeItem(TOKEN_KEY)
		}
	} catch (e) {
		/* storage unavailable — session simply won't persist */
	}
}

function authHeaders() {
	const token = getToken()
	return token ? { Authorization: `Bearer ${token}` } : {}
}

/**
 * Build the adapter bound to a runtime config (`{ apiBase, audience, ... }`).
 * Returned methods read the current token on every call, so a login/logout is
 * picked up without re-creating the adapter.
 */
export function createPortalApi(config) {
	const base = config.apiBase

	async function get(path) {
		const res = await fetch(`${base}${path}`, {
			headers: { Accept: 'application/json', ...authHeaders() },
		})
		if (!res.ok) {
			return null
		}
		return res.json()
	}

	async function send(method, path, body) {
		const res = await fetch(`${base}${path}`, {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
			body: JSON.stringify(body || {}),
		})
		if (!res.ok) {
			return { ok: false, status: res.status, object: null }
		}
		const json = await res.json().catch(() => ({}))
		return { ok: true, status: res.status, object: json.object || json }
	}

	const col = (register, schema) =>
		`/collections/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`

	return {
		/**
		 * Resolve the current session (fail-closed). Returns the session object
		 * (subjectRef/audience/organisation) or null when not authenticated —
		 * the `/user/me` equivalent for the portal.
		 */
		async getSession() {
			const body = await get('/session')
			return body && body.authenticated ? body : null
		},

		/** The subject's aggregated, trust-filtered, v3-normalised manifest. */
		async getContributions() {
			return (await get('/contributions')) || { contributions: [] }
		},

		/**
		 * List one collection's objects, subject-scoped. Disambiguated on the
		 * wire with `?collection=<id>` so two collections sharing a register+
		 * schema (a direct view and a scopeClaim/via view) never collide.
		 */
		async fetchCollection(collection) {
			const body = await get(`${col(collection.register, collection.schema)}?collection=${encodeURIComponent(collection.id)}`)
			return (body && Array.isArray(body.objects)) ? body.objects : []
		},

		/**
		 * Read a single object by id, subject-scoped (portal-scoped-crud, PR #25).
		 * Returns null when the endpoint is absent (pre-#25) or the object is not
		 * the subject's — callers should prefer an already-loaded list row.
		 */
		async fetchObject(collection, id) {
			const body = await get(`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}?collection=${encodeURIComponent(collection.id)}`)
			return body ? (body.object || body) : null
		},

		/**
		 * Create an object via a declared `type: create` action. Only the action's
		 * whitelisted fields are sent; the server stamps ownership.
		 */
		async createObject(action, data) {
			return send('POST', col(action.register, action.schema), data)
		},

		/**
		 * Update an object via a declared `type: update` action (portal-scoped-crud,
		 * PR #25). Ownership is re-verified server-side; the id is never trusted.
		 */
		async updateObject(action, id, data) {
			return send('PATCH', `${col(action.register, action.schema)}/${encodeURIComponent(id)}`, data)
		},

		/**
		 * Populate a `collection` optionsProvider: fetch the referenced scoped
		 * collection and map each row to `{ value, label }`. Because it goes
		 * through the subject-scoped endpoint, it can only ever offer values the
		 * subject may already read.
		 */
		async fetchOptions(provider) {
			const body = await get(`${col(provider.register, provider.schema)}`)
			const rows = (body && Array.isArray(body.objects)) ? body.objects : []
			return rows
				.map((r) => ({
					value: r[provider.valueField] ?? r.id ?? r['@self']?.id,
					label: r[provider.labelField] ?? r.title ?? r.name ?? r.id,
				}))
				.filter((o) => o.value !== undefined && o.value !== null)
		},

		/** Mint a test session via the debug-gated dev-login (404 in prod). */
		async devLogin(audience) {
			const res = await fetch(`${base}/session/dev-login`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify({ audience: audience || config.audience || 'supplier' }),
			})
			if (!res.ok) {
				return null
			}
			const body = await res.json().catch(() => null)
			if (body && body.token) {
				setToken(body.token)
				return body.token
			}
			return null
		},

		/** End the session server-side (best-effort) and drop the local token. */
		async logout() {
			try {
				await fetch(`${base}/session`, { method: 'DELETE', headers: authHeaders() })
			} catch (e) {
				/* best-effort — the token is dropped regardless */
			}
			setToken(null)
		},
	}
}
