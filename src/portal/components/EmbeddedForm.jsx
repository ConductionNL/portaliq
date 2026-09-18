// SPDX-License-Identifier: EUPL-1.2
//
// The form the embed frame actually renders (embed-frame-renders-the-form).
//
// 🔴 THIS IS WHAT WAS MISSING. templates/embed.php emitted <div
// id="portaliq-embed"> and loaded the portal bundle, whose entry point mounted
// only #portaliq-portal. So the frame route served a correct page, with a
// correct Content-Security-Policy and correct refusals, containing an empty
// div. The route worked. The policy worked. The form was never there.
//
// It survived because the end-to-end test fetched the page over plain HTTP and
// asserted the HTML contained the string 'portaliq-embed'. No browser, no
// JavaScript, so the assertion proved the route served a div and nothing at
// all about a form appearing on somebody's website.
//
// 🔴 A REFUSAL RENDERS TOO, AND IN WORDS. The frame is on a municipality's own
// page: a visitor who meets a blank rectangle has no way to know whether the
// form is broken, still loading, or simply not for them. Every refusal the
// controller can return has a sentence here.

import { useState } from 'react'
import { labelFor, refusalSentence } from '../embedCopy.js'

/**
 * The embedded form, or the reason there is not one.
 *
 * @param {object} props Props.
 * @param {object} props.payload The boot payload from the frame route.
 * @param {Function} [props.onSubmit] Submits the answers (test seam).
 * @return {object} The element.
 */
export default function EmbeddedForm({ payload, onSubmit = null }) {
	const fields = payload?.fields || []
	const [values, setValues] = useState({})
	const [reference, setReference] = useState(null)
	const [errors, setErrors] = useState([])
	const [busy, setBusy] = useState(false)

	const refusal = refusalSentence(payload)
	if (refusal !== null) {
		return (
			<div className="portaliq-embed-refusal" data-testid="embed-refusal" role="status">
				<p>{refusal}</p>
				{payload.portalUrl
					? <p><a href={payload.portalUrl}>Ga verder op ons eigen portaal</a></p>
					: null}
			</div>
		)
	}

	if (reference !== null) {
		return (
			<div className="portaliq-embed-confirmation" data-testid="embed-confirmation" role="status">
				<p>{payload?.settings?.confirmationText || 'Bedankt, wij hebben uw aanvraag ontvangen.'}</p>
				<p data-testid="embed-reference">Uw kenmerk: {reference}</p>
			</div>
		)
	}

	/**
	 * Send the answers.
	 *
	 * @param {object} event The submit event.
	 * @return {Promise<void>} Nothing.
	 */
	async function handleSubmit(event) {
		event.preventDefault()
		if (onSubmit === null) {
			return
		}

		setBusy(true)
		setErrors([])
		try {
			const result = await onSubmit(values)
			if (result?.reference) {
				setReference(result.reference)
				return
			}

			// 🔴 A REFUSED SUBMISSION SAYS SO. A form that quietly does nothing
			// on submit is one a visitor presses four times and then leaves,
			// believing they have applied.
			setErrors(result?.errors || ['Uw aanvraag kon niet worden verstuurd.'])
		} catch {
			setErrors(['Uw aanvraag kon niet worden verstuurd. Probeer het later opnieuw.'])
		} finally {
			setBusy(false)
		}
	}

	return (
		<form className="portaliq-embed-form" data-testid="embed-form" onSubmit={handleSubmit}>
			{fields.map((field) => (
				<p key={field.name} className="portaliq-embed-field">
					<label htmlFor={`embed-${field.name}`}>{labelFor(field)}</label>
					<input
						id={`embed-${field.name}`}
						name={field.name}
						data-testid={`embed-field-${field.name}`}
						required={field.required === true}
						defaultValue={field.preset || ''}
						onChange={(e) => setValues((v) => ({ ...v, [field.name]: e.target.value }))} />
				</p>
			))}

			{errors.length > 0
				? (
					<ul className="portaliq-embed-errors" data-testid="embed-errors" role="alert">
						{errors.map((error) => <li key={String(error)}>{String(error)}</li>)}
					</ul>
				)
				: null}

			<button type="submit" data-testid="embed-submit" disabled={busy}>
				{busy ? 'Bezig met versturen…' : 'Versturen'}
			</button>
		</form>
	)
}
