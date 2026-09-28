// SPDX-License-Identifier: EUPL-1.2
//
// The confirm step of an endpoint row action (contribution-pay-screen): a
// guardian who pressed "Pay now" on a school contribution reads what they are
// about to do, and the notice the row carries (a voluntary contribution says
// so), before anything is sent. On confirm it runs the row-scoped forward and
// either sends the browser to the checkout the leaf app returned or says, in
// one line, what happened.
//
// No amount, no invoice state and no payment rule lives here: the server reads
// the row under the subject's scope and the leaf app decides the rest.

import { useEffect, useRef, useState } from 'react'
import { rowNotice, runRowAction } from '../lib/rowAction.js'

/**
 * The browser navigation, kept apart so a test can pass its own.
 *
 * @param {string} url An absolute https URL, already checked by redirectTarget.
 */
function goTo(url) {
	window.location.assign(url)
}

/**
 * Confirm and run one endpoint row action for one row.
 *
 * @param {object} root0 Props.
 * @param {object} root0.action The resolved endpoint row action.
 * @param {object} root0.collection The collection the row belongs to.
 * @param {object} root0.row The row.
 * @param {object} root0.api The portal api (forwardRowAction).
 * @param {(key: string) => string} root0.t The translator.
 * @param {() => void} [root0.onDone] Called after an answer that did not redirect (reload the list).
 * @param {() => void} [root0.onClose] Called when the subject closes the step.
 * @param {(url: string) => void} [root0.navigate] Where a checked redirect goes; defaults to the browser.
 * @return {object} The confirm step.
 */
export default function RowActionConfirm({ action, collection, row, api, t, onDone, onClose, navigate = goTo }) {
	const [busy, setBusy] = useState(false)
	const [message, setMessage] = useState('')
	const headingRef = useRef(null)
	const notice = rowNotice(collection, row)
	const label = action.label || action.id

	// Move focus into the step when it opens, so a keyboard or screen reader
	// user lands on what they are confirming.
	useEffect(() => {
		if (headingRef.current) {
			headingRef.current.focus()
		}
	}, [])

	/**
	 * Run the forward and handle its answer.
	 */
	async function onConfirm() {
		setBusy(true)
		const { redirect, messageKey } = await runRowAction(api, collection, row, action)
		if (redirect) {
			navigate(redirect)
			return
		}
		setBusy(false)
		setMessage(t(messageKey))
		if (onDone) {
			onDone()
		}
	}

	return (
		<section className="portaliq-rowaction-confirm" aria-label={label}>
			<h4 ref={headingRef} tabIndex={-1}>{label}</h4>
			{notice && <p className="portaliq-notice">{notice}</p>}
			<div className="portaliq-rowaction-buttons">
				{message === '' && (
					<button type="button" className="portaliq-cta" disabled={busy} onClick={onConfirm}>
						{busy ? t('Please wait…') : t('Continue')}
					</button>
				)}
				<button type="button" disabled={busy} onClick={() => onClose && onClose()}>
					{message === '' ? t('Cancel') : t('Close')}
				</button>
			</div>
			<p className="portaliq-rowaction-status" role="status">{message}</p>
		</section>
	)
}
