// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The steps the resident's save buttons share (woo-journey-entry-points):
 * whether the manifest offers the button's action, and posting that action
 * with the resident's bearer.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */

import { offeredActions, postAction, saveVisible } from './residentActions.js'
import { residentAuthBase, residentToken } from './residentSession.js'

/** What a save button says when the save did not work. */
export const SAVE_FAILED = 'Bewaren is niet gelukt. Probeer het later opnieuw.'

/**
 * Whether the manifest offers the button's action in its app.
 *
 * @param {object|null} manifest The resident's manifest.
 * @param {{app: string, actionId: string}} button The button's app and action.
 * @return {boolean} True when the button shows.
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export function offersSave(manifest, button) {
	return saveVisible(true, offeredActions(manifest, button.app), button.actionId)
}

/**
 * Post the button's action with this body and the resident's bearer.
 *
 * @param {{app: string, actionId: string}} button The button's app and action.
 * @param {object} body The action's body.
 * @return {Promise<object>} The action's result.
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-save-the-current-search-req-wje-003
 */
export function postSave(button, body) {
	return postAction({
		authBase: residentAuthBase(),
		appId: button.app,
		actionId: button.actionId,
		body,
		token: residentToken(),
	})
}
