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

		<!--
			THE THEME CATALOGUE, WITH ITS ACCESSIBILITY VERDICT (tasks 1.4, 3.1, 3.2).

			`portal.theme` was free text against a catalogue nothing in this app
			showed, so choosing one meant knowing an id — and getting it wrong
			renders the portal UNSTYLED, deliberately and silently. This lists
			what can actually be adopted.

			The verdict travels with the choice rather than arriving in a review
			weeks after a portal went live wearing the theme. A set is never
			refused: naming the failing token, its ratio and the surface it
			fails on is something an operator can act on, while blocking
			adoption would be this app deciding a municipality may not use its
			own brand — a decision it does not own, and one that would be worked
			around by editing the record directly.
		-->
		<NcSettingsSection
			:name="t('portaliq', 'Themes')"
			:description="
				t(
					'portaliq',
					'The token sets a portal can adopt. The contrast verdict is measured against the surfaces this renderer actually paints.',
				)
			">
			<NcNoteCard v-if="themeError" type="error">
				{{ themeError }}
			</NcNoteCard>

			<p v-else-if="themesLoading" class="portaliq-admin-settings__hint">
				{{ t('portaliq', 'Loading themes…') }}
			</p>

			<table v-else-if="themes.length" class="portaliq-themes">
				<thead>
					<tr>
						<th scope="col">{{ t('portaliq', 'Theme') }}</th>
						<th scope="col">{{ t('portaliq', 'Id') }}</th>
						<th scope="col">{{ t('portaliq', 'Contrast') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="set in themes" :key="set.id">
						<td>{{ set.label }}</td>
						<td><code>{{ set.id }}</code></td>
						<td>
							<!--
								THREE STATES, NEVER TWO. "Not checked" is
								reported distinctly from "passes": 43 of the 46
								shipped sets declare none of the tokens this
								check reads, and calling those compliant is
								exactly the false clean bill of health this
								whole surface exists to avoid.
							-->
							<span v-if="!set.contrast.evaluated">
								{{ t('portaliq', 'not checked — this set declares none of the surface tokens') }}
							</span>
							<span v-else-if="set.contrast.passes">
								{{ t('portaliq', 'AA on {count} pair(s)', { count: set.contrast.measured }) }}
							</span>
							<span v-else class="portaliq-themes__fail">
								<span
									v-for="finding in set.contrast.findings"
									:key="finding.token"
									class="portaliq-themes__finding">
									{{ finding.token }} — {{ finding.ratio }}:1 {{ t('portaliq', 'on the') }}
									{{ finding.surface }} ({{ t('portaliq', 'AA wants') }}
									{{ finding.threshold }})
								</span>
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<p v-else class="portaliq-admin-settings__hint">
				{{ t('portaliq', 'No themes available — the theme app is not installed.') }}
			</p>
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'

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
			jwtSigningSecretConfigured: loadState(
				'portaliq',
				'jwtSigningSecretConfigured',
				false,
			),

			organisationInput: '',
			revoking: false,
			revokeResult: null,

			/** The adoptable token sets, each with its own contrast verdict. */
			themes: [],
			themesLoading: true,
			themeError: '',
		}
	},

	/**
	 * Load the theme catalogue.
	 *
	 * @return {Promise<void>} Resolves once the table can render.
	 */
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/portaliq/api/themes'))
			this.themes = data.sets || []
		} catch (error) {
			// SAID OUT LOUD. An empty table and a failed request look identical,
			// and one of them means every portal's theme is unverifiable.
			this.themeError = t('portaliq', 'Could not load the themes: {message}', {
				message: error.message,
			})
		} finally {
			this.themesLoading = false
		}
	},

	methods: {
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

.portaliq-themes {
	inline-size: 100%;
	border-collapse: collapse;
	margin-block-start: 12px;
}

.portaliq-themes th,
.portaliq-themes td {
	text-align: start;
	padding: 6px 8px;
	border-block-end: 1px solid var(--color-border);
	vertical-align: top;
}

.portaliq-themes th {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

/*
 * A FAILING VERDICT IS NOT SIGNALLED BY COLOUR ALONE (WCAG 1.4.1). Each
 * finding names its token, its measured ratio and the surface it fails on, so
 * the colour is emphasis on text that already carries the whole message.
 */
.portaliq-themes__fail {
	color: var(--color-error);
}

.portaliq-themes__finding {
	display: block;
}
</style>
