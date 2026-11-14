// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What a catalogue entry says about its costs and conditions
 * (catalogue-entry-shows-costs-and-conditions, REQ-CSC-001).
 *
 * The fee is the one the server read from the entry's case type; the block
 * only words it. Conditions are the publication's own lines.
 */
import { feeAmount } from './forms/payment.js'

/** The block's words, per page language. */
const WORDS = {
	nl: { cost: 'Kosten: {amount}', conditions: 'Voorwaarden' },
	en: { cost: 'Costs: {amount}', conditions: 'Conditions' },
}

/**
 * The words for a page language: English for an English page, else Dutch.
 *
 * @param {string} locale The page language.
 * @return {{cost: string, conditions: string}} The words.
 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/specs/portal-intake-form/spec.md#requirement-a-catalogue-entry-shows-what-it-costs-and-what-it-asks-of-you-req-csc-001
 */
export function catalogueWords(locale) {
	return String(locale || '').toLowerCase().startsWith('en') ? WORDS.en : WORDS.nl
}

/**
 * The cost line of an entry, "Kosten: € 38,50", or '' when it has no usable fee.
 *
 * @param {object} entry A catalogue entry.
 * @param {string} locale The page language.
 * @return {string} The line.
 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/specs/portal-intake-form/spec.md#requirement-a-catalogue-entry-shows-what-it-costs-and-what-it-asks-of-you-req-csc-001
 */
export function costLine(entry, locale) {
	const amount = feeAmount(entry?.fee || null, locale)
	if (amount === '') {
		return ''
	}
	return catalogueWords(locale).cost.split('{amount}').join(amount)
}

/**
 * The conditions of an entry when they are a list of non-empty lines; none
 * for any other shape.
 *
 * @param {object} entry A catalogue entry.
 * @return {string[]} The lines, trimmed.
 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/specs/portal-intake-form/spec.md#requirement-a-catalogue-entry-shows-what-it-costs-and-what-it-asks-of-you-req-csc-001
 */
export function entryConditions(entry) {
	const lines = entry?.conditions
	if (!Array.isArray(lines)) {
		return []
	}
	if (lines.some((line) => typeof line !== 'string' || line.trim() === '')) {
		return []
	}
	return lines.map((line) => line.trim())
}
