<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One school news item: its title, then its body, as written or translated
	(news-item-translation, news-title-and-newsletter-translation). A labelled
	translation with a title shows the title in the reader's language; one
	notice covers both and the original shows both.
-->
<template>
	<article class="utrecht-article pq-news__item">
		<component
			:is="level === 4 ? 'h4' : 'h3'"
			:class="
				level === 4
					? 'utrecht-heading-4 pq-news__title'
					: 'utrecht-heading-3 pq-news__title'
			"
			:lang="titled ? item.translation.targetLanguage : undefined">
			{{ titled ? item.translation.title : item.title }}
		</component>
		<p
			v-if="publishedOn"
			class="utrecht-paragraph pq-news__date"
			data-testid="news-published-on">
			{{ t('Published on {date}', { date: publishedOn }) }}
		</p>
		<TranslatedText
			:id="`${idPrefix}-${itemKey}`"
			:text="item.body || ''"
			:translation="item.translation || null"
			:t="t"
			:locale="locale"
			bodyClass="utrecht-paragraph pq-news__body"
			:originalTitle="titled ? item.title : ''" />
	</article>
</template>

<script>
import TranslatedText from './TranslatedText.vue'
import { formatDate } from '../../pages/inbox/inbox.js'
import { hasTranslatedTitle } from '../../pages/inbox/translation.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
 */
export default {
	name: 'NewsItem',

	components: { TranslatedText },

	props: {
		/** The feed row, with `translation` when translated. */
		item: { type: Object, required: true },
		/** The translator. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** The title's heading level, 3 or 4. */
		level: { type: Number, default: 3 },
		/** Prefix of the ids, unique per place the item shows. */
		idPrefix: { type: String, default: 'news' },
	},

	computed: {
		/**
		 * @return {string} The day the item was published, '' without one.
		 * @spec openspec/changes/news-publish-date/specs/portaliq-cms/spec.md#requirement-a-news-item-carries-the-moment-it-was-published
		 */
		publishedOn() {
			return formatDate(this.item.publishedAt || '', this.locale)
		},

		/**
		 * @return {boolean} Whether the translation carries the title.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		titled() {
			return hasTranslatedTitle(this.item.translation)
		},

		/**
		 * @return {string} The item's id, title or a placeholder.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		itemKey() {
			return String(
				this.item.id || this.item['@self']?.id || this.item.title || 'item',
			)
		},
	},
}
</script>
