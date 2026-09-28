// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A message text that may have been translated by AI (translated-message-notice,
// decision D24). With a labelled `translation` the reader sees the translation,
// then a notice "Translated by AI from Dutch" in an `aside` landmark with a mark
// and text (never colour alone), and a button that shows the original in place.
// Without one, the text renders as written and no notice appears.

import { useState } from 'react'

/**
 * The name of a language, written in the portal's language. Falls back to
 * the upper-cased tag where the browser cannot name it.
 *
 * @param {string} tag A BCP-47 tag such as `nl` or `pt-BR`.
 * @param {string} locale The portal's locale, such as `en` or `nl`.
 * @return {string} The language's name.
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
		// No Intl.DisplayNames, or a tag it refuses: fall through to the tag.
	}
	return String(tag).toUpperCase()
}

/**
 * The notice sentence for a translation.
 *
 * @param {object} translation The `translation` entry.
 * @param {(key: string, vars?: object) => string} t The portal translator.
 * @param {string} locale The portal's locale.
 * @return {string} "Translated by AI from <language>", or "Translated by AI" for `und`.
 */
export function noticeText(translation, t, locale) {
	const source = translation.sourceLanguage || 'und'
	if (source === 'und') {
		return t('Translated by AI')
	}
	return t('Translated by AI from {language}', { language: languageLabel(source, locale) })
}

/**
 * Whether a `translation` entry is a labelled AI translation worth showing.
 *
 * @param {object|undefined} translation The entry.
 * @return {boolean}
 */
export function isLabelledTranslation(translation) {
	return Boolean(translation && translation.translatedByAi === true && typeof translation.text === 'string' && translation.text !== '')
}

/**
 * @param {object} props The props.
 * @param {string} props.text The text as written.
 * @param {object} [props.translation] The reader's translation entry, if any.
 * @param {(key: string, vars?: object) => string} props.t The portal translator.
 * @param {string} props.locale The portal's locale.
 * @param {string} props.id A stable id for this message, used to wire the button to the original.
 * @param {string} [props.bodyClassName] Class for the shown text.
 * @param {boolean} [props.defaultOpen] Start with the original shown.
 * @param {string} [props.originalTitle] A title translated with the text (a news
 *     item's): the original then shows it above the text, under the same notice.
 */
export default function TranslatedText({ text, translation, t, locale, id, bodyClassName = 'portaliq-message__body', defaultOpen = false, originalTitle }) {
	const [open, setOpen] = useState(defaultOpen)

	if (!isLabelledTranslation(translation)) {
		return <p className={bodyClassName}>{text}</p>
	}

	const originalId = `portaliq-original-${String(id).replace(/[^A-Za-z0-9_-]/g, '-')}`
	const source = translation.sourceLanguage && translation.sourceLanguage !== 'und' ? translation.sourceLanguage : undefined

	return (
		<div className="portaliq-translated">
			<p className={bodyClassName} lang={translation.targetLanguage}>{translation.text}</p>
			<aside className="portaliq-ai-notice" aria-label={t('AI translation')}>
				<span className="portaliq-ai-notice__mark" aria-hidden="true">AI</span>
				<span className="portaliq-ai-notice__text">{noticeText(translation, t, locale)}</span>
				{translation.disclosure && (
					<span className="portaliq-ai-notice__disclosure" lang={translation.disclosureLanguage || undefined}>
						{translation.disclosure}
					</span>
				)}
				<button
					type="button"
					className="portaliq-ai-notice__toggle"
					aria-expanded={open ? 'true' : 'false'}
					aria-controls={originalId}
					onClick={() => setOpen(!open)}
				>
					{open ? t('Hide the original text') : t('Show the original text')}
				</button>
			</aside>
			<blockquote id={originalId} className="portaliq-translated__original" lang={source} hidden={!open}>
				{originalTitle
					? (
						<>
							<p className="portaliq-translated__original-title">{originalTitle}</p>
							<p>{text}</p>
						</>
					)
					: text}
			</blockquote>
		</div>
	)
}
