<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-intake-catalogue" data-testid="intake-catalogue">
		<h2 v-if="heading" class="utrecht-heading-2">
			{{ heading }}
		</h2>

		<p v-if="loading" class="utrecht-paragraph" role="status">
			{{ loadingLabel }}
		</p>

		<p
			v-else-if="failed"
			class="utrecht-paragraph pq-intake-catalogue__error"
			data-testid="intake-catalogue-error"
			role="alert">
			{{ errorLabel }}
		</p>

		<p
			v-else-if="!topics.length"
			class="utrecht-paragraph"
			data-testid="intake-catalogue-empty">
			{{ emptyLabel }}
		</p>

		<template v-else>
			<section
				v-for="topic in topics"
				:key="topic.topic || 'other'"
				class="pq-intake-catalogue__topic"
				data-testid="intake-catalogue-topic">
				<h3 class="utrecht-heading-3">
					{{ topic.topic || otherTopicLabel }}
				</h3>
				<ul class="utrecht-unordered-list">
					<li
						v-for="entry in topic.entries"
						:key="entry.route"
						class="utrecht-unordered-list__item">
						<a
							class="utrecht-link"
							:href="hrefFor(entry)"
							:data-testid="`intake-entry-${entry.route}`"
							@click.prevent="open(entry)">
							{{ entry.title || entry.route }}
						</a>
						<p
							v-if="entry.summary"
							class="utrecht-paragraph pq-intake-catalogue__summary">
							{{ entry.summary }}
						</p>
					</li>
				</ul>
			</section>
		</template>
	</section>
</template>

<script>
import { authBaseFrom } from '../lib/authApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import { fetchCatalogue, formRouteFor } from '../lib/intakeApi.js'

/**
 * The requests a visitor can start, by topic, as the published catalogue
 * lists them today (portal-intake-form-as-an-object, REQ-PIFO-006).
 *
 * The block fetches on every mount and keeps nothing: an entry withdrawn from
 * opencatalogi is gone the next time the page opens, without a portal change.
 * Topics and entries are the catalogue's; which page lists them and which
 * page renders the form are the editor's, through `formPage`.
 */
export default {
	name: 'IntakeCatalogueBlock',

	props: {
		/** The serving portal's slug. Supplied by the host, never authored. */
		portal: {
			type: String,
			default: '',
		},

		/** The route of the page that holds the intake form block. */
		formPage: {
			type: String,
			default: '/aanvragen/formulier',
		},

		/** Shown above the list. */
		heading: {
			type: String,
			default: 'Aanvragen en meldingen',
		},

		/** Shown when the catalogue lists nothing for this portal. */
		emptyLabel: {
			type: String,
			default: 'Er zijn op dit moment geen aanvragen die u hier kunt starten.',
		},

		/** Shown when the catalogue could not be read. */
		errorLabel: {
			type: String,
			default:
				'De lijst met aanvragen kan nu niet worden geladen. Probeer het later opnieuw.',
		},

		/** Shown while the catalogue loads. */
		loadingLabel: {
			type: String,
			default: 'Aanvragen laden…',
		},

		/** The heading for entries that name no topic. */
		otherTopicLabel: {
			type: String,
			default: 'Overig',
		},
	},

	emits: ['navigate'],

	data() {
		return {
			topics: [],
			loading: true,
			failed: false,
		}
	},

	async mounted() {
		try {
			this.topics = await fetchCatalogue(
				authBaseFrom(resolveApiBase()),
				this.portal,
			)
		} catch {
			this.failed = true
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * A real href for an entry, so a new tab and a failed bundle still work.
		 *
		 * @param {object} entry A catalogue entry.
		 * @return {string} The href.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
		 */
		hrefFor(entry) {
			const url = new URL(window.location.href)
			url.search = ''
			url.searchParams.set('route', formRouteFor(this.formPage, entry.route))
			return url.toString()
		},

		/**
		 * Start the form behind an entry. The host owns routing, so this emits.
		 *
		 * @param {object} entry A catalogue entry.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
		 */
		open(entry) {
			this.$emit('navigate', formRouteFor(this.formPage, entry.route))
		},
	},
}
</script>

<style scoped>
.pq-intake-catalogue__topic + .pq-intake-catalogue__topic {
	margin-block-start: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-intake-catalogue__summary {
	margin-block-start: var(--utrecht-space-block-xs, 0.25rem);
}
</style>
