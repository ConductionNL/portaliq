// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "Acting for" in the portal header (cases-my-cases-page REQ-CMC-004): the
// person chooses to act for themself or under one of the mandates they hold.
// The choice applies to "My cases" and to every case screen for the rest of
// the session. Renders nothing for a person who holds no mandate.

import { actingForOptions } from '../lib/myCases.js'

/**
 * The switcher.
 *
 * @param {object} props Props.
 * @param {(key: string) => string} props.t The translator.
 * @param {Array<{id: string, label: string}>} props.mandates The mandates held.
 * @param {string} props.value The current choice.
 * @param {(id: string) => void} props.onChange Called with the new choice.
 * @return {object|null} The element.
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
 */
export default function ActingForSwitcher({ t, mandates, value, onChange }) {
	if (!Array.isArray(mandates) || mandates.length === 0) {
		return null
	}

	return (
		<span className="portaliq-acting-for" data-testid="acting-for">
			<label htmlFor="portaliq-acting-for">{t('Acting for')}</label>
			<select
				id="portaliq-acting-for"
				value={value}
				onChange={(event) => onChange(event.target.value)}>
				{actingForOptions(mandates, t).map((option) => (
					<option key={option.id} value={option.id}>{option.label}</option>
				))}
			</select>
		</span>
	)
}
