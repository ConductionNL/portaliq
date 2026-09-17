// SPDX-License-Identifier: EUPL-1.2
//
// The citizen's own case: what they may correct, what they may add, and what
// the municipality calls the status in public.
//
// Nothing on this screen decides what may be changed. The server answers with
// a writable set resolved from the case type, and this component renders it:
// an open field is an input, a closed field is text with the sentence that
// says why. A disabled control with no explanation is the thing this replaces.

import React, { useCallback, useEffect, useState } from 'react'

/**
 * One field, open or closed, always with its reason when closed.
 *
 * @param {object} root0 Props.
 * @param {string} root0.field The field name.
 * @param {object} root0.state The field's `{ writable, reason }` state.
 * @param {*} root0.value The current answer.
 * @param {Function} root0.onChange Called with the new answer.
 * @param {Function} root0.t The translator.
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
const CaseField = React.memo(function CaseField({ field, state, value, onChange, t }) {
	const writable = state?.writable === true
	const text = (value === null || value === undefined) ? '' : String(value)
	return (
		<div className={writable ? 'portaliq-case-field open' : 'portaliq-case-field closed'} data-testid={`case-field-${field}`}>
			<label htmlFor={`case-field-input-${field}`}>{field}</label>
			{writable
				? (
					<input
						id={`case-field-input-${field}`}
						type="text"
						value={text}
						data-testid={`case-input-${field}`}
						onChange={(e) => onChange(field, e.target.value)}
					/>
				)
				: (
					<>
						<p className="portaliq-case-value" data-testid={`case-value-${field}`}>{text}</p>
						<p className="portaliq-case-reason" data-testid={`case-reason-${field}`}>
							{state?.reason || t('This answer cannot be changed from the portal.')}
						</p>
					</>
				)}
		</div>
	)
})

/**
 * The citizen's case screen: the public status, the answers, and the document
 * the citizen may still add.
 *
 * @param {object} root0 Props.
 * @param {object} root0.collection The manifest collection the case lives in.
 * @param {object} root0.row The case row selected in the table.
 * @param {object} root0.api The portal API adapter.
 * @param {Function} root0.t The translator.
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
export default function CitizenCase({ collection, row, api, t }) {
	const caseId = row && (row.id || row['@self']?.id)
	const [state, setState] = useState({ loading: true, data: null })
	const [draft, setDraft] = useState({})
	const [notice, setNotice] = useState(null)
	const [busy, setBusy] = useState(false)

	const load = useCallback(async () => {
		if (!caseId) {
			return
		}
		setState({ loading: true, data: null })
		const data = await api.fetchCitizenCase(collection, caseId)
		setState({ loading: false, data })
		setDraft({})
	}, [api, collection, caseId])

	useEffect(() => { load() }, [load])

	if (!caseId) {
		return <p className="portaliq-empty"><em>{t('Select a case.')}</em></p>
	}
	if (state.loading) {
		return <p className="portaliq-case-loading">…</p>
	}
	if (!state.data) {
		return <p className="portaliq-error" data-testid="case-unavailable">{t('This case is not yours.')}</p>
	}

	const { writableSet } = state.data
	const caseRow = state.data.case || {}
	const fieldStates = writableSet?.fields || {}
	const windowOpen = writableSet?.window?.open === true
	const documentsOpen = writableSet?.documents?.open === true
	// Every field the case carries is shown; the set decides which are open.
	const fields = Object.keys(caseRow).filter((key) => key !== '@self' && key !== '_files')

	/**
	 * Hold one corrected answer until the citizen saves.
	 *
	 * @param {string} field The field name.
	 * @param {string} value The new answer.
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	function onFieldChange(field, value) {
		setDraft((d) => ({ ...d, [field]: value }))
	}

	/**
	 * Send the corrections. A refusal is shown as the sentence the server
	 * gave, never as a silent failure.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	async function onSave() {
		if (Object.keys(draft).length === 0) {
			return
		}
		setBusy(true)
		const result = await api.amendCitizenCase(collection, caseId, draft)
		setBusy(false)
		setNotice(result.ok ? t('Your change has been saved.') : (result.message || t('The change could not be saved. Please try again.')))
		if (result.ok) {
			load()
		}
	}

	/**
	 * Add a document to the case.
	 *
	 * @param {Event} e The file input's change event.
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	async function onAddDocument(e) {
		const file = e.target.files && e.target.files[0]
		if (!file) {
			return
		}
		setBusy(true)
		const result = await api.addCitizenDocument(collection, caseId, file)
		setBusy(false)
		e.target.value = ''
		setNotice(result.ok
			? t('{name} has been added to your case.', { name: result.document?.name || file.name })
			: (result.message || t('The document could not be added. Please try again.')))
		if (result.ok) {
			load()
		}
	}

	return (
		<section className="portaliq-citizen-case" data-testid="citizen-case">
			{writableSet?.status?.label && (
				<div className="portaliq-case-status" data-testid="case-status">
					<h3>{writableSet.status.label}</h3>
					{writableSet.status.description && <p>{writableSet.status.description}</p>}
				</div>
			)}

			{!windowOpen && (
				<p className="portaliq-case-closed" data-testid="case-window-closed">{writableSet?.window?.reason || ''}</p>
			)}

			<div className="portaliq-case-fields">
				{fields.map((field) => (
					<CaseField
						key={field}
						field={field}
						state={fieldStates[field]}
						value={draft[field] !== undefined ? draft[field] : caseRow[field]}
						onChange={onFieldChange}
						t={t}
					/>
				))}
			</div>

			{windowOpen && (
				<button
					type="button"
					className="portaliq-case-save"
					data-testid="case-save"
					disabled={busy || Object.keys(draft).length === 0}
					onClick={onSave}
				>
					{t('Save my change')}
				</button>
			)}

			<div className="portaliq-case-documents" data-testid="case-documents">
				<h4>{t('Documents')}</h4>
				<ul>
					{(state.data.documents || []).map((file) => (
						<li key={file.id || file.name} data-testid="case-document">{file.name}</li>
					))}
				</ul>
				{documentsOpen
					? (
						<label>
							{t('Add a document')}
							<input type="file" data-testid="case-add-document" disabled={busy} onChange={onAddDocument} />
						</label>
					)
					: (
						<p className="portaliq-case-reason" data-testid="case-documents-closed">
							{writableSet?.documents?.reason || ''}
						</p>
					)}
			</div>

			{notice && <p className="portaliq-case-notice" data-testid="case-notice">{notice}</p>}
		</section>
	)
}
