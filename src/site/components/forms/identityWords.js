// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The words of the signature field and the e-mail code field
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
		emailSent: 'Wij hebben een code gestuurd naar {email}. Vul de code hieronder in. De code is 15 minuten geldig.',
		emailCodeLabel: 'Code uit de e-mail',
		emailCheck: 'Code controleren',
		emailResend: 'Geen code gekregen? Stuur een nieuwe code',
		emailWait: 'U kunt over een minuut een nieuwe code vragen.',
		emailVerified: 'Dit e-mailadres is gecontroleerd.',
		emailVerifyFirst: 'Controleer dit e-mailadres met de code die wij u sturen.',
		emailWrong: 'Deze code klopt niet. Controleer de code of vraag een nieuwe aan.',
		emailExpired: 'Deze code is verlopen. Vraag een nieuwe code aan.',
		emailTooMany: 'Te veel pogingen. Vraag een nieuwe code aan.',
		emailThrottled: 'Er zijn te veel codes gevraagd. Probeer het later opnieuw.',
		emailInvalid: 'Vul een geldig e-mailadres in.',
		emailFailed: 'De code kon niet worden verstuurd. Probeer het later opnieuw.',
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
		emailSent: 'We sent a code to {email}. Enter the code below. The code works for 15 minutes.',
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
	return String(locale || '').toLowerCase().startsWith('en') ? WORDS.en : WORDS.nl
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
	return String(name || '').replace(/\s+/g, ' ').trim().slice(0, 80)
}
