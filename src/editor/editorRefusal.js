/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What kind of refusal an editor panel got back, so each panel words it in
 * its own terms: a version conflict, a write it may not make, a failure that
 * names itself (no answer, only a message), or anything else.
 *
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */

import { isConflict } from './pageSaver.js'

/**
 * The kind of a refused change.
 *
 * @param {object|Error|undefined} error The failure.
 * @return {'conflict'|'forbidden'|'message'|'failed'} The kind.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function refusalKind(error) {
	if (isConflict(error)) {
		return 'conflict'
	}
	const status = error?.response?.status
	if (status === 401 || status === 403) {
		return 'forbidden'
	}
	if (!error?.response && error?.message) {
		return 'message'
	}
	return 'failed'
}
