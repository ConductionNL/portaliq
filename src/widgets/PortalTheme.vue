<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalTheme: which house style one portal wears, as a widget on the
  portal's own page (nldesign-theme-integration 1.4, 3.1, 3.2).

  Lists the token sets the theme app offers that this portal can render, each
  with its contrast verdict on the surfaces a portal paints. Choosing a set
  that fails AA asks for a confirmation that names the failing tokens; a set
  nothing measured reads "Not checked", never as a pass. Saves through
  PortalThemeController, which is admin-only.

  @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
-->
<template>
	<div class="portal-theme" data-testid="portal-theme">
		<NcLoadingIcon v-if="state === 'loading'" />
		<NcNoteCard v-else-if="state === 'error'" type="error">
			{{ t('portaliq', 'The house styles could not be loaded.') }}
		</NcNoteCard>
		<template v-else>
			<p v-if="current && !currentResolves" class="portal-theme__stale">
				{{
					t(
						'portaliq',
						'This portal names {theme}, which the theme app does not offer, so it shows without a house style.',
						{ theme: current },
					)
				}}
			</p>
			<p v-if="sets.length === 0" class="portal-theme__empty">
				{{
					t(
						'portaliq',
						'The theme app offers no house styles. Install and enable it to choose one.',
					)
				}}
			</p>
			<fieldset v-else class="portal-theme__sets">
				<legend>{{ t('portaliq', 'House style') }}</legend>
				<div
					v-for="set in sets"
					:key="set.id"
					class="portal-theme__set"
					:data-testid="`portal-theme-${set.id}`">
					<NcCheckboxRadioSwitch
						:modelValue="chosen"
						:value="set.id"
						:disabled="!isSelectable(set)"
						type="radio"
						name="portal-theme"
						@update:modelValue="choose">
						{{ set.name }}
					</NcCheckboxRadioSwitch>
					<span
						v-if="!isSelectable(set)"
						class="portal-theme__verdict portal-theme__verdict--fails"
						:data-testid="`portal-theme-refusal-${set.id}`">
						{{
							t('portaliq', 'Refused by the theme app: {reason}', {
								reason: set.refusal,
							})
						}}
					</span>
					<span
						v-else
						class="portal-theme__verdict"
						:class="`portal-theme__verdict--${verdictState(set.verdict)}`">
						{{ verdictText(set.verdict) }}
					</span>
				</div>
			</fieldset>
			<NcNoteCard
				v-if="findings.length > 0"
				type="warning"
				data-testid="portal-theme-findings">
				<p>
					{{
						t(
							'portaliq',
							'This house style has text that is hard to read on this portal:',
						)
					}}
				</p>
				<ul>
					<li
						v-for="finding in findings"
						:key="finding.surface + finding.token">
						{{ findingText(finding) }}
					</li>
				</ul>
				<NcButton variant="secondary" :disabled="saving" @click="save(true)">
					{{ t('portaliq', 'Use it anyway') }}
				</NcButton>
			</NcNoteCard>
			<NcNoteCard v-if="notice" :type="noticeType">
				{{ notice }}
			</NcNoteCard>
			<NcButton
				v-if="sets.length > 0"
				variant="primary"
				:disabled="saving || !chosen || chosen === current"
				data-testid="portal-theme-save"
				@click="save(false)">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import {
	createPortalThemeChoice,
	isSelectable,
	verdictState,
} from '../lib/portalThemeChoice.js'

export default {
	name: 'PortalTheme',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
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
			state: 'loading',
			current: '',
			currentResolves: false,
			sets: [],
			chosen: '',
			findings: [],
			saving: false,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		/**
		 * The portal's slug.
		 *
		 * @return {string}
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},
	},

	/**
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	created() {
		this.api = createPortalThemeChoice({
			get: (url) => axios.get(url),
			put: (url, body) => axios.put(url, body),
			url: (path, params) => generateUrl('/apps/portaliq' + path, params),
		})
		this.load()
	},

	methods: {
		isSelectable,
		verdictState,

		/**
		 * @param {object} verdict The set's verdict.
		 * @return {string}
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		verdictText(verdict) {
			const state = verdictState(verdict)
			if (state === 'passes') {
				return t('portaliq', 'Readable')
			}
			if (state === 'fails') {
				return t('portaliq', 'Hard to read')
			}
			return t('portaliq', 'Not checked')
		},

		/**
		 * One failing token, in words.
		 *
		 * @param {object} finding `{surface, token, ratio, threshold}`.
		 * @return {string}
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		findingText(finding) {
			const surface =
				finding.surface === 'footer'
					? t('portaliq', 'footer')
					: t('portaliq', 'page background')
			return t(
				'portaliq',
				'{token} on the {surface} is {ratio}:1, and needs {threshold}:1.',
				{
					token: finding.token,
					surface,
					ratio: finding.ratio,
					threshold: finding.threshold,
				},
			)
		},

		/**
		 * Read the sets and the current choice.
		 *
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		async load() {
			const result = await this.api.load(this.slug)
			this.state = result.state
			this.current = result.current
			this.currentResolves = result.currentResolves
			this.sets = result.sets
			this.chosen = result.currentResolves ? result.current : ''
		},

		/**
		 * @param {string} id The set id.
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		choose(id) {
			this.chosen = id
			this.findings = []
			this.notice = ''
		},

		/**
		 * Save the chosen set.
		 *
		 * @param {boolean} acceptFindings Whether the administrator confirmed failing contrast.
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		async save(acceptFindings) {
			this.saving = true
			const result = await this.api.save(
				this.slug,
				this.chosen,
				acceptFindings,
			)
			this.saving = false
			this.findings = []
			if (result.outcome === 'contrast') {
				this.findings = Array.isArray(result.verdict.findings)
					? result.verdict.findings
					: []
				this.notice = ''
				return
			}
			if (result.outcome !== 'saved') {
				this.noticeType = 'error'
				this.notice =
					result.outcome === 'refused'
						? t(
								'portaliq',
								'The theme app refused this house style: {reason}',
								{
									reason: result.refusal,
								},
							)
						: result.outcome === 'unknown'
							? t(
									'portaliq',
									'The theme app no longer offers this house style.',
								)
							: t('portaliq', 'The house style could not be saved.')
				return
			}
			this.noticeType = 'success'
			this.notice = t('portaliq', 'The house style is saved.')
			this.current = String(result.data?.current || this.chosen)
			this.currentResolves = true
		},
	},
}
</script>

<style scoped>
.portal-theme__sets {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-block: 8px;
}

.portal-theme__set {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.portal-theme__verdict {
	color: var(--color-text-maxcontrast);
}

.portal-theme__verdict--fails {
	color: var(--color-error-text);
}

.portal-theme__verdict--passes {
	color: var(--color-success-text);
}
</style>
