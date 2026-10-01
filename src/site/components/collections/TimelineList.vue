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
		<ol v-else class="utrecht-ordered-list pq-timeline__list">
			<li
				v-for="(entry, index) in ordered"
				:key="entry.id || index"
				class="utrecht-ordered-list__item"
				data-testid="timeline-entry">
				<time
					v-if="hasMoment(entry)"
					class="pq-timeline__moment"
					:datetime="entry.occurredAt || entry.date"
					>{{ dateOf(entry) }}</time
				>
				<span>{{ textOf(entry) }}</span>
			</li>
		</ol>
	</section>
</template>

<script>
import { momentOf, newestFirst, textOf } from './timeline.js'

/**
 * The history of one record as its contributing app returned it (slice b,
 * b6): every entry, newest first, under the label the app declared.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-records-timeline-must-show-as-its-app-returned-it-req-srp-019
 */
export default {
	name: 'TimelineList',

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
	},

	methods: {
		textOf,
		hasMoment(entry) {
			return momentOf(entry) !== -Infinity
		},

		dateOf(entry) {
			try {
				return new Date(momentOf(entry)).toLocaleDateString(this.locale)
			} catch {
				return String(entry.occurredAt || entry.date)
			}
		},
	},
}
</script>

<style scoped>
.pq-timeline__moment {
	margin-inline-end: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
