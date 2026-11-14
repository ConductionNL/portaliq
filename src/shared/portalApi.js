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
// Written for the retired React portal and used by the Vue site renderer
// (src/site) and the embed frame: it imports nothing, so node tests cover the
// one implementation the bundles run.
//
// Auth: the portal session is a bearer minted at the auth edge (`/portal/api/
// session`). The default store below is the React portal's localStorage key;
// the site keeps the bearer per tab in sessionStorage and hands its own store
// in (src/site/lib/authApi.js, which also takes over an old localStorage
// bearer once). The server derives subjectRef/audience/
// organisation from the bearer — the client never sends them. Every method fails
// closed: a non-2xx or a network error yields an empty/`null` result, never a
// throw the UI has to guard.

const TOKEN_KEY = 'portaliq_token'

/**
 * The header that carries a send's submission key, or none. The same rule as
 * `submissionKey.js`, kept here because this module imports nothing
 * (action-forward-survives-a-slow-answer REQ-AFS-001).
 *
 * @param {{submissionKey?: string}|undefined} options The send's options.
 * @return {Record<string, string>} The header to add.
 * @spec openspec/changes/action-forward-survives-a-slow-answer/specs/portal-contribution-contract/spec.md#requirement-a-repeated-submission-carries-the-same-key-req-afs-001
 */
function submissionHeaders(options) {
	const key = options && options.submissionKey
	return typeof key === 'string' && /^[A-Za-z0-9_-]{16,128}$/.test(key)
		? { 'Idempotency-Key': key }
		: {}
}

/**
 * A refused write, `{ ok: false, status, object, error, errors, invalid }`.
 * A refusal may name fields, e.g. a required field left empty
 * (site-multi-step-forms REQ-SMF-024), and the values the store refused by
 * field and kind (site-action-forms).
 *
 * @param {number} status The answer's status.
 * @param {object} refusal The answer's body.
 * @return {object} The refusal.
 */
function writeRefusal(status, refusal) {
	const objectOr = (value) => (value && typeof value === 'object' ? value : {})
	return {
		ok: false,
		status,
		object: null,
		error: typeof refusal?.error === 'string' ? refusal.error : '',
		errors: objectOr(refusal?.errors),
		invalid: objectOr(refusal?.invalid),
	}
}

/**
 *
 */
export function getToken() {
	try {
		return window.localStorage.getItem(TOKEN_KEY) || null
	} catch {
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
	} catch {
		/* storage unavailable — session simply won't persist */
	}
}

/**
 * The multipart body of a row action that carries files, or null without files.
 *
 * Each answer is a form field; an object answer (the row inputs under their
 * `into` key) becomes `key[name]` fields, which the server reads back as the
 * same object. Each file is a part under `<field>[]`.
 *
 * @param {object} answers The answers.
 * @param {{field: string, files: Array<File>}|null} upload The declared field and the chosen files.
 * @return {FormData|null}
 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
 */
export function rowActionFormData(answers, upload) {
	if (!hasFiles(upload)) {
		return null
	}
	const form = new FormData()
	for (const entry of Object.entries(answers || {})) {
		appendAnswer(form, entry)
	}
	for (const file of upload.files) {
		form.append(`${upload.field}[]`, file, file.name)
	}
	return form
}

/**
 * Whether an upload names its field and carries at least one file.
 *
 * @param {{field: string, files: Array<File>}|null} upload The upload.
 * @return {boolean} True with files.
 */
function hasFiles(upload) {
	return Boolean(upload && upload.field && upload.files && upload.files.length > 0)
}

/**
 * One answer as form fields: an object answer as `key[name]` fields, any
 * other value as one field, nothing for null or undefined.
 *
 * @param {FormData} form The body, appended to.
 * @param {Array} entry The answer's key and value.
 * @return {void}
 */
function appendAnswer(form, entry) {
	const key = entry[0]
	const value = entry[1]
	if (value !== null && typeof value === 'object') {
		appendObjectAnswer(form, entry)
		return
	}
	if (value !== undefined && value !== null) {
		form.append(key, String(value))
	}
}

/**
 * An object answer as `key[name]` fields.
 *
 * @param {FormData} form The body, appended to.
 * @param {Array} entry The answer's key and its object value.
 * @return {void}
 */
function appendObjectAnswer(form, entry) {
	for (const [name, inner] of Object.entries(entry[1])) {
		form.append(`${entry[0]}[${name}]`, String(inner))
	}
}

/**
 * Save a Blob URL under a name by clicking a link to it. The cleanup is
 * deferred: revoking the object URL synchronously after click() can cancel
 * the download before the browser has started streaming it.
 *
 * @param {string} objectUrl The Blob URL.
 * @param {string} name The name to save it under.
 * @return {void}
 */
function clickDownload(objectUrl, name) {
	const link = document.createElement('a')
	link.href = objectUrl
	link.download = name
	document.body.appendChild(link)
	link.click()
	setTimeout(() => {
		link.remove()
		window.URL.revokeObjectURL(objectUrl)
	}, 10000)
}

/**
 * The "my cases" answer as the pages read it: the cases only when the
 * answer is ok, the mandates and the one acted under, and the refusal.
 *
 * @param {{ok: boolean, status: number}} res The response.
 * @param {object} json Its body, `{}` when it was not JSON.
 * @return {{ok: boolean, status: number, cases: Array, mandates: Array, activeMandate: object|null, error: string}} The answer.
 */
function myCasesAnswer(res, json) {
	return {
		ok: res.ok,
		status: res.status,
		cases: res.ok ? listIn(json, 'cases') : [],
		mandates: listIn(json, 'mandates'),
		activeMandate: json?.activeMandate || null,
		error: res.ok ? '' : String(json?.error || ''),
	}
}

/**
 * Where one of the resident's inbox messages lives, with an action suffix
 * (`/read`, `/reply`, or none), or null when the message does not name its
 * id, register and schema.
 *
 * @param {object} message An inbox row, carrying its `_source` provenance tag.
 * @param {string} suffix The action's path suffix.
 * @return {string|null} The path.
 */
function inboxMessagePath(message, suffix) {
	const source = message._source || {}
	const id = inboxIdOf(message)
	if (!id || !source.register || !source.schema) {
		return null
	}
	return `/inbox/${encodeURIComponent(source.register)}/${encodeURIComponent(source.schema)}/${encodeURIComponent(id)}${suffix}?collection=${encodeURIComponent(source.collection || '')}`
}

/**
 * An inbox message's id, wherever the row keeps it.
 *
 * @param {object} message An inbox row.
 * @return {string|undefined} The id.
 */
function inboxIdOf(message) {
	return message.id || message['@self']?.id
}

/**
 * The bearer a session answer issues, or '' when it issues none.
 *
 * @param {{json: () => Promise<object>}} res The response.
 * @return {Promise<string>} The bearer.
 */
async function issuedToken(res) {
	const body = await res.json().catch(() => null)
	return body && body.token ? body.token : ''
}

/**
 * The dev-login request: JSON, for one audience, without a bearer.
 *
 * @param {string} audience The session audience.
 * @return {object} The request init.
 */
function devLoginRequest(audience) {
	return {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: JSON.stringify({ audience }),
	}
}

/**
 * A list an answer's body carries under a key, or none.
 *
 * @param {object|null} json The body.
 * @param {string} key The key.
 * @return {Array} The list.
 */
function listIn(json, key) {
	return Array.isArray(json?.[key]) ? json[key] : []
}

/**
 * A task completion as multipart: the comment when there is one, and each
 * file.
 *
 * @param {string} comment The comment, or ''.
 * @param {Array<File>} files The files.
 * @return {FormData} The body.
 */
function completionForm(comment, files) {
	const form = new FormData()
	if (comment) {
		form.append('comment', comment)
	}
	for (const file of files) {
		form.append('files[]', file, file.name)
	}
	return form
}

/**
 * The request that posts a task completion; the browser sets the multipart
 * Content-Type (with its boundary) itself.
 *
 * @param {FormData} form The completion.
 * @param {object} auth The Authorization header, or none.
 * @return {object} The request init.
 */
function completionRequest(form, auth) {
	return {
		method: 'POST',
		headers: { Accept: 'application/json', ...auth },
		body: form,
	}
}

/**
 * What a task completion answered, with its body (`{}` when it was not
 * JSON).
 *
 * @param {{ok: boolean, status: number, json: () => Promise<object>}} res The response.
 * @return {Promise<{ok: boolean, status: number, code: string, body: object}>} The outcome.
 */
async function completionAnswer(res) {
	const body = await res.json().catch(() => ({}))
	return { ok: res.ok, status: res.status, code: body.code || '', body }
}

/**
 * A task completion that never reached the task service.
 *
 * @return {{ok: false, status: 0, code: string, body: object}} The outcome.
 */
function unreachableTask() {
	return {
		ok: false,
		status: 0,
		code: 'task-service-unreachable',
		body: {},
	}
}

/**
 * The threads a read answered, or `failed` for a refusal or a body that is
 * not a list.
 *
 * @param {{ok: boolean, json: () => Promise<unknown>}} res The response.
 * @param {Array|null} failed The answer for a failed read.
 * @return {Promise<Array<object>|null>} The threads.
 */
async function threadsIn(res, failed) {
	if (!res.ok) {
		return failed
	}
	const json = await res.json()
	return Array.isArray(json) ? json : failed
}

/**
 * The objects a collection read answered: its list, else none (null with
 * `orNull`).
 *
 * @param {object|null|undefined} body The read's body.
 * @param {boolean} orNull Answer null instead of none.
 * @return {Array<object>|null} The objects.
 */
function objectsIn(body, orNull) {
	if (body && Array.isArray(body.objects)) {
		return body.objects
	}
	return orNull ? null : []
}

/**
 * One option of a `collection` options provider: the row's value and label,
 * and its description when the provider names a `descriptionField`.
 *
 * @param {object} row The row.
 * @param {object} provider The provider.
 * @return {{value: unknown, label: unknown, description?: string}} The option.
 * @spec openspec/changes/guardian-pages-follow-the-wilgenboom-boards/specs/site-forms/spec.md#requirement-a-choice-card-may-carry-a-second-line
 */
export function optionOf(row, provider) {
	const option = { value: optionValue(row, provider), label: optionLabel(row, provider) }
	const description = descriptionOf(row, provider.descriptionField)
	return description ? { ...option, description } : option
}

/**
 * An option's value: the provider's value field, else the row's id.
 *
 * @param {object} row The row.
 * @param {object} provider The provider.
 * @return {unknown}
 */
function optionValue(row, provider) {
	return row[provider.valueField] ?? row.id ?? row['@self']?.id
}

/**
 * An option's label: the provider's label field, else a title, name or id.
 *
 * @param {object} row The row.
 * @param {object} provider The provider.
 * @return {unknown}
 */
function optionLabel(row, provider) {
	return row[provider.labelField] ?? row.title ?? row.name ?? row.id
}

/**
 * A row's description: the named field's text, trimmed, or ''.
 *
 * @param {object} row The row.
 * @param {string|undefined} field The provider's `descriptionField`.
 * @return {string}
 */
function descriptionOf(row, field) {
	const value = field ? row[field] : ''
	return typeof value === 'string' ? value.trim() : ''
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
	const portalHeader = {
		...(config.organisationSlug
			? { 'X-Portaliq-Portal': config.organisationSlug }
			: {}),
		// The site's own language, so a contributing app answers a Dutch site
		// in Dutch whatever the visitor's browser prefers
		// (contribution-record-page).
		...(config.language ? { 'Accept-Language': config.language } : {}),
	}

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
		const res = await fetch(`${base}${path}`, jsonInit(method, body || {}))
		if (!res.ok) {
			return writeRefusal(res.status, await res.json().catch(() => ({})))
		}
		const json = await res.json().catch(() => ({}))
		return { ok: true, status: res.status, object: json.object || json }
	}

	/**
	 * The init of a JSON request with the bearer.
	 *
	 * @param {string} method The HTTP method.
	 * @param {object|undefined} body The body, sent as JSON.
	 * @return {object} The init.
	 */
	function jsonInit(method, body) {
		return {
			method,
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				...authHeaders(),
			},
			body: JSON.stringify(body),
		}
	}

	/**
	 * A refused write citizen case call, `{ ok: false, status, message, error }`.
	 *
	 * @param {number} status The answer's status.
	 * @param {object} json The answer's body.
	 * @return {object} The refusal.
	 */
	function caseRefusal(status, json) {
		return {
			ok: false,
			status,
			message: json.message || '',
			error: json.error || '',
		}
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
			const res = await fetch(`${base}${path}`, jsonInit(method, body || {}))
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

	/**
	 * One multipart attach of a file: its answer, and whether a second try
	 * may help (a 502, 503 or 504 bounce, or a network error).
	 *
	 * @param {string} url The attach address.
	 * @param {FormData} form The file, as `file`.
	 * @return {Promise<{retry: boolean, result: object}>} The outcome.
	 */
	async function attachOnce(url, form) {
		try {
			const res = await fetch(url, {
				method: 'POST',
				headers: { Accept: 'application/json', ...authHeaders() },
				body: form,
			})
			if (res.ok) {
				const json = await res.json().catch(() => ({}))
				return { retry: false, result: { ok: true, file: json.file || json } }
			}
			return {
				retry: [502, 503, 504].includes(res.status),
				result: { ok: false, status: res.status },
			}
		} catch {
			return { retry: true, result: { ok: false, status: 0 } }
		}
	}

	// The guardian message routes live beside the portal API, not under it
	// (guardian-direct-messages): `/apps/portaliq/api/messages/...`.
	const appRoot = String(base).replace(/\/portal\/api\/?$/, '')

	const draftUrl = (app, actionId) =>
		`${base}/drafts/${encodeURIComponent(app)}/${encodeURIComponent(actionId)}`

	const col = (register, schema) =>
		`/collections/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`

	// A case listed under a mandate is read under that mandate
	// (cases-my-cases-page REQ-CMC-004); '' without one.
	const mandateQuery = (mandateId) =>
		mandateId ? `&mandate=${encodeURIComponent(mandateId)}` : ''

	// The citizen write surface has its own prefix, because the three acts are
	// governed by the case type rather than by the collection's own opt-ins.
	const citizenCase = (register, schema) =>
		`/citizen/cases/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`

	/**
	 * The address of one of the resident's own cases.
	 *
	 * @param {object} collection Manifest collection: `{ register, schema }`.
	 * @param {string} id The case id.
	 * @return {string} The address.
	 */
	function citizenCaseUrl(collection, id) {
		return `${base}${citizenCase(collection.register, collection.schema)}/${encodeURIComponent(id)}`
	}

	/**
	 * The address of an endpoint row action on one row. `actionApp` names
	 * another app's action attached to this collection
	 * (woo-journey-entry-points D3).
	 *
	 * @param {object} collection Manifest collection: `{ id, register, schema }`.
	 * @param {{rowId: string, actionId: string, actionApp: string}} target The row, the action and the app it belongs to, or ''.
	 * @return {string} The address.
	 */
	function rowActionUrl(collection, { rowId, actionId, actionApp }) {
		const attached = actionApp
			? `&actionApp=${encodeURIComponent(actionApp)}`
			: ''
		return `${base}${col(collection.register, collection.schema)}/${encodeURIComponent(rowId)}/actions/${encodeURIComponent(actionId)}?collection=${encodeURIComponent(collection.id)}${attached}`
	}

	/**
	 * Fetch one listed document and save it client-side through a Blob URL,
	 * as downloadFile().
	 *
	 * @param {string} url The document route.
	 * @param {object} entry The listed entry: `{ id, title }`.
	 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }`.
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	async function saveDocument(url, entry) {
		return saveDownload(url, entry.title || 'download')
	}

	/**
	 * Fetch a file with the bearer and save it under a name, through a Blob
	 * URL and a clicked link.
	 *
	 * @param {string} url The file's route.
	 * @param {string} name The name to save it under.
	 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }`; `status` 0 on a network error.
	 */
	async function saveDownload(url, name) {
		try {
			const res = await fetch(url, { headers: { ...authHeaders() } })
			if (!res.ok) {
				return { ok: false, status: res.status }
			}
			clickDownload(window.URL.createObjectURL(await res.blob()), name)
			return { ok: true }
		} catch {
			return { ok: false, status: 0 }
		}
	}

	/**
	 * GET a list from the app with the bearer; anything but a list, a
	 * refusal or a failure reads as an empty list.
	 *
	 * @param {string} path The path under the app root.
	 * @return {Promise<Array<object>>} The list, or `[]`.
	 */
	async function fetchList(path) {
		try {
			const res = await fetch(`${appRoot}${path}`, {
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
	}

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
				return myCasesAnswer(res, await res.json().catch(() => ({})))
			} catch {
				return myCasesAnswer({ ok: false, status: 0 }, {})
			}
		},

		/**
		 * The unified inbox (portal-inbox-v2 T02): every `kind: inbox`
		 * collection across the subject's contributions, merged, sorted by
		 * `receivedAt` descending, each row carrying a `_source` provenance
		 * tag (`appId`/`label`/`register`/`schema`/`collection`).
		 *
		 * @return {Promise<Array<object>|null>} The rows, or null when the
		 *   server did not answer, so a failed read is not an empty inbox.
		 */
		async fetchInbox() {
			const body = await get('/inbox')
			if (body === null) {
				return null
			}
			return Array.isArray(body?.messages) ? body.messages : []
		},

		/**
		 * The party's open portal tasks (portal-task-delivery): read through
		 * portaliq's bearer-guarded proxy, which mints the server-side
		 * X-Portal-Subject assertion — the browser never calls openregister.
		 *
		 * A refused or failed read still answers an empty page, now marked
		 * `failed: true`, so "My tasks" can tell "nothing to do" from "could
		 * not ask" (site-mijn-omgeving-components REQ-SMO-009).
		 *
		 * @return {Promise<{results: Array, total: number, failed?: boolean}>}
		 */
		async fetchTasks() {
			let body
			try {
				body = await get('/tasks')
			} catch {
				body = null
			}
			return body && Array.isArray(body.results)
				? body
				: { results: [], total: 0, failed: true }
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
			const form = completionForm(comment, files)
			let res
			try {
				res = await fetch(
					`${base}/tasks/${encodeURIComponent(uuid)}/complete`,
					completionRequest(form, authHeaders()),
				)
			} catch {
				return unreachableTask()
			}
			return completionAnswer(res)
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
			const path = inboxMessagePath(message, '/read')
			if (path === null) {
				return { ok: false, status: 0 }
			}
			return send('PATCH', path, {})
		},

		/**
		 * Delete one of the resident's own inbox messages
		 * (inbox-delete-own-messages). The server re-checks that it is theirs.
		 *
		 * @param {object} message The message, with its `_source`.
		 * @return {Promise<{ok: boolean, status: number}>}
		 */
		async deleteMessage(message) {
			const path = inboxMessagePath(message, '')
			if (path === null) {
				return { ok: false, status: 0 }
			}
			return send('DELETE', path, {})
		},

		/**
		 * Answer one of the resident's inbox messages through the create action its
		 * collection declares (inbox-reply-with-attachments). The server proves the
		 * message is theirs and sets the carried fields (such as the case) from it.
		 *
		 * @param {object} message The message, with its `_source`.
		 * @param {object} data The fields the resident filled in, files left out.
		 * @return {Promise<object>} `{ ok, status, object, error, errors }`.
		 * @spec openspec/changes/archive/2026-10-09-inbox-reply-with-attachments/tasks.md#t05
		 */
		async replyToMessage(message, data) {
			const path = inboxMessagePath(message, '/reply')
			if (path === null) {
				return { ok: false, status: 0, object: null, error: '', errors: {} }
			}
			return send('POST', path, data)
		},

		/**
		 * The guardian's own message threads (guardian-direct-messages). An
		 * answer the server refuses reads as no threads, never as an error.
		 *
		 * With `orNull`, a refused or failed read answers null instead, so a
		 * page can say it could not ask (site-mijn-omgeving-components
		 * REQ-SMO-009).
		 *
		 * @param {object} [options] The options.
		 * @param {boolean} [options.orNull] Answer null on a failed read.
		 * @return {Promise<Array<object>|null>} The threads, or `[]` (null with `orNull`).
		 */
		async fetchThreads({ orNull = false } = {}) {
			const failed = orNull ? null : []
			try {
				const res = await fetch(`${appRoot}/api/messages/threads`, {
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				return await threadsIn(res, failed)
			} catch {
				return failed
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
		 * One call to the resident's messaging endpoints under
		 * `/api/messages` (site-messages-per-record): the contacts, a new
		 * conversation, a reply, a read mark. The page's own lazy module
		 * names the paths, so the site's entry carries only this one door.
		 *
		 * @param {string} method `GET` or `POST`.
		 * @param {string} path The path under `/api/messages`, starting with a slash.
		 * @param {object|null} [body] The JSON body of a POST.
		 * @return {Promise<{ok: boolean, status: number, data: any}>} The outcome; never throws.
		 */
		async messaging(method, path, body = null) {
			try {
				const res = await fetch(`${appRoot}/api/messages${path}`, {
					method,
					headers: {
						Accept: 'application/json',
						...(body ? { 'Content-Type': 'application/json' } : {}),
						...authHeaders(),
					},
					...(body ? { body: JSON.stringify(body) } : {}),
				})
				const data = await res.json().catch(() => null)
				return { ok: res.ok, status: res.status, data }
			} catch {
				return { ok: false, status: 0, data: null }
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
		 * One call to the portal api, answered as `{ok, status, json}` (or the
		 * response itself with `raw`), never thrown. The pages of the resident's
		 * own area (contacts, plans) build their calls on it in
		 * src/shared/areaApi.js, which loads with them.
		 *
		 * @param {string} method The verb.
		 * @param {string} path The path under the api base.
		 * @param {object|FormData|null} [body] The JSON body, a multipart body, or none.
		 * @param {boolean} [raw] Answer `{ok, status, res}` and leave the body unread.
		 * @return {Promise<{ok: boolean, status: number, json?: object, res?: Response}>} The answer.
		 */
		async request(method, path, body = null, raw = false) {
			const multipart =
				typeof FormData !== 'undefined' && body instanceof FormData
			try {
				const res = await fetch(`${base}${path}`, {
					method,
					headers: {
						...(multipart ? {} : { 'Content-Type': 'application/json' }),
						Accept: 'application/json',
						...authHeaders(),
					},
					...(body === null
						? {}
						: { body: multipart ? body : JSON.stringify(body) }),
				})
				if (raw) {
					return { ok: res.ok, status: res.status, res }
				}
				return {
					ok: res.ok,
					status: res.status,
					json: await res.json().catch(() => ({})),
				}
			} catch {
				return { ok: false, status: 0, json: {} }
			}
		},

		/**
		 * The resident's own notice choices per kind and channel, with
		 * whether push is available (a registered device and a transport that
		 * really delivers)
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
			return fetchList('/api/news/feed')
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
			return fetchList('/api/newsletters/archive')
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
		 * Hand back the secret of an invitation, so the waiting account
		 * behind it joins the signed-in person's own account.
		 *
		 * When the claim moved the account into the invitation's audience,
		 * the answer carries a reissued bearer; it is stored here, so every
		 * later request runs in the new audience.
		 *
		 * @param {string} secret The secret from the invitation.
		 * @return {Promise<object>} `{ ok, status, error, data }`.
		 *
		 * @spec openspec/specs/portal-identity-space/spec.md
		 * @spec openspec/specs/portal-identity-space/spec.md
		 */
		async claimInvitation(secret) {
			const result = await answer('POST', '/identity/invitation/redeem', {
				secret,
			})
			if (
				result.ok
				&& typeof result.data?.token === 'string'
				&& result.data.token !== ''
			) {
				writeToken(result.data.token)
			}
			return result
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
		 * With `orNull`, a refused or failed read answers null instead of `[]`,
		 * so a list can say it could not be loaded rather than that it is
		 * empty (site-mijn-omgeving-components REQ-SMO-009).
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {object} [options] The options.
		 * @param {boolean} [options.orNull] Answer null on a failed read.
		 * @return {Promise<Array<object>|null>} The collection's objects, or `[]` (null with `orNull`).
		 */
		async fetchCollection(collection, { orNull = false } = {}) {
			let body
			try {
				body = await get(
					`${col(collection.register, collection.schema)}?collection=${encodeURIComponent(collection.id)}`,
				)
			} catch {
				if (!orNull) {
					throw new TypeError('The collection could not be read.')
				}
			}
			return objectsIn(body, orNull)
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
		 * Where one case the subject owns stands (site-mijn-omgeving-components
		 * REQ-SMO-022): `{ label, steps }` from the app's `steps` provider,
		 * each step `{ label, description?, state, date? }`. Null when the
		 * collection declares none, the case is not the subject's, or the
		 * steps could not be read.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} id The case id.
		 * @param {string} [mandateId] The mandate a case listed under one is read under.
		 * @return {Promise<object|null>} The steps, or null.
		 */
		async fetchSteps(collection, id, mandateId = '') {
			const body = await get(
				`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/steps?collection=${encodeURIComponent(collection.id)}${mandateQuery(mandateId)}`,
			).catch(() => null)
			return body && Array.isArray(body.steps) ? body : null
		},

		/**
		 * The declared history of one object the subject owns (portaliq#723):
		 * `{ label, entries }`, the entries exactly as the contributing app
		 * returned them. Null when the collection declares none, the object is
		 * not the subject's, or the history could not be read.
		 *
		 * @param {object} collection Manifest collection: `{ id, register, schema }`.
		 * @param {string} id The object id.
		 * @param {string} [mandateId] The mandate a case listed under one is read under.
		 * @return {Promise<object|null>} The timeline, or null.
		 */
		async fetchTimeline(collection, id, mandateId = '') {
			const body = await get(
				`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/timeline?collection=${encodeURIComponent(collection.id)}${mandateQuery(mandateId)}`,
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
		 * The resident's saved draft of an action, or null (site-multi-step-forms T8).
		 *
		 * @param {string} app The contributing app, or the action's register.
		 * @param {string} actionId The action id.
		 * @return {Promise<{step: string, expiresAt: string, answers: object}|null>} The draft.
		 *
		 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
		 * @spec openspec/changes/resident-pages-live-round-3/specs/site-forms/spec.md#requirement-a-form-asks-for-a-draft-only-when-its-action-can-keep-one-req-rr3-005
		 */
		async getDraft(app, actionId) {
			try {
				const res = await fetch(draftUrl(app, actionId), {
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				if (!res.ok) {
					return null
				}
				// No draft yet answers `{found: false}` (REQ-RR3-005).
				const draft = await res.json()
				return draft && draft.found === false ? null : draft
			} catch {
				return null
			}
		},

		/**
		 * Save the resident's draft of an action.
		 *
		 * @param {string} app The contributing app, or the action's register.
		 * @param {string} actionId The action id.
		 * @param {{answers: object, step: string}} draft The draft.
		 * @return {Promise<{id: string, step: string, expiresAt: string, answers: object}|{saved: false, status: number, errors: object}|null>}
		 *         What was kept; a refusal with the message per field (a value of the wrong kind); or null when the call failed.
		 *
		 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
		 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
		 */
		async saveDraft(app, actionId, draft) {
			try {
				const res = await fetch(draftUrl(app, actionId), jsonInit('PUT', draft))
				if (res.ok) {
					return await res.json()
				}
				const refusal = await res.json().catch(() => ({}))
				return {
					saved: false,
					status: res.status,
					errors:
						refusal?.errors && typeof refusal.errors === 'object'
							? refusal.errors
							: {},
				}
			} catch {
				return null
			}
		},

		/**
		 * The resident's live drafts, every form they can still finish; an
		 * empty list when there is none or the list cannot be read.
		 *
		 * @return {Promise<Array<{app: string, action: string, label: string, updatedAt: string, expiresAt: string}>>}
		 *
		 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
		 */
		async listDrafts() {
			try {
				const res = await fetch(`${base}/drafts`, {
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				if (!res.ok) {
					return []
				}
				const body = await res.json()
				return Array.isArray(body?.drafts) ? body.drafts : []
			} catch {
				return []
			}
		},

		/**
		 * Delete the draft once the action was sent.
		 *
		 * @param {string} app The contributing app, or the action's register.
		 * @param {string} actionId The action id.
		 * @return {Promise<boolean>} True when the request went through.
		 *
		 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
		 */
		async discardDraft(app, actionId) {
			try {
				const res = await fetch(draftUrl(app, actionId), {
					method: 'DELETE',
					headers: { Accept: 'application/json', ...authHeaders() },
				})
				return res.ok
			} catch {
				return false
			}
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
		 * @spec openspec/changes/archive/2026-10-07-create-names-its-action/tasks.md#T2
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
		 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
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
		 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
		 */
		async amendCitizenCase(collection, id, fields) {
			const res = await fetch(citizenCaseUrl(collection, id), jsonInit('PATCH', { fields }))
			const json = await res.json().catch(() => ({}))
			if (!res.ok) {
				return caseRefusal(res.status, json)
			}
			return { ok: true, status: res.status, case: json.case || null }
		},

		/**
		 * Download the resident's own list, or one record, as a PDF. The server
		 * reads exactly what the screen reads and renders it through OpenRegister;
		 * the file is saved client-side through a Blob URL, as downloadFile().
		 *
		 * @param {object} collection Manifest collection: `{ register, schema, id, label }`.
		 * @param {string} [id] The record id; none exports the whole list.
		 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }` (400: the list is too long).
		 * @spec openspec/changes/archive/2026-10-09-cases-export-own-data-pdf/tasks.md#t06
		 */
		async downloadPdf(collection, id) {
			const path =
				id === undefined
					? 'export.pdf'
					: `${encodeURIComponent(id)}/export.pdf`
			const url = `${base}${col(collection.register, collection.schema)}/${path}?collection=${encodeURIComponent(collection.id)}`
			return saveDownload(
				url,
				`${(collection.label || 'export').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.pdf`,
			)
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
			return saveDocument(
				`${base}${citizenCase(collection.register, collection.schema)}/${encodeURIComponent(caseId)}/documents/${encodeURIComponent(entry.id)}`,
				entry,
			)
		},

		/**
		 * The documents one object of any collection lists, for a collection
		 * that declares `documents` and is not a case collection. The server
		 * finds the collection in the resident's own aggregate and reads the
		 * object within its scope, so a collection above the session's trust,
		 * an object outside the scope and an unknown id are all null here.
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} id The object id.
		 * @return {Promise<object|null>} `{ documents, documentsLabel }` or null.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
		 */
		async fetchCollectionDocuments(collection, id) {
			const answer = await get(
				`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/documents`,
			)
			return answer && Array.isArray(answer.documents)
				? { documents: answer.documents, documentsLabel: answer.label || '' }
				: null
		},

		/**
		 * Open one document `fetchCollectionDocuments` listed. Only the entry
		 * id travels; the server looks it up again, runs the collection's
		 * `opened` hook when it has one, and answers 503 when that says no.
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} id The object id.
		 * @param {object} entry The listed entry: `{ id, title }`.
		 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }`.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
		 */
		async downloadCollectionDocument(collection, id, entry) {
			return saveDocument(
				`${base}${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/documents/${encodeURIComponent(entry.id)}`,
				entry,
			)
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
				`${citizenCaseUrl(collection, id)}/withdraw`,
				jsonInit('POST', { reason }),
			)
			const json = await res.json().catch(() => ({}))
			if (!res.ok) {
				return caseRefusal(res.status, json)
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
		 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
		 */
		async addCitizenDocument(collection, id, file) {
			const form = new FormData()
			form.append('file', file)
			const res = await fetch(`${citizenCaseUrl(collection, id)}/documents`, {
				method: 'POST',
				headers: { Accept: 'application/json', ...authHeaders() },
				body: form,
			})
			const json = await res.json().catch(() => ({}))
			if (!res.ok) {
				return caseRefusal(res.status, json)
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
		 * @param {{submissionKey?: string}} [options] The form's submission key (REQ-AFS-001).
		 * @return {Promise<{ok: boolean, status: number, body: object}>} The relayed answer.
		 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-subject-take-a-timed-task
		 */
		async forwardAction(app, actionId, body, options = {}) {
			try {
				const res = await fetch(
					`${base}/actions/${encodeURIComponent(app)}/${encodeURIComponent(actionId)}`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							...authHeaders(),
							...submissionHeaders(options),
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
		 * @param {{field: string, files: Array<File>}|null} [upload] Files for an action that declares `files` (REQ-RAF-001).
		 * @param {{submissionKey?: string}} [options] The form's submission key (REQ-AFS-001).
		 * @return {Promise<object>} `{ ok, status, body }`; `status` 0 on a network error.
		 */
		async forwardRowAction(
			collection,
			rowId,
			actionId,
			answers = {},
			actionApp = '',
			upload = null,
			options = {},
		) {
			// Files the action lets the resident add go multipart; without
			// them the body stays JSON (row-action-carries-files REQ-RAF-001).
			const multipart = rowActionFormData(answers, upload)
			try {
				const res = await fetch(
					rowActionUrl(collection, { rowId, actionId, actionApp }),
					{
						method: 'POST',
						headers: {
							...(multipart ? {} : { 'Content-Type': 'application/json' }),
							Accept: 'application/json',
							...authHeaders(),
							...submissionHeaders(options),
						},
						body: multipart || JSON.stringify(answers || {}),
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
			const first = await attachOnce(url, form)
			if (!first.retry) {
				return first.result
			}
			await new Promise((resolve) => setTimeout(resolve, 600))
			return (await attachOnce(url, form)).result
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
		 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-the-generic-portal-form-must-render-a-file-field-as-a-file-picker
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
			return saveDownload(url, file.name || 'download')
		},

		/**
		 * Populate a `collection` optionsProvider: fetch the referenced scoped
		 * collection and map each row to `{ value, label }`. Because it goes
		 * through the subject-scoped endpoint, it can only ever offer values the
		 * subject may already read.
		 *
		 * @param {object} provider optionsProvider: `{ register, schema, valueField, labelField, descriptionField? }`.
		 * @return {Promise<Array<object>>} `{ value, label, description? }`; the description is a choice card's second line.
		 * @spec openspec/changes/guardian-pages-follow-the-wilgenboom-boards/specs/site-forms/spec.md#requirement-a-choice-card-may-carry-a-second-line
		 */
		async fetchOptions(provider) {
			const body = await get(`${col(provider.register, provider.schema)}`)
			const rows = body && Array.isArray(body.objects) ? body.objects : []
			return rows
				.map((r) => optionOf(r, provider))
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
			const res = await fetch(
				`${base}/session/branch`,
				jsonInit('POST', { branch: branch || '' }),
			)
			if (!res.ok) {
				return { ok: false }
			}
			const token = await issuedToken(res)
			if (!token) {
				return { ok: false }
			}
			writeToken(token)
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
			const res = await fetch(
				`${base}/session/dev-login`,
				devLoginRequest(audience || config.audience || 'supplier'),
			)
			if (!res.ok) {
				return null
			}
			const token = await issuedToken(res)
			if (!token) {
				return null
			}
			writeToken(token)
			return token
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
			} catch {
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
 * @spec openspec/changes/signin-eherkenning-eidas-and-the-token-fragment/specs/portal-broker-envelope-login/spec.md#requirement-the-bearer-reaches-the-browser-in-the-fragment-and-leaves-the-address-req-see-003
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
