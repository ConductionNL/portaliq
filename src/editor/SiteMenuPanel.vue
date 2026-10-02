<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SiteMenuPanel: the portal's menu, edited from the portal.

  Part of the editor chunk (portal-in-place-editing A3). The top-level items of
  one menu object: add, rename, reorder, remove, then save the whole menu with
  the version check. Sub-menus travel with their item. Who may save is decided
  by OpenRegister, through the editor groups on the `menu` schema.

  @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
-->
<template>
	<section
		class="pq-site-panel"
		data-testid="site-menu-panel"
		:aria-label="t('portaliq', 'Menu')">
		<h2 class="pq-site-panel__title">
			{{ t('portaliq', 'Menu of this portal') }}
		</h2>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>

		<p v-if="loading">
			{{ t('portaliq', 'Loading the menu…') }}
		</p>
		<p v-else-if="!menus.length">
			{{
				t(
					'portaliq',
					'This portal has no menu yet. An administrator creates one under Menus in Portaliq.',
				)
			}}
		</p>

		<template v-else>
			<p v-if="menus.length > 1">
				<label for="pq-menu-choice">{{ t('portaliq', 'Menu') }}</label>
				<select id="pq-menu-choice" v-model="menuId" @change="pick">
					<option v-for="menu in menus" :key="menu.id" :value="menu.id">
						{{ menu.title }}
					</option>
				</select>
			</p>

			<ol class="pq-site-panel__list">
				<li
					v-for="(item, index) in items"
					:key="`${index}-${item.name}`"
					class="pq-site-panel__row">
					<label
						:for="`pq-menu-name-${index}`"
						class="pq-site-panel__hidden"
						>{{ t('portaliq', 'Name') }}</label
					>
					<input
						:id="`pq-menu-name-${index}`"
						:value="item.name"
						type="text"
						data-testid="site-menu-rename"
						@change="rename(index, $event.target.value)" />
					<code>{{ item.link }}</code>
					<span class="pq-site-panel__actions">
						<NcButton
							variant="tertiary"
							data-testid="site-menu-up"
							:disabled="index === 0"
							:aria-label="
								t('portaliq', 'Move {name} up', { name: item.name })
							"
							@click="move(index, -1)">
							↑
						</NcButton>
						<NcButton
							variant="tertiary"
							data-testid="site-menu-down"
							:disabled="index === items.length - 1"
							:aria-label="
								t('portaliq', 'Move {name} down', {
									name: item.name,
								})
							"
							@click="move(index, 1)">
							↓
						</NcButton>
						<NcButton
							variant="tertiary"
							data-testid="site-menu-remove"
							@click="remove(index)">
							{{ t('portaliq', 'Remove') }}
						</NcButton>
					</span>
				</li>
			</ol>

			<form
				class="pq-site-panel__form"
				data-testid="site-menu-add"
				@submit.prevent="add">
				<h3>{{ t('portaliq', 'Add a menu item') }}</h3>
				<label for="pq-menu-new-name">{{ t('portaliq', 'Name') }}</label>
				<input
					id="pq-menu-new-name"
					v-model="newName"
					type="text"
					required />
				<label for="pq-menu-new-link">{{ t('portaliq', 'Link') }}</label>
				<input
					id="pq-menu-new-link"
					v-model="newLink"
					type="text"
					placeholder="/over-ons/contact" />
				<NcButton type="submit">
					{{ t('portaliq', 'Add') }}
				</NcButton>
			</form>

			<NcButton
				variant="primary"
				data-testid="site-menu-save"
				:disabled="busy || !dirty"
				@click="save">
				{{ t('portaliq', 'Save the menu') }}
			</NcButton>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	addMenuItem,
	createPortalObjects,
	isConflict,
	menuPayload,
	moveMenuItem,
	removeMenuItem,
	renameMenuItem,
	sortedMenuItems,
} from './index.js'
import { instanceUrl } from './instanceUrl.js'

export default {
	name: 'SiteMenuPanel',

	components: { NcButton, NcNoteCard },

	props: {
		/** The portal slug. */
		portal: {
			type: String,
			required: true,
		},
	},

	emits: ['saved'],

	data() {
		return {
			api: createPortalObjects({
				get: (url, config) => axios.get(url, config),
				post: (url, payload) => axios.post(url, payload),
				put: (url, payload, config) => axios.put(url, payload, config),
				del: (url) => axios.delete(url),
				url: (schema, id) =>
					instanceUrl(
						`/apps/openregister/api/objects/portaliq/${schema}${id ? '/' + encodeURIComponent(id) : ''}`,
					),
			}),

			menus: [],
			menuId: '',
			items: [],
			loading: true,
			busy: false,
			dirty: false,
			error: '',
			notice: '',
			newName: '',
			newLink: '',
		}
	},

	computed: {
		/**
		 * The menu being edited.
		 *
		 * @return {object|null} The menu.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		menu() {
			return this.menus.find((m) => m.id === this.menuId) || null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Translate.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The source text.
		 * @param {object} vars The placeholders.
		 * @return {string} The translation.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		t(app, text, vars) {
			return translate(app, text, vars)
		},

		/**
		 * Read the portal's menus and open the first by position.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		async load() {
			this.loading = true
			try {
				const menus = await this.api.list('menu', this.portal)
				this.menus = menus.sort(
					(a, b) => (Number(a.position) || 0) - (Number(b.position) || 0),
				)
				if (!this.menus.some((m) => m.id === this.menuId)) {
					this.menuId = this.menus[0]?.id || ''
				}
				this.pick()
			} catch (error) {
				this.error = this.messageFor(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take the chosen menu's items.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		pick() {
			this.items = sortedMenuItems(this.menu?.items || [])
			this.dirty = false
		},

		/**
		 * Apply one change to the items, or show why it was refused.
		 *
		 * @param {Function} change () => the new items.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		apply(change) {
			this.error = ''
			this.notice = ''
			try {
				this.items = change()
				this.dirty = true
			} catch (error) {
				this.error = this.t('portaliq', error.message)
			}
		},

		/**
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		add() {
			this.apply(() =>
				addMenuItem(this.items, { name: this.newName, link: this.newLink }),
			)
			if (!this.error) {
				this.newName = ''
				this.newLink = ''
			}
		},

		/**
		 * @param {number} index The item.
		 * @param {string} name The new name.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		rename(index, name) {
			this.apply(() => renameMenuItem(this.items, index, name))
		},

		/**
		 * @param {number} index The item.
		 * @param {number} delta The direction.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		move(index, delta) {
			this.apply(() => moveMenuItem(this.items, index, delta))
		},

		/**
		 * @param {number} index The item.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		remove(index) {
			this.apply(() => removeMenuItem(this.items, index))
		},

		/**
		 * Save the menu with the version check, then read it again.
		 *
		 * @return {Promise<void>} Resolves when saved or refused.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		async save() {
			if (!this.menu) {
				return
			}
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				await this.api.save(
					'menu',
					this.menu.id,
					menuPayload(this.menu, this.items),
					this.menu.version,
				)
				await this.load()
				this.notice = this.t(
					'portaliq',
					'The menu is saved. Visitors see it on their next page.',
				)
				this.$emit('saved')
			} catch (error) {
				this.error = this.messageFor(error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} error The error.
		 * @return {string} The message.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
		 */
		messageFor(error) {
			if (isConflict(error)) {
				return this.t(
					'portaliq',
					'Someone else saved this menu after you opened it, so your changes were not saved. Close and reopen the menu to see their version.',
				)
			}
			const status = error?.response?.status
			if (status === 401 || status === 403) {
				return this.t(
					'portaliq',
					'You are not allowed to change the menu of this portal.',
				)
			}
			return this.t('portaliq', 'That did not work. Nothing was changed.')
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
	margin: 0 0 16px;
	padding-inline-start: 24px;
}

.pq-site-panel__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding-block: 4px;
}

.pq-site-panel__actions {
	display: flex;
	gap: 4px;
}

.pq-site-panel__form {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	gap: 8px 12px;
	align-items: center;
	max-width: 640px;
	margin-bottom: 16px;
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
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
