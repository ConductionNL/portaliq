<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The subjects an administrator features in the publication catalogue
	(home-and-theme-landing-pages REQ-HTL-003): image, title, summary and how
	many publications each holds, linking to its own page. Read as an
	anonymous visitor. Without the catalogue it says so in words.
-->
<template>
	<section class="nl-featured-subjects" data-testid="nl-featured-subjects">
		<h2 v-if="heading" class="utrecht-heading-2">
			{{ heading }}
		</h2>
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ say('loading') }}
		</p>
		<p
			v-else-if="state !== 'ok' || subjects.length === 0"
			class="utrecht-paragraph"
			data-testid="nl-featured-subjects-empty">
			{{ say(state === 'ok' ? 'empty' : state) }}
		</p>
		<ul v-else class="nl-featured-subjects__list">
			<li
				v-for="subject in subjects"
				:key="subject.id || subject.slug"
				class="nl-featured-subjects__item"
				data-testid="nl-featured-subject">
				<a class="utrecht-link nl-featured-subjects__link" :href="hrefOf(subject)">
					<img
						v-if="subject.image"
						class="nl-featured-subjects__image"
						:src="subject.image.url"
						:alt="subject.image.alt"
						loading="lazy" />
					<span class="utrecht-heading-3 nl-featured-subjects__title">{{ subject.title }}</span>
					<span v-if="subject.summary" class="utrecht-paragraph">{{ subject.summary }}</span>
					<span v-if="subject.publicationCount !== null" class="nl-featured-subjects__count">{{ countText(subject) }}</span>
				</a>
			</li>
		</ul>
	</section>
</template>

<script>
import { fetchFeatured, subjectHref } from '../../lib/subjects.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import strings from './strings.js'

import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
 */
export default {
	name: 'NlFeaturedSubjects',

	props: {
		/** The heading. */
		heading: { type: String, default: '' },
		/** The most subjects to show. */
		count: { type: Number, default: 6 },
		/** The page a subject opens under; the slug is appended. */
		subjectRoute: { type: String, default: '/onderwerp' },
	},

	data() {
		return { subjects: [], state: 'loading' }
	},

	/**
	 * Read the featured subjects once the widget is on the page.
	 *
	 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
	 */
	async mounted() {
		const answer = await fetchFeatured({ count: this.count > 0 ? this.count : 6 })
		this.subjects = answer.subjects
		this.state = answer.state
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},

		/**
		 * @param {{slug: string}} subject The subject.
		 * @return {string} Its page.
		 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
		 */
		hrefOf(subject) {
			return subjectHref(subject.slug, this.subjectRoute || '/onderwerp')
		},

		/**
		 * @param {{publicationCount: number}} subject The subject.
		 * @return {string} How many publications it holds.
		 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
		 */
		countText(subject) {
			return subject.publicationCount === 1
				? this.say('one')
				: this.say('publications').replace('{count}', String(subject.publicationCount))
		},
	},
}
</script>

<style scoped>
.nl-featured-subjects__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
	gap: var(--utrecht-space-block-md, 1rem);
	list-style: none;
	margin: 0;
	padding: 0;
}

.nl-featured-subjects__link {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.25rem);
	block-size: 100%;
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-border-width-sm, 1px) solid var(--utrecht-color-grey-80, currentcolor);
	text-decoration: none;
}

.nl-featured-subjects__image {
	inline-size: 100%;
	aspect-ratio: 16 / 9;
	object-fit: cover;
}

.nl-featured-subjects__count {
	font-size: var(--utrecht-document-font-size-sm, 0.875rem);
}
</style>
