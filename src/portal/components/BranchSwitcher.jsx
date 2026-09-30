// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The branch choice in the portal header (signin-eherkenning-branch T06,
// REQ-SEB-003): a business user signed in for the whole company narrows to
// one of the company's branches, or goes back to the whole company. Shown
// only when the company has more than one branch; a session the login
// restricted to a branch never gets here (App.jsx).

import { branchOptions } from '../lib/branch.js'

/**
 * The branch switcher.
 *
 * @param {object} props Props.
 * @param {Function} props.t The translator.
 * @param {Array<object>} props.branches The company's branches.
 * @param {string} props.value The branch in effect, or '' for the whole company.
 * @param {Function} props.onChange Called with the chosen branch number, or ''.
 * @return {object|null} The element, or nothing with fewer than two branches.
 *
 * @spec openspec/changes/signin-eherkenning-branch/specs/signin-eherkenning-branch/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
 */
export default function BranchSwitcher({ t, branches, value, onChange }) {
	if (!Array.isArray(branches) || branches.length < 2) {
		return null
	}

	return (
		<span className="portaliq-branch-choice" data-testid="branch-choice">
			<label htmlFor="portaliq-branch-choice">{t('Acting for branch')}</label>
			<select
				id="portaliq-branch-choice"
				value={value}
				onChange={(event) => onChange(event.target.value)}>
				{branchOptions(branches, t).map((option) => (
					<option key={option.id || 'whole'} value={option.id}>{option.label}</option>
				))}
			</select>
		</span>
	)
}
