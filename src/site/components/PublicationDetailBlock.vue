<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-detail" data-testid="publication-detail">
		<p
			v-if="loading"
			class="utrecht-paragraph"
			data-testid="publication-detail-loading">
			Bezig met laden…
		</p>

		<div v-else-if="error" role="alert" data-testid="publication-detail-error">
			<h1 class="utrecht-heading-1">Publicatie niet gevonden</h1>
			<p class="utrecht-paragraph">
				Deze publicatie bestaat niet (meer), of is niet openbaar.
			</p>
		</div>

		<article v-else-if="publication" class="pq-detail__body">
			<h1 class="utrecht-heading-1" data-testid="publication-detail-title">
				{{ title }}
			</h1>

			<!-- "Bewaar in mijn dossier" (woo-journey-entry-points, REQ-WJE-002). -->
			<SaveToDossier
				v-if="signedIn"
				:publication="subjectId"
				:subject="title" />

			<!--
				EVERY FIELD, IN THE ORDER THE API RETURNS THEM, matching the
				reference portal — including the ones that are empty, which it
				renders as `-`.

				That is a faithful reproduction and NOT an endorsement: a
				citizen-facing page that lists `Hidden: -` and `Downloads: -`
				is showing internal bookkeeping. Making it readable is a
				separate change, so that it can be judged on its own rather
				than smuggled in under "match the reference".
			-->
			<dl class="pq-detail__fields" data-testid="publication-detail-fields">
				<div
					v-for="field in fields"
					:key="field.name"
					class="pq-detail__field">
					<dt class="pq-detail__label">
						<strong>{{ field.label }}:</strong>
					</dt>
					<dd class="pq-detail__value">
						<!-- A URL is a link. -->
						<a
							v-if="field.kind === 'link'"
							class="utrecht-link"
							:href="field.value"
							rel="noopener noreferrer"
							target="_blank">
							{{ field.value }}
						</a>

						<!-- An array is a list, one item per line. -->
						<ul
							v-else-if="field.kind === 'list'"
							class="pq-detail__list">
							<li v-for="(item, index) in field.value" :key="index">
								{{ item }}
							</li>
						</ul>

						<!-- An object is a nested group of label/value pairs. -->
						<div
							v-else-if="field.kind === 'group'"
							class="pq-detail__group">
							<div
								v-for="entry in field.value"
								:key="entry.name"
								class="pq-detail__group-entry">
								<strong>{{ entry.name }}</strong>
								<a
									v-if="entry.kind === 'link'"
									class="utrecht-link"
									:href="entry.value"
									rel="noopener noreferrer"
									target="_blank">
									{{ entry.value }}
								</a>
								<span v-else>{{ entry.value }}</span>
							</div>
						</div>

						<span v-else>{{ field.value }}</span>
					</dd>
				</div>
			</dl>

			<!--
				THE DOCUMENTS (woo-search-and-detail, REQ-WSD-005). Rendered
				once the attachment call answered; a failed call leaves the
				section out rather than putting an error over a publication
				that loaded fine.
			-->
			<section
				v-if="documents !== null"
				class="pq-detail__documents"
				data-testid="publication-documents">
				<h2 class="utrecht-heading-2">Documenten</h2>
				<p
					v-if="documents.length === 0"
					class="utrecht-paragraph"
					data-testid="publication-documents-empty">
					Deze publicatie heeft geen documenten.
				</p>
				<ul v-else class="pq-detail__document-list">
					<li
						v-for="document in documents"
						:key="document.id || document.title"
						data-testid="publication-document">
						<a
							v-if="document.href"
							class="utrecht-link"
							:href="document.href"
							download
							rel="noopener noreferrer">
							{{ document.title }}
						</a>
						<span v-else>{{ document.title }}</span>
						<span
							v-if="document.type || document.size"
							class="pq-detail__document-meta">
							({{
								[document.type, document.size]
									.filter(Boolean)
									.join(', ')
							}})
						</span>
						<SaveToDossier
							v-if="signedIn && document.id"
							:publication="subjectId"
							:attachment="document.id"
							:subject="document.title" />
					</li>
				</ul>
			</section>
		</article>
	</section>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import {
	detailFields,
	humaniseLabel,
	toDocuments,
} from '../lib/publicationDetail.js'

/**
 * One publication, rendered as the reference portal renders it.
 *
 * WHAT IT READS
 * -------------
 * The same `@PublicPage` OpenCatalogi endpoint the search block uses, filtered
 * to one id. Portaliq holds no visibility logic here either: if OpenRegister's
 * RBAC does not return the row to an anonymous caller, this page says "not
 * found", which is the same answer an unpublished publication gets. Those two
 * are deliberately indistinguishable — telling a visitor that a publication
 * exists but is hidden from them is itself a disclosure.
 *
 * WHY THE ID COMES FROM A PROP
 * ----------------------------
 * `/publicatie/<id>` is one page and thousands of subjects. The host renderer
 * resolves the route to the `/publicatie` page and hands the trailing segment
 * down as `subjectId`, so this block never parses the URL itself — a block
 * that read `window.location` would work only at the one route an author
 * happened to place it on.
 *
 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
 */
export default {
	name: 'PublicationDetailBlock',

	components: {
		SaveToDossier: defineAsyncComponent(() => import('./SaveToDossier.vue')),
	},

	props: {
		/**
		 * The publication id, taken from the route by the host renderer.
		 *
		 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
		 */
		subjectId: {
			type: String,
			default: '',
		},

		/**
		 * Whether the page shell holds a portal session. Set by the host
		 * after the authored props, never by page configuration.
		 */
		signedIn: {
			type: Boolean,
			default: false,
		},

		/**
		 * The endpoint to read. Relative by default, so a portal served from
		 * the same Nextcloud finds OpenCatalogi without configuration.
		 */
		endpoint: {
			type: String,
			default: '/index.php/apps/opencatalogi/api/federation/publications',
		},
	},

	data() {
		return {
			publication: null,
			documents: null,
			loading: true,
			error: false,
		}
	},

	computed: {
		/**
		 * @return {string} The publication's title.
		 *
		 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
		 */
		title() {
			const row = this.publication || {}
			const self = row['@self'] || {}

			return row.title || row.name || self.name || 'Zonder titel'
		},

		/**
		 * @return {Array<object>} The rendered field rows.
		 *
		 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
		 */
		fields() {
			return detailFields(this.publication)
		},
	},

	watch: {
		/**
		 * Reload when the route moves to another publication.
		 *
		 * A visitor moving from one publication to another does not remount
		 * this component — the route changes and the prop with it. Without
		 * this the second publication would show the first one's fields.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
		 */
		subjectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Fetch the publication behind `subjectId`.
		 *
		 * @return {Promise<void>} Resolves once state is updated.
		 *
		 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-a-malformed-federated-row-must-not-blank-the-list
		 */
		async load() {
			if (!this.subjectId) {
				this.loading = false
				this.error = true
				return
			}

			this.loading = true
			this.error = false

			try {
				// THE BY-ID ROUTE, not `?_id=`.
				//
				// `/api/federation/publications?_id=<uuid>` is accepted and
				// IGNORED: measured 2026-08-20, it answered `total: 11` and
				// returned a different publication first. With `_limit=1` that
				// is one wrong row — the same shape as the directory filter on
				// this API, which also accepts its parameter and filters
				// nothing.
				//
				// `/api/federation/publications/<uuid>` returns the single
				// object, and still through the AGGREGATED endpoint, so a
				// publication that lives on a federated peer opens from its
				// search result.
				const base = this.endpoint.replace(/\/+$/, '')
				const url = new URL(
					`${base}/${encodeURIComponent(this.subjectId)}`,
					window.location.origin,
				)

				const response = await fetch(url.toString(), {
					headers: { Accept: 'application/json' },
				})

				if (response.ok === false) {
					throw new Error(`HTTP ${response.status}`)
				}

				const body = await response.json()
				// The by-id route answers with the object itself; a list shape
				// would mean the route fell through to the collection, which is
				// exactly the confusion `matchesId` exists to refuse.
				const candidate = Array.isArray(body.results)
					? (body.results[0] ?? null)
					: body

				if (candidate === null || this.matchesId(candidate) === false) {
					this.error = true
					this.publication = null
				} else {
					this.publication = candidate
					this.loadDocuments(url.toString())
				}
			} catch {
				// The reason is not surfaced: this runs at a public origin and
				// a raw fetch message would leak the backend's shape.
				this.error = true
				this.publication = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the publication's documents (woo-search-and-detail D4).
		 *
		 * Separate from the publication on purpose: the documents are a list
		 * of their own on opencatalogi's side, and a failure here must not
		 * take the publication down with it. `null` keeps the section out.
		 *
		 * @param {string} publicationUrl The publication's own URL.
		 * @return {Promise<void>} Resolves once `documents` is set.
		 *
		 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-list-and-offer-every-document-for-download-req-wsd-005
		 */
		async loadDocuments(publicationUrl) {
			const asked = this.subjectId
			this.documents = null

			try {
				const response = await fetch(`${publicationUrl}/attachments`, {
					headers: { Accept: 'application/json' },
				})
				if (response.ok === false) {
					return
				}

				const body = await response.json()
				if (asked === this.subjectId) {
					this.documents = toDocuments(body)
				}
			} catch {
				// Left out: see above.
			}
		},

		/**
		 * Whether a returned row is the one that was asked for.
		 *
		 * CHECKED RATHER THAN ASSUMED. `_id` is not a filter every backend
		 * honours — the sibling directory filter on this same API accepts its
		 * parameter and returns everything — and a page that renders row one of
		 * whatever came back would show a DIFFERENT publication than the URL
		 * names, confidently and with no error anywhere.
		 *
		 * @param {object} row One API result.
		 * @return {boolean} True when the row's id matches the route.
		 *
		 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-a-malformed-federated-row-must-not-blank-the-list
		 */
		matchesId(row) {
			const self = (row || {})['@self'] || {}

			return self.id === this.subjectId || (row || {}).id === this.subjectId
		},

		/**
		 * Exposed for the template's benefit in tests.
		 *
		 * @param {string} name A property name.
		 * @return {string} Its human label.
		 *
		 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
		 */
		humanise(name) {
			return humaniseLabel(name)
		},
	},
}
</script>

<style scoped>
.pq-detail__fields {
	margin: 0;
}

.pq-detail__field {
	margin-block-end: 8px;
}

.pq-detail__label {
	display: inline;
}

.pq-detail__value {
	display: inline;
	margin-inline-start: 4px;
}

.pq-detail__list {
	display: inline-block;
	margin: 0;
	padding-inline-start: 20px;
	vertical-align: top;
}

.pq-detail__group {
	display: block;
	padding-inline-start: 20px;
}

.pq-detail__documents {
	margin-block-start: 24px;
}

.pq-detail__document-list {
	margin: 0;
	padding-inline-start: 20px;
}

.pq-detail__document-meta {
	color: var(--nldesign-color-text-muted, #65757b);
	margin-inline-start: 4px;
}

.pq-detail__group-entry > strong {
	margin-inline-end: 4px;
}
</style>
