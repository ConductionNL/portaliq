<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  MailTemplateDialog: the text of one kind of mail for one portal (the
  PtMailsjablonen board). The text is written in CnMarkdownEditor, whose menu
  inserts the variables the kind declares and which names an unknown one
  under the text. Save, Preview and Send test mail go through the actions of
  src/lib/mailTemplateEditing.js; a refusal shows here, where the admin can
  correct it. It closes with the saved message, or with nothing.

  @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-an-admin-can-edit-the-text-of-each-portal-mail-req-pmt-001
-->
<template>
	<CnDialog
		:look="look"
		:name="title"
		:eyebrow="t('portaliq', 'Mail templates')"
		size="large"
		data-testid="mail-template-dialog"
		@closing="$emit('close', null)">
		<template v-if="fields.id === ''">
			<NcSelect
				v-model="kindChoice"
				:options="kindOptions"
				:inputLabel="t('portaliq', 'Kind of mail')"
				:clearable="false"
				data-testid="mail-template-kind" />
			<NcSelect
				v-model="portalChoice"
				:options="portalOptions"
				:inputLabel="t('portaliq', 'Portal')"
				:clearable="false"
				data-testid="mail-template-portal" />
		</template>
		<NcTextField
			v-model="fields.subject"
			:label="t('portaliq', 'Subject line')"
			data-testid="mail-template-subject" />
		<CnMarkdownEditor
			v-model="fields.body"
			:ariaLabel="t('portaliq', 'Text')"
			:variables="variables"
			:insertVariableText="t('portaliq', 'Insert variable')"
			:unknownVariableText="t('portaliq', 'Unknown variable: {names}. Not saved.')"
			:hint="allowedLine"
			:rows="10"
			data-testid="mail-template-body"
			@unknownVariables="unknown = $event" />
		<NcCheckboxRadioSwitch
			:modelValue="fields.active"
			type="switch"
			data-testid="mail-template-active"
			@update:modelValue="fields.active = $event">
			{{ t('portaliq', 'Active') }}
		</NcCheckboxRadioSwitch>
		<NcNoteCard type="info">
			{{ t('portaliq', 'The mail only names what kind of change it is and links to the case. What the case or its documents hold does not belong in it; the resident reads that after signing in.') }}
		</NcNoteCard>
		<section v-if="preview" class="mail-template__preview" data-testid="mail-template-preview">
			<h3>{{ preview.subject }}</h3>
			<p>{{ preview.body }}</p>
		</section>
		<p v-if="notice" :class="noticeClass" role="alert" data-testid="mail-template-notice">
			{{ notice }}
		</p>

		<template #actions>
			<NcButton :disabled="busy" data-testid="mail-template-test" @click="run('sendTest')">
				{{ t('portaliq', 'Send test mail') }}
			</NcButton>
			<NcButton :disabled="busy" data-testid="mail-template-show-preview" @click="run('preview')">
				{{ t('portaliq', 'Preview') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || unknown.length > 0"
				data-testid="mail-template-save"
				@click="run('submit')">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { variablesOf } from '../lib/mailTemplateEditing.js'

export default {
	name: 'MailTemplateDialog',

	components: {
		CnDialog,
		CnMarkdownEditor,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: { type: String, default: undefined },
		/** The fields of the text, from formFromRow(). */
		form: { type: Object, required: true },
		/** The kinds of mail with their variables. */
		kinds: { type: Array, default: () => [] },
		/** The portals a new text can belong to. */
		portals: { type: Array, default: () => [] },
		/** submit, preview and sendTest from createEditorActions(). */
		actions: { type: Object, required: true },
	},

	emits: ['close'],

	data() {
		return {
			fields: { ...this.form },
			unknown: [],
			preview: null,
			notice: '',
			failed: false,
			busy: false,
		}
	},

	computed: {
		/** @return {object|undefined} The kind of mail being edited. */
		kind() {
			return this.kinds.find((kind) => kind.key === this.fields.templateKey)
		},

		/** @return {string} The dialog's title, with the kind's name. */
		title() {
			return this.kind
				? t('portaliq', 'Edit template "{kind}"', { kind: t('portaliq', this.kind.label) })
				: t('portaliq', 'New mail text')
		},

		/** @return {Array<{name: string, label: string}>} The kind's variables. */
		variables() {
			return variablesOf(this.kind)
		},

		/** @return {string} The variables this kind allows, under the text. */
		allowedLine() {
			const names = this.variables.map((variable) => variable.label).join(', ')
			return names === '' ? '' : t('portaliq', 'Allowed in this template: {names}.', { names })
		},

		/** @return {Array<{id: string, label: string}>} The kinds as options. */
		kindOptions() {
			return this.kinds.map((kind) => ({ id: kind.key, label: t('portaliq', kind.label) }))
		},

		/** @return {Array<{id: string, label: string}>} The portals as options. */
		portalOptions() {
			return this.portals.map((portal) => ({ id: portal.slug, label: portal.title || portal.slug }))
		},

		kindChoice: {
			get() {
				return this.kindOptions.find((option) => option.id === this.fields.templateKey) || null
			},

			set(option) {
				this.fields.templateKey = option?.id || ''
			},
		},

		portalChoice: {
			get() {
				return this.portalOptions.find((option) => option.id === this.fields.portal) || null
			},

			set(option) {
				this.fields.portal = option?.id || ''
			},
		},

		/** @return {string} The class of the line under the text. */
		noticeClass() {
			return this.failed ? 'mail-template__refusal' : 'mail-template__done'
		},
	},

	methods: {
		t,

		/**
		 * Run one of the editor's actions; a saved text closes the dialog.
		 *
		 * @param {string} name submit, preview or sendTest.
		 * @return {Promise<void>}
		 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md#requirement-a-template-accepts-only-its-own-variables-and-no-record-content-req-pmt-002
		 */
		async run(name) {
			this.busy = true
			const outcome = await this.actions[name]({ ...this.fields })
			this.busy = false
			if (outcome.ok && name === 'submit') {
				this.$emit('close', outcome.message)
				return
			}
			this.failed = !outcome.ok
			this.notice = outcome.message
			this.preview = outcome.preview || this.preview
		},
	},
}
</script>

<style scoped>
.mail-template__preview {
	margin-top: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	white-space: pre-line;
}

.mail-template__refusal {
	margin-top: 8px;
	color: var(--color-error-text);
}

.mail-template__done {
	margin-top: 8px;
	color: var(--color-success-text);
}
</style>
