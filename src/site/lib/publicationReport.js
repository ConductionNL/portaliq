// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "Fout melden" on a publication page (publication-error-reports-and-withheld-notices
// REQ-PER-002): the words, the address and the one call. A plain module, so
// tests/publication-report.spec.mjs runs it in node. Nothing here keeps the
// visitor's address beyond the request body: no storage, no cookie.

/**
 * The longest description the route accepts.
 *
 * @type {number}
 */
export const MAX_DESCRIPTION = 2000

const WORDS = {
	nl: {
		trigger: 'Fout melden',
		title: 'Fout melden in deze publicatie',
		intro: 'Klopt er iets niet in deze publicatie? Bijvoorbeeld een verkeerde versie van een document, of een naam die onleesbaar had moeten zijn? Laat het ons weten.',
		document: 'Over welk document gaat het?',
		wholePublication: 'De hele publicatie',
		description: 'Wat klopt er niet?',
		descriptionHint:
			'Ten hoogste 2000 tekens. Noem geen persoonsgegevens als dat niet nodig is.',
		email: 'Uw e-mailadres',
		emailHint:
			'Niet verplicht. Alleen als u een antwoord wilt. Wij wissen het 30 dagen nadat de melding is afgehandeld.',
		send: 'Melding versturen',
		sending: 'Bezig met versturen…',
		cancel: 'Annuleren',
		close: 'Sluiten',
		empty: 'Vertel wat er niet klopt.',
		tooLong: 'Uw beschrijving is langer dan 2000 tekens.',
		badEmail: 'Dit e-mailadres klopt niet.',
		tooMany: 'Er zijn net veel meldingen verstuurd. Probeer het later opnieuw.',
		gone: 'Over deze publicatie kunt u geen fout melden.',
		failed: 'Uw melding is niet verstuurd. Probeer het later opnieuw.',
		sent: 'Dank u. Uw melding is verstuurd naar de mensen die dit publiceren.',
	},
	en: {
		trigger: 'Report an error',
		title: 'Report an error in this publication',
		intro: 'Is something wrong in this publication? A wrong version of a document, for example, or a name that should have been made unreadable? Let us know.',
		document: 'Which document is it about?',
		wholePublication: 'The whole publication',
		description: 'What is wrong?',
		descriptionHint:
			'At most 2000 characters. Do not give personal details unless you need to.',
		email: 'Your e-mail address',
		emailHint:
			'Optional. Only if you want a reply. We erase it 30 days after the report is handled.',
		send: 'Send report',
		sending: 'Sending…',
		cancel: 'Cancel',
		close: 'Close',
		empty: 'Tell us what is wrong.',
		tooLong: 'Your description is longer than 2000 characters.',
		badEmail: 'This e-mail address is not right.',
		tooMany: 'Many reports were just sent. Try again later.',
		gone: 'You cannot report an error on this publication.',
		failed: 'Your report was not sent. Try again later.',
		sent: 'Thank you. Your report was sent to the people who publish this.',
	},
}

/**
 * The words in the page language, Dutch by default.
 *
 * @param {string} locale The page language.
 * @return {Record<string, string>} The words.
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export function reportWords(locale) {
	return WORDS[locale] || WORDS.nl
}

/**
 * Whether the page offers "Fout melden": only when the site record says the
 * portal switched error reports on, and only for a publication that loaded.
 *
 * @param {object|null} publications The site record's `publications`.
 * @param {string} publicationId The publication on the page.
 * @return {boolean}
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
 */
export function offersReport(publications, publicationId) {
	return (
		Boolean(publications && publications.errorReports === true)
		&& typeof publicationId === 'string'
		&& publicationId !== ''
	)
}

/**
 * The report route, next to the content API: `.../api/content` becomes
 * `.../api/site/publication-reports`.
 *
 * @param {string} apiBase The content API base.
 * @return {string} The route.
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export function reportsUrlFrom(apiBase) {
	return (
		String(apiBase || '').replace(/\/api\/content\/?$/, '')
		+ '/api/site/publication-reports'
	)
}

/**
 * Why a report cannot be sent yet, as a word key, or '' when it can.
 *
 * @param {{description: string, contactEmail: string}} form The form.
 * @return {string} `empty`, `tooLong`, `badEmail` or ''.
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export function formProblem(form) {
	const description = String(form?.description || '').trim()
	if (description === '') {
		return 'empty'
	}
	if ([...description].length > MAX_DESCRIPTION) {
		return 'tooLong'
	}
	const email = String(form?.contactEmail || '').trim()
	if (email !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) === false) {
		return 'badEmail'
	}
	return ''
}

/**
 * The body the route takes: the portal, the publication, the document, the
 * description and, when given, the address. Nothing else.
 *
 * @param {{portal: string, publication: string, document?: string, description: string, contactEmail?: string}} report The report.
 * @return {object} The body.
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export function reportBody(report) {
	const body = {
		portal: String(report.portal || ''),
		publication: String(report.publication || ''),
		document: String(report.document || ''),
		description: String(report.description || '').trim(),
	}
	const email = String(report.contactEmail || '').trim()
	if (email !== '') {
		body.contactEmail = email
	}
	return body
}

/**
 * The word for a refused send, by status.
 *
 * @param {number} status The HTTP status, 0 on a network error.
 * @return {string} The word key.
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export function failureKey(status) {
	if (status === 413) {
		return 'tooLong'
	}
	if (status === 429) {
		return 'tooMany'
	}
	if (status === 404) {
		return 'gone'
	}
	return 'failed'
}

/**
 * Send one report, without cookies.
 *
 * @param {string} url The report route.
 * @param {object} report What reportBody() takes.
 * @param {typeof fetch} [fetchImpl] `fetch`, replaceable in tests.
 * @return {Promise<{ok: boolean, status: number}>}
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export async function sendReport(
	url,
	report,
	fetchImpl = (...args) => fetch(...args),
) {
	try {
		const response = await fetchImpl(url, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(reportBody(report)),
		})
		return { ok: response.ok === true, status: response.status }
	} catch {
		return { ok: false, status: 0 }
	}
}
