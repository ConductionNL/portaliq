<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The frequently asked questions of this portal (public-faq-and-product-finder).

	An entry is written once; this block shows the ones that belong to the
	page it stands on, to a topic, or all of them grouped by topic. Which
	entries exist is the server's decision (only published ones are served);
	the block chooses only which of them to show. Each question is a real
	`button` inside a heading, as in the accordion.
-->
<template>
	<section class="nl-faq-list" data-testid="nl-faq-list">
		<div v-if="heading || more" class="nl-faq-list__head">
			<h2 v-if="heading" class="utrecht-heading-2">{{ heading }}</h2>
			<a
				v-if="more"
				class="utrecht-link nl-faq-list__more"
				:href="more.href"
				@click="open($event, more)">
				{{ moreLabel }}
			</a>
		</div>

		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ say('loading') }}
		</p>
		<p v-else-if="state === 'failed'" class="utrecht-paragraph" role="status">
			{{ say('failed') }}
		</p>
		<p
			v-else-if="entries.length === 0"
			class="utrecht-paragraph"
			data-testid="nl-faq-empty">
			{{ say('empty') }}
		</p>

		<template v-else>
			<div
				v-for="group in groups"
				:key="group.topic"
				class="nl-faq-list__group"
				data-testid="nl-faq-group">
				<h3 v-if="all" class="utrecht-heading-3">{{ group.topic || say('other') }}</h3>
				<div class="utrecht-accordion">
					<section
						v-for="entry in group.entries"
						:key="entry.key"
						class="utrecht-accordion__section">
						<component :is="entryTag" class="utrecht-accordion__header">
							<button
								type="button"
								class="utrecht-accordion__button"
								:aria-expanded="isOpen(entry.key) ? 'true' : 'false'"
								data-testid="nl-faq-toggle"
								@click="toggle(entry.key)">
								{{ entry.question }}
							</button>
						</component>
						<!-- eslint-disable vue/no-v-html -->
						<!-- The answer is markdown, sanitised by cnRenderMarkdown, the helper pages use. -->
						<div
							v-show="isOpen(entry.key)"
							class="utrecht-accordion__panel"
							data-testid="nl-faq-panel"
							v-html="entry.html" />
						<!-- eslint-enable vue/no-v-html -->
					</section>
				</div>
			</div>
		</template>
	</section>
</template>

<script>
import { cnRenderMarkdown } from '@conduction/nextcloud-vue'
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { fetchFaq } from '../../lib/publicFaq.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import strings from './strings.js'

import '@utrecht/accordion-css/dist/index.css'
import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
 */
export default {
	name: 'NlFaqList',

	props: {
		/** The heading. */
		heading: { type: String, default: '' },
		/** Only the entries of this topic. */
		topic: { type: String, default: '' },
		/** All entries, grouped by topic, instead of the ones of this page. */
		all: { type: Boolean, default: false },
		/** The link to all questions. */
		moreLabel: { type: String, default: '' },
		/** Its address. */
		moreHref: { type: String, default: '/veelgestelde-vragen' },
		/** The serving portal, from the host. */
		portal: { type: String, default: '' },
		/** The route on screen, from the host; picks the entries of this page. */
		currentRoute: { type: String, default: '' },
	},

	emits: ['navigate'],

	data() {
		return { entries: [], state: 'loading', openKeys: [] }
	},

	computed: {
		/**
		 * @return {string} The element the questions use: h3, or h4 under a topic heading.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
		 */
		entryTag() {
			return this.all ? 'h4' : 'h3'
		},

		/**
		 * @return {Array<{topic: string, entries: Array<object>}>} The entries, grouped by topic in the order they arrive.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
		 */
		groups() {
			const groups = []
			this.entries.forEach((entry, index) => {
				const topic = this.all ? entry.topic || '' : ''
				let group = groups.find((candidate) => candidate.topic === topic)
				if (!group) {
					group = { topic, entries: [] }
					groups.push(group)
				}
				group.entries.push({
					key: `${index}-${entry.question}`,
					question: entry.question,
					html: cnRenderMarkdown(entry.answer || ''),
				})
			})
			return groups
		},

		/**
		 * @return {object|null} The link to all questions.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
		 */
		more() {
			return this.moreLabel.trim() ? authoredLink(this.moreHref) : null
		},
	},

	/**
	 * Read the entries once the widget is on the page.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
	 */
	async mounted() {
		try {
			const filter = this.topic.trim()
				? { topic: this.topic.trim() }
				: this.all
					? {}
					: { page: this.currentRoute }
			this.entries = await fetchFaq(this.portal, filter)
			this.state = 'ready'
		} catch {
			this.state = 'failed'
		}
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},

		/**
		 * @param {string} key The entry's key.
		 * @return {boolean} Whether its answer is open.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
		 */
		isOpen(key) {
			return this.openKeys.includes(key)
		},

		/**
		 * Open or close one answer.
		 *
		 * @param {string} key The entry's key.
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
		 */
		toggle(key) {
			this.openKeys = this.isOpen(key)
				? this.openKeys.filter((entry) => entry !== key)
				: [...this.openKeys, key]
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03
		 */
		open(event, link) {
			if (staysInSite(event, link)) {
				event.preventDefault()
				this.$emit('navigate', link.route)
			}
		},
	},
}
</script>

<style scoped>
.nl-faq-list {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.nl-faq-list__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.75rem;
}

.nl-faq-list__head h2 {
	margin: 0;
}

.nl-faq-list__more {
	font-weight: 600;
}

.nl-faq-list__group {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}
</style>
