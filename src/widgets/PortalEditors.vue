<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalEditors: "Beheerders", a card of a portal's Instellingen tab (screens
  PtPortalInstellingen). Who looks after the portal, with their role; a
  beheerder adds and removes them. The list grants no right to change pages.

  Custom because no library widget edits a list inside one object field.

  @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
-->
<template>
	<div class="portal-editors" data-testid="portal-editors">
		<p v-if="editors.length === 0" class="portal-editors__empty">
			{{ t('portaliq', 'Nobody listed yet.') }}
		</p>
		<ul v-else class="portal-editors__list">
			<li v-for="editor in editors" :key="editor.userId" class="portal-editors__row">
				<span class="portal-editors__avatar" aria-hidden="true">{{ initials(editor.displayName || editor.userId) }}</span>
				<span class="portal-editors__name">{{ editor.displayName || editor.userId }}</span>
				<span class="portal-editors__role">{{ roleLabel(editor.role) }}</span>
				<NcButton variant="tertiary" :disabled="busy" @click="remove(editor.userId)">
					{{ t('portaliq', 'Remove') }}
				</NcButton>
			</li>
		</ul>
		<p class="portal-editors__hint">
			{{ t('portaliq', 'Listing someone here does not give them the right to change pages.') }}
		</p>
		<form class="portal-editors__add" @submit.prevent="add">
			<NcTextField
				v-model="draft.userId"
				:label="t('portaliq', 'User name')"
				:error="Boolean(error)"
				:helperText="error ? t('portaliq', error) : ''"
				data-testid="portal-editors-user" />
			<NcTextField v-model="draft.displayName" :label="t('portaliq', 'Name')" />
			<NcSelect
				v-model="role"
				:inputLabel="t('portaliq', 'Role')"
				:options="roleOptions"
				:clearable="false"
				label="label" />
			<NcButton type="submit" variant="secondary" :disabled="busy" data-testid="portal-editors-add">
				{{ t('portaliq', 'Add') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="notice" type="error">
			{{ notice }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { editorError, initials, ROLE_OPTIONS, withEditor } from '../lib/portalDomains.js'

export default {
	name: 'PortalEditors',

	components: { NcButton, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			editors: Array.isArray(this.objectData?.editors) ? [...this.objectData.editors] : [],
			draft: { userId: '', displayName: '', role: 'editor' },
			error: '',
			busy: false,
			notice: '',
		}
	},

	computed: {
		objectId() {
			return this.objectData?.['@self']?.id || this.objectData?.id || ''
		},

		roleOptions() {
			return ROLE_OPTIONS.map((o) => ({ id: o.id, label: t('portaliq', o.label) }))
		},

		role: {
			get() {
				return this.roleOptions.find((o) => o.id === this.draft.role)
			},

			set(option) {
				this.draft.role = option?.id || 'editor'
			},
		},
	},

	methods: {
		t,
		initials,

		roleLabel(role) {
			return role === 'chief-editor' ? t('portaliq', 'Chief editor') : t('portaliq', 'Editor')
		},

		/**
		 * Add the typed editor.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		async add() {
			this.error = editorError(this.draft, this.editors)
			if (this.error) {
				return
			}
			await this.save(withEditor(this.editors, this.draft))
			if (!this.notice) {
				this.draft = { userId: '', displayName: '', role: 'editor' }
			}
		},

		/**
		 * Remove an editor.
		 *
		 * @param {string} userId The Nextcloud user id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		async remove(userId) {
			await this.save(this.editors.filter((e) => e.userId !== userId))
		},

		/**
		 * Save the list; only `editors` is sent.
		 *
		 * @param {Array<object>} editors The new list.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		async save(editors) {
			if (!this.objectId) {
				return
			}
			this.busy = true
			this.notice = ''
			try {
				await axios.patch(generateUrl(`/apps/openregister/api/objects/portaliq/portal/${this.objectId}`), { editors })
				this.editors = editors
			} catch {
				this.notice = t('portaliq', 'The list could not be saved. Try again.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.portal-editors {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.portal-editors__list {
	list-style: none;
	margin: 0;
	padding: 0;
}
.portal-editors__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}
.portal-editors__avatar {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	font-weight: 600;
	font-size: 13px;
}
.portal-editors__name {
	font-weight: 600;
	flex: 1;
}
.portal-editors__role,
.portal-editors__hint,
.portal-editors__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
.portal-editors__add {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}
</style>
