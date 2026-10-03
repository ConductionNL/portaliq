<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		v-if="entries.length > 0"
		class="pq-error-summary"
		:aria-labelledby="headingId"
		role="group"
		data-testid="error-summary">
		<h2
			:id="headingId"
			ref="heading"
			class="utrecht-heading-3 pq-error-summary__heading"
			tabindex="-1"
			data-testid="error-summary-heading">
			{{ heading }}
		</h2>
		<p v-if="intro !== ''" class="utrecht-paragraph">
			{{ intro }}
		</p>
		<ul class="pq-error-summary__list">
			<li v-for="entry in entries" :key="entry.field">
				<a
					v-if="entry.target !== ''"
					class="utrecht-link pq-error-summary__link"
					:href="`#${entry.target}`"
					:data-testid="`error-summary-link-${entry.field}`"
					@click.prevent="focusField(entry.target)">
					{{ entry.message }}
				</a>
				<span v-else :data-testid="`error-summary-text-${entry.field}`">
					{{ entry.message }}
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
/**
 * The NL Design System error summary: shown above a form after a failed
 * submit, with a heading that takes focus and one link per error, in field
 * order. A link moves focus to its field (or the first input of a group). The
 * per-field message stays under each field; this list is the overview.
 *
 * The parent calls `focus()` after every failed submit, so a second failure
 * with the same errors is announced again. While errors stand, the document
 * title starts with the `titlePrefix`, so a screen reader hears it.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
 */
export default {
	name: 'ErrorSummary',

	props: {
		/** The errors, `[{field, target, message}]` from `summaryEntries()`. */
		entries: { type: Array, default: () => [] },
		/** The heading. */
		heading: { type: String, default: 'Er ontbreekt nog iets' },
		/** One line under the heading, '' for none. */
		intro: {
			type: String,
			default: 'Vul dit aan. Daarna kunt u het formulier versturen.',
		},

		/** The prefix of the document title while errors stand. */
		titlePrefix: { type: String, default: 'Fout: ' },
		/** A unique id base for the heading. */
		idBase: { type: String, default: 'pq-error-summary' },
	},

	computed: {
		/**
		 * The heading's id, which names the summary group.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		headingId() {
			return `${this.idBase}-heading`
		},
	},

	watch: {
		/**
		 * Keep the document title in step with the errors.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		entries() {
			this.syncTitle()
		},
	},

	mounted() {
		this.syncTitle()
	},

	beforeUnmount() {
		this.setTitlePrefix(false)
	},

	methods: {
		/**
		 * Move keyboard focus to the summary's heading.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		focus() {
			if (this.$refs.heading) {
				this.$refs.heading.focus()
			}
		},

		/**
		 * Move keyboard focus to one field.
		 *
		 * @param {string} target The field's element id.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		focusField(target) {
			const element =
				typeof document !== 'undefined'
					? document.getElementById(target)
					: null
			if (element) {
				element.focus()
			}
		},

		/**
		 * Add the title prefix while errors stand, remove it once they are gone.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		syncTitle() {
			this.setTitlePrefix(this.entries.length > 0)
		},

		/**
		 * Put the prefix on the document title, or take it off.
		 *
		 * @param {boolean} on Whether the prefix should stand.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		setTitlePrefix(on) {
			if (typeof document === 'undefined' || this.titlePrefix === '') {
				return
			}
			const title = String(document.title || '')
			const has = title.startsWith(this.titlePrefix)
			if (on && !has) {
				document.title = `${this.titlePrefix}${title}`
			} else if (!on && has) {
				document.title = title.slice(this.titlePrefix.length)
			}
		},
	},
}
</script>

<style scoped>
.pq-error-summary {
	border: 2px solid
		var(
			--utrecht-form-field-invalid-border-color,
			var(--utrecht-form-field-error-message-color, #c0210f)
		);
	border-radius: var(--utrecht-alert-border-radius, 0.5rem);
	margin-block-end: var(--utrecht-space-block-md, 1rem);
	padding-block: var(--utrecht-space-block-md, 1rem);
	padding-inline: var(--utrecht-space-inline-lg, 1.25rem);
}

.pq-error-summary__heading {
	margin-block: 0 var(--utrecht-space-block-xs, 0.5rem);
}

.pq-error-summary__heading:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentColor);
	outline-offset: 2px;
}

.pq-error-summary__list {
	margin-block: var(--utrecht-space-block-xs, 0.5rem) 0;
	padding-inline-start: 1.25rem;
}

.pq-error-summary__link {
	color: var(--utrecht-form-field-error-message-color, #c0210f);
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}
</style>
