<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A data badge on the Den Haag CSS: "Nieuw", "Voor 12 oktober". The state
	picks the colour from the theme's `--nl-data-badge-*` tokens; the text
	always carries the meaning, so colour is never the only signal. A date
	sits in a <time> with its machine-readable day.
-->
<template>
	<span
		class="nl-data-badge pq-data-badge"
		:class="`nl-data-badge--${tone}`"
		data-testid="mijn-data-badge">
		<time v-if="datetime" :datetime="datetime">{{ text }}</time>
		<template v-else>{{ text }}</template>
	</span>
</template>

<script>
/** The states the Den Haag data badge styles. */
const TONES = ['neutral', 'success', 'warning', 'error']

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export default {
	name: 'DataBadge',

	props: {
		/** The words on the badge. */
		text: { type: String, required: true },
		/** neutral, success, warning or error. */
		state: { type: String, default: 'neutral' },
		/** A day (`2026-10-12`) when the badge names one. */
		datetime: { type: String, default: '' },
	},

	computed: {
		/**
		 * @return {string} A state the CSS knows, else neutral.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		tone() {
			return TONES.includes(this.state) ? this.state : 'neutral'
		},
	},
}
</script>

<style>
/* The state colours: the package's own CSS, nothing else of it. */
@import '@gemeente-denhaag/data-badge/index.css';
</style>

<style scoped>
/* The badge's shape. The Den Haag package keeps it in JavaScript, which is
   never imported, so it is drawn here from the same tokens. */
.pq-data-badge {
	display: inline-block;
	box-sizing: border-box;
	max-inline-size: max-content;
	padding-block: var(--nl-data-badge-padding-block, 0.125rem);
	padding-inline: var(--nl-data-badge-padding-inline, 0.5rem);
	border: var(--nl-data-badge-border-width, 1px) solid
		var(--nl-data-badge-border-color, currentcolor);
	border-radius: var(--nl-data-badge-border-radius, 0.25rem);
	font-size: var(--nl-data-badge-font-size, 0.875rem);
	font-weight: var(--nl-data-badge-font-weight, 600);
	line-height: var(--nl-data-badge-line-height, 1.5);
	white-space: nowrap;
}
</style>
