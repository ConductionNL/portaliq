<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Where a case stands, as Den Haag process steps: an ordered list in the
	order the steps provider gave. The current step carries
	aria-current="step"; a done step says "Gereed" to a screen reader and may
	show its date; each step's description sits under its label. The step
	marker is decorative: its number or tick repeats what the list says.
-->
<template>
	<!-- THE STEPS AS BARS (steps-as-bars): a row of steps, each a bar over
	     its name and one line under it, as the school boards draw "Waar sta
	     je?". The bar's colour is decorative; the words and aria-current
	     carry the state. -->
	<ol
		v-if="display === 'bars'"
		class="pq-step-bars"
		data-testid="mijn-process-steps">
		<li
			v-for="(step, index) in steps"
			:key="`${index}-${step.label}`"
			class="pq-step-bars__step"
			:class="`pq-step-bars__step--${markerOf(step)}`"
			:aria-current="step.state === 'current' ? 'step' : undefined">
			<span class="pq-step-bars__bar" aria-hidden="true" />
			<p class="pq-step-bars__label">
				<span class="sr-only">{{ stateWords(step) }}: </span>{{ step.label }}
			</p>
			<p v-if="lineOf(step)" class="pq-step-bars__line">
				{{ lineOf(step) }}
			</p>
		</li>
	</ol>
	<ol
		v-else
		class="denhaag-process-steps pq-process-steps"
		data-testid="mijn-process-steps">
		<li
			v-for="(step, index) in steps"
			:key="`${index}-${step.label}`"
			class="denhaag-process-steps__step"
			:aria-current="step.state === 'current' ? 'step' : undefined">
			<div class="denhaag-process-steps__step-header">
				<span
					class="denhaag-step-marker"
					:class="`denhaag-step-marker--${markerOf(step)}`"
					aria-hidden="true">
					<svg
						v-if="step.state === 'done'"
						class="denhaag-icon"
						viewBox="0 0 24 24"
						focusable="false">
						<path
							d="M5 12l5 5 9-10"
							fill="none"
							stroke="currentColor"
							stroke-width="2" />
					</svg>
					<template v-else>{{ index + 1 }}</template>
				</span>
				<p
					class="denhaag-process-steps__step-heading"
					:class="`denhaag-process-steps__step-heading--${markerOf(step)}`">
					<span class="sr-only">{{ stateWords(step) }}: </span
					>{{ step.label }}
				</p>
			</div>
			<div class="denhaag-process-steps__step-body">
				<p
					v-if="step.state === 'done' && step.date"
					class="denhaag-process-steps__step-meta denhaag-process-steps__step-meta--date">
					<time :datetime="step.date">{{ day(step.date) }}</time>
				</p>
				<p
					v-if="step.description"
					class="utrecht-paragraph pq-process-steps__text">
					{{ step.description }}
				</p>
			</div>
		</li>
	</ol>
</template>

<script>
import { dayInWords } from './cases.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
 */
export default {
	name: 'ProcessSteps',

	props: {
		/** The steps: `{label, description?, state, date?}`, in order. */
		steps: { type: Array, required: true },
		/** The translator of the mijn omgeving components. */
		tr: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Today, for the date words; a test passes a fixed day. */
		today: { type: Date, default: null },
		/** `list` (Den Haag process steps) or `bars` (steps-as-bars). */
		display: { type: String, default: 'list' },
	},

	methods: {
		/**
		 * @param {object} step A step.
		 * @return {string} The Den Haag marker state.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		/**
		 * The one line under a bar: the step's own words, else its date when
		 * it is done. Never both, so a date never stands twice (steps-as-bars).
		 *
		 * @param {object} step A step.
		 * @return {string} The line.
		 * @spec openspec/changes/steps-as-bars/specs/site-mijn-omgeving/spec.md#requirement-the-steps-may-draw-as-a-row-of-bars
		 */
		lineOf(step) {
			const text = String(step?.description || '').trim()
			if (text !== '') {
				return text
			}
			return step?.state === 'done' && step?.date ? this.day(step.date) : ''
		},

		markerOf(step) {
			if (step.state === 'done') {
				return 'checked'
			}
			return step.state === 'current' ? 'current' : 'not-checked'
		},

		/**
		 * @param {object} step A step.
		 * @return {string} The step's state in words, for a screen reader.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		stateWords(step) {
			if (step.state === 'done') {
				return this.tr('Done')
			}
			return this.tr(
				step.state === 'current' ? 'Current step' : 'Still to come',
			)
		},

		/**
		 * @param {string} value An ISO date.
		 * @return {string} The day in words.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		day(value) {
			return dayInWords(value, this.today || new Date(), this.locale) || value
		},
	},
}
</script>

<style>
/* The steps' look: the packages' own CSS, nothing else of them. */
@import '@gemeente-denhaag/process-steps/index.css';
@import '@gemeente-denhaag/step-marker/index.css';
</style>

<style scoped>
.pq-process-steps {
	margin: 0;
	padding: 0;
	list-style: none;
}

.denhaag-process-steps__step-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.denhaag-process-steps__step-heading,
.pq-process-steps__text,
.denhaag-process-steps__step-meta {
	margin: 0;
}

.denhaag-process-steps__step-heading {
	font-weight: bold;
}

.denhaag-process-steps__step-body {
	padding-inline-start: calc(var(--denhaag-step-marker-size, 2rem) + 0.75rem);
	padding-block-end: var(--utrecht-space-block-md, 1rem);
}

/* The steps as bars (steps-as-bars). Tokens only. */
.pq-step-bars {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(min(100%, 7.5rem), 1fr));
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-step-bars__bar {
	display: block;
	block-size: 6px;
	margin-block-end: 0.75rem;
	border-radius: 3px;
	background-color: var(--nldesign-color-border, currentcolor);
}

.pq-step-bars__step--checked .pq-step-bars__bar {
	background-color: var(--nldesign-color-primary, currentcolor);
}

.pq-step-bars__step--current .pq-step-bars__bar {
	background-color: var(
		--thematiq-accent-color,
		var(--nldesign-color-accent, var(--nldesign-color-primary, currentcolor))
	);
}

.pq-step-bars__label,
.pq-step-bars__line {
	margin: 0;
}

.pq-step-bars__label {
	font-weight: 600;
	line-height: 1.3;
}

.pq-step-bars__step--not-checked .pq-step-bars__label {
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, inherit)
	);
	font-weight: 400;
}

.pq-step-bars__line {
	margin-block-start: 0.375rem;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, inherit)
	);
	font-size: 0.9375rem;
}
</style>
