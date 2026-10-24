<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The landing page of one subject (home-and-theme-landing-pages REQ-HTL-002):
	its image with the alternative text, its title and description, and the
	search block locked to the subject so only its own publications are listed.
	The subject comes from the route (`/onderwerp/<slug>`). An unknown or
	non-public subject reads as not found.
-->
<template>
	<article class="nl-subject-landing" data-testid="nl-subject-landing">
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ say('loading') }}
		</p>
		<div
			v-else-if="state === 'notFound'"
			role="alert"
			data-testid="nl-subject-not-found">
			<h1 class="utrecht-heading-1">
				{{ say('notFoundTitle') }}
			</h1>
			<p class="utrecht-paragraph">
				{{ say('notFound') }}
			</p>
			<a v-if="backLabel" class="utrecht-link" :href="backHref">{{
				backLabel
			}}</a>
		</div>
		<p v-else-if="state === 'failed'" class="utrecht-paragraph" role="alert">
			{{ say('failed') }}
		</p>
		<template v-else>
			<a v-if="backLabel" class="utrecht-link" :href="backHref">{{
				backLabel
			}}</a>
			<img
				v-if="subject.image"
				class="nl-subject-landing__image"
				:src="subject.image.url"
				:alt="subject.image.alt"
				data-testid="nl-subject-image" />
			<h1 class="utrecht-heading-1" data-testid="nl-subject-title">
				{{ subject.title }}
			</h1>
			<p
				v-if="subject.description || subject.summary"
				class="utrecht-paragraph"
				data-testid="nl-subject-description">
				{{ subject.description || subject.summary }}
			</p>
			<h2 class="utrecht-heading-2">
				{{ say('publications') }}
			</h2>
			<FederatedSearchBlock
				:lockedFilters="{ themes: subject.id }"
				:lockedLabels="{ themes: subject.title }" />
		</template>
	</article>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { fetchSubject } from '../../lib/subjects.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import strings from './strings.js'

import '@utrecht/heading-1-css/dist/index.css'
import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export default {
	name: 'NlSubjectLanding',

	components: {
		FederatedSearchBlock: defineAsyncComponent(
			() => import('../../components/FederatedSearchBlock.vue'),
		),
	},

	props: {
		/** The words of the link back. */
		backLabel: { type: String, default: '' },
		/** The address of the link back. */
		backHref: { type: String, default: '/' },
		/** The subject's slug, from the route, from the host. */
		routeParam: { type: String, default: '' },
	},

	data() {
		return { subject: null, state: 'loading' }
	},

	/**
	 * Read the subject once the page is shown.
	 *
	 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
	 */
	async mounted() {
		const answer = await fetchSubject(this.slug())
		this.subject = answer.subject
		this.state = answer.state
	},

	methods: {
		/**
		 * The slug: the one the host handed over, else the last segment of the address.
		 *
		 * @return {string} The slug.
		 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
		 */
		slug() {
			if (this.routeParam !== '') {
				return this.routeParam
			}
			const parts = String(globalThis.window?.location?.pathname || '')
				.split('/')
				.filter(Boolean)
			return decodeURIComponent(parts[parts.length - 1] || '')
		},

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
.nl-subject-landing > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.nl-subject-landing__image {
	display: block;
	inline-size: 100%;
	max-block-size: 20rem;
	object-fit: cover;
}
</style>
