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
			:description="t('portaliq', 'Pre-app-boot configuration. Most settings live inside the app at /settings (manifest-driven).')">
			<p class="portaliq-admin-settings__hint">
				{{ t('portaliq', 'No pre-boot settings yet. Edit `src/views/AdminRoot.vue` to add fields here.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('portaliq', 'Portal auth edge')"
			:description="t('portaliq', 'The public portal signs supplier/client sessions with a secret dedicated to this app — never the Nextcloud instance secret.')">
			<NcNoteCard v-if="jwtSigningSecretConfigured" type="success">
				{{ t('portaliq', 'A dedicated signing secret is configured. The portal auth edge is safe to use.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="warning">
				{{ t('portaliq', 'No dedicated signing secret is configured yet. The portal cannot issue or accept sessions until the next install/upgrade repair step runs.') }}
			</NcNoteCard>

			<form class="portaliq-admin-settings__revoke" @submit.prevent="revokeOrganisation">
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
				<NcButton variant="error" :disabled="revoking || organisationInput === ''" type="submit">
					{{ t('portaliq', 'Revoke all sessions for this organisation') }}
				</NcButton>
			</form>
			<p v-if="revokeResult !== null" class="portaliq-admin-settings__hint" role="status">
				{{ t('portaliq', 'Revoked {count} session(s).', { count: revokeResult }) }}
			</p>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcSettingsSection, NcNoteCard, NcTextField, NcButton } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'AdminRoot',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcTextField,
		NcButton,
	},
	data() {
		return {
			// Server-derived via IInitialStateService (AdminSettings::getForm());
			// never re-derived client-side (ADR-004 — no DOM data attributes).
			jwtSigningSecretConfigured: loadState('portaliq', 'jwtSigningSecretConfigured', false),
			organisationInput: '',
			revoking: false,
			revokeResult: null,
		}
	},
	methods: {
		async revokeOrganisation() {
			if (this.organisationInput === '') {
				return
			}
			this.revoking = true
			this.revokeResult = null
			try {
				const { data } = await axios.post(
					generateUrl('/apps/portaliq/api/session-admin/revoke-organisation'),
					{ organisation: this.organisationInput },
				)
				this.revokeResult = data.revoked ?? 0
			} catch (e) {
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
</style>
