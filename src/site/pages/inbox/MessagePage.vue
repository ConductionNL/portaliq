<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One message on its own page (a-resident-opens-one-message): the back link
	above and below, the title with when it came in, who sent it and the case
	it is about, the read badge in text, the case card, "Over dit bericht",
	the text, the attachments, the reply form and the action when the
	collection declares them, and "Naar uw zaak". Opening an unread message
	marks it read through the existing mark-read.
-->
<template>
	<article class="pq-message-page" data-testid="message-page">
		<p class="utrecht-paragraph pq-message-page__back">
			<a
				class="utrecht-link"
				:href="hrefOf(inboxRoute)"
				data-testid="message-back"
				@click="follow($event, inboxRoute)">{{ tr('Back to messages') }}</a>
		</p>

		<BusyStatus v-if="state === 'loading'" :t="tr" />
		<p
			v-else-if="state === 'missing'"
			class="utrecht-paragraph pq-message-page__missing"
			data-testid="message-not-found">
			{{ tr('This message does not exist or is not for you.') }}
		</p>
		<LoadError
			v-else-if="state === 'failed'"
			:text="tr('The message could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="load()" />
		<template v-else-if="message">
			<header class="pq-message-page__header">
				<h1
					ref="title"
					class="utrecht-heading-1 pq-message-page__title"
					tabindex="-1"
					data-testid="message-title">
					{{ message.subject || '' }}
				</h1>
				<p v-if="received" class="utrecht-paragraph pq-message-page__received">
					{{ tr('Received on {date} at {time}', received) }}
				</p>
				<p v-if="sender" class="utrecht-paragraph pq-message-page__from">
					<template v-if="caseNumber">
						{{ fromLead }}<a
							v-if="caseRoute"
							class="utrecht-link"
							:href="hrefOf(caseRoute)"
							@click="openCase($event)">{{ caseNumber }}</a><template v-else>{{ caseNumber }}</template>
					</template>
					<template v-else>
						{{ tr('From {sender}', { sender }) }}
					</template>
				</p>
				<strong
					v-if="message.read === true"
					class="pq-message-page__badge"
					data-testid="message-read-badge">
					{{ tr('Read') }}
				</strong>
			</header>

			<section
				v-if="caseRoute"
				class="pq-message-page__case"
				data-testid="message-case-card"
				:aria-label="caseTitle">
				<p class="utrecht-paragraph pq-message-page__case-title">
					<strong>{{ caseTitle }}</strong>
				</p>
				<p v-if="caseNumber" class="utrecht-paragraph pq-message-page__case-number">
					{{ tr('Case {number}', { number: caseNumber }) }}
				</p>
				<a
					class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--secondary-action"
					:href="hrefOf(caseRoute)"
					@click="openCase($event)">{{ tr('Go to your case') }}</a>
			</section>

			<section class="pq-message-page__about" aria-labelledby="pq-message-about">
				<h2 id="pq-message-about" class="utrecht-heading-2">
					{{ tr('About this message') }}
				</h2>
				<dl class="pq-message-page__meta" data-testid="message-about">
					<div v-for="row in about" :key="row.key">
						<dt>{{ row.label }}</dt>
						<dd>{{ row.value }}</dd>
					</div>
				</dl>
			</section>

			<section
				v-if="body"
				class="pq-message-page__text"
				aria-labelledby="pq-message-text">
				<h2 id="pq-message-text" class="utrecht-heading-2">
					{{ tr('Message') }}
				</h2>
				<TranslatedText
					:id="`message-${opened.id}`"
					:text="body"
					:translation="message.translation || null"
					:partsOf="partsOf"
					:t="tr"
					:locale="lang"
					bodyClass="utrecht-paragraph pq-message-page__body"
					@navigate="$emit('navigate', $event)" />
			</section>

			<section
				v-if="filesShown"
				class="pq-message-page__files"
				aria-labelledby="pq-message-files"
				data-testid="message-files">
				<h2 id="pq-message-files" class="utrecht-heading-2">
					{{ tr('Attachments') }}
				</h2>
				<ul class="pq-message-page__file-list">
					<li v-for="file in files" :key="file.id">
						<button
							type="button"
							class="utrecht-button utrecht-button--subtle"
							:disabled="downloadingId === file.id"
							data-testid="message-download"
							@click="download(file)">
							{{ fileLabel(file) }}
						</button>
					</li>
				</ul>
				<p v-if="downloadFailed" class="utrecht-paragraph" role="alert">
					{{ tr('The download did not work.') }}
				</p>
			</section>

			<InboxReply
				v-if="reply && api"
				:message="message"
				:reply="reply"
				:api="api"
				:locale="lang"
				idBase="pq-message-reply" />

			<div v-if="action || caseRoute" class="pq-message-page__actions">
				<a
					v-if="action"
					class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action"
					:href="actionHref"
					data-testid="message-action"
					@click="onAction($event)">{{ action.label }}</a>
				<a
					v-if="caseRoute"
					class="utrecht-button-link utrecht-button-link--html-a"
					:class="
						action
							? 'utrecht-button-link--secondary-action'
							: 'utrecht-button-link--primary-action'
					"
					:href="hrefOf(caseRoute)"
					data-testid="message-to-case"
					@click="openCase($event)">{{ tr('Go to your case') }}</a>
			</div>
		</template>

		<p v-if="state !== 'loading'" class="utrecht-paragraph pq-message-page__back">
			<a
				class="utrecht-link"
				:href="hrefOf(inboxRoute)"
				@click="follow($event, inboxRoute)">{{ tr('Back to messages') }}</a>
		</p>
	</article>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import BusyStatus from '../../components/inbox/BusyStatus.vue'
import TranslatedText from '../../components/inbox/TranslatedText.vue'
import LoadError from '../../components/mijn/LoadError.vue'
import { siteHref } from '../../components/mijn/rows.js'
import {
	actionOf,
	attachmentsOf,
	bodyParts,
	bodyWithoutOpenLink,
	downloadCollection,
	keepRecordToOpen,
	recordRoute,
	rowId,
	sessionStore,
} from './inbox.js'
import {
	aboutRows,
	caseNumberOf,
	fileDetails,
	INBOX_ROUTE,
	inboxSource,
	markOnOpen,
	receivedParts,
	senderOf,
} from './message.js'
import { pageLocale, withStrings } from './translate.js'

import '@utrecht/button-link-css/dist/index.css'

/**
 * The page's origin, or '' where there is no window.
 *
 * @return {string} The origin.
 */
function pageOrigin() {
	try {
		return typeof window !== 'undefined' ? window.location.origin : ''
	} catch {
		return ''
	}
}

/**
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
 */
export default {
	name: 'MessagePage',

	components: {
		BusyStatus,
		InboxReply: defineAsyncComponent(
			() => import('../../components/inbox/InboxReply.vue'),
		),

		LoadError,
		TranslatedText,
	},

	props: {
		/** The message the route opens: `{source, id}`. */
		opened: { type: Object, required: true },
		/** The shared portal API bound to the site's bearer. */
		api: { type: Object, required: true },
		/** The shell's translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: '' },
		/** The contributions aggregate: the inbox collections the source names. */
		contributions: { type: Object, default: null },
		/** Every navigation entry, to open the record a message is about. */
		nav: { type: Array, default: () => [] },
		/** The inbox page's route. */
		inboxRoute: { type: String, default: INBOX_ROUTE },
	},

	emits: ['navigate', 'read', 'titled'],

	data() {
		return {
			state: 'loading',
			message: null,
			downloadingId: null,
			downloadFailed: false,
			origin: pageOrigin(),
		}
	},

	computed: {
		/** @return {string} `nl` or `en`. */
		lang() {
			return pageLocale(this.locale)
		},

		/** @return {(key: string, vars?: object) => string} The translator. */
		tr() {
			return withStrings(this.t, this.lang)
		},

		/**
		 * @return {{date: string, time: string}|null} When the message came in.
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
		 */
		received() {
			return receivedParts(this.message?.receivedAt, this.lang)
		},

		/** @return {string} Who sent it. */
		sender() {
			return senderOf(this.message)
		},

		/** @return {string} The number of the case it is about. */
		caseNumber() {
			return caseNumberOf(this.message)
		},

		/**
		 * "Van {sender}, over zaak " before the linked number.
		 *
		 * @return {string} The words before the number.
		 */
		fromLead() {
			const full = this.tr('From {sender}, about case {number}', {
				sender: this.sender,
				number: '\u0000',
			})
			return full.split('\u0000')[0]
		},

		/**
		 * The record page the message is about; '' without a record link.
		 *
		 * @return {string} The route.
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
		 */
		caseRoute() {
			const link = this.message?.recordLink
			return link?.id ? recordRoute(this.nav, link) || '' : ''
		},

		/** @return {string} The case card's title. */
		caseTitle() {
			return String(this.message?.recordLink?.label || this.message?.recordLink?.title || this.caseNumber || '')
		},

		/**
		 * @return {Array<object>} The rows of "Over dit bericht".
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
		 */
		about() {
			return aboutRows(this.message, this.tr, this.lang)
		},

		/** @return {string} The text, without the address "Naar uw zaak" leads to. */
		body() {
			return bodyWithoutOpenLink(
				this.message?.body || '',
				this.message?.recordLink || null,
				this.caseRoute || null,
			)
		},

		/** @return {Array<object>} The files that came with it. */
		files() {
			return attachmentsOf(this.message)
		},

		/**
		 * "Bijlagen" only for a collection that declares `filesDownload`: only
		 * then does the server list `_files`.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
		 */
		filesShown() {
			return Array.isArray(this.message?._files) && this.files.length > 0
		},

		/**
		 * @return {object|null} The reply its collection declares.
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T08
		 */
		reply() {
			const reply = this.message?._source?.reply
			return reply && reply.action && typeof reply.action.id === 'string' ? reply : null
		},

		/**
		 * @return {{label: string, href: string}|null} Its one same-portal action.
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T08
		 */
		action() {
			return actionOf(this.message, this.origin)
		},

		/** @return {string} The address the action opens. */
		actionHref() {
			const href = this.action?.href || ''
			return href.startsWith('/') && !href.startsWith('//') ? siteHref(href) : href
		},
	},

	watch: {
		opened: {
			deep: true,
			/** Read the message the route now names. */
			handler() {
				this.load()
			},
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * A shown text as text and named links, as the list shows it.
		 *
		 * @param {string} text The shown text.
		 * @return {Array<object>} The parts.
		 */
		partsOf(text) {
			return bodyParts(text, this.origin, {
				publication: this.tr('View the publication'),
				link: this.tr('View the link'),
			})
		},

		/**
		 * Read the message; an unknown source is not found without asking the
		 * server. Then mark it read once, and move focus to its title.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T05
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T07
		 */
		async load() {
			this.message = null
			this.$emit('titled', false)
			const source = inboxSource(this.contributions, this.opened?.source)
			if (!source) {
				this.state = 'missing'
				return
			}
			this.state = 'loading'
			const result = await this.api.fetchMessage(source, this.opened.id)
			if (!result?.ok) {
				this.state = result?.status === 404 || result?.status === 403 ? 'missing' : 'failed'
				return
			}
			this.message = result.message
			this.state = 'shown'
			this.$emit('titled', true)
			this.$nextTick(() => this.$refs.title?.focus?.())
			const marked = await markOnOpen(this.api, this.message)
			if (marked.marked) {
				this.message = { ...this.message, read: true }
				this.$emit('read', rowId(this.message))
			}
		},

		/**
		 * @param {object} file The file.
		 * @return {string} Its name, type and size.
		 */
		fileLabel(file) {
			const details = fileDetails(file)
			const name = file.name || String(file.id)
			return details ? `${name}, ${details}` : name
		},

		/**
		 * Download one file through the scoped download.
		 *
		 * @param {object} file The file.
		 * @return {Promise<void>}
		 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
		 */
		async download(file) {
			const id = rowId(this.message)
			const collection = downloadCollection(this.message)
			if (!id || !collection) {
				return
			}
			this.downloadingId = file.id
			this.downloadFailed = false
			const result = await this.api.downloadFile(collection, id, file)
			this.downloadingId = null
			this.downloadFailed = !result?.ok
		},

		/**
		 * @param {string} route An in-site route.
		 * @return {string} Its real address.
		 */
		hrefOf(route) {
			return siteHref(route)
		},

		/**
		 * Open the case, as a link from the e-mail does.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 */
		openCase(event) {
			if (this.newTab(event)) {
				return
			}
			event?.preventDefault?.()
			keepRecordToOpen(sessionStore(), this.message.recordLink)
			this.$emit('navigate', this.caseRoute)
		},

		/**
		 * A plain click on the action stays in the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 */
		onAction(event) {
			if (this.action?.href.startsWith('/')) {
				this.follow(event, this.action.href)
			}
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {string} route The in-site route.
		 * @return {void}
		 */
		follow(event, route) {
			if (this.newTab(event)) {
				return
			}
			event?.preventDefault?.()
			this.$emit('navigate', route)
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @return {boolean} Whether it opens a new tab or window.
		 */
		newTab(event) {
			return Boolean(
				event?.ctrlKey || event?.metaKey || event?.shiftKey || (event?.button ?? 0) !== 0,
			)
		},
	},
}
</script>

<style scoped>
.pq-message-page {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-md, 1rem);
}

.pq-message-page__title:focus {
	outline: 2px solid var(--utrecht-focus-outline-color, currentColor);
	outline-offset: 2px;
}

.pq-message-page__badge {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border: 1px solid var(--utrecht-color-grey-60, currentColor);
	border-radius: var(--utrecht-border-radius-md, 0.25rem);
}

.pq-message-page__case {
	padding: var(--utrecht-space-block-md, 1rem);
	border: 1px solid var(--utrecht-color-grey-80, currentColor);
	border-radius: var(--utrecht-border-radius-md, 0.25rem);
}

.pq-message-page__meta div {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.pq-message-page__meta dt {
	font-weight: 700;
	min-inline-size: 10rem;
}

.pq-message-page__meta dd {
	margin: 0;
}

.pq-message-page__file-list {
	list-style: none;
	padding: 0;
}

.pq-message-page__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}
</style>
