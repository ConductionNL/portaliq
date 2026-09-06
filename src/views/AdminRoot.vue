<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Nextcloud admin app-settings panel.

 Mounted into `#portaliq-settings` by `src/settings.js` (which
 itself is loaded from `templates/settings/admin.php` via
 `Util::addScript`). This is the panel users reach via Nextcloud's
 "Administration settings" → "Portaliq" (the section label comes from
 `lib/Sections/SettingsSection.php::getName()`).

 In a manifest-driven app this surface is mostly redundant — the
 SPA's `type: "settings"` page (declared in `src/manifest.json`)
 covers admin/user settings inside the app's own UI. The Nextcloud
 admin panel below stays as a placeholder for "before the app
 boots" wiring (e.g. choosing the OpenRegister register before the
 manifest renders).
-->
<template>
	<div class="portaliq-admin-settings">
		<NcSettingsSection
			:name="t('portaliq', 'Pre-boot configuration')"
			:description="
				t(
					'portaliq',
					'Pre-app-boot configuration. Most settings live inside the app at /settings (manifest-driven).',
				)
			">
			<p class="portaliq-admin-settings__hint">
				{{
					t(
						'portaliq',
						'No pre-boot settings yet. Edit `src/views/AdminRoot.vue` to add fields here.',
					)
				}}
			</p>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('portaliq', 'Portal auth edge')"
			:description="
				t(
					'portaliq',
					'The public portal signs supplier/client sessions with a secret dedicated to this app — never the Nextcloud instance secret.',
				)
			">
			<NcNoteCard v-if="jwtSigningSecretConfigured" type="success">
				{{
					t(
						'portaliq',
						'A dedicated signing secret is configured. The portal auth edge is safe to use.',
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-else type="warning">
				{{
					t(
						'portaliq',
						'No dedicated signing secret is configured yet. The portal cannot issue or accept sessions until the next install/upgrade repair step runs.',
					)
				}}
			</NcNoteCard>

			<form
				class="portaliq-admin-settings__revoke"
				@submit.prevent="revokeOrganisation">
				<NcTextField
					v-model="organisationInput"
					:label="t('portaliq', 'Organisation')"
					:placeholder="t('portaliq', 'Organisation UUID')"
					:disabled="revoking" />
				<!--
					@nextcloud/vue 9 repurposed NcButton's `type` prop: it is now
					the NATIVE button type (default "button"), and the visual
					style moved to `variant`. `native-type` no longer exists.
					The Vue-2 spelling (`type="error" native-type="submit"`)
					still renders — as <button type="error">, which is invalid
					and does NOT submit the form — with no console warning and
					no lint error.
				-->
				<NcButton
					variant="error"
					:disabled="revoking || organisationInput === ''"
					type="submit">
					{{ t('portaliq', 'Revoke all sessions for this organisation') }}
				</NcButton>
			</form>
			<p
				v-if="revokeResult !== null"
				class="portaliq-admin-settings__hint"
				role="status">
				{{
					t('portaliq', 'Revoked {count} session(s).', {
						count: revokeResult,
					})
				}}
			</p>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('portaliq', 'Page editors')"
			:description="
				t(
					'portaliq',
					'Members of these groups may create, change and delete portal pages, and are offered the editing control on the portal itself. Administrators always may.',
				)
			">
			<!--
				THE SETTING IS NOT A UI TOGGLE. Saving it writes these groups
				into the page schema's authorization block in OpenRegister,
				which is where a write is actually refused — the designer sends
				its saves straight there, with no Portaliq endpoint in between.
			-->
			<NcSelect
				v-model="editorGroups"
				:inputLabel="t('portaliq', 'Groups that may edit pages')"
				:options="groupOptions"
				:multiple="true"
				:keepOpen="true"
				:disabled="savingGroups"
				label="label"
				data-testid="admin-editor-groups"
				@update:modelValue="saveEditorGroups" />

			<p class="portaliq-admin-settings__hint" role="status">
				<span v-if="groupsError" data-testid="admin-editor-groups-error">{{
					groupsError
				}}</span>
				<span
					v-else-if="groupsSaved"
					data-testid="admin-editor-groups-saved"
					>{{ t('portaliq', 'Saved.') }}</span
				>
				<span v-else-if="editorGroups.length === 0">{{
					t(
						'portaliq',
						'No groups configured — only administrators may edit pages.',
					)
				}}</span>
			</p>
		</NcSettingsSection>

		<!--
			Visitor geography (portal-traffic-visitors-and-geo, Ruben's
			decision 7): DB-IP Lite by default, MaxMind with an account. The
			licence key is write-only here: the server says whether one is
			stored and never hands it back.
		-->
		<NcSettingsSection
			:name="t('portaliq', 'Visitor geography')"
			:description="
				t(
					'portaliq',
					'Which offline database turns a visitor\'s address into a country or region. The address itself is never stored and never sent to a third party.',
				)
			">
			<form class="portaliq-admin-settings__geo" @submit.prevent="saveGeo">
				<NcSelect
					v-model="geoProvider"
					:inputLabel="t('portaliq', 'Provider')"
					:options="geoProviderOptions"
					:clearable="false"
					:disabled="savingGeo"
					label="label"
					data-testid="admin-geo-provider" />

				<template v-if="geoProvider && geoProvider.id === 'maxmind'">
					<NcTextField
						v-model="geoAccountId"
						:label="t('portaliq', 'MaxMind account id')"
						:disabled="savingGeo"
						data-testid="admin-geo-account-id" />
					<NcPasswordField
						v-model="geoLicenseKey"
						:label="t('portaliq', 'MaxMind licence key')"
						:disabled="savingGeo"
						autocomplete="off"
						data-testid="admin-geo-license-key" />
					<p
						v-if="geoLicenseKeySet"
						class="portaliq-admin-settings__hint"
						data-testid="admin-geo-license-key-set">
						{{
							t(
								'portaliq',
								'A licence key is stored. Leave this empty to keep it, or enter a new one to replace it.',
							)
						}}
					</p>
					<NcSelect
						v-model="geoEdition"
						:inputLabel="t('portaliq', 'Edition')"
						:options="geoEditionOptions"
						:clearable="false"
						:disabled="savingGeo"
						label="label"
						data-testid="admin-geo-edition" />
				</template>

				<NcButton
					variant="primary"
					:disabled="savingGeo || !geoProvider"
					type="submit"
					data-testid="admin-geo-save">
					{{ t('portaliq', 'Save geography settings') }}
				</NcButton>
			</form>

			<p class="portaliq-admin-settings__hint" role="status">
				<span v-if="geoError" data-testid="admin-geo-error">{{
					geoError
				}}</span>
				<span v-else-if="geoSaved" data-testid="admin-geo-saved">{{
					t(
						'portaliq',
						'Saved. The database is fetched by the monthly background job, or now with occ portaliq:traffic:geo-refresh.',
					)
				}}</span>
			</p>

			<p class="portaliq-admin-settings__hint" data-testid="admin-geo-status">
				<template v-if="geoStatus.present">
					{{
						t(
							'portaliq',
							'Database installed: {type}, fetched {fetchedAt}.',
							{
								type: geoStatus.metadata.databaseType || 'unknown',
								fetchedAt: geoStatus.metadata.fetchedAt || 'unknown',
							},
						)
					}}
					<br />
					{{
						t('portaliq', 'Attribution: {attribution}', {
							attribution: geoStatus.metadata.attribution || '',
						})
					}}
				</template>
				<template v-else>
					{{
						t(
							'portaliq',
							'No database installed yet. It is fetched when a portal first asks for a region, by the monthly job, or with occ portaliq:traffic:geo-refresh.',
						)
					}}
				</template>
			</p>
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcNoteCard,
	NcPasswordField,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'AdminRoot',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
		NcButton,
	},

	data() {
		return {
			// Server-derived via IInitialStateService (AdminSettings::getForm());
			// never re-derived client-side (ADR-004 — no DOM data attributes).
			jwtSigningSecretConfigured: loadState(
				'portaliq',
				'jwtSigningSecretConfigured',
				false,
			),

			organisationInput: '',
			revoking: false,
			revokeResult: null,

			// The groups that may edit portal pages, as `{id, label}` options
			// so the picker round-trips its own objects; the service accepts
			// either shape and stores the ids.
			editorGroups: [],
			groupOptions: [],
			savingGroups: false,
			groupsSaved: false,
			groupsError: '',

			// Visitor geography. The licence key field is write-only: it
			// starts empty whether or not one is stored, and an empty
			// field on save means "keep what is stored".
			geoProvider: null,
			geoAccountId: '',
			geoLicenseKey: '',
			geoLicenseKeySet: false,
			geoEdition: null,
			geoStatus: { present: false, metadata: {} },
			savingGeo: false,
			geoSaved: false,
			geoError: '',
		}
	},

	computed: {
		/**
		 * The three providers as select options.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		geoProviderOptions() {
			return [
				{ id: 'none', label: t('portaliq', 'None (no geography)') },
				{ id: 'dbip', label: t('portaliq', 'DB-IP Lite (free, CC BY 4.0)') },
				{
					id: 'maxmind',
					label: t('portaliq', 'MaxMind (account required)'),
				},
			]
		},

		/**
		 * The two MaxMind editions as select options.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		geoEditionOptions() {
			return [
				{ id: 'GeoLite2-City', label: 'GeoLite2-City' },
				{ id: 'GeoIP2-City', label: 'GeoIP2-City' },
			]
		},
	},

	/**
	 * Load both admin blocks: the editor groups and the geography settings.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 * @return {void}
	 */
	mounted() {
		this.loadEditorGroups()
		this.loadGeo()
	},

	methods: {
		/**
		 * Load the configured editor groups and the instance's group list.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
		 */
		async loadEditorGroups() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/portaliq/api/settings'),
				)
				this.groupOptions = data.availableGroups || []
				const configured = data.editor_groups || []
				// Shown as the picker's own option objects where the group
				// still exists, and as a bare id where it does not — a group
				// that was deleted must stay visible as configured rather than
				// silently dropping out of the setting on the next save.
				this.editorGroups = configured.map(
					(id) =>
						this.groupOptions.find((option) => option.id === id) || {
							id,
							label: id,
						},
				)
			} catch {
				this.groupsError = t(
					'portaliq',
					'The editor groups could not be loaded.',
				)
			}
		},

		/**
		 * Save the editor groups, which rewrites the page schema's write rules.
		 *
		 * @return {Promise<void>} Resolves when saved.
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
		 */
		async saveEditorGroups() {
			this.savingGroups = true
			this.groupsSaved = false
			this.groupsError = ''
			try {
				await axios.put(generateUrl('/apps/portaliq/api/settings'), {
					editor_groups: this.editorGroups.map((entry) => entry.id),
				})
				this.groupsSaved = true
			} catch {
				this.groupsError = t(
					'portaliq',
					'Saving the editor groups failed. Page editing is unchanged.',
				)
			} finally {
				this.savingGroups = false
			}
		},

		/**
		 * Load the geography settings and the database status. The
		 * response carries whether a key is stored, never the key.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
		 */
		async loadGeo() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/portaliq/api/settings'),
				)
				const geo = data.traffic_geo || {}
				this.geoProvider =
					this.geoProviderOptions.find((o) => o.id === geo.provider)
					|| this.geoProviderOptions[1]
				this.geoAccountId = String(geo.maxmindAccountId || '')
				this.geoLicenseKeySet = geo.maxmindLicenseKeySet === true
				this.geoEdition =
					this.geoEditionOptions.find((o) => o.id === geo.maxmindEdition)
					|| this.geoEditionOptions[0]
				this.geoStatus = {
					present: Boolean(geo.status && geo.status.present),
					metadata: (geo.status && geo.status.metadata) || {},
				}
			} catch {
				this.geoError = t(
					'portaliq',
					'The geography settings could not be loaded.',
				)
			}
		},

		/**
		 * Save the geography settings. The key travels only when typed.
		 *
		 * @return {Promise<void>} Resolves when saved.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
		 */
		async saveGeo() {
			if (!this.geoProvider) {
				return
			}
			this.savingGeo = true
			this.geoSaved = false
			this.geoError = ''
			const block = {
				provider: this.geoProvider.id,
				maxmindAccountId: this.geoAccountId,
				maxmindEdition: this.geoEdition
					? this.geoEdition.id
					: 'GeoLite2-City',
			}
			if (this.geoLicenseKey !== '') {
				block.maxmindLicenseKey = this.geoLicenseKey
			}
			try {
				await axios.put(generateUrl('/apps/portaliq/api/settings'), {
					traffic_geo: block,
				})
				this.geoSaved = true
				this.geoLicenseKey = ''
				await this.loadGeo()
			} catch {
				this.geoError = t(
					'portaliq',
					'Saving the geography settings failed.',
				)
			} finally {
				this.savingGeo = false
			}
		},

		/**
		 * Revoke every active portalSession for one organisation.
		 *
		 * The frontend half of the incident-response action whose backend twin
		 * is SessionAdminController::revokeOrganisation(), which carries this
		 * same anchor.
		 *
		 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.2
		 */
		async revokeOrganisation() {
			if (this.organisationInput === '') {
				return
			}
			this.revoking = true
			this.revokeResult = null
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/portaliq/api/session-admin/revoke-organisation',
					),
					{ organisation: this.organisationInput },
				)
				this.revokeResult = data.revoked ?? 0
			} catch {
				this.revokeResult = 0
			} finally {
				this.revoking = false
			}
		},
	},
}
</script>

<style scoped>
.portaliq-admin-settings {
	max-width: 720px;
}

.portaliq-admin-settings__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.portaliq-admin-settings__revoke {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-top: 12px;
}

.portaliq-admin-settings__geo {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	margin-top: 12px;
	max-width: 420px;
}
</style>
