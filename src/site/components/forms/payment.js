// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The fee line and the payment states of a form, without Vue, so `node --test`
 * asserts them (tests/intake-pay.spec.mjs). The amount shown is the one the
 * case type declared and the server sent; this file only writes it down.
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
 */

/**
 * A fee as a reader writes it ("€ 45,00").
 *
 * @param {{amount: string, currency: string}|null} fee The declared fee.
 * @param {string} locale The page language.
 * @return {string} The amount, or '' without a usable fee.
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
 */
export function feeAmount(fee, locale) {
	const value = Number(fee?.amount)
	if (!isUsableFee(fee, value)) {
		return ''
	}
	try {
		return new Intl.NumberFormat(feeLocale(locale), {
			style: 'currency',
			currency: fee.currency,
		}).format(value)
	} catch {
		return `${fee.currency} ${fee.amount}`
	}
}

/**
 * @param {{amount: string, currency: string}|null} fee The declared fee.
 * @param {number} value Its amount as a number.
 * @return {boolean} Whether it is an amount above zero in a three-letter currency.
 */
function isUsableFee(fee, value) {
	return (
		Boolean(fee)
		&& Number.isFinite(value)
		&& value > 0
		&& /^[A-Z]{3}$/.test(String(fee.currency || ''))
	)
}

/**
 * @param {string} locale The page language.
 * @return {string} The locale a fee is written in.
 */
function feeLocale(locale) {
	return String(locale || 'nl').startsWith('en') ? 'en-GB' : 'nl-NL'
}

/** What the return page says for each payment state it knows. */
const PAYMENT_VIEWS = new Map([
	['paid', { key: 'paid', again: false }],
	['unpaid', { key: 'unpaid', again: true }],
	['failed', { key: 'paymentFailed', again: true }],
])

/**
 * What the return page says about a payment.
 *
 * @param {{state?: string}|null} payment The payment from the status route.
 * @return {{key: string, again: boolean}|null} The words' key and whether to offer "Pay now" again; null without a payment.
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
 */
export function paymentView(payment) {
	if (!payment || typeof payment.state !== 'string') {
		return null
	}
	const view = PAYMENT_VIEWS.get(payment.state)
	return view ? { ...view } : { key: 'paymentUnknown', again: false }
}

/**
 * The reference in the address the resident returned on, or ''.
 *
 * @param {string} search The `location.search`.
 * @return {string} The reference.
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
 */
export function returnedReference(search) {
	const value = new URLSearchParams(String(search || '')).get('reference') || ''
	return /^[A-Za-z0-9-]{1,64}$/.test(value) ? value : ''
}
