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

		<!-- `section-portal-auth-edge` is the anchor lib/Settings/connections.json
			links the Login brokers row to (adopt-connection-registry). Keep it stable. -->
		<NcSettingsSection
			id="section-portal-auth-edge"
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
			<NcNoteCard v-if="revokeFailed" type="error" data-testid="revoke-failed">
				{{
					t(
						'portaliq',
						'Not every session could be revoked. {count} session(s) were revoked; the rest may still be active. Try again, and check that OpenRegister is running.',
						{ count: revokeResult ?? 0 },
					)
				}}
			</NcNoteCard>
			<p
				v-else-if="revokeResult !== null"
				class="portaliq-admin-settings__hint"
				role="status">
				{{
					t('portaliq', 'Revoked {count} session(s).', {
						count: revokeResult,
					})
				}}
			</p>
		</NcSettingsSection>

		<!--
			The e-mail link sign-in (sign-in-with-an-email-link, decision 127).
			OFF by default; the help text says the security review comes first.
		-->
		<NcSettingsSection
			id="section-email-link"
			:name="t('portaliq', 'Sign-in with an e-mail link')"
			:description="
				t(
					'portaliq',
					'Lets a participant with an e-mail account sign in with a one-time link that works for 15 minutes. A portal offers it only when it also lists the sign-in mode email-link.',
				)
			">
			<NcNoteCard type="warning">
				{{
					t(
						'portaliq',
						'Do not turn this on before the security review of this sign-in has been accepted. Anyone who can read the mailbox can sign in while the link works.',
					)
				}}
			</NcNoteCard>
			<NcCheckboxRadioSwitch
				v-model="emailLinkEnabled"
				type="switch"
				:disabled="savingEmailLink"
				data-testid="admin-email-link"
				@update:modelValue="saveEmailLink">
				{{ t('portaliq', 'Allow sign-in with an e-mail link') }}
			</NcCheckboxRadioSwitch>
			<p class="portaliq-admin-settings__hint" role="status">
				<span v-if="emailLinkError">{{ emailLinkError }}</span>
				<span v-else-if="emailLinkSaved">{{ t('portaliq', 'Saved.') }}</span>
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
			Who may do which action (operate-roles-for-content-and-actions
			REQ-ORA-001). One picker per action the app checks; an empty picker
			means only administrators. Administrators always keep every action.
		-->
		<NcSettingsSection
			:name="t('portaliq', 'Actions')"
			:description="
				t(
					'portaliq',
					'Choose which groups may do each action. Administrators always may.',
				)
			">
			<div
				v-for="row in grantRows"
				:key="row.action"
				class="portaliq-admin-settings__grant"
				:data-testid="`admin-action-${row.action}`">
				<p class="portaliq-admin-settings__grant-label">
					<strong>{{ row.label }}</strong>
					<span>{{ row.description }}</span>
				</p>
				<NcSelect
					v-model="row.groups"
					:inputLabel="row.label"
					:options="grantGroupOptions"
					:multiple="true"
					:keepOpen="true"
					:disabled="savingGrants"
					label="label"
					:data-testid="`admin-action-groups-${row.action}`"
					@update:modelValue="saveGrantRows" />
				<p
					v-if="row.groups.length === 0"
					class="portaliq-admin-settings__hint">
					{{ t('portaliq', 'Only administrators') }}
				</p>
			</div>
			<p class="portaliq-admin-settings__hint" role="status">
				<span v-if="grantsError" data-testid="admin-actions-error">{{
					grantsError
				}}</span>
				<span v-else-if="grantsSaved" data-testid="admin-actions-saved">{{
					t('portaliq', 'Saved.')
				}}</span>
			</p>
		</NcSettingsSection>

		<!--
			Visitor geography (portal-traffic-visitors-and-geo, Ruben's
			decision 7): DB-IP Lite by default, MaxMind with an account. The
			licence key is write-only here: the server says whether one is
			stored and never hands it back. `section-visitor-geography` is the
			anchor lib/Settings/connections.json links the Visitor geography
			database row to (adopt-connection-registry). Keep it stable.
		-->
		<NcSettingsSection
			id="section-visitor-geography"
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

		<!--
			The internal address for calls to this server
			(instance-loopback-self-calls). Portaliq calls its own Nextcloud
			for a resident's tasks and actions. Empty means the public
			address, with one retry on 127.0.0.1 when that does not answer
			from inside the server. The server validates the address.
		-->
		<NcSettingsSection
			id="section-internal-address"
			:name="t('portaliq', 'Calls to this server')"
			:description="
				t(
					'portaliq',
					'Portaliq calls this Nextcloud server itself, for example to load a resident\'s tasks. Leave the address empty to use the public address. When that does not answer from inside the server, Portaliq tries 127.0.0.1 once.',
				)
			">
			<form
				class="portaliq-admin-settings__geo"
				@submit.prevent="saveInternalBaseUrl">
				<NcTextField
					v-model="internalBaseUrl"
					:label="t('portaliq', 'Internal address')"
					placeholder="http://nextcloud"
					:disabled="savingInternalBaseUrl"
					data-testid="admin-internal-base-url" />
				<NcButton
					variant="primary"
					:disabled="savingInternalBaseUrl"
					type="submit"
					data-testid="admin-internal-base-url-save">
					{{ t('portaliq', 'Save address') }}
				</NcButton>
			</form>

			<p class="portaliq-admin-settings__hint" role="status">
				<span
					v-if="internalBaseUrlError"
					data-testid="admin-internal-base-url-error"
					>{{ internalBaseUrlError }}</span
				>
				<span
					v-else-if="internalBaseUrlSaved"
					data-testid="admin-internal-base-url-saved"
					>{{ t('portaliq', 'Saved.') }}</span
				>
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
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcPasswordField,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import { loadGrants, saveGrants } from '../lib/actionGrants.js'

export default {
	name: 'AdminRoot',
	components: {
		NcSettingsSection,
		NcCheckboxRadioSwitch,
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
			revokeFailed: false,

			// The groups that may edit portal pages, as `{id, label}` options
			// so the picker round-trips its own objects; the service accepts
			// either shape and stores the ids.
			editorGroups: [],
			groupOptions: [],
			savingGroups: false,
			groupsSaved: false,
			groupsError: '',

			// The Actions section: one row per action the app checks.
			grantRows: [],
			grantGroupOptions: [],
			savingGrants: false,
			grantsSaved: false,
			grantsError: '',

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

			// The internal address for calls to this server. Empty means
			// the public address with the loopback fallback.
			internalBaseUrl: '',
			savingInternalBaseUrl: false,
			internalBaseUrlSaved: false,
			internalBaseUrlError: '',

			// The e-mail link switch, off until an administrator turns it on.
			emailLinkEnabled: false,
			savingEmailLink: false,
			emailLinkSaved: false,
			emailLinkError: '',
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
		this.loadActionGrants()
		this.loadGeo()
	},

	methods: {
		/**
		 * Load the configured editor groups and the instance's group list.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
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
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
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
		 * Load the actions and the groups granted each.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-administrator-grants-an-action-to-a-group-on-screen-req-ora-001
		 */
		async loadActionGrants() {
			try {
				const loaded = await loadGrants(
					axios,
					generateUrl('/apps/portaliq/api/settings/actions'),
				)
				this.grantRows = loaded.rows
				this.grantGroupOptions = loaded.groupOptions
			} catch {
				this.grantsError = t('portaliq', 'The actions could not be loaded.')
			}
		},

		/**
		 * Save the grants as the pickers now stand.
		 *
		 * @return {Promise<void>} Resolves when saved.
		 *
		 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-the-grants-accept-only-known-actions-and-existing-groups-req-ora-002
		 */
		async saveGrantRows() {
			this.savingGrants = true
			this.grantsSaved = false
			this.grantsError = ''
			try {
				const stored = await saveGrants(
					axios,
					generateUrl('/apps/portaliq/api/settings/actions'),
					this.grantRows,
				)
				this.grantRows = stored.rows
				this.grantsSaved = true
			} catch {
				this.grantsError = t(
					'portaliq',
					'Saving the actions failed. The grants are unchanged.',
				)
			} finally {
				this.savingGrants = false
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
				this.internalBaseUrl = String(data.internal_base_url || '')
				this.emailLinkEnabled = data.email_link_signin_enabled === true
			} catch {
				this.geoError = t(
					'portaliq',
					'The geography settings could not be loaded.',
				)
			}
		},

		/**
		 * Save the e-mail link switch; on failure the switch shows what is stored.
		 *
		 * @param {boolean} enabled The new state.
		 * @return {Promise<void>} Resolves when saved.
		 *
		 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
		 */
		async saveEmailLink(enabled) {
			this.savingEmailLink = true
			this.emailLinkSaved = false
			this.emailLinkError = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/portaliq/api/settings'),
					{ email_link_signin_enabled: enabled === true },
				)
				this.emailLinkEnabled =
					(data.config || {}).email_link_signin_enabled === true
				this.emailLinkSaved = true
			} catch {
				this.emailLinkEnabled = !enabled
				this.emailLinkError = t(
					'portaliq',
					'The setting could not be saved. Try again.',
				)
			} finally {
				this.savingEmailLink = false
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
		 * Save the internal address for calls to this server. The server
		 * refuses an invalid address and keeps the stored one.
		 *
		 * @return {Promise<void>} Resolves when saved.
		 *
		 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-an-administrator-can-name-the-internal-address
		 */
		async saveInternalBaseUrl() {
			this.savingInternalBaseUrl = true
			this.internalBaseUrlSaved = false
			this.internalBaseUrlError = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/portaliq/api/settings'),
					{ internal_base_url: this.internalBaseUrl.trim() },
				)
				const config = (data && data.config) || {}
				if (config.internal_base_url_refused === true) {
					this.internalBaseUrlError = t(
						'portaliq',
						'This address is not valid. Use http or https, without a password, a query or "..".',
					)
					return
				}
				this.internalBaseUrl = String(config.internal_base_url || '')
				this.internalBaseUrlSaved = true
			} catch {
				this.internalBaseUrlError = t(
					'portaliq',
					'Saving the address failed.',
				)
			} finally {
				this.savingInternalBaseUrl = false
			}
		},

		/**
		 * Revoke every active portalSession for one organisation.
		 *
		 * The frontend half of the incident-response action whose backend twin
		 * is SessionAdminController::revokeOrganisation(), which carries this
		 * same anchor.
		 *
		 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
		 */
		async revokeOrganisation() {
			if (this.organisationInput === '') {
				return
			}
			this.revoking = true
			this.revokeResult = null
			this.revokeFailed = false
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/portaliq/api/session-admin/revoke-organisation',
					),
					{ organisation: this.organisationInput },
				)
				this.revokeResult = data.revoked ?? 0
			} catch (error) {
				// A refused or incomplete run is an error, never "0 revoked"
				// (security review S5); a 503 still says how many were revoked.
				this.revokeFailed = true
				this.revokeResult = error?.response?.data?.revoked ?? 0
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

.portaliq-admin-settings__grant {
	margin-block-end: 1.25rem;
}

.portaliq-admin-settings__grant-label {
	display: flex;
	flex-direction: column;
	margin: 0 0 0.25rem;
}

.portaliq-admin-settings__grant-label span {
	color: var(--color-text-maxcontrast);
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
