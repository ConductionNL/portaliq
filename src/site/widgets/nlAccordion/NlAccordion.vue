<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Parts an author can fold away, for questions and answers (design D1 row 1).

	Each title is a real `button` inside a heading, with `aria-expanded`: that
	combination is what lets a screen-reader user find the questions by heading
	and hear whether an answer is open. A `div` with a click handler would give
	them neither.
-->
<template>
	<div class="utrecht-accordion" data-testid="nl-accordion">
		<section
			v-for="(item, index) in safeItems"
			:key="`${index}-${item.title}`"
			class="utrecht-accordion__section">
			<component :is="headingTag" class="utrecht-accordion__header">
				<button
					type="button"
					class="utrecht-accordion__button"
					:aria-expanded="open.includes(index) ? 'true' : 'false'"
					:data-testid="`nl-accordion-toggle-${index}`"
					@click="toggle(index)">
					{{ item.title }}
				</button>
			</component>
			<div
				v-show="open.includes(index)"
				class="utrecht-accordion__panel"
				:data-testid="`nl-accordion-panel-${index}`">
				<p class="utrecht-paragraph">{{ item.text }}</p>
			</div>
		</section>
	</div>
</template>

<script>
import '@utrecht/accordion-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlAccordion',

	props: {
		/** The parts: `{title, text}`. */
		items: { type: Array, default: () => [] },
		/** The level of the titles, 2 to 6. */
		headingLevel: { type: [Number, String], default: 3 },
		/** Whether the first part starts open. */
		firstOpen: { type: Boolean, default: false },
	},

	data() {
		return {
			/** Which parts are open. */
			open: this.firstOpen ? [0] : [],
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The parts that have a title.
		 */
		safeItems() {
			return (this.items || [])
				.map((item) => ({
					title: String(item?.title ?? '').trim(),
					text: String(item?.text ?? '').trim(),
				}))
				.filter((item) => item.title !== '')
		},

		/**
		 * @return {string} The element the titles use, h2 to h6.
		 */
		headingTag() {
			const level = Math.round(Number(this.headingLevel) || 3)
			return `h${Math.min(Math.max(level, 2), 6)}`
		},
	},

	methods: {
		/**
		 * Open or close one part.
		 *
		 * @param {number} index Which part.
		 * @return {void}
		 */
		toggle(index) {
			this.open = this.open.includes(index)
				? this.open.filter((entry) => entry !== index)
				: [...this.open, index]
		},
	},
}
</script>
