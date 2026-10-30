<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PortalAdminGroups: the Nextcloud groups that administer one portal, as a
  card in the portal's settings.

  Members of these groups change this portal's settings, domains, house
  style, sign-in and case types, and see no other portal. An instance
  administrator picks the groups; everyone else reads the list. The server
  holds both rules (PortalAdminGroupsController, PortalAdminGroupsGuardListener).

  @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
-->
<template>
	<div class="admin-groups" data-testid="portal-admin-groups">
		<p class="admin-groups__intro">
			{{
				t(
					'portaliq',
					'Members of these groups administer {portal}: its settings, domains, house style, sign-in and case types. They see no other portal.',
					{ portal: portalTitle },
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="canChange">
			<NcSelect
				v-model="selected"
				:options="available"
				:inputLabel="t('portaliq', 'Administrator groups')"
				label="label"
				trackBy="id"
				:multiple="true"
				keepOpen
				data-testid="admin-groups-select"
				@update:modelValue="notice = ''" />

			<NcNoteCard v-if="notice" type="success">
				{{ notice }}
			</NcNoteCard>

			<NcButton
				variant="primary"
				:disabled="saving || !dirty"
				data-testid="admin-groups-save"
				@click="save">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>

		<template v-else>
			<ul v-if="stored.length > 0" class="admin-groups__list">
				<li v-for="group in stored" :key="group.id">
					{{ group.label }}
				</li>
			</ul>
			<p class="admin-groups__hint">
				{{
					t(
						'portaliq',
						'Only an administrator of this installation can change this list.',
					)
				}}
			</p>
		</template>

		<p
			v-if="!loading && !error && stored.length === 0"
			class="admin-groups__hint"
			data-testid="admin-groups-empty">
			{{
				t(
					'portaliq',
					'No group yet. Only administrators of this installation administer this portal.',
				)
			}}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	adminGroupsUrl,
	groupIds,
	groupsChanged,
} from '../lib/portalAdminGroups.js'

export default {
	name: 'PortalAdminGroups',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			stored: [],
			selected: [],
			available: [],
			canChange: false,
			loading: true,
			saving: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * The portal's slug.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},

		/**
		 * The portal's name, for the intro line.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
		 */
		portalTitle() {
			return String(this.objectData?.title || this.slug)
		},

		/**
		 * Whether the picker differs from what is stored.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
		 */
		dirty() {
			return groupsChanged(this.stored, this.selected)
		},
	},

	watch: {
		/**
		 * Load again when the detail page hands over another portal.
		 *
		 * @return {void}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
		 */
		slug() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the portal's groups.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
		 */
		async load() {
			this.loading = true
			this.error = ''
			if (this.slug === '') {
				this.loading = false
				return
			}
			try {
				const { data } = await axios.get(adminGroupsUrl(this.slug, generateUrl))
				this.accept(data)
			} catch {
				this.error = t(
					'portaliq',
					'The groups of this portal could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take an answer from the server as the stored state.
		 *
		 * @param {object} data `{groups, canChange, available}`.
		 * @return {void}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
		 */
		accept(data) {
			this.stored = (data.groups || []).map((group) => ({ ...group }))
			this.selected = (data.groups || []).map((group) => ({ ...group }))
			this.available = data.available || []
			this.canChange = data.canChange === true
		},

		/**
		 * Save the picked groups.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(adminGroupsUrl(this.slug, generateUrl), {
					groups: groupIds(this.selected),
				})
				this.accept(data)
				this.notice = t('portaliq', 'Your choices are saved.')
			} catch {
				this.error = t('portaliq', 'Your choices could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.admin-groups {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
}

.admin-groups__list {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.admin-groups__hint {
	color: var(--color-text-maxcontrast);
}
</style>
