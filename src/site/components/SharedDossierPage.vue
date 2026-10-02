<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="container pq-shared-dossier"
		:aria-busy="answer === null ? 'true' : undefined"
		data-testid="shared-dossier">
		<p v-if="answer === null" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<div
			v-else-if="answer.status === 'notFound'"
			role="alert"
			data-testid="shared-dossier-not-found">
			<h1 class="utrecht-heading-1">
				{{ t('Shared dossier') }}
			</h1>
			<p class="utrecht-paragraph">
				{{
					t(
						'This link no longer works. Ask the person who shared it for a new link.',
					)
				}}
			</p>
		</div>
		<div
			v-else-if="answer.status !== 'ok'"
			role="alert"
			data-testid="shared-dossier-error">
			<h1 class="utrecht-heading-1">
				{{ t('Shared dossier') }}
			</h1>
			<p class="utrecht-paragraph">
				{{ t('We cannot show this dossier right now. Try again later.') }}
			</p>
		</div>
		<template v-else>
			<h1 class="utrecht-heading-1" data-testid="shared-dossier-title">
				{{ dossier.title || t('Shared dossier') }}
			</h1>
			<p class="utrecht-paragraph pq-shared-dossier__intro">
				{{
					t(
						'Someone shared this dossier with you. You see only the documents that are public now.',
					)
				}}
			</p>
			<p
				v-if="dossier.description"
				class="utrecht-paragraph pq-shared-dossier__description"
				data-testid="shared-dossier-description">
				{{ dossier.description }}
			</p>
			<p
				v-if="dossier.items.length === 0"
				class="utrecht-paragraph"
				data-testid="shared-dossier-empty">
				<em>{{ t('This dossier has no public documents right now.') }}</em>
			</p>
			<ul
				v-else
				class="utrecht-unordered-list pq-shared-dossier__items"
				data-testid="shared-dossier-items">
				<li
					v-for="item in dossier.items"
					:key="item.id || item.title"
					class="utrecht-unordered-list__item"
					data-testid="shared-dossier-item">
					<a v-if="item.href" class="utrecht-link" :href="item.href">{{
						item.title || item.href
					}}</a>
					<span v-else>{{ item.title }}</span>
					<p
						v-if="item.note"
						class="utrecht-paragraph pq-shared-dossier__note">
						{{ item.note }}
					</p>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import { fetchSharedDossier } from '../lib/sharedDossier.js'

/**
 * The public page behind a shared dossier link (hydra `woo-citizen-journey`
 * J3.4): the dossier's title and note, and the documents in it that are
 * public right now, each with its link and note. Anyone with the link sees
 * it, signed in or not; it never shows who made the dossier.
 *
 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
 */
export default {
	name: 'SharedDossierPage',

	props: {
		/** The share token from the route, or '' for a malformed one. */
		token: { type: String, default: '' },
		/** The translator. */
		t: { type: Function, required: true },
		/** `fetch`, or a stand-in in a test. */
		fetchImpl: { type: Function, default: null },
		/** The Nextcloud instance root opencatalogi is reached under. */
		instanceRoot: { type: String, default: '/index.php' },
		/** The answer to start from, for a server render or a test. */
		initialAnswer: { type: Object, default: null },
	},

	emits: ['loaded'],

	data() {
		return {
			answer: this.initialAnswer,
		}
	},

	computed: {
		/**
		 * The dossier on screen, or an empty one before it is read.
		 *
		 * @return {object}
		 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
		 */
		dossier() {
			return this.answer && this.answer.dossier
				? this.answer.dossier
				: { title: '', description: '', items: [] }
		},
	},

	watch: {
		/**
		 * Read again when the link on screen changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
		 */
		token() {
			this.load()
		},
	},

	/**
	 * Read the dossier unless an answer was handed in.
	 *
	 * @return {void}
	 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
	 */
	mounted() {
		if (this.answer === null) {
			this.load()
		}
	},

	methods: {
		/**
		 * Read the shared dossier and tell the shell its title.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
		 */
		async load() {
			this.answer = null
			const token = this.token
			const answer = await fetchSharedDossier(
				token,
				this.fetchImpl || ((url, init) => window.fetch(url, init)),
				this.instanceRoot,
			)
			if (token !== this.token) {
				return
			}
			this.answer = answer
			this.$emit('loaded', answer.status === 'ok' ? answer.dossier.title : '')
		},
	},
}
</script>

<style scoped>
.pq-shared-dossier__items {
	padding-inline-start: var(--utrecht-space-inline-lg, 1.5rem);
}

.pq-shared-dossier__note {
	margin-block: var(--utrecht-space-block-xs, 0.25rem) 0;
	overflow-wrap: anywhere;
}
</style>
