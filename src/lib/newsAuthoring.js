/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The News screen, without the screen (staff-news-screen T3).
 *
 * Staff write a news item, choose who it is for (the whole school or one or
 * more groups), change it and publish it. Every write goes through the staff
 * authoring routes of `NewsController` (`POST /api/news`, `PUT
 * /api/news/{id}`, `PUT /api/news/{id}/publish|unpublish`), never through the
 * object API: those routes check the target and keep the read receipts and
 * translations the server owns. The list is read from the object API, where
 * the news schema is readable to every signed-in user.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The transport and the URL generator are
 * handed in by `src/views/NewsAuthoring.vue`, so `tests/news-authoring.spec.mjs`
 * runs it as a plain node script, the same shape as `staffAccountActions.js`.
 *
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */

/** The audience kinds the screen offers, plus the one it only keeps. */
export const AUDIENCE_SCHOOL = 'school'
export const AUDIENCE_GROUPS = 'groups'
export const AUDIENCE_CHILDREN = 'children'

/**
 * The English source sentence for a failed call, by status and error code.
 *
 * @param {object} error The rejected request.
 * @return {string}
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */
export function failureKey(error) {
	const status = error?.response?.status ?? 0
	if (status === 401 || status === 403) {
		return 'You may not write news. Ask an administrator for this right.'
	}
	if (status === 404) {
		return 'This news item no longer exists.'
	}
	if (error?.response?.data?.error === 'invalid_target') {
		return 'Give a title, a text and who the news is for.'
	}
	return 'The news item could not be saved. Try again.'
}

/**
 * A trimmed string.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
function text(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * The non-empty strings of a list.
 *
 * @param {unknown} value The value.
 * @return {Array<string>}
 */
function strings(value) {
	return Array.isArray(value)
		? value.filter((entry) => typeof entry === 'string' && entry !== '')
		: []
}

/**
 * A row's id, wherever the register put it.
 *
 * @param {object} row The row.
 * @return {string}
 */
export function idOf(row) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/**
 * An empty form for a new news item.
 *
 * @return {object}
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */
export function emptyForm() {
	return {
		title: '',
		body: '',
		audience: AUDIENCE_SCHOOL,
		schoolRef: '',
		groupRefs: [],
		childRefs: [],
	}
}

/**
 * The form for an existing news item. An item written for specific children
 * (only the API can do that) keeps its children: the screen shows that and
 * does not offer to change them.
 *
 * @param {object} item The news item.
 * @return {object}
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */
export function formFromItem(item) {
	const target = item?.target && typeof item.target === 'object' ? item.target : {}
	const groupRefs = strings(target.groupRefs)
	const childRefs = strings(target.childRefs)
	let audience = AUDIENCE_SCHOOL
	if (groupRefs.length > 0) {
		audience = AUDIENCE_GROUPS
	} else if (text(target.schoolRef) === '' && childRefs.length > 0) {
		audience = AUDIENCE_CHILDREN
	}
	return {
		title: text(item?.title),
		body: typeof item?.body === 'string' ? item.body : '',
		audience,
		schoolRef: text(target.schoolRef),
		groupRefs,
		childRefs,
	}
}

/**
 * The API target for a form: one dimension, the one the form chose.
 *
 * @param {object} form The form.
 * @return {object}
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */
export function targetFromForm(form) {
	if (form.audience === AUDIENCE_GROUPS) {
		return { groupRefs: strings(form.groupRefs) }
	}
	if (form.audience === AUDIENCE_CHILDREN) {
		return { childRefs: strings(form.childRefs) }
	}
	return { schoolRef: text(form.schoolRef) }
}

/**
 * What is missing before a form can be saved, as English source sentences.
 * Empty when it can be saved.
 *
 * @param {object} form The form.
 * @return {Array<string>}
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */
export function missingFields(form) {
	const missing = []
	if (text(form.title) === '') {
		missing.push('Give the news item a title.')
	}
	if (text(form.body) === '') {
		missing.push('Write the text of the news item.')
	}
	const target = targetFromForm(form)
	const chosen = Object.values(target).some((value) =>
		Array.isArray(value) ? value.length > 0 : value !== '',
	)
	if (!chosen) {
		missing.push(
			form.audience === AUDIENCE_GROUPS
				? 'Choose at least one group.'
				: 'Choose the school.',
		)
	}
	return missing
}

/**
 * Who a news item is for, as `{kind, names}`, with the school and group
 * names from the choices when they are known and the reference otherwise.
 *
 * @param {object} item The news item.
 * @param {{schools: Array, groups: Array}} options The audience choices.
 * @return {{kind: string, names: Array<string>}}
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */
export function audienceOf(item, options = { schools: [], groups: [] }) {
	const form = formFromItem(item)
	const label = (list, ref) =>
		(list || []).find((option) => option.id === ref)?.label || ref
	if (form.audience === AUDIENCE_GROUPS) {
		return {
			kind: AUDIENCE_GROUPS,
			names: form.groupRefs.map((ref) => label(options.groups, ref)),
		}
	}
	if (form.audience === AUDIENCE_CHILDREN) {
		return { kind: AUDIENCE_CHILDREN, names: [] }
	}
	return {
		kind: AUDIENCE_SCHOOL,
		names: form.schoolRef ? [label(options.schools, form.schoolRef)] : [],
	}
}

/**
 * The newest first: by the last change, then by creation.
 *
 * @param {Array<object>} items The news items.
 * @return {Array<object>}
 */
function newestFirst(items) {
	const when = (item) =>
		String(item?.['@self']?.updated || item?.['@self']?.created || '')
	return [...items].sort((a, b) => when(b).localeCompare(when(a)))
}

/**
 * The News screen's calls over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get url => Promise<{data}>
 * @param {Function} deps.post (url, body) => Promise<{data}>
 * @param {Function} deps.put (url, body) => Promise<{data}>
 * @param {Function} deps.generateUrl path => url, Nextcloud's URL generator
 * @return {object}
 * @spec openspec/changes/staff-news-screen/tasks.md#T3
 */
export function createNewsApi({ get, post, put, generateUrl }) {
	const route = (path, id) =>
		generateUrl(path.replace('{id}', encodeURIComponent(id || '')))

	return {
		/**
		 * Every news item, newest first.
		 *
		 * @return {Promise<Array<object>>}
		 */
		async list() {
			const { data } = await get(
				generateUrl('/apps/openregister/api/objects/portaliq/newsItem')
					+ '?_limit=200',
			)
			const rows = Array.isArray(data?.results) ? data.results : []
			return newestFirst(rows.filter((row) => idOf(row) !== ''))
		},

		/**
		 * The school and group choices.
		 *
		 * @return {Promise<{schools: Array, groups: Array}>}
		 */
		async audiences() {
			const { data } = await get(route('/apps/portaliq/api/news/audiences'))
			return {
				schools: Array.isArray(data?.schools) ? data.schools : [],
				groups: Array.isArray(data?.groups) ? data.groups : [],
			}
		},

		/**
		 * Save a form: a new draft, or the changes to an existing item.
		 *
		 * @param {object} form The form.
		 * @param {string} id The item id, '' for a new item.
		 * @param {string} authorRef The signed-in staff member, for a new item.
		 * @return {Promise<object>} The saved item.
		 */
		async save(form, id, authorRef) {
			const body = {
				title: text(form.title),
				body: form.body.trim(),
				target: targetFromForm(form),
			}
			if (id) {
				const { data } = await put(route('/apps/portaliq/api/news/{id}', id), body)
				return data
			}
			const { data } = await post(route('/apps/portaliq/api/news'), {
				...body,
				authorRef,
			})
			return data
		},

		/**
		 * Publish an item, or take it back to a draft.
		 *
		 * @param {string} id The item id.
		 * @param {boolean} published True to publish.
		 * @return {Promise<object>} The item.
		 */
		async setPublished(id, published) {
			const path = published
				? '/apps/portaliq/api/news/{id}/publish'
				: '/apps/portaliq/api/news/{id}/unpublish'
			const { data } = await put(route(path, id))
			return data
		},
	}
}
