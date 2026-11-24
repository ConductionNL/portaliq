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
 * @property {('string'|'text'|'number'|'options')} kind How the inspector edits it.
 * @property {string} label The Dutch label in the inspector.
 */

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
			{ name: 'autocomplete', kind: 'string', label: 'Automatisch invullen (autocomplete)' },
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
		settings: [
			{ name: 'min', kind: 'number', label: 'Minimum' },
			{ name: 'max', kind: 'number', label: 'Maximum' },
			{ name: 'step', kind: 'number', label: 'Stap' },
		],
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
		synonyms: ['dag', 'geboortedatum'],
		settings: [{ name: 'description', kind: 'text', label: 'Toelichting' }],
	},
	{
		type: 'select',
		label: 'Keuzelijst',
		nlds: 'Select',
		synonyms: ['dropdown', 'uitklaplijst'],
		settings: [{ name: 'options', kind: 'options', label: 'Keuzes (één per regel)' }],
	},
	{
		type: 'radio',
		label: 'Keuzerondjes',
		nlds: 'Radio Group',
		synonyms: ['radio', 'één keuze'],
		settings: [{ name: 'options', kind: 'options', label: 'Keuzes (één per regel)' }],
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
		settings: [
			{ name: 'min', kind: 'number', label: 'Minimum' },
			{ name: 'max', kind: 'number', label: 'Maximum' },
			{ name: 'step', kind: 'number', label: 'Stap' },
		],
	},
])

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
	return [
		{ name: 'label', kind: 'string', label: 'Label' },
		{ name: 'id', kind: 'string', label: 'Naam (in de inzending)' },
		{ name: 'required', kind: 'boolean', label: 'Verplicht' },
		...(fieldType(type)?.settings || []),
	]
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
		? fields.filter((field) => field && typeof field === 'object' && typeof field.id === 'string')
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
	const taken = new Set(fields.map((field) => field.id))
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
 * @param {*} fields The current `fields` prop.
 * @param {string} type The field type to add.
 * @return {Array<object>} The new list.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function addField(fields, type) {
	const list = fieldList(fields)
	const declared = fieldType(type)
	if (!declared) {
		return list
	}
	const field = { id: freeId(list, type), type, label: declared.label, required: false }
	if (declared.settings.some((setting) => setting.kind === 'options')) {
		field.options = []
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
		return value === '' || value === null || !Number.isFinite(number) ? undefined : number
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
	const field = list.find((candidate) => candidate.id === id)
	if (!field) {
		return list
	}
	const setting = fieldSettings(field.type).find((candidate) => candidate.name === name)
	if (!setting) {
		return list
	}
	const stored = settingValue(setting.kind, value)
	if (name === 'id') {
		const wanted = String(stored).trim()
		if (wanted === '' || list.some((other) => other !== field && other.id === wanted)) {
			return list
		}
		return list.map((other) => (other === field ? { ...other, id: wanted } : other))
	}
	return list.map((other) => {
		if (other !== field) {
			return other
		}
		const next = { ...other }
		if (stored === undefined) {
			delete next[name]
		} else {
			next[name] = stored
		}
		return next
	})
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
	return fieldList(fields).filter((field) => field.id !== id)
}

/**
 * The list with the field ID moved one place up (-1) or down (+1). Moving past
 * either end changes nothing.
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
	const from = list.findIndex((field) => field.id === id)
	const to = from + (by < 0 ? -1 : 1)
	if (from < 0 || to < 0 || to >= list.length) {
		return list
	}
	const next = [...list]
	const [moved] = next.splice(from, 1)
	next.splice(to, 0, moved)
	return next
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
	const needle = String(query || '').trim().toLowerCase()
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
		note: formSelected === true ? '' : 'Selecteer eerst een formulier op de pagina. Een veld komt altijd in een formulier.',
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
	const after = rest.findIndex((group) => !['content', 'nav'].includes(group.group))
	const at = after < 0 ? rest.length : after
	return [...rest.slice(0, at), merged, ...rest.slice(at)]
}
