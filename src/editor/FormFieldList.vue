<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  FormFieldList — the `form` widget's field list in the page designer's
  inspector (site-nlds-widget-palette design D4, REQ-SNW-003).

  A widget bound to a `form` row (`formId`) lists and edits that row's fields
  instead, through the host's `formRows` client (PageGridEditor), because the
  anonymous submit accepts only the row's field ids; `readonly` is set while
  OpenRegister does not let this user write the row.

  Each field shows its label, name and whether it is required, then the
  settings its type declares in src/lib/formFieldTypes.js. Every edit emits the
  whole new list; the grid editor stores it on the widget's `fields` prop, so
  undo, the dirty check and the save treat it like any other prop.

  "Veld toevoegen" opens the palette, whose Formulieren group adds the field:
  one place to choose a field type, the same place an editor finds widgets.

  @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
-->
<template>
	<div class="form-field-list" data-testid="designer-form-fields">
		<p v-if="list.length === 0" class="form-field-list__empty">
			{{ t('portaliq', 'This form has no fields yet.') }}
		</p>

		<ol v-else class="form-field-list__items">
			<li
				v-for="(field, index) in list"
				:key="`${index}-${field.id}`"
				class="form-field-list__item"
				:data-testid="`designer-form-field-${field.id}`">
				<fieldset class="form-field-list__fieldset">
					<legend class="form-field-list__legend">
						{{ field.label || field.id }}
						<span class="form-field-list__type">{{
							typeLabel(field.type)
						}}</span>
					</legend>

					<div
						v-for="setting in settingsOf(field)"
						:key="setting.name"
						class="form-field-list__setting">
						<template v-if="setting.kind === 'boolean'">
							<input
								:id="controlId(index, setting)"
								type="checkbox"
								:disabled="readonly"
								:checked="field[setting.name] === true"
								:data-testid="`designer-form-field-${field.id}-${setting.name}`"
								@change="
									set(field, setting, $event.target.checked)
								" />
							<label :for="controlId(index, setting)">{{
								setting.label
							}}</label>
						</template>
						<template v-else>
							<label :for="controlId(index, setting)">{{
								setting.label
							}}</label>
							<textarea
								v-if="
									setting.kind === 'text'
									|| setting.kind === 'options'
								"
								:id="controlId(index, setting)"
								class="form-field-list__input"
								rows="3"
								:disabled="readonly"
								:value="displayValue(field, setting)"
								:data-testid="`designer-form-field-${field.id}-${setting.name}`"
								@change="set(field, setting, $event.target.value)" />
							<input
								v-else
								:id="controlId(index, setting)"
								class="form-field-list__input"
								:type="setting.kind === 'number' ? 'number' : 'text'"
								:disabled="readonly"
								:value="displayValue(field, setting)"
								:data-testid="`designer-form-field-${field.id}-${setting.name}`"
								@change="set(field, setting, $event.target.value)" />
						</template>
					</div>

					<div class="form-field-list__actions">
						<button
							type="button"
							:disabled="readonly || index === 0"
							:data-testid="`designer-form-field-${field.id}-up`"
							@click="emitList(moveField(list, field.id, -1))">
							{{ t('portaliq', 'Move up') }}
						</button>
						<button
							type="button"
							:disabled="readonly || index === list.length - 1"
							:data-testid="`designer-form-field-${field.id}-down`"
							@click="emitList(moveField(list, field.id, 1))">
							{{ t('portaliq', 'Move down') }}
						</button>
						<button
							type="button"
							:disabled="readonly"
							:data-testid="`designer-form-field-${field.id}-remove`"
							@click="emitList(removeField(list, field.id))">
							{{ t('portaliq', 'Remove field') }}
						</button>
					</div>
				</fieldset>
			</li>
		</ol>

		<button
			type="button"
			class="form-field-list__add"
			:disabled="readonly"
			data-testid="designer-form-field-add"
			@click="$emit('add')">
			{{ t('portaliq', 'Add field') }}
		</button>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import {
	fieldList,
	fieldSettings,
	fieldType,
	moveField,
	removeField,
	updateField,
} from '../lib/formFieldTypes.js'

export default {
	name: 'FormFieldList',

	props: {
		/** The form widget's `fields` prop. */
		fields: {
			type: [Array, Object, String],
			default: () => [],
		},

		/**
		 * Show the list without letting it change: the bound form row could
		 * not be read, or OpenRegister refused this user a write to it.
		 */
		readonly: {
			type: Boolean,
			default: false,
		},

		/** Prefix for the control ids, so two inspectors never share one. */
		idPrefix: {
			type: String,
			default: 'form-field',
		},
	},

	emits: ['update:fields', 'add'],

	computed: {
		/**
		 * @return {Array<object>} The fields that can be edited.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		list() {
			return fieldList(this.fields)
		},
	},

	methods: {
		moveField,
		removeField,

		/**
		 * Translate, locally: the portal edit mode mounts the inspector on the
		 * public site too, where the admin app's global mixin is absent.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The source text.
		 * @return {string} The translation.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		t(app, text) {
			return translate(app, text)
		},

		/**
		 * @param {string} type The field type.
		 * @return {string} Its Dutch name, or the type itself for one not declared.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		typeLabel(type) {
			return fieldType(type)?.label || type
		},

		/**
		 * @param {object} field The field.
		 * @return {Array<object>} Its settings: label, name, required, then its type's own.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		settingsOf(field) {
			return fieldSettings(field.type)
		},

		/**
		 * @param {number} index The field's place.
		 * @param {object} setting The setting.
		 * @return {string} The control's id.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		controlId(index, setting) {
			return `${this.idPrefix}-${index}-${setting.name}`
		},

		/**
		 * @param {object} field The field.
		 * @param {object} setting The setting.
		 * @return {string} What the control shows: options one per line.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		displayValue(field, setting) {
			const value = field[setting.name]
			if (setting.kind === 'options') {
				return Array.isArray(value) ? value.join('\n') : ''
			}
			return value === undefined || value === null ? '' : String(value)
		},

		/**
		 * @param {object} field The field.
		 * @param {object} setting The setting.
		 * @param {*} value The control's value.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		set(field, setting, value) {
			this.emitList(updateField(this.list, field.id, setting.name, value))
		},

		/**
		 * @param {Array<object>} list The new field list.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		emitList(list) {
			this.$emit('update:fields', list)
		},
	},
}
</script>

<style scoped>
.form-field-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.form-field-list__item + .form-field-list__item {
	margin-block-start: 8px;
}

.form-field-list__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.form-field-list__legend {
	font-weight: 600;
}

.form-field-list__type {
	margin-inline-start: 4px;
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.form-field-list__setting {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin-block: 4px;
}

.form-field-list__input {
	inline-size: 100%;
}

.form-field-list__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-block-start: 8px;
}

.form-field-list__add {
	margin-block-start: 8px;
}
</style>
