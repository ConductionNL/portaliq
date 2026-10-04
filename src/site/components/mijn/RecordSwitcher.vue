<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	For whom a page shows: a radio group over the rows of the page's
	`records` collection (the guardian's children), each with initials, a
	title and a subtitle. The choice is the route; the page moves the focus
	to its heading after a switch. The initials are decorative.
-->
<template>
	<fieldset class="pq-record-switcher" data-testid="mijn-record-switcher">
		<legend class="sr-only">{{ legend }}</legend>
		<div
			v-for="option in options"
			:key="option.id"
			class="pq-record-switcher__option">
			<input
				:id="`${name}-${option.key}`"
				class="pq-record-switcher__input"
				type="radio"
				:name="name"
				:value="option.id"
				:checked="option.id === chosen"
				@change="$emit('choose', option.id)" />
			<label class="pq-record-switcher__label" :for="`${name}-${option.key}`">
				<span class="pq-record-switcher__initials" aria-hidden="true">{{
					option.initials
				}}</span>
				<span class="pq-record-switcher__text">
					<span class="pq-record-switcher__title">{{ option.title }}</span>
					<span
						v-if="option.subtitle"
						class="pq-record-switcher__subtitle"
						>{{ option.subtitle }}</span
					>
				</span>
			</label>
		</div>
	</fieldset>
</template>

<script>
/**
 * The initials of a name: the first letter of its first and last word.
 *
 * @param {string} title The name.
 * @return {string} One or two capitals.
 */
function initialsOf(title) {
	const words = String(title || '')
		.trim()
		.split(/\s+/)
		.filter(Boolean)
	if (words.length === 0) {
		return ''
	}
	const first = words[0][0] || ''
	const last = words.length > 1 ? words[words.length - 1][0] || '' : ''
	return `${first}${last}`.toUpperCase()
}

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
 */
export default {
	name: 'RecordSwitcher',

	props: {
		/** The rows to choose from. */
		rows: { type: Array, required: true },
		/** The id of the chosen row. */
		chosen: { type: String, default: '' },
		/** The fields that name a row. */
		titleFields: { type: Array, default: () => [] },
		/** The fields under its name. */
		subtitleFields: { type: Array, default: () => [] },
		/** The group's name for a screen reader, "Kies voor wie". */
		legend: { type: String, required: true },
		/** The radio group's name, unique on the page. */
		name: { type: String, default: 'pq-record-switcher' },
	},

	emits: ['choose'],

	computed: {
		/**
		 * @return {Array<object>} `{id, key, title, subtitle, initials}` per row.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		options() {
			const pick = (row, fields, fallback) =>
				(fields.length > 0 ? fields : fallback)
					.map((field) => row?.[field])
					.filter(
						(value) => typeof value === 'string' && value.trim() !== '',
					)
					.join(' ')
			return this.rows
				.map((row) => {
					const id = String(
						row?.id || row?.uuid || row?.['@self']?.id || '',
					)
					const title = pick(row, this.titleFields, [
						'name',
						'title',
						'givenName',
					])
					return {
						id,
						key: id.replace(/[^A-Za-z0-9_-]/g, ''),
						title,
						subtitle: pick(row, this.subtitleFields, []),
						initials: initialsOf(title),
					}
				})
				.filter((option) => option.id !== '' && option.title !== '')
		},
	},
}
</script>

<style scoped>
.pq-record-switcher {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin: 0 0 var(--utrecht-space-block-md, 1rem);
	padding: 0;
	border: 0;
}

.pq-record-switcher__input {
	position: absolute;
	opacity: 0;
	inline-size: 1px;
	block-size: 1px;
}

.pq-record-switcher__label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 0.75rem;
	border: 2px solid var(--utrecht-color-grey-80, #ccc);
	border-radius: 0.5rem;
	background-color: var(--utrecht-document-background-color, #fff);
	color: var(--utrecht-document-color, inherit);
	cursor: pointer;
}

.pq-record-switcher__input:checked + .pq-record-switcher__label {
	border-color: var(
		--utrecht-button-primary-action-background-color,
		currentcolor
	);
	font-weight: bold;
}

.pq-record-switcher__input:focus-visible + .pq-record-switcher__label {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.pq-record-switcher__initials {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	inline-size: 2rem;
	block-size: 2rem;
	border-radius: 50%;
	background-color: var(--utrecht-button-primary-action-background-color, #333);
	color: var(--utrecht-button-primary-action-color, #fff);
	font-size: 0.875rem;
	font-weight: bold;
}

.pq-record-switcher__text {
	display: flex;
	flex-direction: column;
}

.pq-record-switcher__subtitle {
	font-size: 0.875em;
	font-weight: normal;
}
</style>
