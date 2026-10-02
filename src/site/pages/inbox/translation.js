/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain logic behind a text AI translated (translated-message-notice,
 * decision D24), the language picker on the messages and news screens, and
 * whether a guardian has news. The same rules as the React portal's
 * TranslatedText.jsx, MessagesPage.jsx and NewsPage.jsx, without a framework.
 */

/**
 * The languages a guardian can pick: the ones hermiq writes a disclosure
 * sentence for. Dutch and English first, then the rest.
 */
export const MESSAGE_LANGUAGES = [
	'nl',
	'en',
	'ar',
	'tr',
	'pl',
	'uk',
	'de',
	'fr',
	'es',
	'it',
	'pt',
	'ro',
	'bg',
	'ru',
	'fa',
	'zh',
]

/**
 * The name of a language in the page language; the upper-cased tag where the
 * browser cannot name it.
 *
 * @param {string} tag A language tag such as `nl` or `pt-BR`.
 * @param {string} locale The page language.
 * @return {string} The name.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
 */
export function languageLabel(tag, locale) {
	if (!tag) {
		return ''
	}
	try {
		const names = new Intl.DisplayNames([locale || 'en'], { type: 'language' })
		const name = names.of(tag)
		if (name && name.toLowerCase() !== tag.toLowerCase()) {
			return name
		}
	} catch {
		// No Intl.DisplayNames, or a tag it refuses: fall back to the tag.
	}
	return String(tag).toUpperCase()
}

/**
 * A picker label: the language in the page language, plus its own name when
 * that differs, so a reader finds their language either way.
 *
 * @param {string} tag The language tag.
 * @param {string} locale The page language.
 * @return {string} For example "Arabic (العربية)".
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
 */
export function pickerLabel(tag, locale) {
	const inPage = languageLabel(tag, locale)
	const own = languageLabel(tag, tag)
	return own && own !== inPage ? `${inPage} (${own})` : inPage
}

/**
 * Whether a `translation` entry is a labelled AI translation worth showing.
 *
 * @param {object|undefined} translation The entry.
 * @return {boolean}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
 */
export function isLabelledTranslation(translation) {
	return Boolean(
		translation
		&& translation.translatedByAi === true
		&& typeof translation.text === 'string'
		&& translation.text !== '',
	)
}

/**
 * The notice sentence for a translation.
 *
 * @param {object} translation The entry.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {string} locale The page language.
 * @return {string} "Translated by AI from <language>", or without the language for `und`.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
 */
export function noticeText(translation, t, locale) {
	const source = translation?.sourceLanguage || 'und'
	if (source === 'und') {
		return t('Translated by AI')
	}
	return t('Translated by AI from {language}', {
		language: languageLabel(source, locale),
	})
}

/**
 * The id of the element that holds the original text.
 *
 * @param {string|number} id The message's id.
 * @return {string} The element id.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
 */
export function originalId(id) {
	return `portaliq-original-${String(id).replace(/[^A-Za-z0-9_-]/g, '-')}`
}

/**
 * Whether a translation also carries the item's title.
 *
 * @param {object|undefined} translation The entry.
 * @return {boolean}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
 */
export function hasTranslatedTitle(translation) {
	return (
		isLabelledTranslation(translation)
		&& typeof translation.title === 'string'
		&& translation.title !== ''
	)
}

/**
 * Whether the feed holds news, so the site only offers News to a guardian
 * who has some.
 *
 * @param {Array<object>|null} feed The feed.
 * @return {boolean}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
 */
export function hasNews(feed) {
	return Array.isArray(feed) && feed.length > 0
}

/**
 * Whether the archive holds a newsletter.
 *
 * @param {Array<object>|null} archive The archive.
 * @return {boolean}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
 */
export function hasArchive(archive) {
	return Array.isArray(archive) && archive.length > 0
}
