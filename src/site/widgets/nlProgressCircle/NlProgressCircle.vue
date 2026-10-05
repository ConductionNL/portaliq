<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The same number as a ring (design D1 row 73).

	No upstream CSS, so tokens only (design D5). The ring is `aria-hidden`
	and the number beside it is the text: a screen reader reads one value, not
	a circle it cannot describe.
-->
<template>
	<div class="nl-circle" data-testid="nl-progress-circle">
		<svg
			class="nl-circle__ring"
			viewBox="0 0 42 42"
			aria-hidden="true"
			focusable="false">
			<circle class="nl-circle__track" cx="21" cy="21" r="18" />
			<circle
				class="nl-circle__value"
				cx="21"
				cy="21"
				r="18"
				:stroke-dasharray="dashArray"
				:stroke-dashoffset="dashOffset" />
		</svg>
		<!--
			WHAT IT ANNOUNCES: the value, as a `meter`-like text with the label
			beside it. The ring carries no meaning of its own, so it is hidden
			from assistive technology rather than described twice.
		-->
		<p
			class="utrecht-paragraph nl-circle__text"
			role="status"
			aria-live="polite"
			data-testid="nl-progress-circle-text">
			{{ label }} {{ percentText }}
		</p>
	</div>
</template>

<script>
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlProgressCircle',

	props: {
		/** How far it has got. */
		value: { type: [Number, String], default: 0 },
		/** What counts as finished. */
		max: { type: [Number, String], default: 100 },
		/** What the circle is about. */
		label: { type: String, default: '' },
	},

	computed: {
		/**
		 * @return {number} The maximum, at least 1.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeMax() {
			return Math.max(Number(this.max) || 100, 1)
		},

		/**
		 * @return {number} The value, inside 0 and the maximum.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeValue() {
			return Math.min(Math.max(Number(this.value) || 0, 0), this.safeMax)
		},

		/**
		 * @return {string} The percentage in words.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		percentText() {
			return Math.round((this.safeValue / this.safeMax) * 100) + '%'
		},

		/**
		 * The ring's circumference, so the dash maths reads as geometry rather
		 * than as a magic number.
		 *
		 * @return {number} The circumference of r=18.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		circumference() {
			return 2 * Math.PI * 18
		},

		/**
		 * @return {string} The dash pattern that draws the whole ring.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		dashArray() {
			return this.circumference.toFixed(2)
		},

		/**
		 * @return {string} How much of the ring stays undrawn.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		dashOffset() {
			const done = this.safeValue / this.safeMax
			return (this.circumference * (1 - done)).toFixed(2)
		},
	},
}
</script>

<style scoped>
/*
 * TOKENS ONLY (design D5): the track is a grey token, the value the primary
 * button's background, the text the document's ink.
 */
.nl-circle {
	display: flex;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.nl-circle__ring {
	inline-size: 4rem;
	block-size: 4rem;
	transform: rotate(-90deg);
}

.nl-circle__track,
.nl-circle__value {
	fill: none;
	stroke-width: 4;
}

.nl-circle__track {
	stroke: var(--utrecht-color-grey-20, var(--utrecht-document-background-color));
}

.nl-circle__value {
	stroke: var(
		--utrecht-button-primary-action-background-color,
		var(--utrecht-document-color)
	);
	stroke-linecap: round;
}

.nl-circle__text {
	margin: 0;
}
</style>
