<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A record's history as a Den Haag contact timeline: an ordered list,
	newest first, each event a sentence and its date and time in a <time>.
	The app decided what is public; nothing is filtered here. The step
	marker is decorative.
-->
<template>
	<ol
		class="denhaag-process-steps denhaag-contact-timeline pq-contact-timeline"
		data-testid="mijn-contact-timeline">
		<li
			v-for="(entry, index) in ordered"
			:key="entry.id || index"
			class="denhaag-process-steps__step denhaag-contact-timeline__step"
			data-testid="timeline-entry">
			<div
				class="denhaag-process-steps__step-header denhaag-contact-timeline__step-header">
				<span
					class="denhaag-step-marker denhaag-step-marker--nested denhaag-step-marker--default"
					aria-hidden="true" />
				<div class="denhaag-contact-timeline__step-header__content">
					<p
						class="denhaag-process-steps__step-heading pq-contact-timeline__text">
						{{ textOf(entry) }}
						<span
							v-if="entry.channel"
							class="pq-contact-timeline__channel"
							data-testid="timeline-channel">
							<svg
								class="pq-contact-timeline__channel-icon"
								viewBox="0 0 24 24"
								aria-hidden="true"
								focusable="false">
								<path :d="channelIcon(entry.channel)" />
							</svg>
							{{ entry.channel }}
						</span>
					</p>
					<time
						v-if="stamp(entry)"
						class="denhaag-contact-timeline__step-header__date"
						:datetime="stamp(entry)">
						{{ moment(entry) }}
					</time>
				</div>
			</div>
		</li>
	</ol>
</template>

<script>
import { channelIcon, newestFirst, textOf } from '../collections/timeline.js'
import { momentInWords } from './documents.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export default {
	name: 'ContactTimeline',

	props: {
		/** The entries as the app returned them. */
		entries: { type: Array, required: true },
		/** The translator of the mijn omgeving components. */
		tr: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	computed: {
		/**
		 * @return {Array<object>} The entries, newest first.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		ordered() {
			return newestFirst(this.entries)
		},
	},

	methods: {
		textOf,
		channelIcon,

		/**
		 * @param {object} entry An entry.
		 * @return {string} Its machine-readable moment, or ''.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		stamp(entry) {
			const value = entry?.occurredAt || entry?.date || ''
			return Number.isNaN(Date.parse(value)) ? '' : String(value)
		},

		/**
		 * @param {object} entry An entry.
		 * @return {string} Its moment in words.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		moment(entry) {
			return momentInWords(this.stamp(entry), this.tr, this.locale)
		},
	},
}
</script>

<style>
/* The timeline's look: the packages' own CSS, nothing else of them. */
@import '@gemeente-denhaag/process-steps/index.css';
@import '@gemeente-denhaag/step-marker/index.css';
@import '@gemeente-denhaag/contact-timeline/index.css';
</style>

<style scoped>
.pq-contact-timeline {
	margin: 0;
	padding: 0;
	list-style: none;
}

.denhaag-contact-timeline__step-header {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding-block-end: var(--utrecht-space-block-sm, 0.5rem);
}

.denhaag-contact-timeline__step-header__content {
	display: flex;
	flex-direction: column;
}

.pq-contact-timeline__text {
	margin: 0;
}

.pq-contact-timeline__text {
	font-size: 1.0625rem;
	font-weight: 600;
}

/* The channel a contact came in by, as a small pill with its icon
   (board Zaak "Eerdere contactmomenten": Telefoon, Bericht via het portaal,
   Balie). */
.pq-contact-timeline__channel {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-inline-start: 8px;
	padding: 1px 8px;
	border: 1px solid
		var(
			--nldesign-website-card-border-color,
			var(--nldesign-color-border, currentcolor)
		);
	border-radius: var(--nldesign-website-badge-border-radius, 12px);
	font-size: 0.8125rem;
	font-weight: 400;
	vertical-align: 2px;
}

.pq-contact-timeline__channel-icon {
	inline-size: 14px;
	block-size: 14px;
	fill: currentcolor;
}

/* The moment sits under the text at its full width, on a phone too; the
   package gives it a narrow column of its own and hides it below 1024px. */
.denhaag-contact-timeline__step-header__date {
	display: block;
	min-width: 0;
	max-width: none;
	color: var(--nldesign-color-text-muted, inherit);
	font-size: 0.8125rem;
}
</style>
