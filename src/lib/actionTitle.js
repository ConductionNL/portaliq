/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The name a submission row reads by in the Submissions list (board
 * PtInzendingen): the action's title as the resident read it, else, for a row
 * written without one, its declared action id.
 */

/**
 * The submission row's action name.
 *
 * @param {{actionId?: string, actionTitle?: string}|null} row The submission.
 * @return {string} The title, else the id.
 * @spec openspec/specs/portaliq-leaf-integrations/spec.md#requirement-the-forms-leaf-attaches-nextcloud-forms-to-portal-submissions-on-a-submissions-surface-that-exists
 */
export function actionTitleOf(row) {
	const title = String(row?.actionTitle ?? '').trim()
	return title !== '' ? title : String(row?.actionId ?? '')
}
