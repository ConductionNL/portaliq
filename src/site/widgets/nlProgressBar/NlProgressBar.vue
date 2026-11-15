<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	How far something has got (design D1 row 72).

	NL Design System publishes no CSS for this one, so it is drawn from
	`--utrecht-*` tokens alone (design D5). What it announces, and when, is in
	the live region at the bottom.
-->
<template>
	<div class="nl-progress" data-testid="nl-progress-bar">
		<label class="utrecht-form-label" :for="fieldId">{{ label }}</label>
		<!--
			THE NATIVE ELEMENT. `progress` is announced with its value and its
			maximum by every screen reader, because the browser's own assistive
			stack understands it. A div with a width in percent announces nothing.
		-->
		<progress
			:id="fieldId"
			class="nl-progress__bar"
			:value="safeValue"
			:max="safeMax"
			data-testid="nl-progress-bar-element">
			{{ percentText }}
		</progress>
		<p class="utrecht-paragraph nl-progress__text">{{ percentText }}</p>
		<!--
			AND WHAT IT SAYS WHEN IT ARRIVES: one polite announcement that the
			work is done. The bar's value is read when somebody visits it;
			reaching the end is news, and news is announced once rather than on
			every step, which is why this region holds only the finished text.
		-->
		<p
			class="nl-progress__done"
			role="status"
			aria-live="polite"
			data-testid="nl-progress-bar-done">
			{{ finished ? doneLabel : '' }}
		</p>
	</div>
</template>

<script>
import '@utrecht/form-label-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlProgressBar',

	props: {
		/** How far it has got. */
		value: { type: [Number, String], default: 0 },
		/** What counts as finished. */
		max: { type: [Number, String], default: 100 },
		/** What the bar is about. */
		label: { type: String, default: '' },
		/** What is said when it reaches its end. */
		doneLabel: { type: String, default: 'Klaar' },
	},

	computed: {
		/**
		 * @return {string} An id for the label to point at.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		fieldId() {
			const slug = String(this.label || 'voortgang')
				.toLowerCase()
				.replace(/[^a-z0-9]+/g, '-')
			return 'nl-progress-' + slug
		},

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
		 * The value in words, for somebody who cannot see the bar and for the
		 * fallback text inside the element.
		 *
		 * @return {string} The percentage.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		percentText() {
			return Math.round((this.safeValue / this.safeMax) * 100) + '%'
		},

		/**
		 * @return {boolean} Whether it has reached its end.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		finished() {
			return this.safeValue >= this.safeMax
		},
	},
}
</script>

<style scoped>
/*
 * TOKENS ONLY (design D5). The track takes a grey token, the value the
 * primary button's own background, so a portal's set themes the bar without
 * this file naming a colour. tests/widget-tokens.spec.mjs fails on a literal.
 */
.nl-progress {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.25rem);
}

.nl-progress__bar {
	inline-size: 100%;
	block-size: var(--utrecht-space-block-sm, 0.5rem);
	border: 0;
	border-radius: var(--utrecht-border-radius-md, 4px);
	background-color: var(
		--utrecht-color-grey-20,
		var(--utrecht-document-background-color)
	);
}

.nl-progress__bar::-webkit-progress-bar {
	border-radius: var(--utrecht-border-radius-md, 4px);
	background-color: var(
		--utrecht-color-grey-20,
		var(--utrecht-document-background-color)
	);
}

.nl-progress__bar::-webkit-progress-value {
	border-radius: var(--utrecht-border-radius-md, 4px);
	background-color: var(
		--utrecht-button-primary-action-background-color,
		var(--utrecht-document-color)
	);
}

.nl-progress__bar::-moz-progress-bar {
	border-radius: var(--utrecht-border-radius-md, 4px);
	background-color: var(
		--utrecht-button-primary-action-background-color,
		var(--utrecht-document-color)
	);
}

.nl-progress__text,
.nl-progress__done {
	margin: 0;
}
</style>
