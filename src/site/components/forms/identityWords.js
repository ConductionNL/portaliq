// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The words of the signature field, the e-mail code field and staff mode
// (resident-identity-in-forms), in Dutch and English. Imports nothing, so the
// node specs run it as a plain script.

const WORDS = {
	nl: {
		drawHint: 'Teken met uw muis, vinger of pen in het vak.',
		drawAgain: 'Opnieuw tekenen',
		typeInstead: 'Typ uw naam in plaats van te tekenen',
		drawInstead: 'Teken uw handtekening in plaats van te typen',
		typedLabel: 'Uw naam als handtekening',
		boxLabel: 'Tekenvak voor uw handtekening',
		signatureSet: 'Handtekening geplaatst',
		emailSend: 'Stuur een code',
		emailSent:
			'Wij hebben een code gestuurd naar {email}. Vul de code hieronder in. De code is 15 minuten geldig.',
		emailCodeLabel: 'Code uit de e-mail',
		emailCheck: 'Code controleren',
		emailResend: 'Geen code gekregen? Stuur een nieuwe code',
		emailWait: 'U kunt over een minuut een nieuwe code vragen.',
		emailVerified: 'Dit e-mailadres is gecontroleerd.',
		emailVerifyFirst: 'Controleer dit e-mailadres met de code die wij u sturen.',
		emailWrong:
			'Deze code klopt niet. Controleer de code of vraag een nieuwe aan.',
		emailExpired: 'Deze code is verlopen. Vraag een nieuwe code aan.',
		emailTooMany: 'Te veel pogingen. Vraag een nieuwe code aan.',
		emailThrottled: 'Er zijn te veel codes gevraagd. Probeer het later opnieuw.',
		emailInvalid: 'Vul een geldig e-mailadres in.',
		emailFailed: 'De code kon niet worden verstuurd. Probeer het later opnieuw.',
		staffHeading: 'Voor medewerkers',
		staffIntro:
			'Vult u dit formulier in voor een inwoner, bijvoorbeeld aan de balie of aan de telefoon? Log in met uw werkaccount en kies hoe u verdergaat. De inwoner of het bedrijf staat dan als aanvrager in de zaak, u als medewerker die het invulde.',
		staffContinueAs: 'Verder als',
		staffResident: 'Inwoner',
		staffResidentHint:
			'U vult in met het BSN van de inwoner. Wij vullen de gegevens uit de Basisregistratie Personen in.',
		staffCompany: 'Bedrijf',
		staffCompanyHint: 'U vult in met het KvK-nummer van het bedrijf.',
		staffNamed: 'Uzelf, met de inwoner als aanvrager',
		staffNamedHint:
			'U vult in onder uw eigen naam en noteert wie de aanvrager is.',
		staffBsnLabel: 'BSN van de inwoner',
		staffKvkLabel: 'KvK-nummer van het bedrijf',
		staffNameLabel: 'Naam van de aanvrager',
		staffSignIn: 'Inloggen met werkaccount',
		staffEmpty: 'Vul dit in om verder te gaan.',
		staffInvalid:
			'Dit nummer klopt niet. Controleer het en probeer het opnieuw.',
		staffNotAllowed:
			'U mag dit formulier niet invullen voor een inwoner. Vraag uw beheerder om toegang.',
		staffFailed: 'Verdergaan is niet gelukt. Probeer het later opnieuw.',
		staffFillingForBsn:
			'U vult dit formulier in voor de inwoner met BSN {value}. U staat als medewerker in de zaak.',
		staffFillingForKvk:
			'U vult dit formulier in voor het bedrijf met KvK-nummer {value}. U staat als medewerker in de zaak.',
		staffFillingForNamed:
			'U vult dit formulier in voor {value}. U staat als medewerker in de zaak.',
	},
	en: {
		drawHint: 'Draw with your mouse, finger or pen in the box.',
		drawAgain: 'Draw again',
		typeInstead: 'Type your name instead of drawing',
		drawInstead: 'Draw your signature instead of typing',
		typedLabel: 'Your name as signature',
		boxLabel: 'Box to draw your signature in',
		signatureSet: 'Signature added',
		emailSend: 'Send a code',
		emailSent:
			'We sent a code to {email}. Enter the code below. The code works for 15 minutes.',
		emailCodeLabel: 'Code from the e-mail',
		emailCheck: 'Check code',
		emailResend: 'No code? Send a new code',
		emailWait: 'You can ask for a new code in a minute.',
		emailVerified: 'This e-mail address is checked.',
		emailVerifyFirst: 'Check this e-mail address with the code we send you.',
		emailWrong: 'This code is not right. Check the code or ask for a new one.',
		emailExpired: 'This code has expired. Ask for a new code.',
		emailTooMany: 'Too many tries. Ask for a new code.',
		emailThrottled: 'Too many codes were asked for. Try again later.',
		emailInvalid: 'Fill in a valid e-mail address.',
		emailFailed: 'The code could not be sent. Try again later.',
		staffHeading: 'For employees',
		staffIntro:
			'Are you filling this form in for a resident, at the desk or on the phone? Sign in with your work account and choose how to go on. The resident or the company is then the applicant on the case, and you the employee who filled it in.',
		staffContinueAs: 'Continue as',
		staffResident: 'Resident',
		staffResidentHint:
			"You fill in with the resident's citizen service number (BSN). We fill in the details from the Personal Records Database.",
		staffCompany: 'Company',
		staffCompanyHint:
			"You fill in with the company's Chamber of Commerce (KvK) number.",
		staffNamed: 'Yourself, with the resident as applicant',
		staffNamedHint:
			'You fill in under your own name and note who the applicant is.',
		staffBsnLabel: "Resident's BSN",
		staffKvkLabel: "Company's KvK number",
		staffNameLabel: "Applicant's name",
		staffSignIn: 'Sign in with work account',
		staffEmpty: 'Fill this in to go on.',
		staffInvalid: 'This number is not right. Check it and try again.',
		staffNotAllowed:
			'You may not fill this form in for a resident. Ask your administrator for access.',
		staffFailed: 'Going on did not work. Try again later.',
		staffFillingForBsn:
			'You are filling this form in for the resident with BSN {value}. The case names you as the employee.',
		staffFillingForKvk:
			'You are filling this form in for the company with KvK number {value}. The case names you as the employee.',
		staffFillingForNamed:
			'You are filling this form in for {value}. The case names you as the employee.',
	},
}

/**
 * The words in a language.
 *
 * @param {string} locale The locale; anything but English reads as Dutch.
 * @return {Record<string, string>} The words.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
 */
export function identityWords(locale) {
	return String(locale || '')
		.toLowerCase()
		.startsWith('en')
		? WORDS.en
		: WORDS.nl
}

/**
 * The sentence for a refused code request or check, by the server's reason.
 *
 * @param {Record<string, string>} words The words.
 * @param {string} reason The reason the server gave.
 * @return {string} The sentence.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
export function emailCodeProblem(words, reason) {
	const sentences = {
		wrong: words.emailWrong,
		expired: words.emailExpired,
		too_many_tries: words.emailTooMany,
		throttled: words.emailThrottled,
		wait: words.emailWait,
		invalid: words.emailInvalid,
	}
	return sentences[reason] || words.emailFailed
}

/**
 * A typed name, cleaned for the signature image: one line, at most 80 characters.
 *
 * @param {string} name What was typed.
 * @return {string} The name to draw, or '' when nothing is left.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
 */
export function typedSignatureName(name) {
	return String(name || '')
		.replace(/\s+/g, ' ')
		.trim()
		.slice(0, 80)
}

/**
 * The line that tells the employee for whom they are filling the form in.
 *
 * @param {Record<string, string>} words The words.
 * @param {{kind: string, value: string}|null} applicant The applicant, or null.
 * @return {string} The line, or '' outside staff mode.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export function staffFillingFor(words, applicant) {
	const lines = {
		bsn: words.staffFillingForBsn,
		kvk: words.staffFillingForKvk,
		named: words.staffFillingForNamed,
	}
	const line = applicant ? lines[applicant.kind] : ''
	return line ? line.replace('{value}', String(applicant.value || '')) : ''
}
