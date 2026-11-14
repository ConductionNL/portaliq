// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// unavailable.js: what a resident reads when a form takes no submissions
// right now (board FormulierNietBeschikbaar): maintenance, replaced, full, not
// yet open or closed. The server decides the situation; this only words it.

const ZONE = 'Europe/Amsterdam'

const WORDS = {
	nl: {
		maintenanceTitle: 'Dit formulier is even niet beschikbaar',
		window: '{day} van {from} tot {until} uur',
		windowSpan: 'Van {from} tot {until} uur',
		draftKept: 'Was u al bezig? Uw opgeslagen antwoorden blijven bewaard.',
		replacedTitle: 'Dit formulier is niet meer beschikbaar',
		replacedLink: 'Naar het nieuwe formulier',
		fullTitle: 'Er kunnen nu geen aanvragen meer bij',
		fullTotal: 'Alle {count} plekken zijn vergeven.',
		fullMonth: 'Het maximum van {count} aanvragen voor deze maand is bereikt.',
		fullYear: 'Het maximum van {count} aanvragen voor dit jaar is bereikt.',
		reopens: 'Vanaf {date} kunt u weer een aanvraag doen.',
		notYetOpenTitle: 'Dit formulier is nog niet beschikbaar',
		opensOn: 'Dit formulier opent op {date}.',
		closedTitle: 'Dit formulier is niet meer beschikbaar',
		contact: 'Heeft u een vraag? Neem dan contact met ons op.',
	},
	en: {
		maintenanceTitle: 'This form is briefly unavailable',
		window: '{day} from {from} to {until}',
		windowSpan: 'From {from} to {until}',
		draftKept: 'Had you already started? Your saved answers are kept.',
		replacedTitle: 'This form is no longer available',
		replacedLink: 'Go to the new form',
		fullTitle: 'No more applications can be made right now',
		fullTotal: 'All {count} places have been taken.',
		fullMonth: 'The maximum of {count} applications for this month has been reached.',
		fullYear: 'The maximum of {count} applications for this year has been reached.',
		reopens: 'From {date} you can apply again.',
		notYetOpenTitle: 'This form is not available yet',
		opensOn: 'This form opens on {date}.',
		closedTitle: 'This form is no longer available',
		contact: 'Do you have a question? Please contact us.',
	},
}

/**
 * Fill `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {object} vars The values.
 * @return {string}
 */
function fill(text, vars) {
	return Object.keys(vars).reduce((out, key) => out.split(`{${key}}`).join(String(vars[key])), text)
}

/**
 * The language code Intl formats in.
 *
 * @param {string} locale `nl` or `en`.
 * @return {string}
 */
function intlLocale(locale) {
	return locale === 'en' ? 'en-GB' : 'nl-NL'
}

/**
 * A `YYYY-MM-DD` calendar day written out with its weekday, e.g.
 * "dinsdag 5 januari 2027". The weekday is worked out from the date.
 *
 * @param {string} day The day.
 * @param {string} [locale] `nl` or `en`.
 * @return {string} The written day, or '' for anything that is not a day.
 */
export function longDay(day, locale = 'nl') {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(day || ''))
	if (!match) {
		return ''
	}
	// Noon UTC lands on the same calendar day in Amsterdam all year.
	const moment = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12))
	return new Intl.DateTimeFormat(intlLocale(locale), {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: ZONE,
	}).format(moment)
}

/**
 * A local `YYYY-MM-DDTHH:MM` moment split into its day and time.
 *
 * @param {string} value The moment.
 * @return {{day: string, time: string}|null}
 */
function localMoment(value) {
	const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/.exec(String(value || ''))
	return match ? { day: match[1], time: match[2] } : null
}

/**
 * The maintenance window in words: one day with two times, or two days.
 *
 * @param {object} unavailable The situation.
 * @param {object} words The words.
 * @param {string} locale The locale.
 * @return {string} The window, or '' when it cannot be read.
 */
function windowLine(unavailable, words, locale) {
	const from = localMoment(unavailable.from)
	const until = localMoment(unavailable.until)
	if (!from || !until) {
		return ''
	}
	if (from.day === until.day) {
		return fill(words.window, { day: longDay(from.day, locale), from: from.time, until: until.time })
	}
	return fill(words.windowSpan, {
		from: `${longDay(from.day, locale)} ${from.time}`,
		until: `${longDay(until.day, locale)} ${until.time}`,
	})
}

/**
 * A route on this portal, or '' for anything that leaves it.
 *
 * @param {string} route The route.
 * @return {string}
 */
function inSiteRoute(route) {
	const value = String(route || '').trim()
	return value.startsWith('/') && !value.startsWith('//') && !value.includes('\\') ? value : ''
}

/**
 * The line saying how many places a full form had.
 *
 * @param {object} unavailable The situation.
 * @param {object} words The words.
 * @return {string}
 */
function fullLine(unavailable, words) {
	const count = Number(unavailable.limit) || 0
	if (count < 1) {
		return ''
	}
	const text = { month: words.fullMonth, year: words.fullYear }[unavailable.per] || words.fullTotal
	return fill(text, { count })
}

/**
 * What the page shows for a form that is not available.
 *
 * @param {object|null} unavailable The server's `unavailable`: `situation` and its details.
 * @param {string} [locale] `nl` or `en`.
 * @return {{situation: string, title: string, lines: Array<string>, link: {href: string, label: string}|null}|null}
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
 */
export function unavailableView(unavailable, locale = 'nl') {
	if (!unavailable || typeof unavailable !== 'object') {
		return null
	}
	const lang = locale === 'en' ? 'en' : 'nl'
	const words = WORDS[lang]
	const situation = String(unavailable.situation || '')
	const keep = (lines) => lines.filter((line) => line !== '')

	if (situation === 'maintenance') {
		return {
			situation,
			title: words.maintenanceTitle,
			lines: keep([windowLine(unavailable, words, lang), String(unavailable.text || '').trim(), words.draftKept]),
			link: null,
		}
	}

	if (situation === 'replaced') {
		const href = inSiteRoute(unavailable.replacedBy)
		return {
			situation,
			title: words.replacedTitle,
			lines: href ? [] : [words.contact],
			link: href ? { href, label: words.replacedLink } : null,
		}
	}

	if (situation === 'full') {
		const reopens = longDay(unavailable.reopensOn, lang)
		return {
			situation,
			title: words.fullTitle,
			lines: keep([fullLine(unavailable, words), reopens ? fill(words.reopens, { date: reopens }) : '']),
			link: null,
		}
	}

	if (situation === 'notYetOpen') {
		const opens = longDay(unavailable.opensOn, lang)
		return {
			situation,
			title: words.notYetOpenTitle,
			lines: keep([opens ? fill(words.opensOn, { date: opens }) : '']),
			link: null,
		}
	}

	return { situation: 'closed', title: words.closedTitle, lines: [words.contact], link: null }
}
