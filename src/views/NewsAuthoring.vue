<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  NewsAuthoring: the staff News screen (staff-news-screen T4).

  Staff see every news item, write a new one, change one and publish it or
  take it back. A custom page, because the index page's object form writes
  through the object API: news must go through the staff authoring routes,
  which check the audience and keep the read receipts and translations the
  server owns, and its audience is one choice (the whole school or groups)
  rather than a nested target object. The calls live in
  src/lib/newsAuthoring.js; this view is the screen.

  @spec openspec/changes/staff-news-screen/tasks.md#T4
  @visual exclude a plain toolbar, note card and semantic table built from Nextcloud components; the calls, form rules and wiring are pinned by tests/news-authoring.spec.mjs
-->
<template>
	<div class="news-authoring">
		<header class="news-authoring__bar">
			<h2 class="news-authoring__title">
				{{ t('portaliq', 'News') }}
			</h2>
			<NcButton
				variant="primary"
				data-testid="news-new"
				:disabled="loading"
				@click="open(null)">
				{{ t('portaliq', 'New news item') }}
			</NcButton>
		</header>
		<p class="news-authoring__intro">
			{{ t('portaliq', 'Parents see a news item in the portal once it is published, if it is for their child\'s school or group.') }}
		</p>

		<NcNoteCard v-if="failure" type="error" data-testid="news-failure">
			{{ t('portaliq', failure) }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent
			v-else-if="items.length === 0"
			:name="t('portaliq', 'No news yet')"
			:description="t('portaliq', 'Write a news item for the whole school or for a group.')" />
		<table v-else class="news-authoring__table" data-testid="news-list">
			<thead>
				<tr>
					<th scope="col">
						{{ t('portaliq', 'Title') }}
					</th>
					<th scope="col">
						{{ t('portaliq', 'For') }}
					</th>
					<th scope="col">
						{{ t('portaliq', 'Status') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('portaliq', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="item in items" :key="idOf(item)" data-testid="news-row">
					<td>{{ item.title }}</td>
					<td>{{ audienceText(item) }}</td>
					<td>
						<span :class="['news-authoring__status', 'news-authoring__status--' + (item.status === 'published' ? 'published' : 'draft')]">
							{{ item.status === 'published' ? t('portaliq', 'Published') : t('portaliq', 'Draft') }}
						</span>
					</td>
					<td class="news-authoring__actions">
						<NcButton
							variant="tertiary"
							:aria-label="t('portaliq', 'Change {title}', { title: item.title })"
							data-testid="news-change"
							@click="open(item)">
							{{ t('portaliq', 'Change') }}
						</NcButton>
						<NcButton
							:variant="item.status === 'published' ? 'secondary' : 'primary'"
							:disabled="busy === idOf(item)"
							:aria-label="item.status === 'published'
								? t('portaliq', 'Take back {title}', { title: item.title })
								: t('portaliq', 'Publish {title}', { title: item.title })"
							data-testid="news-publish"
							@click="togglePublished(item)">
							{{ item.status === 'published' ? t('portaliq', 'Take back') : t('portaliq', 'Publish') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NewsItemDialog
			v-if="editing"
			:item="editing.item"
			:options="options"
			@close="save" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import NewsItemDialog from '../dialogs/NewsItemDialog.vue'
import {
	AUDIENCE_CHILDREN,
	AUDIENCE_GROUPS,
	audienceOf,
	createNewsApi,
	failureKey,
	idOf,
} from '../lib/newsAuthoring.js'

export default {
	name: 'NewsAuthoring',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NewsItemDialog,
	},

	data() {
		return {
			api: createNewsApi({
				get: (url) => axios.get(url),
				post: (url, body) => axios.post(url, body),
				put: (url, body) => axios.put(url, body),
				generateUrl,
			}),
			items: [],
			options: { schools: [], groups: [] },
			loading: true,
			failure: '',
			busy: '',
			editing: null,
		}
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,
		idOf,

		/**
		 * Load the news items and the audience choices.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		async load() {
			this.loading = true
			try {
				const [items, options] = await Promise.all([this.api.list(), this.api.audiences()])
				this.items = items
				this.options = options
				this.failure = ''
			} catch (error) {
				this.failure = error?.response?.status === 403
					? failureKey(error)
					: 'The news could not be loaded. Try again.'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Who an item is for, as one line.
		 *
		 * @param {object} item The news item.
		 * @return {string}
		 *
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		audienceText(item) {
			const { kind, names } = audienceOf(item, this.options)
			if (kind === AUDIENCE_CHILDREN) {
				return t('portaliq', 'Specific children')
			}
			if (kind === AUDIENCE_GROUPS) {
				return t('portaliq', 'Groups: {names}', { names: names.join(', ') })
			}
			return names.length > 0
				? t('portaliq', 'Whole school: {name}', { name: names[0] })
				: t('portaliq', 'Whole school')
		},

		/**
		 * Open the dialog for a new item (null) or an existing one.
		 *
		 * @param {object|null} item The news item.
		 * @return {void}
		 *
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		open(item) {
			this.editing = { item }
		},

		/**
		 * Save what the dialog closed with, then reload the list.
		 *
		 * @param {object|null} form The form, or null when cancelled.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		async save(form) {
			const item = this.editing?.item || null
			this.editing = null
			if (!form) {
				return
			}
			try {
				await this.api.save(form, item ? idOf(item) : '', getCurrentUser()?.uid || '')
				showSuccess(item
					? t('portaliq', 'The news item is changed.')
					: t('portaliq', 'The news item is saved as a draft. Publish it when it is ready.'))
				await this.load()
			} catch (error) {
				this.failure = failureKey(error)
			}
		},

		/**
		 * Publish an item, or take a published one back to a draft.
		 *
		 * @param {object} item The news item.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		async togglePublished(item) {
			const publish = item.status !== 'published'
			this.busy = idOf(item)
			try {
				await this.api.setPublished(idOf(item), publish)
				showSuccess(publish
					? t('portaliq', 'The news item is published.')
					: t('portaliq', 'The news item is back to a draft. Parents no longer see it.'))
				await this.load()
			} catch (error) {
				this.failure = failureKey(error)
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.news-authoring {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 1100px;
}

.news-authoring__bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.news-authoring__title {
	margin: 0;
}

.news-authoring__intro {
	color: var(--color-text-maxcontrast);
	margin: calc(var(--default-grid-baseline) * 2) 0 calc(var(--default-grid-baseline) * 4);
}

.news-authoring__table {
	width: 100%;
	border-collapse: collapse;
}

.news-authoring__table th,
.news-authoring__table td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.news-authoring__actions {
	display: flex;
	gap: var(--default-grid-baseline);
	justify-content: flex-end;
}

.news-authoring__status {
	border: 1px solid currentcolor;
	border-radius: var(--border-radius-pill);
	padding: 2px calc(var(--default-grid-baseline) * 2);
}

.news-authoring__status--published {
	color: var(--color-success-text);
}

.news-authoring__status--draft {
	color: var(--color-warning-text);
}
</style>
