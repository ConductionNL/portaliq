<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PortalCaseTypes: which case types residents see in one portal, as a widget
  on the portal's own page.

  Lists the case types the portal can name (its published request forms, a
  case app's declared caseTypeSource, and whatever it already hides), each
  with a "Show in this portal" switch. Saving writes the portal's
  hiddenCaseTypes through PortalCaseTypesController, which is admin-only.
  Nothing about a case changes: a hidden type's cases leave the resident's
  list in this portal and come back when the type is shown again.

  @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
-->
<template>
	<div class="case-types" data-testid="portal-case-types">
		<p class="case-types__intro">
			{{
				t('portaliq', 'Choose which case types residents see in {portal}.', {
					portal: portalTitle,
				})
			}}
		</p>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<p v-if="rows.length === 0" class="case-types__empty">
				{{
					t(
						'portaliq',
						'This portal names no case types yet. Publish a request form, or ask the case app to declare its case types.',
					)
				}}
			</p>

			<ul v-else class="case-types__list">
				<li
					v-for="row in rows"
					:key="row.typeId"
					class="case-types__row"
					:data-testid="`case-type-${row.typeId}`">
					<span
						:id="`case-type-label-${row.typeId}`"
						class="case-types__label"
						>{{ row.label }}</span
					>
					<NcCheckboxRadioSwitch
						:modelValue="row.shown"
						type="switch"
						:aria-describedby="`case-type-label-${row.typeId}`"
						@update:modelValue="(value) => setShown(row, value)">
						{{ t('portaliq', 'Show in this portal') }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>

			<NcNoteCard v-if="warn" type="warning" data-testid="case-types-warning">
				{{
					t(
						'portaliq',
						'Residents with a case of this type will no longer see it here.',
					)
				}}
			</NcNoteCard>

			<NcNoteCard v-if="notice" type="success">
				{{ notice }}
			</NcNoteCard>

			<NcButton
				variant="primary"
				:disabled="saving || !dirty"
				data-testid="case-types-save"
				@click="save">
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
import { caseTypesUrl, hiddenFrom, hidesMore } from '../lib/caseTypeVisibility.js'

export default {
	name: 'PortalCaseTypes',

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
			saved: [],
			rows: [],
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
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},

		/**
		 * The portal's name, for the intro line.
		 *
		 * @return {string}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		portalTitle() {
			return String(this.objectData?.title || this.slug)
		},

		/**
		 * Whether the switches differ from what is stored.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		dirty() {
			return (
				JSON.stringify(hiddenFrom(this.rows))
				!== JSON.stringify(hiddenFrom(this.saved))
			)
		},

		/**
		 * Whether saving would hide a type residents see now.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		warn() {
			return hidesMore(this.saved, this.rows)
		},
	},

	watch: {
		/**
		 * Load again when the detail page hands over another portal.
		 *
		 * @return {void}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
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
		 * Read the portal's case types.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		async load() {
			this.loading = true
			this.error = ''
			if (this.slug === '') {
				this.loading = false
				return
			}
			try {
				const { data } = await axios.get(
					caseTypesUrl(this.slug, generateUrl),
				)
				this.accept(data.caseTypes || [])
			} catch {
				this.error = t(
					'portaliq',
					'The case types of this portal could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take a list from the server as the stored state.
		 *
		 * @param {Array<object>} caseTypes The listed case types.
		 * @return {void}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		accept(caseTypes) {
			this.saved = caseTypes.map((row) => ({ ...row }))
			this.rows = caseTypes.map((row) => ({ ...row }))
		},

		/**
		 * Flip one switch.
		 *
		 * @param {object} row The case type.
		 * @param {boolean} value Shown or not.
		 * @return {void}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		setShown(row, value) {
			row.shown = Boolean(value)
			this.notice = ''
		},

		/**
		 * Save the hidden list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(
					caseTypesUrl(this.slug, generateUrl),
					{
						hiddenCaseTypes: hiddenFrom(this.rows),
					},
				)
				this.accept(data.caseTypes || [])
				this.notice = t('portaliq', 'Your choices are saved.')
			} catch {
				this.error = t(
					'portaliq',
					'Your choices could not be saved. Try again.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-types {
	padding: calc(var(--default-grid-baseline) * 2);
}

.case-types__list {
	list-style: none;
	padding: 0;
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.case-types__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.case-types__label {
	font-weight: bold;
}
</style>
