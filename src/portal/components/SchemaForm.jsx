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

import React, { useEffect, useState } from 'react'

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
 *
 * @param root0
 * @param root0.action
 * @param root0.api
 * @param root0.onSubmitted
 */
export default function SchemaForm({ action, api, onSubmitted }) {
	const fields = action.fields || []
	const fieldConfigs = action.fieldConfigs || {}
	const optionsProviders = action.optionsProviders || {}

	const [values, setValues] = useState({})
	const [options, setOptions] = useState({})
	const [submitting, setSubmitting] = useState(false)
	const [error, setError] = useState(null)
	const [done, setDone] = useState(null)

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
			const el = formEl.querySelector(`#f-${action.id}-${field}`)
			submitValues[field] = (el ? el.value : values[field]) ?? ''
		}
		// Client-side required check (the server is the authority regardless).
		for (const field of fields) {
			if (fieldConfigs[field]?.required && !submitValues[field]) {
				setError(`${fieldConfigs[field]?.label || field} is verplicht.`)
				return
			}
		}
		setSubmitting(true)
		const result = await api.createObject(action, submitValues)
		setSubmitting(false)
		if (!result.ok) {
			setError('Opslaan is niet gelukt.')
			return
		}
		setValues({})
		setDone(action.successMessage || 'Opgeslagen.')
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
						{opts
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
			{error && <p className="portaliq-error">{error}</p>}
			{done && <p className="portaliq-success">{done}</p>}
		</form>
	)
}
