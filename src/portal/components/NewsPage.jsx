// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// School news for a guardian (news-and-newsletter-authoring), read-only, in the
// language the guardian picks (news-item-translation, decision D24). The server
// translates each body into the account's `messageLanguage` and keeps the
// original; a translated body renders through TranslatedText, which shows the
// AI notice and the original one click away, exactly as a message does.

import { useCallback, useEffect, useState } from 'react'
import { MessageLanguagePicker } from './MessagesPage.jsx'
import TranslatedText from './TranslatedText.jsx'

/**
 * Whether the feed holds anything to show, so the portal only offers the page
 * to a guardian who has news.
 *
 * @param {Array<object>|null} feed The feed.
 * @return {boolean}
 */
export function hasNews(feed) {
	return Array.isArray(feed) && feed.length > 0
}

/**
 * One news item: its title, then its body as written or translated.
 *
 * @param {object} props The props.
 * @param {object} props.item The feed row, carrying `translation` when translated.
 * @param {(key: string, vars?: object) => string} props.t The portal translator.
 * @param {string} props.locale The portal's locale.
 */
export function NewsItem({ item, t, locale }) {
	const id = item.id || item['@self']?.id || item.title || 'item'
	return (
		<article className="portaliq-news__item">
			<h3 className="portaliq-news__title">{item.title}</h3>
			<TranslatedText
				text={item.body || ''}
				translation={item.translation}
				t={t}
				locale={locale}
				id={`news-${id}`}
				bodyClassName="portaliq-news__body"
			/>
		</article>
	)
}

/**
 * @param {object} props The props.
 * @param {object} props.api The portal API adapter.
 * @param {(key: string, vars?: object) => string} props.t The portal translator.
 * @param {string} props.locale The portal's locale.
 */
export default function NewsPage({ api, t, locale }) {
	const [feed, setFeed] = useState(null)
	const [language, setLanguage] = useState('')
	const [error, setError] = useState('')

	const loadFeed = useCallback(async () => {
		setFeed((await api.fetchNewsFeed()) || [])
	}, [api])

	useEffect(() => {
		let live = true
		;(async () => {
			const details = await api.getDetails()
			if (live) {
				setLanguage(details?.messageLanguage || '')
			}
		})()
		loadFeed()
		return () => { live = false }
	}, [api, loadFeed])

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
		setFeed(null)
		loadFeed()
	}

	return (
		<section className="portaliq-news">
			<MessageLanguagePicker
				id="portaliq-news-language"
				label={t('Show messages in')}
				hint={t('News from school is translated by AI into your language. You can always see the original text.')}
				language={language}
				onChange={onLanguageChange}
				error={error}
				t={t}
				locale={locale}
			/>
			{feed === null && <p className="portaliq-loading">…</p>}
			{feed !== null && feed.length === 0 && <p className="portaliq-empty"><em>{t('No news yet.')}</em></p>}
			{hasNews(feed) && feed.map((item, i) => (
				<NewsItem key={item.id || item['@self']?.id || i} item={item} t={t} locale={locale} />
			))}
		</section>
	)
}
