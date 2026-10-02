<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The newsletters sent to this guardian, each with its items shown as the
	news screen shows them. A newsletter's own title shows in the reader's
	language under the same notice (newsletter-title-translation).
-->
<template>
	<section
		v-if="shown"
		class="pq-news__archive"
		aria-labelledby="portaliq-news-archive-heading">
		<h2 id="portaliq-news-archive-heading" class="utrecht-heading-2">
			{{ t('Newsletters') }}
		</h2>
		<article
			v-for="(newsletter, i) in archive"
			:key="newsletterKey(newsletter, i)"
			class="utrecht-article pq-newsletter">
			<TranslatedText
				:id="`newsletter-${newsletterKey(newsletter, i)}`"
				as="h3"
				:text="newsletter.title || ''"
				:translation="newsletter.translation || null"
				:t="t"
				:locale="locale"
				bodyClass="utrecht-heading-3 pq-newsletter__title" />
			<p
				v-if="itemsOf(newsletter).length === 0"
				class="utrecht-paragraph pq-empty">
				<em>{{ t('This newsletter has no items for you.') }}</em>
			</p>
			<NewsItem
				v-for="(item, j) in itemsOf(newsletter)"
				:key="item.id || item['@self']?.id || j"
				:item="item"
				:t="t"
				:locale="locale"
				:level="4"
				:idPrefix="`newsletter-${newsletterKey(newsletter, i)}`" />
		</article>
	</section>
</template>

<script>
import NewsItem from './NewsItem.vue'
import TranslatedText from './TranslatedText.vue'
import { hasArchive } from '../../pages/inbox/translation.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
 */
export default {
	name: 'NewsletterArchive',

	components: { NewsItem, TranslatedText },

	props: {
		/** The archive, newest first, each newsletter carrying `items`. */
		archive: { type: Array, default: () => [] },
		/** The translator. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	computed: {
		/**
		 * @return {boolean} Whether there is a newsletter to show.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		shown() {
			return hasArchive(this.archive)
		},
	},

	methods: {
		/**
		 * @param {object} newsletter The newsletter.
		 * @param {number} i Its position.
		 * @return {string} Its id.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		newsletterKey(newsletter, i) {
			return String(newsletter.id || newsletter['@self']?.id || i)
		},

		/**
		 * @param {object} newsletter The newsletter.
		 * @return {Array<object>} Its items.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		itemsOf(newsletter) {
			return Array.isArray(newsletter.items) ? newsletter.items : []
		},
	},
}
</script>
