// SPDX-License-Identifier: EUPL-1.2
//
// Manifest-driven form (Phase 3). Renders exactly the action's WHITELISTED
// `fields`, shaped by the contribution-manifest-v3 UI-config: `fieldConfigs`
// (label / required / size / placeholder / help) and `optionsProviders` (static
// options, or a subject-scoped `collection` dropdown fetched through the adapter).
//
// SECURITY: the form can only ever submit the action's whitelisted fields — the
// server re-whitelists regardless, and a `collection` dropdown is populated
// through the subject-scoped endpoint, so it can only offer values the subject
// may already read. `fieldConfigs`/`optionsProviders` for a non-whitelisted field
// were already dropped by the server-side normaliser, so they never reach here.
//
// A field declared `type: file` (assignment-portal-file-upload) renders as a
// file picker. It is never part of the create body: the record is created
// first, then each picked file is uploaded into the field and the server
// writes the reference (src/portal/lib/fileFieldSubmit.js).

import React, { useEffect, useState } from 'react'
import { DEFAULT_MAX_SIZE_MB, fileFields, oversizedFiles, submitWithFiles, uploadFiles } from '../lib/fileFieldSubmit.js'

// Large/full fields render as a textarea; everything else a single-line input
// (unless an optionsProvider makes it a select).
/**
 *
 * @param cfg
 */
function isMultiline(cfg) {
	return cfg && (cfg.size === 'large' || cfg.size === 'full')
}

/**
 * A translator that only interpolates, for a caller that passes none.
 *
 * @param {string} key The English source string.
 * @param {Record<string, string|number>} [vars] Placeholder values.
 * @return {string} The interpolated string.
 */
function untranslated(key, vars) {
	let text = key
	for (const [name, value] of Object.entries(vars || {})) {
		text = text.replace(`{${name}}`, String(value))
	}
	return text
}

/**
 * The picker for one file field, with the chosen names and the size limit.
 *
 * @param {object} props Props.
 * @param {string} props.id The input id the label points at.
 * @param {object} props.cfg The field config (`multiple`, `accept`, `maxSizeMb`, `disabled`).
 * @param {File[]} props.files The files picked so far.
 * @param {(picked: FileList|null) => void} props.onPick Receives the picked FileList.
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @return {object} The element.
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-the-generic-portal-form-must-render-a-file-field-as-a-file-picker
 */
function FileFieldInput({ id, cfg, files, onPick, t }) {
	const accept = (cfg.accept || []).join(',')
	return (
		<>
			<input
				id={id}
				type="file"
				multiple={cfg.multiple === true}
				accept={accept || undefined}
				disabled={cfg.disabled}
				onChange={(e) => onPick(e.target.files)}
			/>
			{files.length > 0 && (
				<small className="portaliq-help">
					{t('Selected: {files}', { files: files.map((f) => f.name).join(', ') })}
				</small>
			)}
			<small className="portaliq-help">
				{t('Up to {size} MB per file', { size: cfg.maxSizeMb || DEFAULT_MAX_SIZE_MB })}
			</small>
		</>
	)
}

/**
 *
 * @param root0
 * @param root0.action
 * @param root0.api
 * @param root0.onSubmitted
 * @param {(key: string, vars?: object) => string} [root0.t] Optional translator; without one the English source strings render.
 */
export default function SchemaForm({ action, api, onSubmitted, t }) {
	const translate = t || untranslated
	const fields = action.fields || []
	const fieldConfigs = action.fieldConfigs || {}
	const optionsProviders = action.optionsProviders || {}
	const fileFieldNames = fileFields(action)

	const [values, setValues] = useState({})
	const [options, setOptions] = useState({})
	const [submitting, setSubmitting] = useState(false)
	const [error, setError] = useState(null)
	const [done, setDone] = useState(null)
	// Picked files per file field, and the files of a saved record that did
	// not attach (so they can be tried again against the same record).
	const [files, setFiles] = useState({})
	const [pending, setPending] = useState(null)
	// Bumped to remount the file inputs, which is the only way to clear them.
	const [fileInputKey, setFileInputKey] = useState(0)

	// Resolve `collection` option providers up front (static ones are inline).
	useEffect(() => {
		let cancelled = false
		for (const field of fields) {
			const p = optionsProviders[field]
			if (p && p.type === 'static') {
				setOptions((o) => ({ ...o, [field]: p.options || [] }))
			} else if (p && p.type === 'collection') {
				api.fetchOptions(p).then((opts) => {
					if (!cancelled) {
						setOptions((o) => ({ ...o, [field]: opts }))
					}
				})
			}
		}
		return () => { cancelled = true }
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [action.id])

	/**
	 *
	 * @param field
	 * @param value
	 */
	function setField(field, value) {
		setValues((v) => ({ ...v, [field]: value }))
	}

	/**
	 * Keep the files picked for one file field.
	 *
	 * @param {string} field The file field.
	 * @param {FileList|null} picked The picked files.
	 */
	function setFieldFiles(field, picked) {
		setFiles((f) => ({ ...f, [field]: Array.from(picked || []) }))
	}

	/**
	 * Report files that did not attach, or clear the report when all did.
	 *
	 * @param {string} id The saved record's id.
	 * @param {Array<{field: string, file: File}>} failed The files that did not attach.
	 */
	function reportFailed(id, failed) {
		if (failed.length === 0) {
			setPending(null)
			return
		}
		setPending({ id, failed })
		setError(translate('Saved, but these files were not attached: {files}', { files: failed.map((f) => f.file.name).join(', ') }))
	}

	/**
	 * Try the files that did not attach again, against the same record.
	 */
	async function retryFailed() {
		if (!pending) {
			return
		}
		const byField = {}
		for (const { field, file } of pending.failed) {
			byField[field] = [...(byField[field] || []), file]
		}
		setError(null)
		setSubmitting(true)
		const { failed } = await uploadFiles(api, action, pending.id, byField)
		setSubmitting(false)
		reportFailed(pending.id, failed)
		if (failed.length === 0) {
			setDone(translate('All files are attached.'))
		}
	}

	/**
	 *
	 * @param e
	 */
	async function submit(e) {
		e.preventDefault()
		setError(null)
		setDone(null)
		// Read the LIVE field values from the form element at submit time rather
		// than the React `values` state: a controlled input's onChange state
		// update can still be in flight on the first submit (it commits a render
		// behind), which would make a just-filled required field read as empty.
		// The DOM is the source of truth here; `values` is only the fallback.
		const formEl = e.currentTarget
		const submitValues = {}
		for (const field of fields) {
			if (fileFieldNames.includes(field)) {
				continue
			}
			const el = formEl.querySelector(`#f-${action.id}-${field}`)
			submitValues[field] = (el ? el.value : values[field]) ?? ''
		}
		// Client-side required check (the server is the authority regardless).
		for (const field of fields) {
			if (fileFieldNames.includes(field)) {
				if (fieldConfigs[field]?.required && (files[field] || []).length === 0) {
					setError(translate('Please choose a file for {field}.', { field: fieldConfigs[field]?.label || field }))
					return
				}
				continue
			}
			if (fieldConfigs[field]?.required && !submitValues[field]) {
				setError(`${fieldConfigs[field]?.label || field} is verplicht.`)
				return
			}
		}
		// Refuse an oversized file before the record is saved; the server
		// checks the same limit again.
		const tooLarge = oversizedFiles(action, files)
		if (tooLarge.length > 0) {
			setError(translate('These files are too large: {files}', { files: tooLarge.join(', ') }))
			return
		}
		setSubmitting(true)
		const result = await submitWithFiles(api, action, submitValues, files)
		setSubmitting(false)
		if (!result.ok) {
			setError('Opslaan is niet gelukt.')
			return
		}
		setValues({})
		setFiles({})
		setFileInputKey((k) => k + 1)
		reportFailed(result.id, result.failed)
		if (result.failed.length === 0) {
			setDone(action.successMessage || 'Opgeslagen.')
		}
		if (onSubmitted) {
			onSubmitted(result.object, action)
		}
	}

	return (
		<form className="portaliq-form" onSubmit={submit}>
			{fields.map((field) => {
				const cfg = fieldConfigs[field] || {}
				const label = cfg.label || field
				const opts = options[field]
				return (
					<div key={field} className={`portaliq-field portaliq-field-${cfg.size || 'medium'}`}>
						<label htmlFor={`f-${action.id}-${field}`}>
							{label}{cfg.required ? ' *' : ''}
						</label>
						{fileFieldNames.includes(field)
							? (
								<FileFieldInput
									key={`${field}-${fileInputKey}`}
									id={`f-${action.id}-${field}`}
									cfg={cfg}
									files={files[field] || []}
									onPick={(picked) => setFieldFiles(field, picked)}
									t={translate}
								/>
							)
							: opts
							? (
								<select
									id={`f-${action.id}-${field}`}
									value={values[field] || ''}
									disabled={cfg.disabled}
									onChange={(e) => setField(field, e.target.value)}
								>
									<option value="">—</option>
									{opts.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
								</select>
							)
							: isMultiline(cfg)
								? (
									<textarea
										id={`f-${action.id}-${field}`}
										value={values[field] || ''}
										placeholder={cfg.placeholder || ''}
										disabled={cfg.disabled}
										onChange={(e) => setField(field, e.target.value)}
									/>
								)
								: (
									<input
										id={`f-${action.id}-${field}`}
										type="text"
										value={values[field] || ''}
										placeholder={cfg.placeholder || ''}
										disabled={cfg.disabled}
										onChange={(e) => setField(field, e.target.value)}
									/>
								)}
						{cfg.help && <small className="portaliq-help">{cfg.help}</small>}
					</div>
				)
			})}
			<div className="portaliq-form-actions">
				<button type="submit" disabled={submitting}>
					{submitting ? '…' : (action.submitLabel || action.label || 'Opslaan')}
				</button>
			</div>
			{error && <p className="portaliq-error" role="alert">{error}</p>}
			{pending && (
				<button type="button" disabled={submitting} onClick={retryFailed}>
					{translate('Try these files again')}
				</button>
			)}
			{done && <p className="portaliq-success">{done}</p>}
		</form>
	)
}
