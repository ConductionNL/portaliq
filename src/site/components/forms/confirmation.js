// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The decisions behind the introduction page, the statements and the
 * confirmation page of a form, without Vue, so `node --test` asserts them
 * (tests/form-statements.spec.mjs).
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t04
 */

/**
 * A confirmation body with its values filled in. A sentence whose value is
 * empty is left out, so no page reads "U krijgt uiterlijk  een besluit."
 *
 * @param {string} body The body, with `{reference}` and `{deadline}`.
 * @param {{reference: string, deadline: string}} values The values.
 * @return {string} The sentences that can be said, joined.
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t04
 */
export function fillBody(body, values) {
	return String(body || '')
		.split(/(?<=[.!?])\s+/)
		.map((sentence) => {
			let empty = false
			const filled = sentence.replace(/\{(reference|deadline)\}/g, (match, name) => {
				const value = String(values?.[name] ?? '').trim()
				if (value === '') {
					empty = true
				}
				return value
			})
			return empty ? '' : filled
		})
		.filter((sentence) => sentence.trim() !== '')
		.join(' ')
}

/**
 * What the confirmation page shows.
 *
 * @param {object|null} confirmation The binding's `confirmation`.
 * @param {string} fallbackText The form's `confirmationText`.
 * @param {{reference: string, deadline?: string}} values The reference and the decision date.
 * @param {string} defaultTitle The heading when the binding names none.
 * @return {{title: string, body: string, next: Array<{title: string, text: string}>}} The page.
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t04
 */
export function confirmationView(confirmation, fallbackText, values, defaultTitle) {
	const own = confirmation && typeof confirmation === 'object' ? confirmation : {}
	const title = String(own.title || '').trim() || defaultTitle
	const body = fillBody(String(own.body || '').trim() || fallbackText, values)
	const next = (Array.isArray(own.next) ? own.next : [])
		.filter((step) => step && (String(step.title || '').trim() || String(step.text || '').trim()))
		.map((step) => ({ title: String(step.title || ''), text: String(step.text || '') }))
	return { title, body, next }
}

/**
 * The statements still to accept: required ones that are not ticked.
 *
 * @param {Array<{key: string, required: boolean, text: string}>} asked The statements the form asks.
 * @param {string[]} accepted The keys ticked.
 * @return {string[]} The keys of required statements not ticked, or without text.
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
 */
export function missingStatements(asked, accepted) {
	const ticked = Array.isArray(accepted) ? accepted : []
	return (Array.isArray(asked) ? asked : [])
		.filter((statement) => statement.required === true && (!statement.text || !ticked.includes(statement.key)))
		.map((statement) => statement.key)
}

/**
 * The introduction page's content, or null when the form opens at step 1.
 *
 * @param {object|null} intro The binding's `intro`.
 * @return {{lead: string, blocks: Array<{title: string, text: string, items: string[]}>}|null} The page.
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t02
 */
export function introView(intro) {
	if (!intro || typeof intro !== 'object') {
		return null
	}
	const blocks = (Array.isArray(intro.blocks) ? intro.blocks : [])
		.map((block) => ({
			title: String(block?.title || ''),
			text: String(block?.text || ''),
			items: (Array.isArray(block?.items) ? block.items : []).map(String).filter(Boolean),
		}))
		.filter((block) => block.title || block.text || block.items.length > 0)
	const lead = String(intro.lead || '')
	return lead === '' && blocks.length === 0 ? null : { lead, blocks }
}
