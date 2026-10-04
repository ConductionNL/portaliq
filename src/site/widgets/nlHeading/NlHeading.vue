<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A heading, at the level the author chose (design D1 rows 41 to 48).

	The level is clamped to 1 to 6 rather than trusted: a page with an h0 or an
	h9 is markup no browser has an outline for, and a heading is the one element
	a screen-reader user navigates by.
-->
<template>
	<component :is="tag" :class="headingClass" data-testid="nl-heading">
		{{ text }}
	</component>
</template>

<script>
import '@utrecht/heading-1-css/dist/index.css'
import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/heading-4-css/dist/index.css'
import '@utrecht/heading-5-css/dist/index.css'
import '@utrecht/heading-6-css/dist/index.css'

export default {
	name: 'NlHeading',

	props: {
		/** The text the visitor reads. */
		text: { type: String, default: '' },
		/** The heading level, 1 to 6. */
		level: { type: [Number, String], default: 2 },
	},

	computed: {
		/**
		 * @return {number} The level, clamped into 1 to 6.
		 */
		safeLevel() {
			const level = Math.round(Number(this.level) || 2)
			return Math.min(Math.max(level, 1), 6)
		},

		/**
		 * @return {string} The element to render.
		 */
		tag() {
			return `h${this.safeLevel}`
		},

		/**
		 * @return {string} Utrecht's class for that level.
		 */
		headingClass() {
			return `utrecht-heading-${this.safeLevel}`
		},
	},
}
</script>
