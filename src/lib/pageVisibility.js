// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Taking a published page back to a draft from the page list
 * (page-public-on-purpose). A draft page is not served: its route answers
 * exactly as one that does not exist.
 */
import { draftStatusPayload } from '../editor/pageBody.js'

/**
 * The row's page id, wherever the register put it.
 *
 * @param {object} row A page row.
 * @return {string} The id, or ''.
 */
function idOf(row) {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/**
 * The page list's `Take back to draft` handler.
 *
 * @param {object} deps The collaborators.
 * @param {object} deps.saver The page saver, from createPageSaver().
 * @param {(text: string) => string} deps.translate Translates a sentence.
 * @param {(message: string) => void} deps.notify Shows a success sentence.
 * @param {(message: string) => void} deps.notifyError Shows a failure sentence.
 * @param {() => void} deps.reload Shows the changed list.
 * @return {{takePageBackToDraft: (payload: {item: object}) => Promise<boolean>}} The handlers.
 * @spec openspec/changes/page-public-on-purpose/tasks.md#1.2
 */
export function createPageVisibilityHandlers({ saver, translate, notify, notifyError, reload }) {
	return {
		/**
		 * Take a published page back to a draft, refusing when it changed
		 * since it was read.
		 *
		 * @param {{item: object}} payload The row the action was chosen on.
		 * @return {Promise<boolean>} Whether the page is a draft now.
		 */
		async takePageBackToDraft({ item } = {}) {
			const id = idOf(item)
			if (id === '') {
				return false
			}
			try {
				const { page, version } = await saver.load(id)
				await saver.save(id, draftStatusPayload(page), version)
			} catch {
				notifyError(translate('The page could not be taken back to a draft. Try again.'))
				return false
			}
			notify(translate('The page is a draft again. Nobody can read it without signing in.'))
			reload()
			return true
		},
	}
}
