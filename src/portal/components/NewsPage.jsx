// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// School news for a guardian (news-and-newsletter-authoring), read-only, in the
// language the guardian picks (news-item-translation, decision D24). The server
// translates each body into the account's `messageLanguage` and keeps the
// original; a translated body renders through TranslatedText, which shows the
// AI notice and the original one click away, exactly as a message does. The
// title is translated with the body and shares its notice, and the newsletter
// archive renders its items through the same NewsItem
// (news-title-and-newsletter-translation).

import { useCallback, useEffect, useState } from 'react'
import { MessageLanguagePicker } from './MessagesPage.jsx'
import TranslatedText, { isLabelledTranslation } from './TranslatedText.jsx'

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
 * Whether the archive holds a newsletter to show.
 *
 * @param {Array<object>|null} archive The newsletter archive.
 * @return {boolean}
 */
export function hasArchive(archive) {
	return Array.isArray(archive) && archive.length > 0
}

/**
 * One news item: its title, then its body, as written or translated. A labelled
 * translation that carries a title shows it in the reader's language; the one
 * notice covers both and the original shows both.
 *
 * @param {object} props The props.
 * @param {object} props.item The feed row, carrying `translation` when translated.
 * @param {(key: string, vars?: object) => string} props.t The portal translator.
 * @param {string} props.locale The portal's locale.
 * @param {number} [props.level] The heading level of the title, 3 or 4.
 * @param {string} [props.idPrefix] Prefix of the ids, unique per place the item shows.
 */
export function NewsItem({ item, t, locale, level = 3, idPrefix = 'news' }) {
	const id = item.id || item['@self']?.id || item.title || 'item'
	const translation = item.translation
	const titled = isLabelledTranslation(translation) && typeof translation.title === 'string' && translation.title !== ''
	const Heading = level === 4 ? 'h4' : 'h3'
	return (
		<article className="portaliq-news__item">
			<Heading className="portaliq-news__title" lang={titled ? translation.targetLanguage : undefined}>
				{titled ? translation.title : item.title}
			</Heading>
			<TranslatedText
				text={item.body || ''}
				translation={translation}
				t={t}
				locale={locale}
				id={`${idPrefix}-${id}`}
				bodyClassName="portaliq-news__body"
				originalTitle={titled ? item.title : undefined}
			/>
		</article>
	)
}

/**
 * The newsletters sent to this guardian, each with its items shown as the News
 * page shows them: translated, with the AI notice and the original.
 *
 * @param {object} props The props.
 * @param {Array<object>|null} props.archive The archive, newest first, each newsletter carrying `items`.
 * @param {(key: string, vars?: object) => string} props.t The portal translator.
 * @param {string} props.locale The portal's locale.
 */
export function NewsletterArchive({ archive, t, locale }) {
	if (!hasArchive(archive)) {
		return null
	}
	return (
		<section className="portaliq-news__archive" aria-labelledby="portaliq-news-archive-heading">
			<h2 id="portaliq-news-archive-heading">{t('Newsletters')}</h2>
			{archive.map((newsletter, i) => {
				const newsletterId = newsletter.id || newsletter['@self']?.id || String(i)
				const items = Array.isArray(newsletter.items) ? newsletter.items : []
				return (
					<article key={newsletterId} className="portaliq-newsletter">
						<h3 className="portaliq-newsletter__title">{newsletter.title}</h3>
						{items.length === 0 && <p className="portaliq-empty"><em>{t('This newsletter has no items for you.')}</em></p>}
						{items.map((item, j) => (
							<NewsItem
								key={item.id || item['@self']?.id || j}
								item={item}
								t={t}
								locale={locale}
								level={4}
								idPrefix={`newsletter-${newsletterId}`}
							/>
						))}
					</article>
				)
			})}
		</section>
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
	const [archive, setArchive] = useState([])
	const [language, setLanguage] = useState('')
	const [error, setError] = useState('')

	const loadFeed = useCallback(async () => {
		const [items, newsletters] = await Promise.all([api.fetchNewsFeed(), api.fetchNewsletterArchive()])
		setFeed(items || [])
		setArchive(newsletters || [])
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
			<NewsletterArchive archive={archive} t={t} locale={locale} />
		</section>
	)
}
