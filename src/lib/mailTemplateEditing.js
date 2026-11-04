/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Editing a portal's mail text on the Mail templates page: open the editor
 * for a new or stored text, check it, preview it, send a test mail and put
 * the default text back.
 *
 * A text is checked against its kind's variables before it is stored:
 * MailTemplateAdminController::preview names the unknown ones, and a text
 * with one is refused. The stored row is a `portalMailTemplate` object.
 *
 * The GET, POST, PUT, DELETE and URL generator are handed in by
 * src/customComponents.js, so tests/mail-template-editing.spec.mjs runs this
 * module as a plain node script.
 *
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */

export const KINDS_PATH = '/apps/portaliq/api/mail-templates'
export const PREVIEW_PATH = '/apps/portaliq/api/mail-templates/preview'
export const TEST_PATH = '/apps/portaliq/api/mail-templates/test'
export const TEMPLATES_PATH = '/apps/openregister/api/objects/portaliq/portalMailTemplate'
export const PORTALS_PATH = '/apps/openregister/api/objects/portaliq/portal'

const REQUIRED = ['templateKey', 'portal', 'subject', 'body']

/**
 * The variables of a kind of mail, in the shape CnMarkdownEditor takes.
 *
 * @param {object|null} kind A kind from the kinds endpoint.
 * @return {Array<{name: string, label: string}>} The variables.
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-a-template-accepts-only-its-own-variables-and-no-record-content-req-pmt-002
 */
export function variablesOf(kind) {
	return (kind?.variables || []).map((name) => ({ name, label: `{${name}}` }))
}

/**
 * The editor's fields for a stored row, or empty ones for a new text.
 *
 * @param {object|null} row A `portalMailTemplate` row.
 * @return {{id: string, templateKey: string, portal: string, subject: string, body: string, active: boolean}}
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */
export function formFromRow(row) {
	const source = row || {}
	return {
		id: fieldOf(source, 'id') || fieldOf(source, 'uuid'),
		templateKey: fieldOf(source, 'templateKey'),
		portal: fieldOf(source, 'portal'),
		subject: fieldOf(source, 'subject'),
		body: fieldOf(source, 'body'),
		active: source.active !== false,
	}
}

/**
 * One field of a row as text, '' when it is empty.
 *
 * @param {object} row The row.
 * @param {string} name The field.
 * @return {string} The text.
 */
function fieldOf(row, name) {
	return String(row[name] || '')
}

/**
 * The required fields the form leaves empty.
 *
 * @param {object} form The editor's fields.
 * @return {Array<string>} Their names, in the order of REQUIRED.
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */
export function missingFields(form) {
	return REQUIRED.filter((name) => String(form?.[name] || '').trim() === '')
}

/**
 * The refusal for unknown variables, as the board words it.
 *
 * @param {Array<string>} names The unknown variables, without braces.
 * @param {(text: string, vars?: object) => string} translate The translator.
 * @return {string} The message.
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-a-template-accepts-only-its-own-variables-and-no-record-content-req-pmt-002
 */
export function unknownMessage(names, translate) {
	const list = names.map((name) => `{${name}}`).join(', ')
	return translate('Unknown variable: {names}. Not saved.', { names: list })
}

/**
 * The fields a check, a preview or a test mail sends.
 *
 * @param {object} form The editor's fields.
 * @return {{templateKey: string, subject: string, body: string}}
 */
function textOf(form) {
	return { templateKey: form.templateKey, subject: form.subject, body: form.body }
}

/**
 * The calls behind the editor.
 *
 * @param {{get: (url: string) => Promise<object>, post: (url: string, body: object) => Promise<object>, put: (url: string, body: object) => Promise<object>, del: (url: string) => Promise<object>, generateUrl: (path: string, params?: object) => string}} http The HTTP verbs and URL generator.
 * @return {object} kinds, portals, check, sendTest, save, remove.
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */
export function createMailTemplateApi({ get, post, put, del, generateUrl }) {
	const rowUrl = (id) => generateUrl(`${TEMPLATES_PATH}/{id}`, { id })
	return {
		kinds: async () => (await get(generateUrl(KINDS_PATH)))?.data?.kinds || [],
		portals: async () => (await get(generateUrl(PORTALS_PATH) + '?_limit=200'))?.data?.results || [],
		check: async (form) => (await post(generateUrl(PREVIEW_PATH), textOf(form)))?.data || {},
		sendTest: (form) => post(generateUrl(TEST_PATH), textOf(form)),
		save: (form) => {
			const { id, ...fields } = form
			return id === '' ? post(generateUrl(TEMPLATES_PATH), fields) : put(rowUrl(id), fields)
		},
		remove: (id) => del(rowUrl(id)),
	}
}

/**
 * A refusal in the editor.
 *
 * @param {object} ctx The context: `translate`.
 * @param {string} message The untranslated message.
 * @return {{ok: boolean, message: string}}
 */
function refused(ctx, message) {
	return { ok: false, message: ctx.translate(message) }
}

/**
 * Check a text against its kind and store it.
 *
 * @param {object} ctx The context: `api`, `translate`.
 * @param {object} form The editor's fields.
 * @return {Promise<{ok: boolean, message: string}>}
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-a-template-accepts-only-its-own-variables-and-no-record-content-req-pmt-002
 */
async function submitText(ctx, form) {
	if (missingFields(form).length > 0) {
		return refused(ctx, 'Fill in the kind of mail, the portal, the subject line and the text.')
	}
	const answer = await ctx.api.check(form)
	if (answer.ok !== true) {
		return { ok: false, message: unknownMessage(answer.unknownVariables || [], ctx.translate) }
	}
	await ctx.api.save(form)
	return { ok: true, message: ctx.translate('The mail text is saved.') }
}

/**
 * Send the text with sample values to the admin's own address.
 *
 * @param {object} ctx The context: `api`, `translate`.
 * @param {object} form The editor's fields.
 * @return {Promise<{ok: boolean, message: string}>}
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-send-a-test-mail-req-pmt-004
 */
async function sendTestText(ctx, form) {
	await ctx.api.sendTest(form)
	return { ok: true, message: ctx.translate('The test mail went to your own address.') }
}

/**
 * The editor's own actions: save, preview and test mail. Each answers
 * `{ok, message}` (a preview also `preview`), so a refusal shows in the
 * editor where the admin can correct it.
 *
 * @param {object} api The calls of createMailTemplateApi.
 * @param {(text: string, vars?: object) => string} translate The translator.
 * @return {{submit: (form: object) => Promise<object>, preview: (form: object) => Promise<object>, sendTest: (form: object) => Promise<object>}}
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */
export function createEditorActions(api, translate) {
	const ctx = { api, translate }
	const attempt = (work) => work().catch(() => refused(ctx, 'The change could not be saved. Try again.'))
	return {
		submit: (form) => attempt(() => submitText(ctx, form)),
		preview: (form) => attempt(async () => ({ ok: true, message: '', preview: await api.check(form) })),
		sendTest: (form) => attempt(() => sendTestText(ctx, form)),
	}
}

/**
 * Say what happened and show the list again.
 *
 * @param {object} deps The handlers' dependencies: `notify`, `reload`.
 * @param {string|null} message What the editor or the reset closed with.
 * @return {boolean} Whether anything changed.
 */
function finish(deps, message) {
	if (typeof message !== 'string' || message === '') {
		return false
	}
	deps.notify(message)
	deps.reload()
	return true
}

/**
 * Open the editor on a form; a saved text reloads the list.
 *
 * @param {object} deps The handlers' dependencies, with `actions`.
 * @param {object} form The editor's fields.
 * @return {Promise<boolean>} Whether a text was saved.
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */
async function editText(deps, form) {
	let options
	try {
		options = { kinds: await deps.api.kinds(), portals: form.id === '' ? await deps.api.portals() : [] }
	} catch {
		deps.notifyError(deps.translate('The kinds of mail could not be loaded. Try again.'))
		return false
	}
	return finish(deps, await deps.openEditor({ form, ...options, actions: deps.actions }))
}

/**
 * Remove the stored text, so the default text is sent again.
 *
 * @param {object} deps The handlers' dependencies.
 * @param {object} item The stored row.
 * @return {Promise<boolean>} Whether it was removed.
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */
async function resetText(deps, item) {
	const id = formFromRow(item).id
	if (id === '' || (await deps.confirmReset(item)) !== true) {
		return false
	}
	try {
		await deps.api.remove(id)
	} catch {
		deps.notifyError(deps.translate('The default text could not be put back. Try again.'))
		return false
	}
	return finish(deps, deps.translate('The default text is sent again.'))
}

/**
 * The page's handlers: `New mail text`, `Change` and `Back to the default
 * text`.
 *
 * @param {object} deps api, openEditor, confirmReset, notify, notifyError, translate, reload.
 * @return {{newMailTemplate: () => Promise<boolean>, editMailTemplate: (payload: object) => Promise<boolean>, resetMailTemplate: (payload: object) => Promise<boolean>}}
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
 */
export function createMailTemplateHandlers(deps) {
	const ctx = { ...deps, actions: createEditorActions(deps.api, deps.translate) }
	return {
		newMailTemplate: () => editText(ctx, formFromRow(null)),
		editMailTemplate: ({ item }) => editText(ctx, formFromRow(item)),
		resetMailTemplate: ({ item }) => resetText(ctx, item),
	}
}
