<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SitePagesPanel: the portal's pages as a tree, managed from the portal.

  Part of the editor chunk (portal-in-place-editing A3). An editor creates a
  page under another, renames one, moves one to another parent or position,
  and deletes a page that was never published. The route is never changed by
  any of these: it is the page's address.

  @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
-->
<template>
	<section
		class="pq-site-panel"
		data-testid="site-pages-panel"
		:aria-label="t('portaliq', 'Pages')">
		<h2 class="pq-site-panel__title">
			{{ t('portaliq', 'Pages of this portal') }}
		</h2>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>

		<p v-if="loading">
			{{ t('portaliq', 'Loading the pages…') }}
		</p>

		<ul v-else class="pq-site-panel__list">
			<li
				v-for="node in rows"
				:key="node.page.id"
				class="pq-site-panel__row"
				:style="{ paddingInlineStart: `${node.depth * 24}px` }"
				:data-testid="`site-pages-row-${node.page.id}`">
				<template v-if="renaming === node.page.id">
					<label
						:for="`rename-${node.page.id}`"
						class="pq-site-panel__hidden"
						>{{ t('portaliq', 'New title') }}</label
					>
					<input
						:id="`rename-${node.page.id}`"
						v-model="draftTitle"
						type="text" />
					<NcButton
						data-testid="site-pages-rename-save"
						:disabled="busy"
						@click="rename(node.page)">
						{{ t('portaliq', 'Save') }}
					</NcButton>
					<NcButton @click="renaming = ''">
						{{ t('portaliq', 'Cancel') }}
					</NcButton>
				</template>
				<template v-else-if="moving === node.page.id">
					<label :for="`move-parent-${node.page.id}`">{{
						t('portaliq', 'Under')
					}}</label>
					<select :id="`move-parent-${node.page.id}`" v-model="moveParent">
						<option value="">
							{{ t('portaliq', 'The top of the portal') }}
						</option>
						<option
							v-for="option in rows"
							:key="option.page.id"
							:value="option.page.id"
							:disabled="option.page.id === node.page.id">
							{{ option.page.title }}
						</option>
					</select>
					<label :for="`move-order-${node.page.id}`">{{
						t('portaliq', 'Position')
					}}</label>
					<input
						:id="`move-order-${node.page.id}`"
						v-model.number="moveOrder"
						type="number"
						min="0"
						class="pq-site-panel__number" />
					<NcButton
						data-testid="site-pages-move-save"
						:disabled="busy"
						@click="move(node.page)">
						{{ t('portaliq', 'Move') }}
					</NcButton>
					<NcButton @click="moving = ''">
						{{ t('portaliq', 'Cancel') }}
					</NcButton>
				</template>
				<template v-else>
					<span class="pq-site-panel__name">
						{{ node.page.title }}
						<code>{{ node.page.route }}</code>
						<em v-if="node.page.status === 'draft'">{{
							t('portaliq', 'draft')
						}}</em>
						<strong v-if="node.page.id === currentPageId">{{
							t('portaliq', 'this page')
						}}</strong>
					</span>
					<span class="pq-site-panel__actions">
						<NcButton
							variant="tertiary"
							data-testid="site-pages-rename"
							@click="startRename(node.page)">
							{{ t('portaliq', 'Rename') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							data-testid="site-pages-move"
							@click="startMove(node.page)">
							{{ t('portaliq', 'Move') }}
						</NcButton>
						<NcButton
							v-if="
								canDeletePage(node.page)
								&& node.page.id !== currentPageId
							"
							variant="tertiary"
							data-testid="site-pages-delete"
							:disabled="busy"
							@click="remove(node.page)">
							{{ t('portaliq', 'Delete') }}
						</NcButton>
					</span>
				</template>
			</li>
		</ul>

		<form
			class="pq-site-panel__form"
			data-testid="site-pages-new"
			@submit.prevent="create">
			<h3>{{ t('portaliq', 'New page') }}</h3>
			<label for="pq-new-title">{{ t('portaliq', 'Title') }}</label>
			<input id="pq-new-title" v-model="newTitle" type="text" required />
			<label for="pq-new-route">{{ t('portaliq', 'Route') }}</label>
			<input
				id="pq-new-route"
				v-model="newRoute"
				type="text"
				required
				placeholder="/over-ons/contact" />
			<label for="pq-new-parent">{{ t('portaliq', 'Under') }}</label>
			<select id="pq-new-parent" v-model="newParent">
				<option value="">
					{{ t('portaliq', 'The top of the portal') }}
				</option>
				<option
					v-for="option in rows"
					:key="option.page.id"
					:value="option.page.id">
					{{ option.page.title }}
				</option>
			</select>
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ t('portaliq', 'Create the page as a draft') }}
			</NcButton>
		</form>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	buildPageTree,
	canDeletePage,
	createPortalObjects,
	flattenPageTree,
	isConflict,
	movePagePayload,
	newPagePayload,
	renamePagePayload,
} from './index.js'

export default {
	name: 'SitePagesPanel',

	components: { NcButton, NcNoteCard },

	props: {
		/** The portal slug. */
		portal: {
			type: String,
			required: true,
		},

		/** The page being edited, which is never deleted from here. */
		currentPageId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			api: createPortalObjects({
				get: (url, config) => axios.get(url, config),
				post: (url, payload) => axios.post(url, payload),
				put: (url, payload, config) => axios.put(url, payload, config),
				del: (url) => axios.delete(url),
				url: (schema, id) =>
					generateUrl(
						`/apps/openregister/api/objects/portaliq/${schema}${id ? '/' + encodeURIComponent(id) : ''}`,
					),
			}),

			pages: [],
			loading: true,
			busy: false,
			error: '',
			notice: '',
			renaming: '',
			draftTitle: '',
			moving: '',
			moveParent: '',
			moveOrder: 0,
			newTitle: '',
			newRoute: '',
			newParent: '',
		}
	},

	computed: {
		/**
		 * The pages in tree order, with their depth.
		 *
		 * @return {Array<object>} The rows.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		rows() {
			return flattenPageTree(buildPageTree(this.pages))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		canDeletePage,

		/**
		 * Translate.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The source text.
		 * @param {object} vars The placeholders.
		 * @return {string} The translation.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		t(app, text, vars) {
			return translate(app, text, vars)
		},

		/**
		 * Read the portal's pages.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		async load() {
			this.loading = true
			try {
				this.pages = await this.api.list('page', this.portal)
			} catch (error) {
				this.error = this.messageFor(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run one write, report it, and read the pages again.
		 *
		 * @param {Function} write () => Promise.
		 * @param {string} notice The message on success.
		 * @return {Promise<void>} Resolves when done.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		async run(write, notice) {
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				await write()
				this.notice = notice
				this.renaming = ''
				this.moving = ''
				await this.load()
			} catch (error) {
				this.error = this.messageFor(error)
				if (isConflict(error)) {
					await this.load()
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * A message for a failed write. A plain Error carries the editor's own
		 * mistake (an empty title, a taken route); a response is OpenRegister's.
		 *
		 * @param {object} error The error.
		 * @return {string} The message.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		messageFor(error) {
			if (isConflict(error)) {
				return this.t(
					'portaliq',
					'Someone else changed this page in the meantime, so nothing was saved. The list now shows their version: try again.',
				)
			}
			const status = error?.response?.status
			if (status === 401 || status === 403) {
				return this.t(
					'portaliq',
					'You are not allowed to change the pages of this portal.',
				)
			}
			if (!error?.response && error?.message) {
				return this.t('portaliq', error.message)
			}
			return this.t('portaliq', 'That did not work. Nothing was changed.')
		},

		/**
		 * @param {object} page The page.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		startRename(page) {
			this.moving = ''
			this.renaming = page.id
			this.draftTitle = page.title
		},

		/**
		 * @param {object} page The page.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		startMove(page) {
			this.renaming = ''
			this.moving = page.id
			this.moveParent = page.parent || ''
			this.moveOrder = Number.isInteger(page.order) ? page.order : 0
		},

		/**
		 * @param {object} page The page.
		 * @return {Promise<void>} Resolves when renamed.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		rename(page) {
			return this.run(
				() =>
					this.api.save(
						'page',
						page.id,
						renamePagePayload(page, this.draftTitle),
						page.version,
					),
				this.t('portaliq', 'The page is renamed. Its address is unchanged.'),
			)
		},

		/**
		 * @param {object} page The page.
		 * @return {Promise<void>} Resolves when moved.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		move(page) {
			return this.run(
				() =>
					this.api.save(
						'page',
						page.id,
						movePagePayload(
							page,
							{ parent: this.moveParent, order: this.moveOrder },
							this.pages,
						),
						page.version,
					),
				this.t('portaliq', 'The page is moved. Its address is unchanged.'),
			)
		},

		/**
		 * Delete a draft page, after the editor confirms.
		 *
		 * @param {object} page The page.
		 * @return {Promise<void>} Resolves when deleted.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		async remove(page) {
			if (!canDeletePage(page)) {
				return
			}
			if (
				!window.confirm(
					this.t('portaliq', 'Delete the draft page "{title}"?', {
						title: page.title,
					}),
				)
			) {
				return
			}
			await this.run(
				() => this.api.remove('page', page.id),
				this.t('portaliq', 'The draft page is deleted.'),
			)
		},

		/**
		 * Create a draft page.
		 *
		 * @return {Promise<void>} Resolves when created.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		async create() {
			await this.run(
				async () => {
					const payload = newPagePayload(
						{
							title: this.newTitle,
							route: this.newRoute,
							portal: this.portal,
							parent: this.newParent,
						},
						this.pages,
					)
					await this.api.create('page', payload)
					this.newTitle = ''
					this.newRoute = ''
				},
				this.t(
					'portaliq',
					'The page is created as a draft. Visitors see it once it is published.',
				),
			)
		},
	},
}
</script>

<style scoped>
.pq-site-panel {
	margin: 0 0 16px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.pq-site-panel__title {
	margin-top: 0;
	font-size: 1.2em;
}

.pq-site-panel__list {
	list-style: none;
	margin: 0 0 16px;
	padding: 0;
}

.pq-site-panel__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-block: 4px;
	border-bottom: 1px solid var(--color-border);
}

.pq-site-panel__name {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
}

.pq-site-panel__actions {
	display: flex;
	gap: 4px;
}

.pq-site-panel__number {
	width: 5em;
}

.pq-site-panel__form {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	gap: 8px 12px;
	align-items: center;
	max-width: 640px;
}

.pq-site-panel__form h3,
.pq-site-panel__form button {
	grid-column: 1 / -1;
	margin: 0;
}

.pq-site-panel__hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
}
</style>
