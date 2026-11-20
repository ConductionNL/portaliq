/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Submissions list's row actions that the manifest cannot declare on its
 * own: "Conversation in Talk" starts or opens the Talk room linked to the
 * submission through OpenRegister's Talk integration, and "Link a form" stores
 * the request form a submission came through, writing `formBinding` only. The
 * factory takes its collaborators as arguments so it runs as a plain node
 * module in tests/submission-row-actions.spec.mjs.
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md
 */

/** One submission in OpenRegister. */
export const SUBMISSION_PATH = '/apps/openregister/api/objects/portaliq/portalSubmission/'

/** The portals, to find the submission's portals by organisation. */
export const PORTALS_PATH = '/apps/openregister/api/objects/portaliq/portal'

/** The request forms. */
export const FORM_BINDINGS_PATH = '/apps/openregister/api/objects/portaliq/portalFormBinding'

/** Talk's room type for an invite-only group room, as nc-vue's CnTalkRoomCreate sends. */
const GROUP_ROOM = 2

/**
 * The rows of an OpenRegister list answer, in either shape.
 *
 * @param {object|Array} data The answer body.
 * @return {Array<object>} The rows.
 */
function rowsOf(data) {
	const rows = Array.isArray(data) ? data : (data?.results ?? data?.items)
	return Array.isArray(rows) ? rows.filter((row) => row && typeof row === 'object') : []
}

/**
 * A record's id, wherever it keeps it.
 *
 * @param {object} row The record.
 * @return {string} The id, or ''.
 */
function idOf(row) {
	return String(row?.id ?? row?.uuid ?? row?.['@self']?.id ?? '').trim()
}

/**
 * The name of the room for a submission: the action as the resident read it
 * and its receipt reference.
 *
 * @param {object} submission The submission row.
 * @return {string} The name.
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-start-a-talk-conversation-about-a-submission
 */
export function conversationName(submission) {
	const action = String(submission?.actionTitle || submission?.actionId || '').trim()
	const reference = String(submission?.receiptMessageRef || '').trim()
	return [action, reference].filter((part) => part !== '').join(' ')
}

/**
 * Where a linked room opens: its own address, else the Talk call by token.
 *
 * @param {object} room A linked room, as OpenRegister lists it.
 * @return {string} The address, or '' for a room without one.
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-start-a-talk-conversation-about-a-submission
 */
export function roomAddress(room) {
	const url = String(room?.url ?? '')
	if (url.startsWith('/') || /^https:\/\//i.test(url)) {
		return url
	}
	const token = String(room?.roomToken ?? room?.token ?? '').trim()
	return token === '' ? '' : `/index.php/call/${encodeURIComponent(token)}`
}

/**
 * The request forms a submission may be linked to: those of the portals of
 * its organisation, as `{ id, label }` sorted by label.
 *
 * @param {Array<object>} portals The portal rows of the organisation.
 * @param {Array<object>} bindings The request form rows.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
 */
export function formOptions(portals, bindings) {
	const slugs = new Set(portals.map((portal) => String(portal?.slug ?? '')).filter((slug) => slug !== ''))
	return bindings
		.filter((binding) => slugs.has(String(binding?.portal ?? '')) && idOf(binding) !== '')
		.map((binding) => ({
			id: idOf(binding),
			label: String(binding.formName || binding.route || idOf(binding)),
		}))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The Submissions row action handlers.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string) => Promise<object>} deps.get HTTP GET.
 * @param {(url: string, body: object) => Promise<object>} deps.post HTTP POST.
 * @param {(url: string, body: object) => Promise<object>} deps.patch HTTP PATCH.
 * @param {(path: string) => string} deps.generateUrl Nextcloud URL generator.
 * @param {(url: string) => void} deps.open Goes to an address.
 * @param {(options: Array<object>, current: string) => Promise<string|null>} deps.chooseForm Asks which form, null when cancelled.
 * @param {(text: string) => void} deps.notify Success toast.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {(text: string) => string} deps.translate Translator.
 * @param {() => void} deps.reload Shows the list again.
 * @return {{startSubmissionConversation: Function, linkSubmissionForm: Function}}
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md
 */
export function createSubmissionHandlers({ get, post, patch, generateUrl, open, chooseForm, notify, notifyError, translate, reload }) {
	return {
		/**
		 * `Gesprek in Talk`: open the submission's linked room, or start one
		 * linked to it and open that.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>} Whether a room opened.
		 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-start-a-talk-conversation-about-a-submission
		 */
		async startSubmissionConversation({ item }) {
			const id = idOf(item)
			if (id === '') {
				notifyError(translate('The conversation could not be started.'))
				return false
			}
			const links = generateUrl(SUBMISSION_PATH + encodeURIComponent(id) + '/talk')
			try {
				const linked = rowsOf((await get(links))?.data).map(roomAddress).find((address) => address !== '')
				if (linked) {
					open(linked)
					return true
				}
				const { data } = await post(links + '/new', {
					name: conversationName(item) || translate('Submission'),
					description: '',
					type: GROUP_ROOM,
				})
				const created = roomAddress(data?.link ?? data)
					|| rowsOf((await get(links))?.data).map(roomAddress).find((address) => address !== '')
				if (!created) {
					notifyError(translate('The conversation could not be started.'))
					return false
				}
				open(created)
				return true
			} catch {
				notifyError(translate('The conversation could not be started.'))
				return false
			}
		},

		/**
		 * `Formulier koppelen`: choose one of the request forms of the
		 * submission's portals and store it as `formBinding`, nothing else.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>} Whether a form was linked.
		 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
		 */
		async linkSubmissionForm({ item }) {
			const id = idOf(item)
			const organisation = String(item?.organisation ?? '').trim()
			let options = []
			try {
				if (id !== '' && organisation !== '') {
					const portals = rowsOf((await get(generateUrl(PORTALS_PATH) + '?' + new URLSearchParams({ organisation, _limit: '100' }).toString()))?.data)
					const bindings = rowsOf((await get(generateUrl(FORM_BINDINGS_PATH) + '?' + new URLSearchParams({ _limit: '500' }).toString()))?.data)
					options = formOptions(portals, bindings)
				}
			} catch {
				notifyError(translate('The request forms could not be loaded.'))
				return false
			}
			if (options.length === 0) {
				notifyError(translate('This portal has no request forms to link.'))
				return false
			}
			const chosen = await chooseForm(options, String(item?.formBinding ?? ''))
			if (!chosen || !options.some((option) => option.id === chosen)) {
				return false
			}
			try {
				await patch(generateUrl(SUBMISSION_PATH + encodeURIComponent(id)), { formBinding: chosen })
			} catch {
				notifyError(translate('The form could not be linked.'))
				return false
			}
			notify(translate('The form is linked.'))
			reload()
			return true
		},
	}
}
