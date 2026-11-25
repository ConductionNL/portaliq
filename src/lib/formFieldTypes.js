// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The field types an editor can place inside a `form` widget, and the pure
 * edits on a form's field list (site-nlds-widget-palette design D1 and D4,
 * REQ-SNW-003).
 *
 * WHY THE FIELD LIST IS EDITED HERE AND NOT IN THE INSPECTOR. The list lives on
 * the form widget's `fields` prop, in the shape `FormBlock` already renders and
 * submits: `{id, type, label, required, ...settings}`. Every edit returns a NEW
 * list, so the grid editor's history and dirty check see a change the same way
 * they see any other prop, and a node test can hold the rules without a DOM:
 * ids stay unique, a removed field is gone, and a type's settings are only the
 * ones that type declares.
 *
 * Name, label and whether the field is required are always editable (D4); the
 * rest comes from each type's `settings`, which are D1's "editable fields".
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */

/**
 * One editable setting of a field type.
 *
 * @typedef {object} FieldSetting
 * @property {string} name The key on the field.
 * @property {('string'|'text'|'number'|'options'|'boolean')} kind How the inspector edits it.
 * @property {string} label The Dutch label in the inspector.
 */

/**
 * The limits a number and a range share.
 *
 * @type {Array<FieldSetting>}
 */
const LIMITS = Object.freeze([
	{ name: 'min', kind: 'number', label: 'Minimum' },
	{ name: 'max', kind: 'number', label: 'Maximum' },
	{ name: 'step', kind: 'number', label: 'Stap' },
])

/**
 * The field types, in the order the Formulieren group lists them.
 *
 * `nlds` is the NL Design System component the type draws (design D1), so a
 * search for "Radio Group" finds `radio` the way it finds a widget.
 *
 * @type {Array<{type: string, label: string, nlds: string, synonyms: Array<string>, settings: Array<FieldSetting>}>}
 */
export const FORM_FIELD_TYPES = Object.freeze([
	{
		type: 'text',
		label: 'Tekstveld',
		nlds: 'Text Input',
		synonyms: ['tekst', 'invoer', 'naam'],
		settings: [
			{ name: 'description', kind: 'text', label: 'Toelichting' },
			{
				name: 'autocomplete',
				kind: 'string',
				label: 'Automatisch invullen (autocomplete)',
			},
		],
	},
	{
		type: 'textarea',
		label: 'Tekstvak',
		nlds: 'Text Area',
		synonyms: ['lange tekst', 'opmerking', 'bericht'],
		settings: [
			{ name: 'description', kind: 'text', label: 'Toelichting' },
			{ name: 'rows', kind: 'number', label: 'Aantal regels' },
		],
	},
	{
		type: 'number',
		label: 'Getal',
		nlds: 'Number Input',
		synonyms: ['nummer', 'aantal'],
		settings: LIMITS,
	},
	{
		type: 'email',
		label: 'E-mailadres',
		nlds: 'Text Input',
		synonyms: ['mail', 'e-mail'],
		settings: [{ name: 'description', kind: 'text', label: 'Toelichting' }],
	},
	{
		type: 'tel',
		label: 'Telefoonnummer',
		nlds: 'Text Input',
		synonyms: ['telefoon', 'mobiel'],
		settings: [{ name: 'description', kind: 'text', label: 'Toelichting' }],
	},
	{
		type: 'textAffixed',
		label: 'Tekstveld met voor- of achtervoegsel',
		nlds: 'Customizable Text Input',
		synonyms: ['input group', 'euro', 'voorvoegsel', 'achtervoegsel'],
		settings: [
			{ name: 'prefix', kind: 'string', label: 'Voorvoegsel' },
			{ name: 'suffix', kind: 'string', label: 'Achtervoegsel' },
		],
	},
	{
		type: 'date',
		label: 'Datum',
		nlds: 'Date Input Group',
		synonyms: ['dag', 'geboortedatum', 'datumkiezer', 'date picker', 'kalender'],
		// D1 rows 22 and 23: one type, `picker` switches the day, month and
		// year group for a calendar, and both honour the earliest and latest date.
		settings: [
			{ name: 'description', kind: 'text', label: 'Toelichting' },
			{ name: 'picker', kind: 'boolean', label: 'Datumkiezer (kalender)' },
			{ name: 'earliest', kind: 'string', label: 'Vroegste datum (jjjj-mm-dd)' },
			{ name: 'latest', kind: 'string', label: 'Laatste datum (jjjj-mm-dd)' },
		],
	},
	{
		type: 'select',
		label: 'Keuzelijst',
		nlds: 'Select',
		synonyms: ['dropdown', 'uitklaplijst', 'combobox', 'zoeken'],
		// D1 rows 81 and 82: `searchable` turns the list into a Select Combobox.
		settings: [
			{ name: 'options', kind: 'options', label: 'Keuzes (één per regel)' },
			{ name: 'searchable', kind: 'boolean', label: 'Zoeken in de keuzes' },
		],
	},
	{
		type: 'radio',
		label: 'Keuzerondjes',
		nlds: 'Radio Group',
		synonyms: ['radio', 'één keuze'],
		settings: [
			{ name: 'options', kind: 'options', label: 'Keuzes (één per regel)' },
		],
	},
	{
		type: 'checkboxes',
		label: 'Aanvinkvakjes',
		nlds: 'Checkbox Group',
		synonyms: ['meerdere keuzes', 'vinkjes'],
		settings: [
			{ name: 'options', kind: 'options', label: 'Keuzes (één per regel)' },
			{ name: 'minimum', kind: 'number', label: 'Minimaal aantal keuzes' },
		],
	},
	{
		type: 'checkbox',
		label: 'Aanvinkvakje',
		nlds: 'Checkbox',
		synonyms: ['vinkje', 'akkoord'],
		settings: [{ name: 'description', kind: 'text', label: 'Toelichting' }],
	},
	{
		type: 'switch',
		label: 'Schakelaar',
		nlds: 'Switch',
		synonyms: ['aan uit', 'toggle'],
		settings: [{ name: 'description', kind: 'text', label: 'Toelichting' }],
	},
	{
		type: 'range',
		label: 'Schuifbalk',
		nlds: 'Range',
		synonyms: ['schuif', 'slider'],
		settings: LIMITS,
	},
	{
		// D1 row 28, Fieldset: a legend and a description over fields of its
		// own. It holds fields, not a value: its fields submit under their own
		// names, and a group never sits inside a group.
		type: 'group',
		label: 'Groep van velden',
		nlds: 'Fieldset',
		synonyms: ['fieldset', 'groep', 'onderdeel', 'sectie'],
		settings: [{ name: 'description', kind: 'text', label: 'Toelichting' }],
	},
])

/**
 * The type of a field that holds fields instead of a value.
 */
export const GROUP_TYPE = 'group'

/**
 * The declared type, or null for one this list does not know.
 *
 * @param {string} type The field type.
 * @return {object|null} The type.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function fieldType(type) {
	return FORM_FIELD_TYPES.find((entry) => entry.type === type) || null
}

/**
 * The settings the inspector offers for a field: name, label and required
 * always, then the type's own.
 *
 * @param {string} type The field type.
 * @return {Array<FieldSetting|{name: string, kind: string, label: string}>} The settings.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function fieldSettings(type) {
	if (type === GROUP_TYPE) {
		// A group answers nothing itself, so "required" means nothing on it;
		// its legend is its label.
		return [
			{ name: 'label', kind: 'string', label: 'Legenda' },
			{ name: 'id', kind: 'string', label: 'Naam' },
			...(fieldType(type)?.settings || []),
		]
	}
	return [
		{ name: 'label', kind: 'string', label: 'Label' },
		{ name: 'id', kind: 'string', label: 'Naam (in de inzending)' },
		{ name: 'required', kind: 'boolean', label: 'Verplicht' },
		...(fieldType(type)?.settings || []),
	]
}

/**
 * Whether a field is a group (fieldset) of fields.
 *
 * @param {*} field A field.
 * @return {boolean} True for a `group`.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function isFieldGroup(field) {
	return !!field && field.type === GROUP_TYPE
}

/**
 * The fields inside a group: those that are objects with an id, and never a
 * group (one level deep, design D1 row 28).
 *
 * @param {object} group A `group` field.
 * @return {Array<object>} Its fields.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function groupFields(group) {
	return fieldList(group?.fields).filter((field) => !isFieldGroup(field))
}

/**
 * The fields that take an answer, in the order the form shows them: a
 * group's fields in its place, the group itself left out. This is the list a
 * form validates and submits, so a field in a group is checked and sent like
 * any other, under its own name.
 *
 * @param {*} fields The `fields` prop.
 * @return {Array<object>} The answering fields.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function answerFields(fields) {
	return fieldList(fields).flatMap((field) =>
		isFieldGroup(field) ? groupFields(field) : [field],
	)
}

/**
 * The form in the parts it draws: runs of loose fields, and each group with
 * its legend, description and fields. Neighbouring loose fields share one
 * part, so a form without groups is one part, drawn as before.
 *
 * @param {*} fields The `fields` prop.
 * @return {Array<{group: object|null, fields: Array<object>}>} The parts.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function fieldSections(fields) {
	const sections = []
	for (const field of fieldList(fields)) {
		if (isFieldGroup(field)) {
			sections.push({ group: field, fields: groupFields(field) })
			continue
		}
		const last = sections[sections.length - 1]
		if (last && last.group === null) {
			last.fields.push(field)
		} else {
			sections.push({ group: null, fields: [field] })
		}
	}
	return sections
}

/**
 * Every name in the form, a group's and its fields' included.
 *
 * @param {Array<object>} list The field list.
 * @return {Array<string>} The names.
 */
function allIds(list) {
	return list.flatMap((field) =>
		isFieldGroup(field)
			? [field.id, ...groupFields(field).map((child) => child.id)]
			: [field.id],
	)
}

/**
 * Where the field ID sits: the list it is in and, for a field in a group,
 * that group.
 *
 * @param {Array<object>} list The top-level field list.
 * @param {string} id The field.
 * @return {{field: object, siblings: Array<object>, group: object|null}|null} Its place, or null.
 */
function placeOf(list, id) {
	const top = list.find((field) => field.id === id)
	if (top) {
		return { field: top, siblings: list, group: null }
	}
	for (const group of list.filter(isFieldGroup)) {
		const children = groupFields(group)
		const child = children.find((field) => field.id === id)
		if (child) {
			return { field: child, siblings: children, group }
		}
	}
	return null
}

/**
 * The list with the siblings of a place replaced: the top level, or one
 * group's fields.
 *
 * @param {Array<object>} list The top-level field list.
 * @param {object|null} group The group whose fields change, or null for the top level.
 * @param {Array<object>} siblings The new siblings.
 * @return {Array<object>} The new list.
 */
function withSiblings(list, group, siblings) {
	if (!group) {
		return siblings
	}
	return list.map((field) =>
		field === group ? { ...field, fields: siblings } : field,
	)
}

/**
 * A form's field list as an array, whatever the prop held.
 *
 * @param {*} fields The `fields` prop.
 * @return {Array<object>} The fields that are objects with an id.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function fieldList(fields) {
	return Array.isArray(fields)
		? fields.filter(
				(field) =>
					field
					&& typeof field === 'object'
					&& typeof field.id === 'string',
			)
		: []
}

/**
 * A name for a new field that no field in the list carries: the type, then
 * the type with a number.
 *
 * @param {Array<object>} fields The current fields.
 * @param {string} type The new field's type.
 * @return {string} The name.
 */
function freeId(fields, type) {
	const taken = new Set(allIds(fields))
	if (!taken.has(type)) {
		return type
	}
	let number = 2
	while (taken.has(`${type}${number}`)) {
		number++
	}
	return `${type}${number}`
}

/**
 * The list with a new field of TYPE at the end.
 *
 * The label starts as the type's own Dutch name, so the form shows a labelled
 * field the moment it is added; an unknown type adds nothing.
 *
 * With INTO naming a group of the form the field goes at the end of that
 * group; a group itself, or an INTO that names no group, goes at the end of
 * the form, since a group never sits inside a group.
 *
 * @param {*} fields The current `fields` prop.
 * @param {string} type The field type to add.
 * @param {string} [into] The group to add it to; '' for the form itself.
 * @return {Array<object>} The new list.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function addField(fields, type, into = '') {
	const list = fieldList(fields)
	const declared = fieldType(type)
	if (!declared) {
		return list
	}
	const field = {
		id: freeId(list, type),
		type,
		label: declared.label,
	}
	if (type === GROUP_TYPE) {
		field.fields = []
	} else {
		field.required = false
	}
	if (declared.settings.some((setting) => setting.kind === 'options')) {
		field.options = []
	}
	const group = type === GROUP_TYPE
		? null
		: list.find((candidate) => isFieldGroup(candidate) && candidate.id === into)
	if (group) {
		return withSiblings(list, group, [...groupFields(group), field])
	}
	return [...list, field]
}

/**
 * The value a setting stores, from what the inspector's control gave.
 *
 * @param {string} kind The setting's kind.
 * @param {*} value The control's value.
 * @return {*} The stored value; undefined to drop the key.
 */
function settingValue(kind, value) {
	if (kind === 'boolean') {
		return value === true
	}
	if (kind === 'number') {
		const number = Number(value)
		return value === '' || value === null || !Number.isFinite(number)
			? undefined
			: number
	}
	if (kind === 'options') {
		const lines = Array.isArray(value) ? value : String(value ?? '').split('\n')
		return lines.map((line) => String(line).trim()).filter((line) => line !== '')
	}
	return String(value ?? '')
}

/**
 * The list with one setting of the field ID changed.
 *
 * Only a setting the field's type declares is written, so a field never
 * carries a key its renderer would not read. A name is kept unique: renaming a
 * field to a name another field carries is refused and the list is unchanged,
 * because two fields with one name would submit one value.
 *
 * @param {*} fields The current `fields` prop.
 * @param {string} id The field to change.
 * @param {string} name The setting.
 * @param {*} value The new value.
 * @return {Array<object>} The new list.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function updateField(fields, id, name, value) {
	const list = fieldList(fields)
	const place = placeOf(list, id)
	if (!place) {
		return list
	}
	const { field, siblings, group } = place
	const setting = fieldSettings(field.type).find(
		(candidate) => candidate.name === name,
	)
	if (!setting) {
		return list
	}
	const stored = settingValue(setting.kind, value)
	let next = { ...field }
	if (name === 'id') {
		// Unique over the whole form: a field in a group submits under its
		// own name, next to the fields outside it.
		const wanted = String(stored).trim()
		if (
			wanted === ''
			|| (wanted !== field.id && allIds(list).includes(wanted))
		) {
			return list
		}
		next = { ...field, id: wanted }
	} else if (stored === undefined) {
		delete next[name]
	} else {
		next[name] = stored
	}
	const changed = siblings.map((other) => (other === field ? next : other))
	return withSiblings(list, group, changed)
}

/**
 * The list without the field ID.
 *
 * @param {*} fields The current `fields` prop.
 * @param {string} id The field to remove.
 * @return {Array<object>} The new list.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function removeField(fields, id) {
	const list = fieldList(fields)
	const place = placeOf(list, id)
	if (!place) {
		return list
	}
	// Removing a group removes the fields in it with it.
	return withSiblings(
		list,
		place.group,
		place.siblings.filter((field) => field !== place.field),
	)
}

/**
 * The list with the field ID moved one place up (-1) or down (+1) among its
 * siblings. Moving past either end changes nothing.
 *
 * @param {*} fields The current `fields` prop.
 * @param {string} id The field to move.
 * @param {number} by -1 or +1.
 * @return {Array<object>} The new list.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function moveField(fields, id, by) {
	const list = fieldList(fields)
	const place = placeOf(list, id)
	if (!place) {
		return list
	}
	// A field moves among its own siblings: inside its group, or between the
	// form's own fields and groups.
	const from = place.siblings.indexOf(place.field)
	const to = from + (by < 0 ? -1 : 1)
	if (to < 0 || to >= place.siblings.length) {
		return list
	}
	const next = [...place.siblings]
	const [moved] = next.splice(from, 1)
	next.splice(to, 0, moved)
	return withSiblings(list, place.group, next)
}

/**
 * The Formulieren group for the palette: the field types as entries, matched
 * by the same words a widget is (label, type, NL Design System name,
 * synonyms), and whether they can be added now.
 *
 * With no form widget selected the group is still shown, so an editor learns
 * where the fields are, but it says to select a form first and its entries are
 * disabled: adding a field to the grid, or to nothing, is not an option.
 *
 * @param {string} query The palette search.
 * @param {boolean} formSelected Whether a `form` widget is selected.
 * @return {{group: string, label: string, enabled: boolean, note: string, entries: Array<object>}|null} The group, or null when the search matches no field type.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function formFieldGroup(query, formSelected) {
	const needle = String(query || '')
		.trim()
		.toLowerCase()
	const entries = FORM_FIELD_TYPES.filter((entry) => {
		if (needle === '') {
			return true
		}
		return [entry.label, entry.type, entry.nlds, ...entry.synonyms]
			.map((word) => word.toLowerCase())
			.some((word) => word.includes(needle))
	}).map((entry) => ({ key: entry.type, label: entry.label, nlds: entry.nlds }))

	if (entries.length === 0) {
		return null
	}

	return {
		group: 'forms',
		label: 'Formulieren',
		enabled: formSelected === true,
		note:
			formSelected === true
				? ''
				: 'Selecteer eerst een formulier op de pagina. Een veld komt altijd in een formulier.',
		entries,
	}
}

/**
 * The palette's groups with the Formulieren group in its place: after
 * Navigatie and before Terugkoppeling (design D2's order). A widget the
 * catalogue already files under `forms` stays in that group, after the
 * field types.
 *
 * @param {Array<object>} groups The widget groups from `paletteGroups()`.
 * @param {object|null} fieldGroup The group from `formFieldGroup()`.
 * @return {Array<object>} The groups.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function withFormFieldGroup(groups, fieldGroup) {
	const list = Array.isArray(groups) ? groups : []
	if (!fieldGroup) {
		return list
	}
	const existing = list.find((group) => group.group === 'forms')
	// `fields` are the field types this group adds to the selected form;
	// `entries` stay the widgets, so the palette renders them as it renders
	// every other group's tiles.
	const merged = {
		group: fieldGroup.group,
		label: fieldGroup.label,
		enabled: fieldGroup.enabled,
		note: fieldGroup.note,
		fields: fieldGroup.entries,
		entries: existing ? existing.entries : [],
	}
	const rest = list.filter((group) => group.group !== 'forms')
	const after = rest.findIndex(
		(group) => !['content', 'nav'].includes(group.group),
	)
	const at = after < 0 ? rest.length : after
	return [...rest.slice(0, at), merged, ...rest.slice(at)]
}
