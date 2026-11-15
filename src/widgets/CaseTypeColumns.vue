<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  CaseTypeColumns: "Columns in My cases" for one case type in one portal
  (board PtZaaktypeWeergave). Per field the case apps show, the administrator
  picks how its value reads (text, link, QR code or date) and the words of a
  link or code, and sees what the resident sees. Saving writes the portal's
  caseTypeColumns for this case type through PortalCaseTypesController.

  @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
-->
<template>
	<section
		class="case-type-columns"
		:aria-labelledby="headingId"
		:data-testid="`case-type-columns-${caseType.typeId}`">
		<h3 :id="headingId" class="case-type-columns__title">
			{{ t('portaliq', 'Columns in My cases') }}
		</h3>
		<p>
			{{
				t(
					'portaliq',
					'Per column you choose how the value is shown. A link can be a QR code, with its own text under it.',
				)
			}}
		</p>

		<p v-if="rows.length === 0" class="case-type-columns__empty">
			{{ t('portaliq', 'The case apps show no columns in My cases yet.') }}
		</p>

		<div
			v-for="row in rows"
			:key="row.field"
			class="case-type-columns__row"
			data-testid="case-type-column">
			<NcTextField
				:modelValue="row.label"
				:label="t('portaliq', 'Field')"
				readonly />
			<NcSelect
				:modelValue="optionOf(row.render)"
				:options="options"
				:inputLabel="t('portaliq', 'Display')"
				:clearable="false"
				@update:modelValue="(option) => setRender(row, option)" />
			<NcTextField
				v-if="hasLinkLabel(row.render)"
				:modelValue="row.linkLabel"
				:label="t('portaliq', 'Text of the link')"
				:maxlength="60"
				@update:modelValue="(value) => setLinkLabel(row, value)" />
			<div
				v-if="hasLinkLabel(row.render)"
				class="case-type-columns__preview"
				data-testid="case-type-column-preview">
				<span class="case-type-columns__preview-title">{{
					t('portaliq', 'What the resident sees')
				}}</span>
				<CnQrCode
					v-if="row.render === 'qr'"
					:value="previewValue"
					:label="row.linkLabel || row.label"
					:size="96" />
				<span class="case-type-columns__preview-link">{{
					row.linkLabel || row.label
				}}</span>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>

		<NcButton
			v-if="rows.length > 0"
			:disabled="saving"
			data-testid="case-type-columns-save"
			@click="save">
			{{ t('portaliq', 'Save columns') }}
		</NcButton>
	</section>
</template>

<script>
import { CnQrCode } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	COLUMN_RENDERS,
	columnsToSave,
	columnsUrl,
	editableColumns,
	hasLinkLabel,
} from '../lib/caseTypeColumns.js'

export default {
	name: 'CaseTypeColumns',

	components: { CnQrCode, NcButton, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** The portal slug. */
		slug: { type: String, required: true },
		/** The case type: `typeId` and `label`. */
		caseType: { type: Object, required: true },
		/** The fields the case apps show: `field` and `label`. */
		fields: { type: Array, default: () => [] },
		/** The case type's stored columns. */
		stored: { type: Array, default: () => [] },
	},

	emits: ['saved'],

	data() {
		return {
			rows: editableColumns(this.fields, this.stored),
			saving: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * The id of the section heading.
		 *
		 * @return {string}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
		 */
		headingId() {
			return `case-type-columns-title-${this.caseType.typeId}`
		},

		/**
		 * The display choices, the case app's own first.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
		 */
		options() {
			const labels = {
				text: t('portaliq', 'Text'),
				link: t('portaliq', 'Link'),
				qr: t('portaliq', 'QR code'),
				date: t('portaliq', 'Date'),
			}
			return [
				{ id: '', label: t('portaliq', 'As the case app shows it') },
				...COLUMN_RENDERS.map((id) => ({ id, label: labels[id] })),
			]
		},

		/**
		 * The address the preview code draws: this portal's My cases page.
		 *
		 * @return {string}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
		 */
		previewValue() {
			return `${window.location.origin}/mijn/zaken`
		},
	},

	methods: {
		hasLinkLabel,

		/**
		 * The choice of a render kind.
		 *
		 * @param {string} render The render kind.
		 * @return {object}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
		 */
		optionOf(render) {
			return this.options.find((option) => option.id === render) || this.options[0]
		},

		/**
		 * Choose how a column reads.
		 *
		 * @param {object} row The column.
		 * @param {object|null} option The chosen display.
		 * @return {void}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
		 */
		setRender(row, option) {
			row.render = option?.id || ''
			this.notice = ''
		},

		/**
		 * Set a column's link text.
		 *
		 * @param {object} row The column.
		 * @param {string} value The words.
		 * @return {void}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
		 */
		setLinkLabel(row, value) {
			row.linkLabel = String(value || '')
			this.notice = ''
		},

		/**
		 * Save this case type's columns.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const url = columnsUrl({ slug: this.slug, typeId: this.caseType.typeId }, generateUrl)
				const { data } = await axios.put(url, { columns: columnsToSave(this.rows) })
				this.$emit('saved', data.columns || {})
				this.notice = t('portaliq', 'The columns are saved.')
			} catch {
				this.error = t('portaliq', 'The columns could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-columns {
	padding: calc(var(--default-grid-baseline) * 2) 0;
}

.case-type-columns__title {
	margin-top: 0;
}

.case-type-columns__row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.case-type-columns__preview {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.case-type-columns__preview-title {
	color: var(--color-text-maxcontrast);
}

.case-type-columns__preview-link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
