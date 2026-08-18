<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-panel" data-testid="editor-inspector">
		<h2 class="pq-panel__title">Instellingen</h2>

		<p v-if="!block" class="pq-panel__empty" data-testid="inspector-empty">
			Kies een blok op de pagina.
		</p>

		<template v-else>
			<p class="pq-panel__subject">
				<strong>{{ info ? info.label : block.widgetKey }}</strong>
				<span v-if="info">{{ info.description }}</span>
			</p>

			<!--
				DRIVEN BY THE BLOCK'S DECLARED FIELDS (task 4.3), never by a
				form written per block. A hand-written form is the version that
				goes stale: a block grows a prop, nobody updates its form, and
				the prop becomes unreachable to every author while looking
				supported in the code.
			-->
			<label
				v-for="field in fields"
				:key="field.name"
				class="pq-panel__field"
				:data-field="field.name">
				<span>{{ field.label }}</span>

				<textarea
					v-if="field.type === 'textarea'"
					:value="valueOf(field)"
					rows="6"
					:data-testid="`inspector-${field.name}`"
					@input="set(field, $event.target.value)" />

				<select
					v-else-if="field.type === 'select'"
					:value="valueOf(field)"
					:data-testid="`inspector-${field.name}`"
					@change="set(field, coerce(field, $event.target.value))">
					<option v-for="option in field.options" :key="option" :value="option">
						{{ option }}
					</option>
				</select>

				<input
					v-else-if="field.type === 'boolean'"
					type="checkbox"
					:checked="Boolean(valueOf(field))"
					:data-testid="`inspector-${field.name}`"
					@change="set(field, $event.target.checked)" />

				<input
					v-else-if="field.type === 'number'"
					type="number"
					:value="valueOf(field)"
					:data-testid="`inspector-${field.name}`"
					@input="set(field, Number($event.target.value))" />

				<!--
					A LIST IS EDITED AS JSON, AND THAT IS STATED RATHER THAN
					DRESSED UP. A per-item editor for cards and actions is the
					right answer and it is not built; offering a broken
					drag-and-drop list would be worse than a text area that
					plainly works. Invalid JSON is REFUSED with a message rather
					than silently discarded — an author who mistypes a bracket
					must not lose the rest of what they wrote.
				-->
				<template v-else-if="field.type === 'list'">
					<textarea
						:value="jsonOf(field)"
						rows="8"
						spellcheck="false"
						:data-testid="`inspector-${field.name}`"
						@input="setJson(field, $event.target.value)" />
					<span v-if="jsonErrors[field.name]" class="pq-panel__error" role="alert">
						{{ jsonErrors[field.name] }}
					</span>
				</template>

				<input
					v-else
					type="text"
					:value="valueOf(field)"
					:data-testid="`inspector-${field.name}`"
					@input="set(field, $event.target.value)" />

				<small v-if="field.help" class="pq-panel__help">{{ field.help }}</small>
			</label>

			<!--
				A BLOCK WITH NOTHING TO CONFIGURE SAYS SO. An empty panel reads
				as a broken inspector, and "this block has no settings" is a
				fact the author can act on.
			-->
			<p v-if="fields.length === 0" class="pq-panel__empty">
				Dit blok heeft geen instellingen.
			</p>

			<button
				type="button"
				class="pq-panel__danger"
				data-testid="inspector-remove"
				@click="$emit('remove')">
				Blok verwijderen
			</button>
		</template>
	</section>
</template>

<script>
import { blockInfo } from '../../site/lib/blockCatalog.js'

/**
 * The selected block's settings, built from its declared fields.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
export default {
	name: 'BlockInspector',

	props: {
		/** The selected block, or null. */
		block: {
			type: Object,
			default: null,
		},
	},

	emits: ['change', 'remove'],

	data() {
		return {
			/** Per-field parse errors, keyed by field name. */
			jsonErrors: {},
		}
	},

	computed: {
		/**
		 * @return {object|null} The catalogue entry for the selected block.
		 */
		info() {
			return this.block ? blockInfo(this.block.widgetKey) : null
		},

		/**
		 * @return {Array<object>} The fields to render.
		 */
		fields() {
			return this.info ? this.info.fields : []
		},
	},

	methods: {
		/**
		 * The current value of a field.
		 *
		 * @param {object} field The field definition.
		 * @return {*} The value, or ''.
		 */
		valueOf(field) {
			const props = (this.block && this.block.props) || {}
			return props[field.name] ?? ''
		},

		/**
		 * A list field as formatted JSON.
		 *
		 * @param {object} field The field definition.
		 * @return {string} The JSON.
		 */
		jsonOf(field) {
			const value = this.valueOf(field)
			if (value === '' || value === undefined) {
				return '[]'
			}

			return JSON.stringify(value, null, 2)
		},

		/**
		 * Coerce a select's string value back to the option's own type.
		 *
		 * A `<select>` always hands back a string, so a heading level chosen as
		 * `2` would be stored as `"2"` — and `headingLevel: "2"` fails the
		 * block's own numeric validator while looking correct in the data.
		 *
		 * @param {object} field The field definition.
		 * @param {string} value The raw value.
		 * @return {*} The coerced value.
		 */
		coerce(field, value) {
			const match = (field.options || []).find((o) => String(o) === value)
			return match === undefined ? value : match
		},

		/**
		 * Set a field.
		 *
		 * @param {object} field The field definition.
		 * @param {*}      value The value.
		 * @return {void}
		 */
		set(field, value) {
			this.$emit('change', { name: field.name, value })
		},

		/**
		 * Set a list field from JSON, refusing what does not parse.
		 *
		 * @param {object} field The field definition.
		 * @param {string} raw   The typed JSON.
		 * @return {void}
		 */
		setJson(field, raw) {
			try {
				const parsed = JSON.parse(raw)
				this.jsonErrors = { ...this.jsonErrors, [field.name]: '' }
				this.$emit('change', { name: field.name, value: parsed })
			} catch (error) {
				// KEPT ON SCREEN, NOT DISCARDED. The author's text stays in the
				// textarea; only the commit is withheld until it parses.
				this.jsonErrors = {
					...this.jsonErrors,
					[field.name]: `Geen geldige JSON: ${error.message}`,
				}
			}
		},
	},
}
</script>
