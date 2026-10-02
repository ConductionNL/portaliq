// SPDX-License-Identifier: EUPL-1.2
//
// The resident's notice choices on the inbox page
// (inbox-notifications-and-preferences, REQ-NAP-008): per kind, e-mail and
// push, each a checkbox with its own label. Collapsed by default. The push
// column shows only when the account registered a device. The inbox message
// itself is not a choice: it is the record of what happened. When the
// organisation offers the government message box, one more row lets the
// resident switch letters to it off (inbox-berichtenbox-channel, REQ-MBC-005).
//
// @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-choices-live-on-the-inbox-page-req-nap-008

import { useEffect, useState } from 'react'
import { messageBoxChoice, withMessageBoxChoice } from '../../shared/messageBox.js'

const KINDS = [
	{ key: 'case.updated', label: 'Changes on your cases' },
	{ key: 'message.created', label: 'New messages' },
]

/**
 * @param {object} root0 Props.
 * @param {object} root0.api The portal API.
 * @param {Function} root0.t Translator.
 * @return {object|null} The settings section, or nothing until the choices load.
 */
export default function NotificationSettings({ api, t }) {
	const [loaded, setLoaded] = useState(null)
	const [choices, setChoices] = useState({})
	const [status, setStatus] = useState('')
	const [busy, setBusy] = useState(false)

	useEffect(() => {
		let alive = true
		api.fetchNotificationPreferences().then((answer) => {
			if (alive && answer) {
				setLoaded(answer)
				setChoices(answer.preferences)
			}
		})
		return () => {
			alive = false
		}
	}, [api])

	if (!loaded) {
		return null
	}

	const channels = loaded.pushAvailable ? ['email', 'push'] : ['email']
	const messageBox = messageBoxChoice(loaded)

	/**
	 * @param {string} kind The kind.
	 * @param {string} channel The channel.
	 * @param {boolean} value On or off.
	 */
	function set(kind, channel, value) {
		setStatus('')
		setChoices((c) => ({
			...c,
			[kind]: { ...(c[kind] || {}), [channel]: value },
		}))
	}

	/**
	 * @param {Event} event The submit.
	 */
	async function save(event) {
		event.preventDefault()
		setBusy(true)
		const saved = await api.saveNotificationPreferences(choices)
		setBusy(false)
		if (saved) {
			setChoices(saved.preferences)
			setStatus(t('Your choices are saved.'))
		} else {
			setStatus(t('Your choices could not be saved. Try again.'))
		}
	}

	return (
		<details className="portaliq-notification-settings">
			<summary>{t('Notification settings')}</summary>
			<form onSubmit={save}>
				<table>
					<thead>
						<tr>
							<th scope="col">
								<span className="portaliq-sr-only">
									{t('Notification settings')}
								</span>
							</th>
							{channels.map((channel) => (
								<th key={channel} scope="col">
									{channel === 'email' ? t('E-mail') : t('Push')}
								</th>
							))}
						</tr>
					</thead>
					<tbody>
						{KINDS.map((kind) => (
							<tr key={kind.key}>
								<th scope="row">{t(kind.label)}</th>
								{channels.map((channel) => {
									const id =
										`portaliq-notify-${kind.key}-${channel}`.replace(
											/\./g,
											'-',
										)
									return (
										<td key={channel}>
											<input
												id={id}
												type="checkbox"
												checked={
													choices[kind.key]?.[channel]
													!== false
												}
												onChange={(e) =>
													set(
														kind.key,
														channel,
														e.target.checked,
													)
												}
											/>
											<label
												htmlFor={id}
												className="portaliq-sr-only">
												{`${t(kind.label)}: ${channel === 'email' ? t('E-mail') : t('Push')}`}
											</label>
										</td>
									)
								})}
							</tr>
						))}
					</tbody>
				</table>
				{messageBox && (
					<p className="portaliq-notification-settings__message-box">
						<input
							id="portaliq-notify-message-box"
							type="checkbox"
							checked={choices.messageBox?.enabled !== false}
							onChange={(e) => {
								setStatus('')
								setChoices((c) =>
									withMessageBoxChoice(c, e.target.checked),
								)
							}}
						/>
						<label htmlFor="portaliq-notify-message-box">
							{t('Also send letters to {label}', {
								label: messageBox.label,
							})}
						</label>
					</p>
				)}
				<button type="submit" disabled={busy}>
					{t('Save')}
				</button>
				{status && <p role="status">{status}</p>}
			</form>
		</details>
	)
}
