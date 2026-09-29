// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The asker's side of an access request (identity-access-requests, T04 and
// T05). A signed-in portal user asks for access to the cases of a company or
// person they act for, giving a reason, and follows every request they made:
// waiting, granted, or refused with the reason the organisation gave. The
// owner answers from the Access requests page in Nextcloud; a grant writes
// the mandate that opens the cases.

import { useEffect, useState } from 'react'
import Loading from './Loading.jsx'

/**
 * What is missing before a request can be sent, as an English source key, or
 * '' when nothing is.
 *
 * @param {{onBehalfOf: string, reason: string}} draft The form values.
 * @return {string} The problem, or ''.
 *
 * @spec openspec/specs/portal-access-requests/spec.md#requirement-you-ask-for-access-and-follow-your-request-req-iar-001
 */
export function requestProblem(draft) {
	if (String(draft?.onBehalfOf || '').trim() === '') {
		return 'Say whose cases you need access to.'
	}

	if (String(draft?.reason || '').trim() === '') {
		return 'Give a reason for your request.'
	}

	return ''
}

/**
 * The requests, newest first; a request without a date goes last.
 *
 * @param {Array<object>} requests The requests.
 * @return {Array<object>} A sorted copy.
 *
 * @spec openspec/specs/portal-access-requests/spec.md#requirement-you-ask-for-access-and-follow-your-request-req-iar-001
 */
export function newestFirst(requests) {
	const stamp = (request) => {
		const time = Date.parse(request?.requestedAt || '')
		return Number.isNaN(time) ? -Infinity : time
	}

	return [...(Array.isArray(requests) ? requests : [])].sort(
		(a, b) => stamp(b) - stamp(a),
	)
}

/**
 * The words a request's state reads as, as an English source key. Anything
 * the organisation has not answered yet reads as waiting.
 *
 * @param {string} state The stored state.
 * @return {string} The label key.
 *
 * @spec openspec/specs/portal-access-requests/spec.md#requirement-you-ask-for-access-and-follow-your-request-req-iar-001
 */
export function stateLabel(state) {
	if (state === 'granted') {
		return 'Granted'
	}

	if (state === 'refused') {
		return 'Refused'
	}

	return 'Waiting for an answer'
}

/**
 * A date in the reader's language, or the raw value when it does not parse.
 *
 * @param {string} value An ISO date.
 * @param {string} locale The reader's locale.
 * @return {string} The date.
 */
function formatDate(value, locale) {
	const time = Date.parse(value || '')
	if (Number.isNaN(time)) {
		return ''
	}

	try {
		return new Intl.DateTimeFormat(locale || 'nl', { dateStyle: 'long' }).format(
			new Date(time),
		)
	} catch {
		return new Date(time).toISOString().slice(0, 10)
	}
}

/**
 * The access-requests page.
 *
 * @param {object} props Props.
 * @param {object} props.api The portal API adapter.
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @param {string} [props.locale] The reader's locale.
 * @param {Array<object>|null} [props.initialRequests] Requests to show without fetching (test seam).
 * @return {object} The element.
 *
 * @spec openspec/specs/portal-access-requests/spec.md#requirement-you-ask-for-access-and-follow-your-request-req-iar-001
 */
export default function AccessRequestsPage({
	api,
	t,
	locale = 'nl',
	initialRequests = null,
}) {
	const [requests, setRequests] = useState(initialRequests || [])
	const [loading, setLoading] = useState(initialRequests === null)
	const [draft, setDraft] = useState({ onBehalfOf: '', reason: '' })
	const [problem, setProblem] = useState('')
	const [notice, setNotice] = useState('')
	const [busy, setBusy] = useState(false)

	useEffect(() => {
		if (initialRequests !== null) {
			return undefined
		}

		let live = true
		api.fetchMyAccessRequests().then((mine) => {
			if (live) {
				setRequests(mine)
				setLoading(false)
			}
		})
		return () => {
			live = false
		}
	}, [api, initialRequests])

	/**
	 * Send the request, then read the list again so it shows as waiting.
	 *
	 * @param {object} event The submit event.
	 * @return {Promise<void>} Nothing.
	 */
	async function handleSubmit(event) {
		event.preventDefault()
		setNotice('')
		const missing = requestProblem(draft)
		setProblem(missing)
		if (missing !== '') {
			return
		}

		setBusy(true)
		const outcome = await api.requestAccess(
			draft.onBehalfOf.trim(),
			draft.reason.trim(),
		)
		setBusy(false)
		if (!outcome.ok) {
			setProblem(
				outcome.error === 'reason_required'
					? 'Give a reason for your request.'
					: 'Your request could not be sent. Try again later.',
			)
			return
		}

		setDraft({ onBehalfOf: '', reason: '' })
		setNotice('Your request has been sent.')
		setRequests(await api.fetchMyAccessRequests())
	}

	const sorted = newestFirst(requests)

	return (
		<section className="portaliq-access" data-testid="access-requests">
			<h2>{t('Access to cases')}</h2>
			<p>
				{t(
					'Ask for access to the cases of a company or person you act for. The organisation answers your request.',
				)}
			</p>

			<form
				className="portaliq-access__form"
				onSubmit={handleSubmit}
				noValidate>
				<p>
					<label htmlFor="access-request-party">
						{t('Whose cases do you need access to?')}
					</label>
					<input
						id="access-request-party"
						name="onBehalfOf"
						data-testid="access-request-party"
						value={draft.onBehalfOf}
						onChange={(e) =>
							setDraft((d) => ({ ...d, onBehalfOf: e.target.value }))
						}
					/>
				</p>
				<p>
					<label htmlFor="access-request-reason">
						{t('Why do you need access?')}
					</label>
					<textarea
						id="access-request-reason"
						name="reason"
						data-testid="access-request-reason"
						value={draft.reason}
						onChange={(e) =>
							setDraft((d) => ({ ...d, reason: e.target.value }))
						}
					/>
				</p>

				{problem !== '' && (
					<p
						className="portaliq-error"
						role="alert"
						data-testid="access-request-problem">
						{t(problem)}
					</p>
				)}
				{notice !== '' && (
					<p role="status" data-testid="access-request-notice">
						{t(notice)}
					</p>
				)}

				<button
					type="submit"
					data-testid="access-request-submit"
					disabled={busy}>
					{t('Ask for access')}
				</button>
			</form>

			<h3>{t('Your requests')}</h3>
			{loading && <Loading t={t} />}
			{!loading && sorted.length === 0 && (
				<p data-testid="access-requests-empty">
					{t('You have not asked for access yet.')}
				</p>
			)}
			{!loading && sorted.length > 0 && (
				<ul
					className="portaliq-access__list"
					data-testid="access-requests-list">
					{sorted.map((request, index) => (
						<li
							key={request.id || request.uuid || `request-${index}`}
							data-testid="access-request-row"
							data-state={request.state || 'pending'}>
							<strong>
								{t('For {party}', {
									party: request.onBehalfOf || '',
								})}
							</strong>{' '}
							<span>{t(stateLabel(request.state))}</span>
							{request.requestedAt && (
								<span>
									{' '}
									{t('Asked on {date}', {
										date: formatDate(
											request.requestedAt,
											locale,
										),
									})}
								</span>
							)}
							{request.state === 'refused'
								&& request.decisionReason && (
									<p>
										{t('Reason: {reason}', {
											reason: request.decisionReason,
										})}
									</p>
								)}
						</li>
					))}
				</ul>
			)}
		</section>
	)
}
