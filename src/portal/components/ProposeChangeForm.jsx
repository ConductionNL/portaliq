// SPDX-License-Identifier: EUPL-1.2
//
// The propose-change form (change-proposal-queue's SPA rendering,
// guardian-self-service-profile). Renders exactly a `type: propose-change`
// action's `proposable` allow-list, pre-filled from the current row, plus a
// note. Submitting sends ONLY the fields whose value actually changed, as
// `{property, proposedValue}` pairs — the server re-whitelists against the
// same `proposable` list regardless and refuses a proposal with nothing
// changed (`nothing_proposed`), so checking here also avoids a pointless
// round trip.

import { Button, FormFieldTextbox } from '@utrecht/component-library-react'
import { useState } from 'react'

/**
 * @param root0
 * @param root0.action
 * @param root0.row
 * @param root0.onSubmit
 * @param root0.onCancel
 */
export default function ProposeChangeForm({ action, row, onSubmit, onCancel }) {
	const fields = action.proposable || []
	const [values, setValues] = useState(() => {
		const initial = {}
		for (const field of fields) {
			initial[field] =
				row?.[field] === null || row?.[field] === undefined
					? ''
					: String(row[field])
		}
		return initial
	})
	const [note, setNote] = useState('')
	const [submitting, setSubmitting] = useState(false)
	const [error, setError] = useState(null)

	/**
	 * @param field
	 * @param value
	 */
	function handleChange(field, value) {
		setValues((v) => ({ ...v, [field]: value }))
	}

	/**
	 * @param event
	 */
	async function handleSubmit(event) {
		event.preventDefault()
		setError(null)
		const changes = fields
			.filter((field) => {
				const original =
					row?.[field] === null || row?.[field] === undefined
						? ''
						: String(row[field])
				return original !== values[field]
			})
			.map((field) => ({ property: field, proposedValue: values[field] }))
		if (changes.length === 0) {
			setError('Wijzig minstens één veld voordat u een voorstel indient.')
			return
		}
		setSubmitting(true)
		const result = await onSubmit(changes, note)
		setSubmitting(false)
		if (!result || result.ok === false) {
			setError('Voorstel indienen is niet gelukt.')
			return
		}
		setNote('')
	}

	return (
		<form className="portaliq-propose-form" onSubmit={handleSubmit}>
			{fields.map((field) => (
				<div key={field} className="portaliq-field">
					<label htmlFor={`propose-${action.id}-${field}`}>{field}</label>
					<input
						id={`propose-${action.id}-${field}`}
						type="text"
						value={values[field] || ''}
						onChange={(event) => handleChange(field, event.target.value)}
					/>
				</div>
			))}
			<FormFieldTextbox
				label="Toelichting"
				value={note}
				onChange={(event) => setNote(event.target.value)}
			/>
			<div className="portaliq-propose-form__buttons">
				<Button
					type="submit"
					appearance="primary-action-button"
					disabled={submitting}>
					{submitting ? '…' : 'Voorstel indienen'}
				</Button>
				<Button type="button" appearance="subtle-button" onClick={onCancel}>
					Annuleren
				</Button>
			</div>
			{error && <p className="portaliq-error">{error}</p>}
		</form>
	)
}
