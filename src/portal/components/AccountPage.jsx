// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "My account" (identity-profile-page T07, T09, T10). The signed-in person's
// own account: their name, their e-mail addresses and phone numbers with the
// preferred one of each kind marked, how the organisation contacts them, and
// removing the account. A new e-mail address is used for nothing until the
// link in its confirmation mail is followed; the preferred confirmed address
// is where notifications go.

import { useCallback, useEffect, useState } from 'react'
import { refusalText } from '../../shared/account.js'
import Loading from './Loading.jsx'

const CHANNELS = [
	{ value: 'portal', label: 'Only through the portal' },
	{ value: 'email', label: 'By e-mail' },
	{ value: 'phone', label: 'By phone' },
	{ value: 'post', label: 'By post' },
]

/**
 * The prompt for a missing e-mail address, shown above the page after
 * sign-in while the account has no address in use (T09).
 *
 * @param {object} props Props.
 * @param {Function} props.t The translator.
 * @param {Function} props.onOpen Opens "My account".
 * @param {Function} props.onDismiss Hides the prompt for the session.
 * @return {object} The element.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-are-asked-for-an-e-mail-address-when-there-is-none-req-ipp-005
 */
export function ContactPrompt({ t, onOpen, onDismiss }) {
	return (
		<div className="portaliq-notice portaliq-contact-prompt" role="status" data-testid="contact-prompt">
			<p>{t('Add an e-mail address so we can tell you when something changes.')}</p>
			<button type="button" onClick={onOpen}>{t('Go to My account')}</button>
			{' '}
			<button type="button" onClick={onDismiss}>{t('Not now')}</button>
		</div>
	)
}

/**
 * One kind of address: the list, and a form to add one.
 *
 * @param {object} props Props.
 * @param {string} props.kind `email` or `phone`.
 * @param {Array<object>} props.entries The addresses of this kind.
 * @param {Function} props.t The translator.
 * @param {Function} props.act Runs an address action: (action, kind, value).
 * @return {object} The element.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-keep-several-addresses-one-of-each-kind-preferred-req-ipp-003
 */
function AddressList({ kind, entries, t, act }) {
	const [draft, setDraft] = useState('')
	const email = kind === 'email'
	const inputId = `portaliq-account-add-${kind}`
	return (
		<section className="portaliq-account__addresses" aria-labelledby={`portaliq-account-${kind}`}>
			<h3 id={`portaliq-account-${kind}`}>{email ? t('E-mail addresses') : t('Phone numbers')}</h3>
			{entries.length === 0 && <p>{email ? t('You have no e-mail address on your account.') : t('You have no phone number on your account.')}</p>}
			<ul>
				{entries.map((entry) => (
					<li key={entry.value} data-testid={`address-${kind}`}>
						<span>{entry.value}</span>
						{entry.preferred && <strong> ({t('Preferred')})</strong>}
						{email && !entry.confirmed && <em> ({t('Waiting for confirmation')})</em>}
						{' '}
						{email && !entry.confirmed && (
							<button type="button" onClick={() => act('add', kind, entry.value)}>{t('Send the link again')}</button>
						)}
						{!entry.preferred && (!email || entry.confirmed) && (
							<button type="button" onClick={() => act('prefer', kind, entry.value)}>{t('Make preferred')}</button>
						)}
						<button type="button" onClick={() => act('remove', kind, entry.value)}>{t('Remove')}</button>
					</li>
				))}
			</ul>
			<form onSubmit={(event) => { event.preventDefault(); act('add', kind, draft).then((ok) => ok && setDraft('')) }}>
				<label htmlFor={inputId}>{email ? t('New e-mail address') : t('New phone number')}</label>
				<input id={inputId} type={email ? 'email' : 'tel'} value={draft} onChange={(event) => setDraft(event.target.value)} required />
				<button type="submit">{email ? t('Add e-mail address') : t('Add phone number')}</button>
			</form>
		</section>
	)
}

/**
 * The "My account" page.
 *
 * @param {object} props Props.
 * @param {object} props.api The portal API adapter.
 * @param {Function} props.t The translator.
 * @param {Function} [props.onRemoved] Called after the account is removed: signs out.
 * @param {object|null} [props.initialDetails] An answer to show without fetching (test seam).
 * @param {boolean} [props.initialConfirmRemove] Open on the removal step (test seam).
 * @return {object} The element.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-see-your-own-account-req-ipp-001
 */
export default function AccountPage({ api, t, onRemoved = () => {}, initialDetails = null, initialConfirmRemove = false }) {
	const [details, setDetails] = useState(initialDetails)
	const [name, setName] = useState(initialDetails?.displayName || '')
	const [notice, setNotice] = useState('')
	const [error, setError] = useState('')
	const [confirmRemove, setConfirmRemove] = useState(initialConfirmRemove)

	const reload = useCallback(async () => {
		const answer = await api.getDetails()
		setDetails(answer || { failed: true })
		setName(answer?.displayName || '')
	}, [api])

	useEffect(() => {
		if (initialDetails === null) {
			reload()
		}
	}, [reload, initialDetails])

	/**
	 * Run one change, show its outcome, and read the account again.
	 *
	 * @param {Promise<object>} pending The api call.
	 * @param {string} done The notice on success.
	 * @return {Promise<boolean>} Whether it worked.
	 */
	async function run(pending, done) {
		setNotice('')
		setError('')
		const answer = await pending
		if (!answer || !answer.ok) {
			setError(t(refusalText(answer?.error)))
			return false
		}
		setNotice(done)
		await reload()
		return true
	}

	/**
	 * An address action from a list.
	 *
	 * @param {string} action `add`, `prefer` or `remove`.
	 * @param {string} kind `email` or `phone`.
	 * @param {string} value The address.
	 * @return {Promise<boolean>} Whether it worked.
	 */
	function act(action, kind, value) {
		if (action === 'prefer') {
			return run(api.preferContactAddress(kind, value), t('Your preferred address is changed.'))
		}
		if (action === 'remove') {
			return run(api.removeContactAddress(kind, value), t('The address is removed.'))
		}
		const sent = kind === 'email' ? t('We sent a link to {address}. Follow it to confirm the address.', { address: value }) : t('The phone number is added.')
		return run(api.addContactAddress(kind, value), sent)
	}

	/**
	 * Remove the account, then sign out.
	 */
	async function removeAccount() {
		const answer = await api.removeOwnAccount()
		if (!answer || !answer.ok) {
			setError(t(refusalText(answer?.error)))
			return
		}
		onRemoved()
	}

	if (details === null) {
		return <Loading t={t} />
	}

	if (details.failed) {
		return <p role="alert">{t('Your account cannot be shown right now.')}</p>
	}

	const entries = Array.isArray(details.contactAddresses) ? details.contactAddresses : []
	const channel = details.contactChannel || 'portal'
	return (
		<section className="portaliq-account" aria-labelledby="portaliq-account-title">
			<h2 id="portaliq-account-title">{t('My account')}</h2>
			{notice && <p className="portaliq-notice" role="status">{notice}</p>}
			{error && <p className="portaliq-error" role="alert">{error}</p>}

			<form onSubmit={(event) => { event.preventDefault(); run(api.setDisplayName(name), t('Your name is saved.')) }}>
				<label htmlFor="portaliq-account-name">{t('Name')}</label>
				<input id="portaliq-account-name" type="text" value={name} onChange={(event) => setName(event.target.value)} required />
				<button type="submit">{t('Save name')}</button>
			</form>

			<AddressList kind="email" entries={entries.filter((e) => e.kind === 'email')} t={t} act={act} />
			<AddressList kind="phone" entries={entries.filter((e) => e.kind === 'phone')} t={t} act={act} />

			<fieldset className="portaliq-account__channel">
				<legend>{t('How should we contact you?')}</legend>
				{CHANNELS.map((option) => (
					<label key={option.value}>
						<input
							type="radio"
							name="portaliq-contact-channel"
							value={option.value}
							checked={channel === option.value}
							onChange={() => run(api.setContactChannel(option.value), t('Your choice is saved.'))} />
						{' '}{t(option.label)}
					</label>
				))}
			</fieldset>

			<section className="portaliq-account__remove" aria-labelledby="portaliq-account-remove">
				<h3 id="portaliq-account-remove">{t('Remove my account')}</h3>
				{!confirmRemove && (
					<button type="button" onClick={() => setConfirmRemove(true)}>{t('Remove my account')}</button>
				)}
				{confirmRemove && (
					<div role="group" aria-labelledby="portaliq-account-remove">
						<p>{t('Your portal account is removed. Your cases stay with the organisation.')}</p>
						<button type="button" onClick={removeAccount}>{t('Yes, remove my account')}</button>
						{' '}
						<button type="button" onClick={() => setConfirmRemove(false)}>{t('Cancel')}</button>
					</div>
				)}
			</section>
		</section>
	)
}
