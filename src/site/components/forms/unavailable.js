// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What a closed form says (board FormulierNietBeschikbaar): the heading, the
// lines and the link for each situation the server answers. No Vue, so node
// tests it.
//
// @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001

const WORDS = {
	nl: {
		maintenanceHeading: 'Dit formulier is even niet beschikbaar',
		maintenanceWork: 'Wij werken aan het formulier {form}. Dat doen wij op {day} van {from} tot {until} uur.',
		maintenanceWorkNoName: 'Wij werken aan dit formulier. Dat doen wij op {day} van {from} tot {until} uur.',
		maintenanceDraft: 'Was u al bezig? Uw opgeslagen antwoorden blijven bewaard. Na {until} uur gaat u verder waar u was.',
		replacedHeading: 'Dit formulier is niet meer beschikbaar',
		replacedText: 'Wij gebruiken dit formulier niet meer. Er is een nieuw formulier voor hetzelfde verzoek.',
		replacedLink: 'Naar het nieuwe formulier',
		closedText: 'Wij gebruiken dit formulier niet meer.',
		limitHeading: 'Er kunnen nu geen aanvragen meer bij',
		limitTaken: 'Voor {form} zijn alle {count} plekken vergeven.',
		limitTakenNoName: 'Voor dit formulier zijn alle {count} plekken vergeven.',
		limitReopens: 'Vanaf {day} kunt u weer een aanvraag doen.',
		notYetHeading: 'Dit formulier is nog niet open',
		notYetText: 'Dit formulier opent op {day}.',
		contactHeading: 'Liever bellen of mailen?',
		timeSeparator: '.',
	},
	en: {
		maintenanceHeading: 'This form is briefly unavailable',
		maintenanceWork: 'We are working on the form {form}. We do this on {day} from {from} to {until}.',
		maintenanceWorkNoName: 'We are working on this form. We do this on {day} from {from} to {until}.',
		maintenanceDraft: 'Already started? Your saved answers are kept. After {until} you continue where you left off.',
		replacedHeading: 'This form is no longer available',
		replacedText: 'We no longer use this form. There is a new form for the same request.',
		replacedLink: 'Go to the new form',
		closedText: 'We no longer use this form.',
		limitHeading: 'No more applications can be added now',
		limitTaken: 'All {count} places for {form} are taken.',
		limitTakenNoName: 'All {count} places for this form are taken.',
		limitReopens: 'From {day} you can apply again.',
		notYetHeading: 'This form is not open yet',
		notYetText: 'This form opens on {day}.',
		contactHeading: 'Rather call or email?',
		timeSeparator: ':',
	},
}

/**
 * The words in a language, Dutch when the language has none.
 *
 * @param {string} locale `nl` or `en`.
 * @return {object} The words.
 */
export function unavailableWords(locale) {
	return WORDS[locale] || WORDS.nl
}

/**
 * Fill `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {object} vars The values.
 * @return {string} The filled text.
 */
function fill(text, vars) {
	return text.replace(/\{(\w+)\}/g, (match, name) => (Object.hasOwn(vars, name) ? String(vars[name]) : match))
}

/**
 * A `Y-m-d` day written out: "maandag 5 januari 2027".
 *
 * @param {string} day The day.
 * @param {string} locale `nl` or `en`.
 * @return {string} The day in words, or '' when it is not a day.
 */
export function dayInWords(day, locale) {
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(day || ''))
	if (!match) {
		return ''
	}
	const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12))
	return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'nl-NL', {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	}).format(date).replace(',', '')
}

/**
 * The time of a local `Y-m-dTH:i` moment: "18.00" in Dutch, "18:00" in English.
 *
 * @param {string} moment The moment.
 * @param {object} words The words, for the separator.
 * @return {string} The time, or ''.
 */
function timeOf(moment, words) {
	const match = /T(\d{2}):(\d{2})/.exec(String(moment || ''))
	return match ? `${match[1]}${words.timeSeparator}${match[2]}` : ''
}

/**
 * What the page shows for a situation.
 *
 * @param {object|null} situation The server's `unavailable` answer.
 * @param {string} formName The form's name, or ''.
 * @param {string} locale `nl` or `en`.
 * @return {{heading: string, lines: string[], link: ({href: string, label: string}|null), contact: boolean}} The view.
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
 */
export function unavailableView(situation, formName, locale) {
	const words = unavailableWords(locale)
	const kind = situation && typeof situation === 'object' ? situation.situation : ''
	const form = String(formName || '').trim()

	if (kind === 'maintenance') {
		const until = timeOf(situation.until, words)
		const own = String(situation.text || '').trim()
		const vars = { form, day: dayInWords(situation.from, locale), from: timeOf(situation.from, words), until }
		return {
			heading: words.maintenanceHeading,
			lines: [own || fill(form ? words.maintenanceWork : words.maintenanceWorkNoName, vars), fill(words.maintenanceDraft, vars)],
			link: null,
			contact: false,
		}
	}

	if (kind === 'replaced') {
		const href = String(situation.replacedBy || '')
		return {
			heading: words.replacedHeading,
			lines: [words.replacedText],
			link: href.startsWith('/') && !href.startsWith('//') ? { href, label: words.replacedLink } : null,
			contact: true,
		}
	}

	if (kind === 'limitReached') {
		const lines = [fill(form ? words.limitTaken : words.limitTakenNoName, { form, count: Number(situation.count) || 0 })]
		const day = dayInWords(situation.reopensOn, locale)
		if (day !== '') {
			lines.push(fill(words.limitReopens, { day }))
		}
		return { heading: words.limitHeading, lines, link: null, contact: false }
	}

	if (kind === 'notYetOpen') {
		return {
			heading: words.notYetHeading,
			lines: [fill(words.notYetText, { day: dayInWords(situation.opensOn, locale) })],
			link: null,
			contact: false,
		}
	}

	return { heading: words.replacedHeading, lines: [words.closedText], link: null, contact: true }
}
