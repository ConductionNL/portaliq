<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="container pq-document" data-testid="document-page">
		<p
			v-if="loading"
			class="utrecht-paragraph"
			role="status"
			data-testid="document-page-loading">
			{{ t('Loading…') }}
		</p>

		<article v-else-if="page" class="pq-document__body">
			<p class="utrecht-paragraph pq-document__part-of">
				{{ t('Part of') }}
				<a
					class="utrecht-link"
					:href="hrefForRoute(page.publication.route)"
					data-testid="document-page-publication"
					@click.prevent="$emit('navigate', page.publication.route)">
					{{ page.publication.title || t('the publication') }}
				</a>
			</p>
			<h1 class="utrecht-heading-1" data-testid="document-page-title">
				{{ page.document.title }}
			</h1>

			<dl
				v-if="page.facts.length > 0"
				class="pq-document__facts"
				data-testid="document-page-facts">
				<div
					v-for="fact in page.facts"
					:key="fact.label"
					class="pq-document__fact">
					<dt>
						<strong>{{ fact.label }}</strong>
					</dt>
					<dd>{{ fact.value }}</dd>
				</div>
			</dl>

			<p class="pq-document__actions">
				<!-- The browser's viewer (REQ-PDC-001). -->
				<a
					v-if="page.document.view"
					class="utrecht-button utrecht-button--primary-action"
					:href="page.document.view"
					target="_blank"
					rel="noopener noreferrer"
					data-testid="document-page-view">
					{{ t('View')
					}}<span class="sr-only">{{
						` ${page.document.title}, ${t('opens in a new tab')}`
					}}</span>
				</a>
				<a
					v-if="page.document.href"
					class="utrecht-button utrecht-button--secondary-action"
					:href="page.document.href"
					download
					rel="noopener noreferrer"
					data-testid="document-page-download">
					{{ t('Download')
					}}<span class="sr-only">{{ ` ${page.document.title}` }}</span>
				</a>
			</p>

			<!-- Only the metadata links the read carries (REQ-PDC-004). -->
			<p
				v-if="page.document.metadataUrl || page.document.diwooUrl"
				class="utrecht-paragraph pq-document__metadata">
				<a
					v-if="page.document.metadataUrl"
					class="utrecht-link"
					:href="page.document.metadataUrl"
					data-testid="document-page-metadata">
					{{ t('Metadata (JSON)') }}
				</a>
				<a
					v-if="page.document.diwooUrl"
					class="utrecht-link"
					:href="page.document.diwooUrl"
					data-testid="document-page-diwoo">
					{{ t('Metadata (DiWoo XML)') }}
				</a>
			</p>
		</article>
	</section>
</template>

<script>
import { loadDocumentPage } from '../lib/documentPage.js'

/**
 * Whether the pinned `@conduction/nextcloud-vue` offers a public data-file
 * preview; the same switch as the publication page's (REQ-PDC-001).
 */
const PUBLIC_PREVIEW = false

/**
 * One public document's own page (REQ-PDC-002).
 *
 * Reads the document through the public read of its publication. Nothing
 * found emits `notfound`, and the shell renders its not-found page: a draft's
 * document and an unknown id get the same answer.
 *
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export default {
	name: 'DocumentPage',

	props: {
		/** The publication id in the route. */
		publicationId: {
			type: String,
			required: true,
		},

		/** The document id in the route. */
		documentId: {
			type: String,
			required: true,
		},

		/** The shell's translator. */
		t: {
			type: Function,
			required: true,
		},

		/** The shell's href for an in-site route. */
		hrefForRoute: {
			type: Function,
			default: (route) => route,
		},
	},

	emits: ['navigate', 'notfound', 'subject'],

	data() {
		return {
			page: null,
			loading: true,
		}
	},

	watch: {
		/**
		 * Read again when the route moves to another document.
		 *
		 * @return {void}
		 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
		 */
		documentId() {
			this.load()
		},

		/**
		 * Read again when the route moves to another publication.
		 *
		 * @return {void}
		 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
		 */
		publicationId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the document, then tell the shell what it shows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
		 */
		async load() {
			const asked = `${this.publicationId}/${this.documentId}`
			this.loading = true
			const page = await loadDocumentPage({
				publicationId: this.publicationId,
				documentId: this.documentId,
				t: this.t,
				preview: PUBLIC_PREVIEW,
			})
			if (asked !== `${this.publicationId}/${this.documentId}`) {
				return
			}

			this.page = page
			this.loading = false
			if (page === null) {
				this.$emit('notfound')
				return
			}
			this.$emit('subject', { title: page.document.title })
		},
	},
}
</script>

<style scoped>
.pq-document__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: var(--pq-space-xs, 0.25rem) var(--pq-space-m, 1rem);
	margin-block: var(--pq-space-m, 1rem);
}

.pq-document__fact {
	display: contents;
}

.pq-document__fact dd {
	margin: 0;
}

.pq-document__actions,
.pq-document__metadata {
	display: flex;
	flex-wrap: wrap;
	gap: var(--pq-space-s, 0.5rem) var(--pq-space-m, 1rem);
}
</style>
