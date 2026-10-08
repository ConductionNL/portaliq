<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	School news for a guardian (news-and-newsletter-authoring), read only, in
	the language the guardian picks (news-item-translation). Each item and the
	newsletter archive render through TranslatedText, so a translation always
	shows its AI notice and its original.
-->
<template>
	<section class="pq-news">
		<MessageLanguagePicker
			id="portaliq-news-language"
			:label="tr('Show messages in')"
			:hint="
				tr(
					'News from school is translated by AI into your language. You can always see the original text.',
				)
			"
			:language="language"
			:error="error"
			:t="tr"
			:locale="lang"
			@change="changeLanguage" />
		<BusyStatus v-if="feed === null" :t="tr" />
		<p v-else-if="feed.length === 0" class="utrecht-paragraph pq-empty">
			<em>{{ tr('No news yet.') }}</em>
		</p>
		<template v-else>
			<NewsItem
				v-for="(item, i) in feed"
				:key="item.id || item['@self']?.id || i"
				:item="item"
				:t="tr"
				:locale="lang" />
		</template>
		<NewsletterArchive :archive="archive" :t="tr" :locale="lang" />
	</section>
</template>

<script>
import BusyStatus from '../../components/inbox/BusyStatus.vue'
import MessageLanguagePicker from '../../components/inbox/MessageLanguagePicker.vue'
import NewsItem from '../../components/inbox/NewsItem.vue'
import NewsletterArchive from '../../components/inbox/NewsletterArchive.vue'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import { pageLocale, withStrings } from './translate.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
 */
export default {
	name: 'NewsPage',

	components: { BusyStatus, MessageLanguagePicker, NewsItem, NewsletterArchive },

	props: PAGE_PROPS,

	emits: PAGE_EMITS,

	data() {
		return { feed: null, archive: [], language: '', error: '' }
	},

	computed: {
		/**
		 * @return {string} `nl` or `en`.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		lang() {
			return pageLocale(this.locale)
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		tr() {
			return withStrings(this.t, this.lang)
		},
	},

	created() {
		this.loadLanguage()
		this.loadFeed()
	},

	methods: {
		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		async loadLanguage() {
			const details = await this.api.getDetails()
			this.language = details?.messageLanguage || ''
		},

		/**
		 * Read the feed and the newsletter archive.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		async loadFeed() {
			const [items, newsletters] = await Promise.all([
				this.api.fetchNewsFeed(),
				this.api.fetchNewsletterArchive(),
			])
			this.feed = Array.isArray(items) ? items : []
			this.archive = Array.isArray(newsletters) ? newsletters : []
		},

		/**
		 * Save the picked language, then read the news again in it.
		 *
		 * @param {string} next The language tag, '' for as written.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-school-news-req-srp-033
		 */
		async changeLanguage(next) {
			this.error = ''
			const result = await this.api.setMessageLanguage(next)
			if (!result?.ok) {
				this.error = this.tr('Your language choice could not be saved.')
				return
			}
			this.language = next
			this.feed = null
			await this.loadFeed()
		},
	},
}
</script>
