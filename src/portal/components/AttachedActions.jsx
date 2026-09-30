// SPDX-License-Identifier: EUPL-1.2
//
// Another app's actions on this record (woo-journey-entry-points D3): on a
// dossier, pipelinq's "Stel een vraag over dit dossier" and dossiq's "Start
// een Woo-verzoek". One button per action; choosing one opens its fields,
// labelled from the action's own fieldConfigs, and forwards them with the
// record's id, which the server proves belongs to the resident first.

import { useState } from 'react'
import {
	attachedActionsOf,
	fieldLabel,
	runAttachedAction,
} from '../lib/attachedActions.js'

/**
 * The attached actions of one record.
 *
 * @param {object} props            The props.
 * @param {object} props.collection The collection the record belongs to.
 * @param {object} props.row        The record on screen.
 * @param {object} props.api        The portal api.
 * @param {(key: string) => string} props.t The translator.
 * @return {object|null} The buttons and the open form, or nothing.
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
 */
export default function AttachedActions({ collection, row, api, t }) {
	const actions = attachedActionsOf(collection)
	const [open, setOpen] = useState(null)
	const [values, setValues] = useState({})
	const [busy, setBusy] = useState(false)
	const [message, setMessage] = useState('')

	if (actions.length === 0 || !row || !api) {
		return null
	}

	/**
	 * Send the open action.
	 *
	 * @param {object} event The submit event.
	 */
	async function onSubmit(event) {
		event.preventDefault()
		setBusy(true)
		const result = await runAttachedAction(api, collection, row, open, values)
		setBusy(false)
		if (
			result.ok
			&& typeof open.successMessage === 'string'
			&& open.successMessage !== ''
		) {
			setMessage(open.successMessage)
		} else {
			setMessage(t(result.messageKey))
		}
		if (result.ok) {
			setOpen(null)
			setValues({})
		}
	}

	return (
		<section
			className="portaliq-attached-actions"
			data-testid="attached-actions">
			{open === null
				&& actions.map((action) => (
					<button
						key={`${action.app}:${action.id}`}
						type="button"
						className="portaliq-cta"
						data-testid={`attached-action-${action.id}`}
						onClick={() => {
							setOpen(action)
							setMessage('')
						}}>
						{action.label || action.id}
					</button>
				))}
			{open !== null && (
				<form
					className="portaliq-action-form"
					aria-label={open.label || open.id}
					onSubmit={onSubmit}>
					<h4>{open.label || open.id}</h4>
					{(open.fields || [])
						.filter((field) => typeof field === 'string')
						.map((field) => (
							<p key={field}>
								<label htmlFor={`attached-${open.id}-${field}`}>
									{fieldLabel(open, field)}
								</label>
								<textarea
									id={`attached-${open.id}-${field}`}
									value={values[field] || ''}
									required={
										open.fieldConfigs?.[field]?.required === true
									}
									onChange={(event) =>
										setValues((v) => ({
											...v,
											[field]: event.target.value,
										}))
									}
								/>
							</p>
						))}
					<div className="portaliq-rowaction-buttons">
						<button
							type="submit"
							className="portaliq-cta"
							disabled={busy}>
							{busy
								? t('Please wait…')
								: open.submitLabel || t('Send')}
						</button>
						<button
							type="button"
							className="portaliq-cta-subtle"
							onClick={() => setOpen(null)}>
							{t('Cancel')}
						</button>
					</div>
				</form>
			)}
			{message !== '' && <p role="status">{message}</p>}
		</section>
	)
}
