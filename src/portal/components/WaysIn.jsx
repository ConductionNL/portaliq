// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The ways into the portal for a visitor without an account
// (identity-ways-in-screens): "Create an account", "Follow a case with its
// case number", and what a mailed link opens: an activation, an invitation or
// one case, read only. Each door renders only when the runtime config's
// `waysIn` says it leads somewhere (REQ-IWI-005).

import { useEffect, useState } from 'react'
import { registrationOutcomeText, solveChallenge, wayInRefusalText } from '../lib/waysIn.js'

/**
 * The sentence after a door that ends in an account.
 *
 * @param {Function} t The translator.
 * @param {string} emailSignIn The label of the e-mail sign-in, or ''.
 * @return {string} The text.
 */
function readyText(t, emailSignIn) {
	return emailSignIn
		? t('Your account is ready. Sign in with {provider}.', { provider: emailSignIn })
		: t('Your account is ready. You can sign in now.')
}

/**
 * "Create an account" (REQ-IWI-002): the challenge is solved in the browser,
 * the honeypot rides along empty, and the policy's outcome is shown.
 *
 * @param {object} props Props.
 * @param {object} props.api The portal API adapter.
 * @param {Function} props.t The translator.
 * @param {Function} [props.solve] The proof of work (test seam).
 * @return {object} The element.
 *
 * @spec openspec/changes/identity-ways-in-screens/tasks.md#T02
 */
export function CreateAccountForm({ api, t, solve = solveChallenge }) {
	const [email, setEmail] = useState('')
	const [name, setName] = useState('')
	const [honeypot, setHoneypot] = useState('')
	const [busy, setBusy] = useState(false)
	const [outcome, setOutcome] = useState(null)

	/**
	 * Send the registration.
	 *
	 * @param {Event} event The submit.
	 */
	async function submit(event) {
		event.preventDefault()
		setBusy(true)
		setOutcome(null)
		const challenge = (await api.challenge('registration')) || {}
		const solution = challenge.difficulty ? await solve(challenge.nonce, challenge.difficulty) : ''
		const answer = await api.registerAccount({
			email,
			displayName: name,
			challenge,
			solution,
			honeypot: challenge.honeypotField ? { field: challenge.honeypotField, value: honeypot } : null,
		})
		setBusy(false)
		setOutcome(answer.ok
			? { role: 'status', text: t(registrationOutcomeText(answer.data?.awaiting)) }
			: { role: 'alert', text: t(wayInRefusalText(answer.error)) })
	}

	return (
		<section className="portaliq-way-in" aria-labelledby="portaliq-way-in-register" data-testid="way-in-register">
			<h2 id="portaliq-way-in-register">{t('Create an account')}</h2>
			<form onSubmit={submit}>
				<label htmlFor="portaliq-register-email">{t('E-mail address')}</label>
				<input id="portaliq-register-email" type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
				<label htmlFor="portaliq-register-name">{t('Your name')}</label>
				<input id="portaliq-register-name" type="text" autoComplete="name" value={name} onChange={(event) => setName(event.target.value)} />
				{/* A field no person sees or fills; a bot that fills every field fills it. */}
				<div className="portaliq-way-in__trap" aria-hidden="true">
					<label htmlFor="portaliq-register-website">{t('Leave this field empty')}</label>
					<input id="portaliq-register-website" type="text" tabIndex={-1} autoComplete="off" value={honeypot} onChange={(event) => setHoneypot(event.target.value)} />
				</div>
				<button type="submit" disabled={busy}>{busy ? t('One moment…') : t('Create an account')}</button>
			</form>
			{outcome && (
				<p className={outcome.role === 'alert' ? 'portaliq-error' : 'portaliq-notice'} role={outcome.role} data-testid="way-in-register-result">{outcome.text}</p>
			)}
		</section>
	)
}

/**
 * "Follow a case with its case number" (REQ-IWI-003): the case types listed
 * are exactly the portal's that admit the reference kind.
 *
 * @param {object} props Props.
 * @param {object} props.api The portal API adapter.
 * @param {Function} props.t The translator.
 * @param {Array<object>} props.caseTypes `{ register, schema, caseType, label }` per case type.
 * @return {object} The element.
 *
 * @spec openspec/changes/identity-ways-in-screens/tasks.md#T04
 */
export function ReferenceLinkForm({ api, t, caseTypes }) {
	const [choice, setChoice] = useState(0)
	const [caseReference, setCaseReference] = useState('')
	const [email, setEmail] = useState('')
	const [busy, setBusy] = useState(false)
	const [outcome, setOutcome] = useState(null)

	/**
	 * Ask for the link.
	 *
	 * @param {Event} event The submit.
	 */
	async function submit(event) {
		event.preventDefault()
		const type = caseTypes[choice]
		if (!type) {
			return
		}
		setBusy(true)
		const answer = await api.requestReferenceLink({ ...type, caseReference, email })
		setBusy(false)
		// The server answers the same whether or not the number and the
		// address belong together, so the sentence does too.
		setOutcome(answer.ok
			? { role: 'status', text: t('If the case number and the e-mail address belong together, we sent a link to that address. It works once.') }
			: { role: 'alert', text: t(wayInRefusalText(answer.error)) })
	}

	return (
		<section className="portaliq-way-in" aria-labelledby="portaliq-way-in-reference" data-testid="way-in-reference">
			<h2 id="portaliq-way-in-reference">{t('Follow a case with its case number')}</h2>
			<form onSubmit={submit}>
				{caseTypes.length > 1 && (
					<>
						<label htmlFor="portaliq-reference-type">{t('Kind of case')}</label>
						<select id="portaliq-reference-type" value={choice} onChange={(event) => setChoice(Number(event.target.value))}>
							{caseTypes.map((type, index) => (
								<option key={`${type.register}/${type.schema}/${type.caseType}`} value={index}>{type.label}</option>
							))}
						</select>
					</>
				)}
				<label htmlFor="portaliq-reference-number">{t('Case number')}</label>
				<input id="portaliq-reference-number" type="text" value={caseReference} onChange={(event) => setCaseReference(event.target.value)} required />
				<label htmlFor="portaliq-reference-email">{t('E-mail address')}</label>
				<input id="portaliq-reference-email" type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
				<button type="submit" disabled={busy}>{t('Send me a link')}</button>
			</form>
			{outcome && (
				<p className={outcome.role === 'alert' ? 'portaliq-error' : 'portaliq-notice'} role={outcome.role} data-testid="way-in-reference-result">{outcome.text}</p>
			)}
		</section>
	)
}

/**
 * One case opened with a reference link: its fields as the case app
 * declared them, and no way to change anything (REQ-IWI-003).
 *
 * @param {object} props Props.
 * @param {Function} props.t The translator.
 * @param {string} props.caseReference The case number.
 * @param {object} props.record The case, projected by the case app.
 * @return {object} The element.
 *
 * @spec openspec/changes/identity-ways-in-screens/tasks.md#T05
 */
export function ReferenceCaseView({ t, caseReference, record }) {
	const entries = Object.entries(record || {}).filter(([key]) => !key.startsWith('@') && !key.startsWith('_') && key !== 'id' && key !== 'uuid')
	return (
		<section className="portaliq-reference-case" aria-labelledby="portaliq-reference-case-title" data-testid="reference-case">
			<h2 id="portaliq-reference-case-title">{t('Case {reference}', { reference: caseReference })}</h2>
			<p className="portaliq-notice">{t('You are viewing this case with a link. You cannot change anything here.')}</p>
			<dl>
				{entries.map(([key, value]) => (
					<div key={key}>
						<dt>{key}</dt>
						<dd>
							{Array.isArray(value)
								? (
									<ul>
										{value.map((item, index) => (
											<li key={index}>{typeof item === 'object' && item !== null ? Object.values(item).filter((part) => typeof part !== 'object').join(' · ') : String(item)}</li>
										))}
									</ul>
								)
								: String(typeof value === 'object' && value !== null ? JSON.stringify(value) : (value ?? ''))}
						</dd>
					</div>
				))}
			</dl>
		</section>
	)
}

/**
 * What a mailed link opens: an activation, an invitation, or one case.
 *
 * @param {object} props Props.
 * @param {object} props.api The portal API adapter.
 * @param {Function} props.t The translator.
 * @param {{kind: string, token: string}} props.link The consumed fragment.
 * @param {string} props.emailSignIn The label of the e-mail sign-in, or ''.
 * @param {string} props.portalName The portal's name, for the invitation.
 * @return {object} The element.
 *
 * @spec openspec/changes/identity-ways-in-screens/tasks.md#T06
 */
export function WayInLink({ api, t, link, emailSignIn, portalName }) {
	const [result, setResult] = useState(null)
	const [opened, setOpened] = useState(null)

	useEffect(() => {
		let gone = false
		if (link.kind === 'activate') {
			api.activateAccount(link.token).then((answer) => {
				if (!gone) {
					setResult(answer.ok ? { role: 'status', text: readyText(t, emailSignIn) } : { role: 'alert', text: t(wayInRefusalText(answer.error || 'activation_not_valid')) })
				}
			})
		}
		if (link.kind === 'reference') {
			api.redeemReferenceLink(link.token).then(async (answer) => {
				const read = answer.ok && answer.data?.bearer ? await api.referenceCase(answer.data.bearer) : null
				if (gone) {
					return
				}
				if (read?.case) {
					setOpened({ caseReference: read.caseReference || answer.data.caseReference, record: read.case })
				} else {
					setResult({ role: 'alert', text: t('This link is no longer valid.') })
				}
			})
		}
		return () => {
			gone = true
		}
	}, [api, link, emailSignIn, t])

	/**
	 * Accept the invitation.
	 */
	async function accept() {
		const answer = await api.acceptInvitation(link.token)
		setResult(answer.ok ? { role: 'status', text: readyText(t, emailSignIn) } : { role: 'alert', text: t(wayInRefusalText(answer.error || 'invitation_not_valid')) })
	}

	if (opened) {
		return <ReferenceCaseView t={t} caseReference={opened.caseReference} record={opened.record} />
	}

	return (
		<section className="portaliq-way-in" data-testid={`way-in-link-${link.kind}`}>
			{link.kind === 'invitation' && !result && (
				<>
					<h2>{t('You are invited to {portal}', { portal: portalName })}</h2>
					<button type="button" onClick={accept}>{t('Accept')}</button>
				</>
			)}
			{result && (
				<p className={result.role === 'alert' ? 'portaliq-error' : 'portaliq-notice'} role={result.role} data-testid="way-in-link-result">{result.text}</p>
			)}
		</section>
	)
}
