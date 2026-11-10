// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The decisions behind a repeating group (form-flow-repeating-groups-
 * calculations-and-decisions REQ-FFL-001), without Vue so `node --test` asserts
 * them: how many items are allowed, what a card is called and says, what is
 * missing, and what removing an item leaves.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */

/**
 * The words of the group.
 */
export const GROUP_WORDS = Object.freeze({
	item: 'Item',
	add: 'Nog een item toevoegen',
	change: 'Wijzigen',
	remove: 'Verwijderen',
	save: 'Opslaan',
	cancel: 'Annuleren',
	addMore: 'Voeg nog {n} {item} toe',
	tooMany: 'U kunt hoogstens {n} toevoegen.',
	required: '{field} is verplicht.',
})

/**
 * What a group's `repeat` declares, with the defaults filled in.
 *
 * @param {object} field The group field.
 * @return {{min: number, max: number, itemLabel: string, itemsLabel: string, addLabel: string}} The repeat settings; `max` 0 means no limit.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function repeatOf(field) {
	const repeat = (field && field.repeat) || {}
	const itemLabel = wordsOr(repeat.itemLabel, GROUP_WORDS.item)
	return {
		min: minimumOf(field, repeat),
		max: positiveCount(repeat.max),
		itemLabel,
		itemsLabel: wordsOr(repeat.itemsLabel, itemLabel),
		addLabel: wordsOr(repeat.addLabel, GROUP_WORDS.add),
	}
}

/**
 * @param {unknown} value A declared count.
 * @return {number} The count when it is a whole number above zero, else 0.
 */
function positiveCount(value) {
	return Number.isInteger(value) && value > 0 ? value : 0
}

/**
 * @param {object|null} field The group field.
 * @param {object} repeat Its `repeat`.
 * @return {number} The declared minimum, else one for a required group, else 0.
 */
function minimumOf(field, repeat) {
	return positiveCount(repeat.min) || (field && field.required === true ? 1 : 0)
}

/**
 * @param {unknown} words A declared label.
 * @param {string} fallback The label when none is declared.
 * @return {string} The declared label, or the fallback.
 */
function wordsOr(words, fallback) {
	return typeof words === 'string' && words !== '' ? words : fallback
}

/**
 * Whether another item may be added.
 *
 * @param {object} field The group field.
 * @param {Array<object>} items The items so far.
 * @return {boolean} False once `repeat.max` items exist.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function canAdd(field, items) {
	const { max } = repeatOf(field)
	return max === 0 || (items || []).length < max
}

/**
 * The name of a card: the item label and its number, counted from 1.
 *
 * @param {object} field The group field.
 * @param {number} index The item's place, from 0.
 * @return {string} For example "Bewoner 2".
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function itemTitle(field, index) {
	return `${repeatOf(field).itemLabel} ${index + 1}`
}

/**
 * What a card says about its item: the first answer, then the rest on a
 * second line.
 *
 * @param {object} field The group field.
 * @param {object} item The item's answers.
 * @return {{first: string, rest: string}} The two lines; either may be empty.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function itemLines(field, item) {
	const answers = (Array.isArray(field.fields) ? field.fields : [])
		.map((sub) => String((item || {})[sub.name] ?? '').trim())
		.filter((text) => text !== '')
	return { first: answers[0] || '', rest: answers.slice(1).join(', ') }
}

/**
 * The sentence that says how many items are still missing, or '' when the
 * minimum is met: "Voeg nog 1 bewoner toe".
 *
 * @param {object} field The group field.
 * @param {Array<object>} items The items so far.
 * @param {string} [template] The sentence, with `{n}` and `{item}`.
 * @return {string} The sentence, or ''.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function missingMessage(field, items, template = GROUP_WORDS.addMore) {
	const repeat = repeatOf(field)
	const missing = repeat.min - (items || []).length
	if (missing <= 0) {
		return ''
	}
	const item = (missing === 1 ? repeat.itemLabel : repeat.itemsLabel).toLowerCase()
	return template.split('{n}').join(String(missing)).split('{item}').join(item)
}

/**
 * The items after removing one. The cards are numbered from their place, so
 * what remains is numbered from 1 again.
 *
 * @param {Array<object>} items The items.
 * @param {number} index The item to remove.
 * @return {Array<object>} A new list without it.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function withoutItem(items, index) {
	return (items || []).filter((_item, at) => at !== index)
}

/**
 * The items after saving one: a new item goes last, a changed one stays in place.
 *
 * @param {Array<object>} items The items.
 * @param {number} index The place of the changed item, or -1 for a new one.
 * @param {object} item The saved answers.
 * @return {Array<object>} A new list.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function withItem(items, index, item) {
	const list = [...(items || [])]
	if (index < 0 || index >= list.length) {
		list.push({ ...item })
	} else {
		list[index] = { ...item }
	}
	return list
}

/**
 * The required sub-fields an item leaves empty, as messages per sub-field.
 *
 * @param {object} field The group field.
 * @param {object} item The item's answers.
 * @param {string} [template] The message, with `{field}`.
 * @return {Record<string, string>} The message per sub-field.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function itemErrors(field, item, template = GROUP_WORDS.required) {
	const errors = {}
	for (const sub of Array.isArray(field.fields) ? field.fields : []) {
		if (isLeftEmpty(sub, item)) {
			errors[sub.name] = template.split('{field}').join(sub.label || sub.name)
		}
	}
	return errors
}

/**
 * @param {object} sub A sub-field.
 * @param {object|null} item The item's answers.
 * @return {boolean} Whether it is required and the item leaves it empty.
 */
function isLeftEmpty(sub, item) {
	return sub.required === true && String((item || {})[sub.name] ?? '').trim() === ''
}

/**
 * The count errors of the groups among some fields: one message per group
 * that holds too few items or too many.
 *
 * @param {Array<object>} fields The fields to check.
 * @param {Record<string, any>} values The values per field name.
 * @param {{addMore?: string, tooMany?: string}} [words] The sentences.
 * @return {Record<string, string>} The message per group.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export function groupCountErrors(fields, values, words = GROUP_WORDS) {
	const errors = {}
	for (const field of Array.isArray(fields) ? fields : []) {
		const message = field && field.type === 'group' ? countError(field, { values, words }) : ''
		if (message !== '') {
			errors[field.name] = message
		}
	}
	return errors
}

/**
 * A group's count error: too few items, or too many.
 *
 * @param {object} field The group field.
 * @param {{values: Record<string, unknown>|null, words: {addMore: string, tooMany: string}}} check The values per field name and the sentences.
 * @return {string} The message, or '' when the count is right.
 */
function countError(field, check) {
	const value = (check.values || {})[field.name]
	const items = Array.isArray(value) ? value : []
	const missing = missingMessage(field, items, check.words.addMore)
	if (missing !== '') {
		return missing
	}
	const { max } = repeatOf(field)
	return max > 0 && items.length > max
		? check.words.tooMany.split('{n}').join(String(max))
		: ''
}
