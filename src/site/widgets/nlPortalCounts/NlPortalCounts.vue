<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What the portal publishes, in numbers (home-and-theme-landing-pages
	REQ-HTL-004): one request for the facet counts, made without the viewer's
	session so an officer sees what the public sees. Each number links to the
	search with that filter set. A count the endpoint did not answer is left
	out, never shown as zero.
-->
<template>
	<section class="nl-portal-counts" data-testid="nl-portal-counts">
		<h2 v-if="heading" class="utrecht-heading-2">
			{{ heading }}
		</h2>
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ say('loading') }}
		</p>
		<p
			v-else-if="state !== 'ok' || counts.length === 0"
			class="utrecht-paragraph"
			data-testid="nl-portal-counts-empty">
			{{ say(state === 'ok' ? 'empty' : state) }}
		</p>
		<ul v-else class="nl-portal-counts__list">
			<li
				v-for="entry in counts"
				:key="entry.value"
				class="nl-portal-counts__item"
				data-testid="nl-portal-count">
				<a class="utrecht-link" :href="entry.href">
					<span class="nl-portal-counts__number">{{ entry.count }}</span>
					<span class="nl-portal-counts__label">{{ entry.label }}</span>
				</a>
			</li>
		</ul>
	</section>
</template>

<script>
import { fetchCounts } from '../../lib/subjects.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import strings from './strings.js'

import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export default {
	name: 'NlPortalCounts',

	props: {
		/** The heading. */
		heading: { type: String, default: '' },
		/** What to count per: `category`, `subject` or `year`. */
		by: { type: String, default: 'category' },
		/** The search page the counts link into. */
		searchRoute: { type: String, default: '/zoeken' },
	},

	data() {
		return { counts: [], state: 'loading' }
	},

	/**
	 * Read the counts once the widget is on the page.
	 *
	 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
	 */
	async mounted() {
		const answer = await fetchCounts(this.by, {
			searchRoute: this.searchRoute || '/zoeken',
			locale: pageLocale(),
		})
		this.counts = answer.counts
		this.state = answer.state
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},
	},
}
</script>

<style scoped>
.nl-portal-counts__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
	gap: var(--utrecht-space-block-md, 1rem);
	list-style: none;
	margin: 0;
	padding: 0;
}

.nl-portal-counts__item .utrecht-link {
	display: flex;
	flex-direction: column;
	text-decoration: none;
}

.nl-portal-counts__number {
	font-size: var(--utrecht-heading-2-font-size, 2rem);
	font-weight: var(--utrecht-heading-2-font-weight, inherit);
}
</style>
