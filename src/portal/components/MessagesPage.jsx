// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A guardian's conversations with school (guardian-direct-messages), read-only,
// in the language the guardian picks (translated-message-notice). The language
// picker writes the account's own `messageLanguage`; messages the server
// translated carry `translation` and render through TranslatedText, which shows
// the AI notice and the original one click away.

import { useCallback, useEffect, useState } from 'react'
import Loading from './Loading.jsx'
import TranslatedText, { languageLabel } from './TranslatedText.jsx'

/**
 * The languages a guardian can pick: the ones hermiq writes a disclosure
 * sentence for. Order: Dutch and English first, then the rest.
 */
export const MESSAGE_LANGUAGES = ['nl', 'en', 'ar', 'tr', 'pl', 'uk', 'de', 'fr', 'es', 'it', 'pt', 'ro', 'bg', 'ru', 'fa', 'zh']

/**
 * A picker label: the language in the portal's language, plus its own name
 * when that differs, so a reader who does not read the portal's language
 * still finds theirs.
 *
 * @param {string} tag The language tag.
 * @param {string} locale The portal's locale.
 * @return {string} For example "Arabic (العربية)".
 */
export function pickerLabel(tag, locale) {
	const inPortal = languageLabel(tag, locale)
	const own = languageLabel(tag, tag)
	return own && own !== inPortal ? `${inPortal} (${own})` : inPortal
}

/**
 * The language picker a guardian uses on the messages and the news page. It
 * writes the account's own `messageLanguage`, so both pages follow one choice.
 *
 * @param {object} props The props.
 * @param {string} props.id The select's id, unique on the page.
 * @param {string} props.label The label text.
 * @param {string} props.hint The sentence under the picker.
 * @param {string} props.language The current language, '' for as written.
 * @param {(event: Event) => void} props.onChange Change handler.
 * @param {string} [props.error] An error to announce.
 * @param {(key: string, vars?: object) => string} props.t The portal translator.
 * @param {string} props.locale The portal's locale.
 */
export function MessageLanguagePicker({ id, label, hint, language, onChange, error = '', t, locale }) {
	return (
		<div className="portaliq-messages__language">
			<label htmlFor={id}>{label}</label>
			<select id={id} value={language} onChange={onChange}>
				<option value="">{t('As written')}</option>
				{MESSAGE_LANGUAGES.map((tag) => (
					<option key={tag} value={tag}>{pickerLabel(tag, locale)}</option>
				))}
			</select>
			<p className="portaliq-messages__hint">{hint}</p>
			{error && <p className="portaliq-error" role="alert">{error}</p>}
		</div>
	)
}

/**
 * @param {string} value An ISO date-time.
 * @param {string} locale The portal's locale.
 * @return {string}
 */
function formatDateTime(value, locale) {
	if (!value) {
		return ''
	}
	try {
		return new Date(value).toLocaleString(locale === 'en' ? 'en-GB' : 'nl-NL')
	} catch {
		return String(value)
	}
}

/**
 * @param {object} props The props.
 * @param {object} props.api The portal API adapter.
 * @param {(key: string, vars?: object) => string} props.t The portal translator.
 * @param {string} props.locale The portal's locale.
 * @param {string} props.subjectRef The reader's own subjectRef.
 */
export default function MessagesPage({ api, t, locale, subjectRef }) {
	const [threads, setThreads] = useState(null)
	const [activeId, setActiveId] = useState(null)
	const [messages, setMessages] = useState(null)
	const [language, setLanguage] = useState('')
	const [error, setError] = useState('')

	useEffect(() => {
		let live = true
		;(async () => {
			const [list, details] = await Promise.all([api.fetchThreads(), api.getDetails()])
			if (!live) {
				return
			}
			setThreads(list)
			setLanguage(details?.messageLanguage || '')
			if (list.length > 0) {
				setActiveId(list[0].id || list[0]['@self']?.id || null)
			}
		})()
		return () => { live = false }
	}, [api])

	const loadMessages = useCallback(async (threadId) => {
		if (!threadId) {
			return
		}
		setMessages(null)
		setMessages((await api.fetchThreadMessages(threadId)) || [])
	}, [api])

	useEffect(() => { loadMessages(activeId) }, [activeId, loadMessages])

	/**
	 * @param {Event} event The change event of the picker.
	 */
	async function onLanguageChange(event) {
		const next = event.target.value
		setError('')
		const result = await api.setMessageLanguage(next)
		if (!result.ok) {
			setError(t('Your language choice could not be saved.'))
			return
		}
		setLanguage(next)
		loadMessages(activeId)
	}

	if (threads === null) {
		return <Loading t={t} />
	}

	return (
		<section className="portaliq-messages">
			<MessageLanguagePicker
				id="portaliq-message-language"
				label={t('Show messages in')}
				hint={t('Messages from school are translated by AI into this language. You can always see the original text.')}
				language={language}
				onChange={onLanguageChange}
				error={error}
				t={t}
				locale={locale}
			/>

			{threads.length === 0 && <p className="portaliq-empty"><em>{t('No conversations yet.')}</em></p>}

			{threads.length > 0 && (
				<nav className="portaliq-messages__threads" aria-label={t('Conversations')}>
					<ul>
						{threads.map((thread) => {
							const id = thread.id || thread['@self']?.id
							return (
								<li key={id}>
									<button
										type="button"
										aria-current={id === activeId ? 'true' : undefined}
										onClick={() => setActiveId(id)}
									>
										{thread.kind === 'group' ? t('Group conversation') : t('Conversation with school')}
										{' · '}
										{formatDateTime(thread.createdAt, locale)}
									</button>
								</li>
							)
						})}
					</ul>
				</nav>
			)}

			{activeId && messages === null && <Loading t={t} />}

			{messages && (
				<ol className="portaliq-messages__list">
					{messages.map((message, i) => {
						const id = message.id || message['@self']?.id || i
						const own = message.senderRef === subjectRef
						return (
							<li key={id} className={own ? 'portaliq-message portaliq-message--own' : 'portaliq-message'}>
								<div className="portaliq-message__header">
									<span className="portaliq-message__sender">{own ? t('You') : t('School')}</span>
									<span className="portaliq-message__date">{formatDateTime(message.sentAt, locale)}</span>
								</div>
								<TranslatedText text={message.body || ''} translation={message.translation} t={t} locale={locale} id={id} />
							</li>
						)
					})}
				</ol>
			)}
		</section>
	)
}
