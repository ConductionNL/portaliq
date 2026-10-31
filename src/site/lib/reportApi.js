// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The calls and words of the report pages (intake-report-pages D1, D2): a
// report without an account, filed on a public page, and the follow-up with
// the receipt code alone. Imports only the challenge solver, so the node
// specs run it as a plain module. Nothing here writes the code anywhere: it
// travels in a request body and lives in a component's state, never in the
// address, browser storage, the page title or a traffic event.

import { solveChallenge } from './waysIn.js'

const WORDS = {
	nl: {
		whatHappened: 'Wat wilt u melden?',
		whatHappenedHint:
			'Vertel wat er gebeurt of gebeurd is. Noem geen namen als dat niet nodig is.',
		whenWhere: 'Wanneer en waar was dit?',
		optional: '(niet verplicht)',
		contactTitle: 'Wilt u dat wij weten wie u bent?',
		contactHint:
			'Uw gegevens bewaren wij apart van de melding. Alleen de vertrouwenspersoon van de gemeente kan ze inzien, met een reden die wordt vastgelegd.',
		name: 'Naam',
		reach: 'E-mailadres of telefoonnummer',
		send: 'Melding versturen',
		sending: 'Bezig met versturen…',
		empty: 'Vertel wat u wilt melden.',
		failed: 'Uw melding is niet verstuurd. Probeer het later opnieuw.',
		challengeFailed:
			'De controle tegen automatische meldingen lukte niet. Probeer het opnieuw.',
		yourCode: 'Uw meldcode',
		keepCode: 'Bewaar deze code. Wij kunnen hem niet opnieuw sturen.',
		codeOnce:
			'U ziet de code nu één keer. Het is de enige manier om terug te komen.',
		copy: 'Kopieer de code',
		copied: 'De code is gekopieerd.',
		alreadyReported: 'Al gemeld?',
		comeBack:
			'Kom terug met uw meldcode. U leest dan het antwoord en kunt reageren.',
		toThread: 'Bekijk het antwoord',
		noTrace:
			'Deze pagina bewaart niets waaraan u te herkennen bent: geen cookies, geen IP-adres.',
		expect: 'Wat u van ons mag verwachten',
		openThread: 'Bekijk het antwoord',
		wrongCode: 'Wij konden geen melding openen met deze code.',
		yourReport: 'Uw melding',
		hereWithCode:
			'U bent hier met uw meldcode. Lees wat de gemeente schreef en antwoord als u wilt.',
		receivedOn: 'Ontvangen op {date}',
		conversation: 'Het gesprek',
		you: 'U',
		organisation: 'De gemeente',
		answer: 'Uw antwoord',
		answerHint:
			'Uw naam staat niet bij uw bericht. Noem geen namen als dat niet nodig is.',
		sendAnswer: 'Antwoord versturen',
		answerSent: 'Uw antwoord is verstuurd.',
		answerFailed: 'Uw antwoord is niet verstuurd. Probeer het later opnieuw.',
		acknowledgement: 'Bevestiging binnen {days} dagen',
		feedback: 'Uitkomst binnen {days} dagen',
		metOn: 'Gehaald op {date}',
		dueBy: 'Uiterlijk {date}',
		daysLeft: 'Loopt nog, {days} dagen over',
		overdue: 'De termijn is verstreken',
		termsSource: 'Deze termijnen komen uit de regels voor dit soort meldingen.',
		keepCodeShort:
			'Bewaar uw meldcode. Kwijt is kwijt: wij kunnen hem niet opnieuw sturen.',
		noTraceThread:
			'Geen cookies, geen IP-adres, geen naam bij uw berichten. Alleen wie de meldcode heeft, ziet dit gesprek.',
	},
	en: {
		whatHappened: 'What do you want to report?',
		whatHappenedHint:
			'Tell us what is happening or what happened. Do not name people unless you need to.',
		whenWhere: 'When and where was this?',
		optional: '(optional)',
		contactTitle: 'Do you want us to know who you are?',
		contactHint:
			'We keep your details apart from the report. Only the confidential adviser can see them, with a reason that is recorded.',
		name: 'Name',
		reach: 'E-mail address or phone number',
		send: 'Send report',
		sending: 'Sending…',
		empty: 'Tell us what you want to report.',
		failed: 'Your report was not sent. Try again later.',
		challengeFailed:
			'The check against automated reports did not pass. Try again.',
		yourCode: 'Your report code',
		keepCode: 'Keep this code. We cannot send it to you again.',
		codeOnce:
			'You see the code once, now. It is the only way to come back.',
		copy: 'Copy the code',
		copied: 'The code is copied.',
		alreadyReported: 'Already reported?',
		comeBack:
			'Come back with your report code. You then read the answer and can respond.',
		toThread: 'Read the answer',
		noTrace:
			'This page keeps nothing you can be recognised by: no cookies, no IP address.',
		expect: 'What you can expect from us',
		openThread: 'Read the answer',
		wrongCode: 'We could not open a report with this code.',
		yourReport: 'Your report',
		hereWithCode:
			'You are here with your report code. Read what we wrote and answer if you want to.',
		receivedOn: 'Received on {date}',
		conversation: 'The conversation',
		you: 'You',
		organisation: 'The organisation',
		answer: 'Your answer',
		answerHint:
			'Your name is not shown with your message. Do not name people unless you need to.',
		sendAnswer: 'Send answer',
		answerSent: 'Your answer is sent.',
		answerFailed: 'Your answer was not sent. Try again later.',
		acknowledgement: 'Confirmation within {days} days',
		feedback: 'Outcome within {days} days',
		metOn: 'Met on {date}',
		dueBy: 'At the latest {date}',
		daysLeft: 'Still running, {days} days left',
		overdue: 'The term has passed',
		termsSource: 'These terms come from the rules for this kind of report.',
		keepCodeShort:
			'Keep your report code. Lost is lost: we cannot send it to you again.',
		noTraceThread:
			'No cookies, no IP address, no name with your messages. Only who holds the report code sees this conversation.',
	},
}

/**
 * The words in a language; Dutch unless the locale is English.
 *
 * @param {string} locale The locale.
 * @return {Record<string, string>} The words.
 *
 * @spec openspec/changes/intake-report-pages/tasks.md#t10
 */
export function reportWords(locale) {
	return String(locale || '')
		.toLowerCase()
		.startsWith('en')
		? WORDS.en
		: WORDS.nl
}

/**
 * Fill `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {Record<string, string|number>} vars The values.
 * @return {string} The text.
 *
 * @spec openspec/changes/intake-report-pages/tasks.md#t05
 */
export function fillReport(text, vars = {}) {
	return String(text).replace(/\{(\w+)\}/g, (_, key) =>
		String(vars[key] ?? ''),
	)
}

/**
 * The contact a reporter chose to give, in the fields the server keeps:
 * `name`, and `email` or `phone` from the one "e-mail or phone" field.
 * Empty fields are left out, so an empty block sends `{}`.
 *
 * @param {string} name The name.
 * @param {string} reach The e-mail address or phone number.
 * @return {{name?: string, email?: string, phone?: string}} The contact.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-files-a-report-on-a-public-page-and-keeps-a-code-req-irp-001
 */
export function contactOf(name, reach) {
	const contact = {}
	const given = String(name || '').trim()
	const way = String(reach || '').trim()
	if (given !== '') {
		contact.name = given
	}
	if (way !== '') {
		contact[way.includes('@') ? 'email' : 'phone'] = way
	}
	return contact
}

/**
 * The lines of one term of the follow-up page: "Bevestiging binnen 7 dagen"
 * with "Gehaald op ..." or "Loopt nog, 84 dagen over" and "Uiterlijk ...".
 *
 * @param {{term: string, days: number, dueAt: string, met: boolean, metAt: string, daysLeft: number}} term A term from the server.
 * @param {Record<string, string>} words The words.
 * @param {(iso: string) => string} date Formats a moment as a date.
 * @return {{title: string, state: string, due: string}} The lines.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-follows-up-with-the-code-alone-req-irp-002
 */
export function termLines(term, words, date) {
	const title = fillReport(
		term.term === 'acknowledgement' ? words.acknowledgement : words.feedback,
		{ days: term.days },
	)
	let state = words.overdue
	if (term.met) {
		state = fillReport(words.metOn, { date: date(term.metAt) })
	} else if (Number(term.daysLeft) >= 0) {
		state = fillReport(words.daysLeft, { days: term.daysLeft })
	}
	return { title, state, due: fillReport(words.dueBy, { date: date(term.dueAt) }) }
}

/**
 * POST a JSON body and answer `{ok, status, error, data}`, never thrown.
 *
 * @param {string} url The address.
 * @param {object} body The body.
 * @param {typeof fetch} fetchImpl The fetch to use.
 * @return {Promise<{ok: boolean, status: number, error: string, data: object}>} The answer.
 */
async function post(url, body, fetchImpl) {
	try {
		const response = await fetchImpl(url, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			body: JSON.stringify(body),
		})
		const data = await response.json().catch(() => ({}))
		return {
			ok: response.ok,
			status: response.status,
			error: String(data?.error || ''),
			data: data || {},
		}
	} catch {
		return { ok: false, status: 0, error: '', data: {} }
	}
}

/**
 * The report routes of one portal. No cookie is sent: a reporter has no
 * session, and the page keeps nothing they can be recognised by.
 *
 * @param {string} authBase The portal API base (`.../portal/api`).
 * @param {string} portal The serving portal's slug, or ''.
 * @param {typeof fetch} [fetchImpl] `fetch`, replaceable in tests.
 * @param {typeof solveChallenge} [solve] The challenge solver, replaceable in tests.
 * @return {object} The calls.
 *
 * @spec openspec/changes/intake-report-pages/tasks.md#t05
 */
export function reportApi(
	authBase,
	portal,
	fetchImpl = (...args) => fetch(...args),
	solve = solveChallenge,
) {
	const query = `portal=${encodeURIComponent(portal || '')}`
	return {
		/**
		 * Solve the portal's challenge for a report and file it.
		 *
		 * @param {{caseType: string, register: string, schema: string, report: object, contact: object, trap?: string}} filing What to file; `trap` is the honeypot's value.
		 * @return {Promise<{ok: boolean, status: number, error: string, code: string}>} The code on success.
		 */
		async file({ caseType, register, schema, report, contact, trap = '' }) {
			let challenge
			try {
				const response = await fetchImpl(
					`${authBase}/identity/challenge?surface=report&${query}`,
					{ credentials: 'omit', headers: { Accept: 'application/json' } },
				)
				challenge = response.ok ? await response.json() : {}
			} catch {
				challenge = {}
			}
			const solution = challenge.difficulty
				? await solve(challenge.nonce, challenge.difficulty)
				: ''
			const sent = { ...report }
			if (challenge.honeypotField) {
				sent[challenge.honeypotField] = trap
			}
			const answer = await post(
				`${authBase}/reports?${query}`,
				{
					caseType,
					register,
					schema,
					report: sent,
					contact,
					nonce: challenge.nonce || '',
					solution,
					expiresAt: challenge.expiresAt || 0,
					signature: challenge.signature || '',
				},
				fetchImpl,
			)
			return {
				ok: answer.ok,
				status: answer.status,
				error: answer.error,
				code: answer.ok ? String(answer.data.code || '') : '',
			}
		},

		/**
		 * Open the thread a code belongs to.
		 *
		 * @param {string} code The receipt code.
		 * @return {Promise<{report: object, messages: Array<object>, terms: Array<object>}|null>} Null for a code that opens nothing.
		 */
		async thread(code) {
			const answer = await post(
				`${authBase}/reports/thread?${query}`,
				{ code },
				fetchImpl,
			)
			if (!answer.ok) {
				return null
			}
			return {
				report: answer.data.report || {},
				messages: Array.isArray(answer.data.messages)
					? answer.data.messages
					: [],
				terms: Array.isArray(answer.data.terms) ? answer.data.terms : [],
			}
		},

		/**
		 * Answer on the thread.
		 *
		 * @param {string} code The receipt code.
		 * @param {string} body The answer.
		 * @return {Promise<boolean>} Whether it was written.
		 */
		async answer(code, body) {
			const answer = await post(
				`${authBase}/reports/thread/answer?${query}`,
				{ code, body },
				fetchImpl,
			)
			return answer.ok
		},
	}
}
