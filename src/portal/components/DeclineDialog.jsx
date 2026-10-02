// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Decline to sign a document from its row (case-actions-sign-a-document, D4,
// REQ-SGN-005). The dialog asks why and forwards the reason; a refusal keeps
// the dialog open with the answer in words.

import { useEffect, useRef, useState } from 'react'
import { outcome } from '../../shared/signing.js'

/**
 * The decline dialog for one row.
 *
 * @param {object} props Props.
 * @param {object} props.action The resolved `decline` row action.
 * @param {object} props.collection The collection the row belongs to.
 * @param {object} props.row The row.
 * @param {object} props.api The portal api (forwardRowAction).
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @param {() => void} [props.onDone] Called after a decline (reload the list).
 * @param {() => void} [props.onClose] Called when the resident closes the dialog.
 * @return {object} The dialog.
 *
 * @spec openspec/specs/portal-document-signing/spec.md#requirement-a-resident-can-decline-with-a-reason-req-sgn-005
 */
export default function DeclineDialog({ action, collection, row, api, t, onDone, onClose }) {
	const rowId = row && (row.id || row['@self']?.id)
	const documentName = row?.documentName || ''
	const [reason, setReason] = useState('')
	const [busy, setBusy] = useState(false)
	const [message, setMessage] = useState(null)
	const headingRef = useRef(null)

	useEffect(() => {
		if (headingRef.current) {
			headingRef.current.focus()
		}
	}, [])

	/**
	 * Send the decline with its reason and show the answer.
	 *
	 * @param {object} event The submit event.
	 * @return {Promise<void>} Nothing.
	 */
	async function onSubmit(event) {
		event.preventDefault()
		if (reason.trim() === '') {
			setMessage({ done: false, key: 'Give a reason.' })
			return
		}

		setBusy(true)
		const result = await api.forwardRowAction(collection, rowId, action.id, { reason: reason.trim() })
		const answer = outcome('decline', result, documentName)
		setBusy(false)
		setMessage(answer)
		if (answer.done && onDone) {
			onDone()
		}
	}

	const label = t(action.label || 'Decline to sign')
	const done = message !== null && message.done
	const fieldId = `decline-reason-${rowId}`

	return (
		<section className="portaliq-rowaction-confirm portaliq-decline" aria-label={label} data-testid="decline-dialog">
			<h4 ref={headingRef} tabIndex={-1}>{label}{documentName ? `: ${documentName}` : ''}</h4>
			<form onSubmit={onSubmit} noValidate>
				{!done && (
					<p>
						<label htmlFor={fieldId}>{t('Why do you decline?')}</label>
						<textarea id={fieldId} value={reason} data-testid="decline-reason" onChange={(e) => setReason(e.target.value)} />
					</p>
				)}
				<div className="portaliq-rowaction-buttons">
					{!done && (
						<button type="submit" className="portaliq-cta" data-testid="decline-submit" disabled={busy}>
							{busy ? t('Please wait…') : label}
						</button>
					)}
					<button type="button" disabled={busy} onClick={() => onClose && onClose()}>
						{done ? t('Close') : t('Cancel')}
					</button>
				</div>
			</form>
			<p className="portaliq-rowaction-status" role="status" data-testid="decline-status">
				{message ? t(message.key, message.vars) : ''}
			</p>
		</section>
	)
}
