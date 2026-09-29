// SPDX-License-Identifier: EUPL-1.2
//
// The confirmation step before a resident withdraws their request
// (case-actions-withdraw-screen, REQ-WDS-002). It says what withdrawing
// means, in the case app's words when it gives them, asks an optional reason,
// and sends nothing until the resident presses "Withdraw request". Focus
// moves to the heading when the step opens.
//
// @spec openspec/changes/case-actions-withdraw-screen/specs/citizen-case-withdraw-screen/spec.md#requirement-withdrawing-takes-a-confirmation-with-an-optional-reason-req-wds-002

import { useEffect, useRef, useState } from 'react'

/**
 * @param {object} root0 Props.
 * @param {Function} root0.t The translator.
 * @param {string} [root0.confirmText] The case app's own words, if any.
 * @param {boolean} root0.busy Whether the withdrawal is being sent.
 * @param {Function} root0.onConfirm Called with the reason.
 * @param {Function} root0.onCancel Called when the resident keeps the request.
 * @return {object}
 */
export default function WithdrawCaseConfirm({ t, confirmText, busy, onConfirm, onCancel }) {
	const [reason, setReason] = useState('')
	const headingRef = useRef(null)

	useEffect(() => {
		headingRef.current?.focus()
	}, [])

	/**
	 * @param {Event} event The submit.
	 */
	function submit(event) {
		event.preventDefault()
		onConfirm(reason.trim())
	}

	return (
		<form className="portaliq-case-withdraw-confirm" data-testid="case-withdraw-confirm" onSubmit={submit}>
			<h4 ref={headingRef} tabIndex={-1}>{t('Withdraw this request?')}</h4>
			<p>{confirmText || t('If you withdraw, we stop handling your request. You cannot undo this.')}</p>
			<label htmlFor="portaliq-withdraw-reason">{t('Why are you withdrawing? (optional)')}</label>
			<textarea
				id="portaliq-withdraw-reason"
				data-testid="case-withdraw-reason"
				value={reason}
				onChange={(e) => setReason(e.target.value)}
			/>
			<div className="portaliq-case-withdraw-buttons">
				<button type="submit" data-testid="case-withdraw-submit" disabled={busy}>{t('Withdraw request')}</button>
				<button type="button" data-testid="case-withdraw-cancel" onClick={onCancel}>{t('Keep my request')}</button>
			</div>
		</form>
	)
}
