// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The branch (vestiging) a business session acts for, as the header shows it
// (signin-eherkenning-branch REQ-SEB-001). The session answer carries
// `branch` (a 12-digit vestigingsnummer, or '') and `branchRestricted` (the
// login itself was for that branch only).

const BRANCH_NUMBER = /^\d{12}$/

/**
 * The header text for the branch in effect, or '' when the session acts for
 * the whole company.
 *
 * @param {object|null} session The answer of GET /portal/api/session.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {string} The text.
 *
 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T06
 */
export function branchInEffect(session, t) {
	const number =
		session && typeof session.branch === 'string' ? session.branch : ''
	if (!BRANCH_NUMBER.test(number)) {
		return ''
	}

	if (session.branchRestricted === true) {
		return t('Signed in for branch {number}', { number })
	}

	return t('Branch {number}', { number })
}

/**
 * The header's branch options for a whole-company session
 * (signin-eherkenning-branch T06): the whole company first, then each branch
 * by name and address. Empty without branches.
 *
 * @param {Array<{number: string, name: string, address: string}>|null} branches The company's branches.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {Array<{id: string, label: string}>} The options.
 *
 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
 */
export function branchOptions(branches, t) {
	if (!Array.isArray(branches) || branches.length === 0) {
		return []
	}

	return [
		{ id: '', label: t('Whole company') },
		...branches
			.filter((branch) => BRANCH_NUMBER.test(String(branch?.number || '')))
			.map((branch) => ({
				id: branch.number,
				label: [branch.name || branch.number, branch.address]
					.filter(Boolean)
					.join(', '),
			})),
	]
}
