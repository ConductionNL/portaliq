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
// Shared by the React portal (src/portal) and the Vue site renderer
// (src/site): it imports nothing, so node tests cover the one implementation
// both bundles run.
//
// Auth: the portal session is a bearer minted at the auth edge (`/portal/api/
// session`). The React portal stores it in localStorage (the default below);
// the site keeps it per tab in sessionStorage and hands its own store in. The server derives subjectRef/audience/
// organisation from the bearer — the client never sends them. Every method fails
// closed: a non-2xx or a network error yields an empty/`null` result, never a
// throw the UI has to guard.

const TOKEN_KEY = 'portaliq_token'

/**
 *
 */
export function getToken() {
	try {
		return window.localStorage.getItem(TOKEN_KEY) || null
	} catch (e) {
		return null
	}
}

/**
 *
 * @param token
 */
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

/**
 * Build the adapter bound to a runtime config (`{ apiBase, audience, ... }`).
 * Returned methods read the current token on every call, so a login/logout is
 * picked up without re-creating the adapter.
 *
 * @param {object} config Runtime portal config: `{ apiBase, audience }`.
 * @param {object} [store] Where the bearer lives; localStorage when omitted.
 * @param {() => (string|null)} [store.getToken] Read the bearer.
 * @param {(token: string|null) => void} [store.setToken] Store or forget the bearer.
 * @return {object} The bound portal API adapter.
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
export function createPortalApi(config, store = {}) {
	const base = config.apiBase
	const readToken = store.getToken || getToken
	const writeToken = store.setToken || setToken

	/**
	 * The Authorization header for the current bearer, or none.
	 *
	 * @return {object} The header.
	 */
	function authHeaders() {
		const token = readToken()
		return token ? { Authorization: `Bearer ${token}` } : {}
	}

	// The portal this page is served as. The server applies that portal's
	// hidden case types, not another portal's of the same organisation
	// (operate-show-per-case-type).
	const portalHeader = config.organisationSlug
		? { 'X-Portaliq-Portal': config.organisationSlug }
		: {}

	/**
	 *
	 * @param path
	 */
	async function get(path) {
		const res = await fetch(`${base}${path}`, {
			headers: {
				Accept: 'application/json',
				...portalHeader,
				...authHeaders(),
			},
		})
		if (!res.ok) {
			return null
		}
		return res.json()
	}

	/**
	 *
	 * @param method
	 * @param path
	 * @param body
	 */
	async function send(method, path, body) {
		const res = await fetch(`${base}${path}`, {
			method,
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				...authHeaders(),
			},
			body: JSON.stringify(body || {}),
		})
		if (!res.ok) {
			return { ok: false, status: res.status, object: null }
		}
		const json = await res.json().catch(() => ({}))
		return { ok: true, status: res.status, object: json.object || json }
	}

	/**
	 * Send a write and keep the refusal the server named (identity-profile-page):
	 * `{ ok, status, error, data }`, where `error` is the answer's `error` code.
	 *
	 * @param {string} method The HTTP method.
	 * @param {string} path The path under the portal API.
	 * @param {object} body The JSON body.
	 * @return {Promise<object>} The envelope.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
	 */
	async function answer(method, path, body) {
		try {
			const res = await fetch(`${base}${path}`, {
				method,
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					...authHeaders(),
				},
				body: JSON.stringify(body || {}),
			})
			const data = await res.json().catch(() => ({}))
			return {
				ok: res.ok,
				status: res.status,
				error: res.ok ? '' : String(data?.error || ''),
				data,
			}
		} catch {
			return { ok: false, status: 0, error: '', data: null }
		}
	}

	// The guardian message routes live beside the portal API, not under it
	// (guardian-direct-messages): `/apps/portaliq/api/messages/...`.
	const appRoot = String(base).replace(/\/portal\/api\/?$/, '')

	const col = (register, schema) =>
		`/collections/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`

	// The citizen write surface has its own prefix, because the three acts are
	// governed by the case type rather than by the collection's own opt-ins.
	const citizenCase = (register, schema) =>
		`/citizen/cases/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`

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

		/**
		 * The subject's aggregated, trust-filtered, v3-normalised manifest —
		 * carries the subject's own unread inbox count (portal-inbox-v2 T04).
		 */
		async getContributions() {
			return (
				(await get('/contributions')) || {
					contributions: [],
					unreadCount: 0,
				}
			)
		},

		/**
		 * "My cases" (cases-my-cases-page): every `kind: cases` collection
		 * merged by the server, newest first, each row carrying `_source` and
		 * `_closed`, plus the mandates held. A chosen mandate is sent as
		 * `mandate`; a refusal (409 `group_too_large`) comes back as one, never
		 * as an empty list.
		 *
		 * @param {string} [mandateId] The mandate acted under, or none.
		 * @return {Promise<{ok: boolean, status: number, cases: Array, mandates: Array, activeMandate: object|null, error: string}>}
		 * @spec openspec/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
		 */
		async fetchMyCases(mandateId = '') {
			const query = mandateId
				? `?mandate=${encodeURIComponent(mandateId)}`
				: ''
			try {
				const res = await fetch(`${base}/my-cases${query}`, {
					headers: {
						Accept: 'application/json',
						...portalHeader,
						...authHeaders(),
					},
				})
				const json = await res.json().catch(() => ({}))
				return {
					ok: res.ok,
					status: res.status,
					cases: res.ok && Array.isArray(json?.cases) ? json.cases : [],
					mandates: Array.isArray(json?.mandates) ? json.mandates : [],
					activeMandate: json?.activeMandate || null,
					error: res.ok ? '' : String(json?.error || ''),
				}
			} catch {
				return {
					ok: false,
					status: 0,
					cases: [],
					mandates: [],
					activeMandate: null,
					error: '',
				}
			}
		},

		/**
		 * The unified inbox (portal-inbox-v2 T02): every `kind: inbox`
		 * collection across the subject's contributions, merged, sorted by
		 * `receivedAt` descending, each row carrying a `_source` provenance
		 * tag (`appId`/`label`/`register`/`schema`/`collection`).
		 */
		async fetchInbox() {
			const body = await get('/inbox')
			return body && Array.isArray(body.messages) ? body.messages : []
		},

		/**
		 * The party's open portal tasks (portal-task-delivery): read through
		 * portaliq's bearer-guarded proxy, which mints the server-side
		 * X-Portal-Subject assertion — the browser never calls openregister.
		 *
		 * @return {Promise<{results: Array, total: number}>}
		 */
		async fetchTasks() {
			const body = await get('/tasks')
			return body && Array.isArray(body.results)
				? body
				: { results: [], total: 0 }
		},

		/**
		 * One portal task's detail through the proxy.
		 *
		 * @param {string} uuid The task uuid.
		 * @return {Promise<{ok: boolean, status: number, code: string, task: object|null}>}
		 */
		async fetchTask(uuid) {
			const res = await fetch(`${base}/tasks/${encodeURIComponent(uuid)}`, {
				headers: { Accept: 'application/json', ...authHeaders() },
			})
			const body = await res.json().catch(() => ({}))
			if (!res.ok) {
				return {
					ok: false,
					status: res.status,
					code: body.code || '',
					task: null,
				}
			}
			return { ok: true, status: res.status, code: '', task: body }
		},

		/**
		 * Complete a portal task: comment + files as multipart through the
		 * proxy (never a direct openregister call). The browser leaves the
		 * multipart Content-Type (with its boundary) to fetch().
		 *
		 * @param {string} uuid The task uuid.
		 * @param {{comment?: string, files?: File[]}} payload The completion.
		 * @return {Promise<{ok: boolean, status: number, code: string, body: object}>}
		 */
		async completeTask(uuid, { comment = '', files = [] } = {}) {
			const form = new FormData()
			if (comment) {
				form.append('comment', comment)
			}
			for (const file of files) {
				form.append('files[]', file, file.name)
			}
			let res
			try {
				res = await fetch(
					`${base}/tasks/${encodeURIComponent(uuid)}/complete`,
					{
						method: 'POST',
						headers: { Accept: 'application/json', ...authHeaders() },
						body: form,
					},
				)
			} catch {
				return {
					ok: false,
					status: 0,
					code: 'task-service-unreachable',
					body: {},
				}
			}
			const body = await res.json().catch(() => ({}))
			return { ok: res.ok, status: res.status, code: body.code || '', body }
		},

		/**
		 * Mark ONE inbox message read (portal-inbox-v2 T03). Ownership is
		 * re-verified server-side; the endpoint can only ever set `read` —
		 * this call never sends any other field.
		 *
		 * @param {object} message An inbox row, carrying its `_source` provenance tag.
		 * @return {Promise<object>} `{ ok, status }` result envelope.
		 */
		async markMessageRead(message) {
			const source = message._source || {}
			const id = message.id || message['@self']?.id
			if (!id || !source.register || !source.schema) {
				return { ok: false, status: 0 }
			}
			const path = `/inbox/${encodeURIComponent(source.register)}/${encodeURIComponent(source.schema)}/${encodeURIComponent(id)}/read?collection=${encodeURIComponent(source.collection || '')}`
			return send('PATCH', path, {})
		},

		/**
		 * The guardian's own message threads (guardian-direct-messages). An
		 * answer the server refuses reads as no threads, never as an error.
		 *
		 * @return {Promise<Array<object>>} The threads, or `[]`.
		 */
		async fetchThreads() {
			try {
				const res = await fetch(`${appRoot}/api/messages/threads`, {
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				if (!res.ok) {
					return []
				}
				const json = await res.json()
				return Array.isArray(json) ? json : []
			} catch {
				return []
			}
		},

		/**
		 * One thread's messages, each carrying `translation` when the server
		 * translated it into the reader's language (translated-message-notice).
		 *
		 * @param {string} threadId The thread id.
		 * @return {Promise<Array<object>|null>} The messages, or null when refused.
		 */
		async fetchThreadMessages(threadId) {
			try {
				const res = await fetch(
					`${appRoot}/api/messages/threads/${encodeURIComponent(threadId)}/messages`,
					{
						headers: { Accept: 'application/json', ...authHeaders() },
					},
				)
				if (!res.ok) {
					return null
				}
				const json = await res.json()
				return Array.isArray(json) ? json : null
			} catch {
				return null
			}
		},

		/**
		 * Ask for access to a party's cases (identity-access-requests,
		 * REQ-IAR-001). The server refuses a request without a reason, and
		 * that refusal comes back as its error code rather than as a throw.
		 *
		 * @param {string} onBehalfOf The party whose cases are asked for.
		 * @param {string} reason What the asker needs the access for.
		 * @return {Promise<{ok: boolean, error: string}>} The outcome.
		 *
		 * @spec openspec/specs/portal-access-requests/spec.md#requirement-you-ask-for-access-and-follow-your-request-req-iar-001
		 */
		async requestAccess(onBehalfOf, reason) {
			try {
				const res = await fetch(`${base}/identity/access-requests`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						...authHeaders(),
					},
					body: JSON.stringify({ onBehalfOf, reason }),
				})
				if (res.ok) {
					return { ok: true, error: '' }
				}
				const json = await res.json().catch(() => ({}))
				return { ok: false, error: String(json?.error || res.status) }
			} catch {
				return { ok: false, error: 'network' }
			}
		},

		/**
		 * The resident's own notice choices per kind and channel, with
		 * whether a device is registered for push
		 * (inbox-notifications-and-preferences, REQ-NAP-007).
		 *
		 * @return {Promise<{preferences: object, pushAvailable: boolean}|null>} Null when refused.
		 */
		async fetchNotificationPreferences() {
			try {
				const json = await get('/identity/notification-preferences')
				return json && json.preferences ? json : null
			} catch {
				return null
			}
		},

		/**
		 * Save the resident's own notice choices.
		 *
		 * @param {object} preferences Kind to `{email, push}` booleans.
		 * @return {Promise<{preferences: object, pushAvailable: boolean}|null>} The saved choices, or null.
		 */
		async saveNotificationPreferences(preferences) {
			try {
				const res = await fetch(
					`${base}/identity/notification-preferences`,
					{
						method: 'PATCH',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							...authHeaders(),
						},
						body: JSON.stringify({ preferences }),
					},
				)
				if (!res.ok) {
					return null
				}
				return await res.json()
			} catch {
				return null
			}
		},

		/**
		 * The caller's own registered details: the BRP record for a resident,
		 * the KvK record for a business user (identity-registered-details).
		 * No identifier is sent: the server reads the account behind the
		 * bearer. Any failure reads as an unavailable source, never as an
		 * empty record.
		 *
		 * @return {Promise<object>} `{available: true, kind, person|company, links}` or `{available: false, reason}`.
		 *
		 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-sees-their-own-brp-record-req-ird-001
		 */
		async fetchRegisteredDetails() {
			try {
				const json = await get('/identity/registered-details')
				if (json && typeof json.available === 'boolean') {
					return json
				}
			} catch {
				// Falls through to the unavailable answer.
			}
			return { available: false, reason: 'source_unavailable' }
		},

		/**
		 * The access requests this user made, with the answers they were
		 * given (identity-access-requests, REQ-IAR-001). A refused answer
		 * reads as none, never as an error.
		 *
		 * @return {Promise<Array<object>>} The requests, or `[]`.
		 *
		 * @spec openspec/specs/portal-access-requests/spec.md#requirement-you-ask-for-access-and-follow-your-request-req-iar-001
		 */
		async fetchMyAccessRequests() {
			try {
				const json = await get('/identity/access-requests')
				return Array.isArray(json?.requests) ? json.requests : []
			} catch {
				return []
			}
		},

		/**
		 * The guardian's news feed (news-and-newsletter-authoring), each body in
		 * the reader's `messageLanguage` when the server translated it: such a row
		 * carries `translation` (news-item-translation). A refused answer reads as
		 * no news, never as an error.
		 *
		 * @return {Promise<Array<object>>} The published items, or `[]`.
		 */
		async fetchNewsFeed() {
			try {
				const res = await fetch(`${appRoot}/api/news/feed`, {
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				if (!res.ok) {
					return []
				}
				const json = await res.json()
				return Array.isArray(json) ? json : []
			} catch {
				return []
			}
		},

		/**
		 * The newsletters sent to this guardian (news-and-newsletter-authoring),
		 * newest first, each carrying its `items` translated like the feed's
		 * (news-title-and-newsletter-translation). A refused answer reads as no
		 * newsletters, never as an error.
		 *
		 * @return {Promise<Array<object>>} The newsletters, or `[]`.
		 */
		async fetchNewsletterArchive() {
			try {
				const res = await fetch(`${appRoot}/api/newsletters/archive`, {
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				if (!res.ok) {
					return []
				}
				const json = await res.json()
				return Array.isArray(json) ? json : []
			} catch {
				return []
			}
		},

		/**
		 * The account holder's own details, including `messageLanguage`.
		 *
		 * @return {Promise<object|null>}
		 */
		async getDetails() {
			return get('/identity/details')
		},

		/**
		 * Set the language school messages are shown in; '' shows them as written.
		 *
		 * @param {string} language A language tag, or ''.
		 * @return {Promise<object>} `{ ok, status }` result envelope.
		 */
		async setMessageLanguage(language) {
			return send('PATCH', '/identity/details', { messageLanguage: language })
		},

		/**
		 * Change the account holder's own name (identity-profile-page).
		 *
		 * @param {string} displayName The new name.
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
		 */
		async setDisplayName(displayName) {
			return answer('PATCH', '/identity/details', { displayName })
		},

		/**
		 * Add an e-mail address (it gets a confirmation mail) or a phone number.
		 *
		 * @param {string} kind `email` or `phone`.
		 * @param {string} value The address.
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
		 */
		async addContactAddress(kind, value) {
			return answer('POST', '/identity/addresses', { kind, value })
		},

		/**
		 * Mark an address as the preferred one of its kind.
		 *
		 * @param {string} kind `email` or `phone`.
		 * @param {string} value The address.
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
		 */
		async preferContactAddress(kind, value) {
			return answer('POST', '/identity/addresses/preferred', { kind, value })
		},

		/**
		 * Remove an address.
		 *
		 * @param {string} kind `email` or `phone`.
		 * @param {string} value The address.
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
		 */
		async removeContactAddress(kind, value) {
			return answer('POST', '/identity/addresses/remove', { kind, value })
		},

		/**
		 * Choose how the organisation contacts the account holder.
		 *
		 * @param {string} channel `portal`, `email`, `phone` or `post`.
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
		 */
		async setContactChannel(channel) {
			return answer('PUT', '/identity/contact-channel', { channel })
		},

		/**
		 * Confirm an address with the secret from its mail.
		 *
		 * @param {string} token The secret.
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
		 */
		async confirmEmail(token) {
			return answer('POST', '/identity/email/confirm', { token })
		},

		/**
		 * Remove the account holder's own portal account. The cases stay.
		 *
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T07
		 */
		async removeOwnAccount() {
			return answer('POST', '/identity/remove', {})
		},

		/**
		 * List one collection's objects, subject-scoped. Disambiguated on the
		 * wire with `?collection=<id>` so two collections sharing a register+
		 * schema (a direct view and a scopeClaim/via view) never collide.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @return {Promise<Array<object>>} The collection's objects, or `[]`.
		 */
		async fetchCollection(collection) {
			const body = await get(
				`${col(collection.register, collection.schema)}?collection=${encodeURIComponent(collection.id)}`,
			)
			return body && Array.isArray(body.objects) ? body.objects : []
		},

		/**
		 * Read a single object by id, subject-scoped (portal-scoped-crud, PR #25).
		 * Returns null when the endpoint is absent (pre-#25) or the object is not
		 * the subject's — callers should prefer an already-loaded list row.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} id The object id.
		 * @return {Promise<object|null>} The object, or null when absent/foreign.
		 */
		async fetchObject(collection, id) {
			const body = await get(
				`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}?collection=${encodeURIComponent(collection.id)}`,
			)
			return body ? body.object || body : null
		},

		/**
		 * The declared history of one object the subject owns (portaliq#723):
		 * `{ label, entries }`, the entries exactly as the contributing app
		 * returned them. Null when the collection declares none, the object is
		 * not the subject's, or the history could not be read.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} id The object id.
		 * @return {Promise<object|null>} The timeline, or null.
		 */
		async fetchTimeline(collection, id) {
			const body = await get(
				`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/timeline?collection=${encodeURIComponent(collection.id)}`,
			)
			return body && Array.isArray(body.entries) ? body : null
		},

		/**
		 * The items of one object the subject owns (my-dossiers): `{ label,
		 * items, removeAction }` from the app's `itemList` provider, read only
		 * after the server proved the object is the subject's. Null when the
		 * list could not be read.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} id The object id.
		 * @return {Promise<object|null>} The answer, or null.
		 */
		async fetchItems(collection, id) {
			const body = await get(
				`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/items?collection=${encodeURIComponent(collection.id)}`,
			)
			return body && Array.isArray(body.items) ? body : null
		},

		/**
		 * Create an object via a declared `type: create` action. Only the action's
		 * whitelisted fields are sent; the server stamps ownership.
		 *
		 * The action's id travels as `?actionId=`, so the server writes through
		 * the action whose form was filled in. Two create actions on one schema
		 * (a request and a complaint both writing `ticket`) otherwise fell to
		 * whichever was declared first.
		 *
		 * @param {object} action Manifest action: `{ id, register, schema }`.
		 * @param {object} data The whitelisted field values.
		 * @return {Promise<object>} `{ ok, status, object }` result envelope.
		 *
		 * @spec openspec/changes/create-names-its-action/tasks.md#T2
		 */
		async createObject(action, data) {
			const id = action && typeof action.id === 'string' ? action.id : ''
			const query = id !== '' ? `?actionId=${encodeURIComponent(id)}` : ''
			return send(
				'POST',
				`${col(action.register, action.schema)}${query}`,
				data,
			)
		},

		/**
		 * Update an object via a declared `type: update` action (portal-scoped-crud,
		 * PR #25). Ownership is re-verified server-side; the id is never trusted.
		 *
		 * @param {object} action Manifest action: `{ id, register, schema }`.
		 * @param {string} id The object id.
		 * @param {object} data The whitelisted field values.
		 * @return {Promise<object>} `{ ok, status, object }` result envelope.
		 */
		async updateObject(action, id, data) {
			// Name the action (`?action=`) so the server applies THIS transition's
			// `set`, not just the first update action declared for the schema.
			return send(
				'PATCH',
				`${col(action.register, action.schema)}/${encodeURIComponent(id)}?action=${encodeURIComponent(action.id)}`,
				data,
			)
		},

		/**
		 * Queue a change proposal against an owned record via a declared
		 * `type: propose-change` action (change-proposal-queue,
		 * guardian-self-service-profile). The server re-verifies the record is
		 * the subject's own and re-whitelists against the action's `proposable`
		 * list regardless of what is sent here.
		 *
		 * @param {object} action Manifest action: `{ id, register, schema }`.
		 * @param {string} id The object id the proposal is against.
		 * @param {Array<{property: string, proposedValue: *}>} changes The changed fields only.
		 * @param {string} note What the proposer says about it.
		 * @return {Promise<object>} `{ ok, status, object }` result envelope (`object` is the queued proposal).
		 */
		async proposeChange(action, id, changes, note) {
			return send('POST', '/proposals', {
				register: action.register,
				schema: action.schema,
				id,
				changes,
				note,
			})
		},

		/**
		 * Withdraw a proposal this same subject made, while it is still queued.
		 *
		 * @param {string} id The proposal id.
		 * @return {Promise<object>} `{ ok, status, object }` result envelope.
		 */
		async withdrawProposal(id) {
			return send('POST', `/proposals/${encodeURIComponent(id)}/withdraw`, {})
		},

		/**
		 * Every proposal this subject made, any state — filtered server-side by
		 * the bearer, never by anything the client sends.
		 *
		 * @return {Promise<Array<object>>} The subject's own proposals.
		 */
		async fetchMyProposals() {
			const body = await get('/proposals/mine')
			return body && Array.isArray(body.proposals) ? body.proposals : []
		},

		/**
		 * The citizen's own case, with the writable set that governs it: which
		 * fields are open, which are closed and why, whether documents may
		 * still be added, and the public status label the case app supplied.
		 * The portal renders this and decides nothing itself.
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} id The case id.
		 * @param {string} [mandateId] The mandate the case was listed under, if any.
		 * @return {Promise<object|null>} `{ case, writableSet, documents }` or null.
		 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
		 */
		async fetchCitizenCase(collection, id, mandateId = '') {
			// A case listed under a mandate is read under that mandate
			// (cases-my-cases-page REQ-CMC-004); the server shows it read-only.
			const query = mandateId
				? `?mandate=${encodeURIComponent(mandateId)}`
				: ''
			return get(
				`${citizenCase(collection.register, collection.schema)}/${encodeURIComponent(id)}${query}`,
			)
		},

		/**
		 * Correct answers the citizen already gave. The server judges every
		 * field against the case type again, so a refusal here is the same
		 * refusal the read path described.
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} id The case id.
		 * @param {object} fields The answers to change, by field name.
		 * @return {Promise<object>} `{ ok, status, case }` or `{ ok: false, status, message, error }`.
		 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
		 */
		async amendCitizenCase(collection, id, fields) {
			const res = await fetch(
				`${base}${citizenCase(collection.register, collection.schema)}/${encodeURIComponent(id)}`,
				{
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						...authHeaders(),
					},
					body: JSON.stringify({ fields }),
				},
			)
			const json = await res.json().catch(() => ({}))
			if (!res.ok) {
				return {
					ok: false,
					status: res.status,
					message: json.message || '',
					error: json.error || '',
				}
			}
			return { ok: true, status: res.status, case: json.case || null }
		},

		/**
		 * Open one document the case screen listed. Only the entry id travels:
		 * the server looks it up again among what this case lists, so the
		 * browser never learns where a file lives (cases-documents-on-the-case,
		 * REQ-CDC-002). Saved client-side through a Blob URL, as downloadFile().
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} caseId The case id.
		 * @param {object} entry The listed entry: `{ id, title }`.
		 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }`.
		 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
		 */
		async downloadCitizenDocument(collection, caseId, entry) {
			const url = `${base}${citizenCase(collection.register, collection.schema)}/${encodeURIComponent(caseId)}/documents/${encodeURIComponent(entry.id)}`
			try {
				const res = await fetch(url, { headers: { ...authHeaders() } })
				if (!res.ok) {
					return { ok: false, status: res.status }
				}
				const blob = await res.blob()
				const objectUrl = window.URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = objectUrl
				link.download = entry.title || 'download'
				document.body.appendChild(link)
				link.click()
				setTimeout(() => {
					link.remove()
					window.URL.revokeObjectURL(objectUrl)
				}, 10000)
				return { ok: true }
			} catch {
				return { ok: false, status: 0 }
			}
		},

		/**
		 * Withdraw the citizen's own request. Only the reason travels; the
		 * server decides whether the window is open and which status follows
		 * (case-actions-withdraw-screen, REQ-WDS-002).
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} id The case id.
		 * @param {string} reason Why, or ''.
		 * @return {Promise<object>} `{ ok, status, case, withdrawal }` or `{ ok: false, status, message, error }`.
		 * @spec openspec/specs/citizen-case-withdraw-screen/spec.md#requirement-withdrawing-takes-a-confirmation-with-an-optional-reason-req-wds-002
		 */
		async withdrawCitizenCase(collection, id, reason) {
			const res = await fetch(
				`${base}${citizenCase(collection.register, collection.schema)}/${encodeURIComponent(id)}/withdraw`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						...authHeaders(),
					},
					body: JSON.stringify({ reason }),
				},
			)
			const json = await res.json().catch(() => ({}))
			if (!res.ok) {
				return {
					ok: false,
					status: res.status,
					message: json.message || '',
					error: json.error || '',
				}
			}
			return {
				ok: true,
				status: res.status,
				case: json.case || null,
				withdrawal: json.withdrawal || null,
			}
		},

		/**
		 * Add a document to the running case. Nothing already on the case is
		 * replaced: the server gives a colliding name a suffix.
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} id The case id.
		 * @param {File} file The document to add.
		 * @return {Promise<object>} `{ ok, document }` or `{ ok: false, status, message, error }`.
		 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
		 */
		async addCitizenDocument(collection, id, file) {
			const form = new FormData()
			form.append('file', file)
			const res = await fetch(
				`${base}${citizenCase(collection.register, collection.schema)}/${encodeURIComponent(id)}/documents`,
				{
					method: 'POST',
					headers: { Accept: 'application/json', ...authHeaders() },
					body: form,
				},
			)
			const json = await res.json().catch(() => ({}))
			if (!res.ok) {
				return {
					ok: false,
					status: res.status,
					message: json.message || '',
					error: json.error || '',
				}
			}
			return { ok: true, document: json.document || null }
		},

		/**
		 * Forward a declared endpoint action and return the leaf app's answer
		 * (portal-take-assessment). Portaliq checks the action is the
		 * subject's, stamps any declared `subjectField` and signs the subject
		 * assertion; the status and JSON body come back as the leaf app sent them.
		 *
		 * @param {string} app The contributing app.
		 * @param {string} actionId The endpoint action id.
		 * @param {object} body The request body (only the action's `fields` are forwarded).
		 * @return {Promise<{ok: boolean, status: number, body: object}>} The relayed answer.
		 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-subject-take-a-timed-task
		 */
		async forwardAction(app, actionId, body) {
			try {
				const res = await fetch(
					`${base}/actions/${encodeURIComponent(app)}/${encodeURIComponent(actionId)}`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							...authHeaders(),
						},
						body: JSON.stringify(body || {}),
					},
				)
				const json = await res.json().catch(() => ({}))
				return { ok: res.ok, status: res.status, body: json }
			} catch {
				return { ok: false, status: 0, body: {} }
			}
		},

		/**
		 * Run an endpoint row action for one row the subject owns
		 * (contribution-pay-screen): a guardian pays one contribution. The row
		 * is named only in the path; the server reads it under the collection's
		 * scope and stamps its id itself. `answers` carries what a dialog
		 * collected (a signing consent, a decline reason); the server keeps only
		 * the fields the action declares (case-actions-sign-a-document).
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} rowId The row's id.
		 * @param {string} actionId The endpoint row action's id.
		 * @param {object} [answers] The answers to send, `{}` by default.
		 * @param {string} [actionApp] Another app whose action is attached to this collection.
		 * @return {Promise<object>} `{ ok, status, body }`; `status` 0 on a network error.
		 */
		async forwardRowAction(
			collection,
			rowId,
			actionId,
			answers = {},
			actionApp = '',
		) {
			// `actionApp` names another app's action attached to this
			// collection (woo-journey-entry-points D3).
			const attached = actionApp
				? `&actionApp=${encodeURIComponent(actionApp)}`
				: ''
			try {
				const res = await fetch(
					`${base}${col(collection.register, collection.schema)}/${encodeURIComponent(rowId)}/actions/${encodeURIComponent(actionId)}?collection=${encodeURIComponent(collection.id)}${attached}`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							...authHeaders(),
						},
						body: JSON.stringify(answers || {}),
					},
				)
				const json = await res.json().catch(() => ({}))
				return { ok: res.ok, status: res.status, body: json }
			} catch {
				return { ok: false, status: 0, body: {} }
			}
		},

		/**
		 * Attach a file to an object the subject owns (the file-upload block).
		 * Ownership is re-verified server-side; the collection must declare
		 * `filesUpload`. Sends multipart with field name `file`.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} id The owning object's id.
		 * @param {File} file The file to attach.
		 * @return {Promise<object>} `{ ok, file }` on success, `{ ok: false, status }` otherwise.
		 */
		async uploadFile(collection, id, file) {
			const form = new FormData()
			form.append('file', file)
			const url = `${base}${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/files?collection=${encodeURIComponent(collection.id)}`
			// Retry once on a transient upstream error (502/503/504): a low-worker
			// dev instance can bounce an upload that overlaps another same-object
			// request (e.g. the detail card's file-list read). Idempotent enough
			// for a fresh attach; the server re-verifies ownership either way.
			for (let attempt = 0; attempt < 2; attempt++) {
				try {
					const res = await fetch(url, {
						method: 'POST',
						headers: { Accept: 'application/json', ...authHeaders() },
						body: form,
					})
					if (res.ok) {
						const json = await res.json().catch(() => ({}))
						return { ok: true, file: json.file || json }
					}
					if (
						attempt === 0
						&& (res.status === 502
							|| res.status === 503
							|| res.status === 504)
					) {
						await new Promise((resolve) => setTimeout(resolve, 600))
						continue
					}
					return { ok: false, status: res.status }
				} catch (e) {
					if (attempt === 0) {
						await new Promise((resolve) => setTimeout(resolve, 600))
						continue
					}
					return { ok: false, status: 0 }
				}
			}
			return { ok: false, status: 0 }
		},

		/**
		 * Upload one file into a declared file field of an object the subject
		 * owns (assignment-portal-file-upload). The server proves ownership the
		 * way the named action writes, checks the file against the field's
		 * `accept` and `maxSizeMb`, attaches it and writes the reference into the
		 * field itself. Not retried: a retried append could store the file twice.
		 *
		 * @param {object} action Manifest action: `{ id, register, schema }`.
		 * @param {string} id The owning object's id.
		 * @param {string} field The file field.
		 * @param {File} file The file to upload.
		 * @return {Promise<object>} `{ ok, file, value }` on success, `{ ok: false, status, error }` otherwise.
		 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-the-generic-portal-form-must-render-a-file-field-as-a-file-picker
		 */
		async uploadFieldFile(action, id, field, file) {
			const form = new FormData()
			form.append('file', file)
			const url = `${base}${col(action.register, action.schema)}/${encodeURIComponent(id)}/fields/${encodeURIComponent(field)}?action=${encodeURIComponent(action.id)}`
			try {
				const res = await fetch(url, {
					method: 'POST',
					headers: { Accept: 'application/json', ...authHeaders() },
					body: form,
				})
				const json = await res.json().catch(() => ({}))
				if (!res.ok) {
					return { ok: false, status: res.status, error: json.error || '' }
				}
				return { ok: true, file: json.file || null, value: json.value }
			} catch {
				return { ok: false, status: 0, error: '' }
			}
		},

		/**
		 * Download a file attached to an object the subject owns (the
		 * file-download block, portal-document-download — the read-side
		 * counterpart of `uploadFile`). Ownership + the collection's
		 * `filesDownload` opt-in are re-verified server-side; a foreign/absent
		 * file is an identical 404. Fetched with the bearer auth header (not a
		 * plain `<a href>`, which cannot carry it) and saved client-side via a
		 * Blob object URL.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} id The owning object's id.
		 * @param {object} file The attached file record: `{ id, name }`.
		 * @return {Promise<object>} `{ ok }` on success, `{ ok: false, status }` otherwise.
		 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
		 */
		async downloadFile(collection, id, file) {
			const url = `${base}${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/files/${encodeURIComponent(file.id)}?collection=${encodeURIComponent(collection.id)}`
			try {
				const res = await fetch(url, { headers: { ...authHeaders() } })
				if (!res.ok) {
					return { ok: false, status: res.status }
				}
				const blob = await res.blob()
				const objectUrl = window.URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = objectUrl
				link.download = file.name || 'download'
				document.body.appendChild(link)
				link.click()
				// Defer cleanup: revoking the object URL synchronously after click()
				// can cancel the download before the browser has started streaming it.
				setTimeout(() => {
					link.remove()
					window.URL.revokeObjectURL(objectUrl)
				}, 10000)
				return { ok: true }
			} catch (e) {
				return { ok: false, status: 0 }
			}
		},

		/**
		 * Populate a `collection` optionsProvider: fetch the referenced scoped
		 * collection and map each row to `{ value, label }`. Because it goes
		 * through the subject-scoped endpoint, it can only ever offer values the
		 * subject may already read.
		 *
		 * @param {object} provider optionsProvider: `{ register, schema, valueField, labelField }`.
		 * @return {Promise<Array<object>>} `{ value, label }` pairs.
		 */
		async fetchOptions(provider) {
			const body = await get(`${col(provider.register, provider.schema)}`)
			const rows = body && Array.isArray(body.objects) ? body.objects : []
			return rows
				.map((r) => ({
					value: r[provider.valueField] ?? r.id ?? r['@self']?.id,
					label: r[provider.labelField] ?? r.title ?? r.name ?? r.id,
				}))
				.filter((o) => o.value !== undefined && o.value !== null)
		},

		/**
		 * The branch in effect and the branches a whole-company business
		 * session may choose (signin-eherkenning-branch T06). Any failure
		 * reads as a restricted session with none.
		 *
		 * @return {Promise<{branch: string, restricted: boolean, branches: Array<object>}>}
		 *
		 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
		 */
		async fetchBranches() {
			try {
				const json = await get('/session/branches')
				if (json && Array.isArray(json.branches)) {
					return json
				}
			} catch {
				// Falls through to "none".
			}
			return { branch: '', restricted: true, branches: [] }
		},

		/**
		 * Re-issue the session for one branch, or '' for the whole company,
		 * and keep the new bearer. A refusal keeps the old one.
		 *
		 * @param {string} branch The branch number, or ''.
		 * @return {Promise<{ok: boolean}>} Whether the branch is now in effect.
		 *
		 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
		 */
		async chooseBranch(branch) {
			const res = await fetch(`${base}/session/branch`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					...authHeaders(),
				},
				body: JSON.stringify({ branch: branch || '' }),
			})
			if (!res.ok) {
				return { ok: false }
			}
			const body = await res.json().catch(() => null)
			if (!body || !body.token) {
				return { ok: false }
			}
			writeToken(body.token)
			return { ok: true }
		},

		/**
		 * Rotate the bearer ahead of its natural expiry (portal-session-hardening-v2,
		 * T04): mints a new jti and revokes the old one server-side, capped by the
		 * absolute session lifetime. Fails closed silently — a revoked, expired, or
		 * past-the-cap bearer is simply not rotated; the existing (or absent) token
		 * is left as-is and the next getSession() call surfaces the real state.
		 *
		 * @return {Promise<{token: string, expiresAt: number, hardExpiresAt: number, idleTimeout: number}|null>}
		 *         The answer with when the rotated session ends
		 *         (signin-session-idle-warning-and-sso T02), or null.
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T03
		 */
		async refreshSession() {
			const res = await fetch(`${base}/session/refresh`, {
				method: 'POST',
				headers: { Accept: 'application/json', ...authHeaders() },
			})
			if (!res.ok) {
				return null
			}
			const body = await res.json().catch(() => null)
			if (body && body.token) {
				writeToken(body.token)
				return body
			}
			return null
		},

		/**
		 * Mint a test session via the debug-gated dev-login (404 in prod).
		 *
		 * @param {string} [audience] Session audience; falls back to the config's.
		 * @return {Promise<string|null>} The minted bearer, or null.
		 */
		async devLogin(audience) {
			const res = await fetch(`${base}/session/dev-login`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				body: JSON.stringify({
					audience: audience || config.audience || 'supplier',
				}),
			})
			if (!res.ok) {
				return null
			}
			const body = await res.json().catch(() => null)
			if (body && body.token) {
				writeToken(body.token)
				return body.token
			}
			return null
		},

		/**
		 * End the session server-side (best-effort) and drop the local token.
		 *
		 * @return {Promise<object|null>} The answer, carrying `logoutUrl` when
		 *         the broker offers a sign-out (signin-session-idle-warning-and-sso
		 *         T11), or null when the edge could not be reached.
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T11
		 */
		async logout() {
			let answer = null
			try {
				const res = await fetch(`${base}/session`, {
					method: 'DELETE',
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				answer = res.ok ? await res.json().catch(() => null) : null
			} catch (e) {
				/* best-effort — the token is dropped regardless */
			}
			writeToken(null)
			return answer
		},

		/**
		 * Build the OIDC broker login URL for one of the org's configured
		 * providers (portal-oidc-broker-login). A plain GET redirect — the
		 * caller navigates the WHOLE page to it (`window.location.href =`),
		 * never a `fetch()`, so the broker's own login page renders.
		 *
		 * @param {string} provider The provider preset (`digid`|`eherkenning`|`eidas`|`generic`).
		 * @return {string}
		 */
		oidcStartUrl(provider) {
			const org = config.organisationSlug || ''
			return `${base}/session/oidc/start?org=${encodeURIComponent(org)}&provider=${encodeURIComponent(provider)}`
		},
	}
}

/**
 * Pick up the bearer minted by an OIDC callback redirect (portal-oidc-broker-
 * login): the token travels in the URL FRAGMENT (`#token=...`), never a query
 * string, so it is never sent to the server and never appears in a log.
 * Stores it via `setToken()` and strips the fragment from the visible URL
 * (history.replaceState) so the bearer never lingers in browser history.
 * A no-op (returns false) when there is no `#token=` fragment.
 *
 * @return {boolean} Whether a token was picked up.
 */
export function consumeOidcCallbackFragment() {
	const hash = window.location.hash || ''
	const match = hash.match(/^#token=(.+)$/)
	if (!match) {
		return false
	}

	const token = decodeURIComponent(match[1])
	setToken(token)

	const url = new URL(window.location.href)
	url.hash = ''
	window.history.replaceState(null, '', url.toString())

	return true
}
