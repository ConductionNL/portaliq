// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Sign a document from its row (case-actions-sign-a-document, D4, REQ-SGN-004).
// The dialog fetches the document through the contribution's `viewDocument`
// row action and shows it with a download link. The sign button stays disabled
// until the resident ticks that they read it, and only then is `{consent: true}`
// sent through the row-scoped forward. Without a `viewDocument` action there
// is nothing to read, so there is nothing to sign either.

import { useEffect, useRef, useState } from 'react'
import { documentView, outcome } from '../../shared/signing.js'

/**
 * The signing dialog for one row.
 *
 * @param {object} props Props.
 * @param {object} props.action The resolved `sign` row action.
 * @param {object|null} props.viewAction The resolved `viewDocument` row action, or null.
 * @param {object} props.collection The collection the row belongs to.
 * @param {object} props.row The row.
 * @param {object} props.api The portal api (forwardRowAction).
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @param {() => void} [props.onDone] Called after a signature (reload the list).
 * @param {() => void} [props.onClose] Called when the resident closes the dialog.
 * @param {object|null} [props.initialDocument] A document view to show without fetching (test seam).
 * @return {object} The dialog.
 *
 * @spec openspec/specs/portal-document-signing/spec.md#requirement-the-resident-reads-the-document-before-signing-it-req-sgn-004
 */
export default function SigningDialog({ action, viewAction, collection, row, api, t, onDone, onClose, initialDocument = null }) {
	const rowId = row && (row.id || row['@self']?.id)
	const [document, setDocument] = useState(initialDocument || (viewAction ? { state: 'loading' } : { state: 'unavailable' }))
	const [read, setRead] = useState(false)
	const [busy, setBusy] = useState(false)
	const [message, setMessage] = useState(null)
	const headingRef = useRef(null)
	const documentName = (document.state === 'shown' && document.name) || row?.documentName || ''

	useEffect(() => {
		if (headingRef.current) {
			headingRef.current.focus()
		}
	}, [])

	useEffect(() => {
		if (initialDocument || !viewAction || !rowId) {
			return undefined
		}

		let live = true
		api.forwardRowAction(collection, rowId, viewAction.id).then((result) => {
			if (live) {
				setDocument(documentView(result))
			}
		})
		return () => {
			live = false
		}
	}, [api, collection, rowId, viewAction, initialDocument])

	/**
	 * Send the signature and show the answer.
	 *
	 * @return {Promise<void>} Nothing.
	 */
	async function onSign() {
		setBusy(true)
		const result = await api.forwardRowAction(collection, rowId, action.id, { consent: true })
		const answer = outcome('sign', result, documentName)
		setBusy(false)
		setMessage(answer)
		if (answer.done && onDone) {
			onDone()
		}
	}

	const label = t(action.label || 'Sign')
	const done = message !== null && message.done

	return (
		<section className="portaliq-rowaction-confirm portaliq-signing" aria-label={label} data-testid="signing-dialog">
			<h4 ref={headingRef} tabIndex={-1}>{label}{documentName ? `: ${documentName}` : ''}</h4>

			{document.state === 'loading' && <p role="status">{t('Loading the document…')}</p>}

			{document.state === 'unavailable' && (
				<p className="portaliq-notice" data-testid="signing-unavailable">{t('This document cannot be shown here.')}</p>
			)}

			{document.state === 'shown' && (
				<div className="portaliq-signing__document">
					{document.inline
						? (
							<object data={document.href} type={document.mimeType} className="portaliq-signing__preview" aria-label={documentName}>
								<p>{t('This document cannot be shown here.')}</p>
							</object>
						)
						: <p className="portaliq-notice">{t('This document is too large to show here. Download it to read it.')}</p>}
					<p>
						<a href={document.href} download={document.name} data-testid="signing-download">
							{t('Download {documentName}', { documentName: document.name })}
						</a>
					</p>
				</div>
			)}

			{document.state === 'shown' && !done && (
				<p>
					<input
						id={`signing-read-${rowId}`}
						type="checkbox"
						checked={read}
						data-testid="signing-read"
						onChange={(e) => setRead(e.target.checked)} />
					<label htmlFor={`signing-read-${rowId}`}>{t('I have read this document and I sign it.')}</label>
				</p>
			)}

			<div className="portaliq-rowaction-buttons">
				{document.state === 'shown' && !done && (
					<button type="button" className="portaliq-cta" data-testid="signing-submit" disabled={!read || busy} onClick={onSign}>
						{busy ? t('Please wait…') : label}
					</button>
				)}
				<button type="button" disabled={busy} onClick={() => onClose && onClose()}>
					{done ? t('Close') : t('Cancel')}
				</button>
			</div>

			<p className="portaliq-rowaction-status" role="status" data-testid="signing-status">
				{message ? t(message.key, message.vars) : ''}
			</p>
		</section>
	)
}
