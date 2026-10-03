<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-timeline"
		:aria-busy="entries === null ? 'true' : undefined"
		data-testid="timeline-list">
		<h3 class="utrecht-heading-4">
			{{ heading }}
		</h3>
		<p v-if="entries === null" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<p
			v-else-if="ordered.length === 0"
			class="utrecht-paragraph pq-timeline__empty">
			<em>{{ t('Nothing has happened yet.') }}</em>
		</p>
		<!-- A Den Haag contact timeline (site-mijn-omgeving-components
		     REQ-SMO-005), newest first, each event its moment and sentence. -->
		<ContactTimeline v-else :entries="ordered" :tr="mijnTr" :locale="locale" />
	</section>
</template>

<script>
import ContactTimeline from '../mijn/ContactTimeline.vue'
import { mijnTranslator } from '../mijn/rows.js'
import { newestFirst } from './timeline.js'

/**
 * The history of one record as its contributing app returned it (slice b,
 * b6): every entry, newest first, under the label the app declared.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-records-timeline-must-show-as-its-app-returned-it-req-srp-019
 */
export default {
	name: 'TimelineList',

	components: { ContactTimeline },

	props: {
		/** The heading the app declared. */
		label: { type: String, default: '' },
		/** The entries, or null while they load. */
		entries: { type: Array, default: null },
		/** The translator. */
		t: { type: Function, required: true },
		/** The language. */
		locale: { type: String, default: 'nl' },
	},

	computed: {
		heading() {
			return this.label || this.t('What happened')
		},

		ordered() {
			return newestFirst(this.entries)
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator of the mijn omgeving components.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		mijnTr() {
			return mijnTranslator(this.t, this.locale)
		},
	},
}
</script>

<style scoped>
/* The list's look comes from ContactTimeline. */
.pq-timeline {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}
</style>
