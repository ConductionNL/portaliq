<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Een nieuw plan starten" (shared-plans-with-a-caseworker, board Plannen):
	an empty plan or a template the municipality set up, a name, and the
	resident's approved contacts to take part. Nothing is sent until "Plan
	starten"; a refusal is told in words and the dialog stays open.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-plan-start"
		aria-labelledby="pq-plan-start-title"
		data-testid="plan-start"
		@cancel.prevent="$emit('cancel')">
		<form method="dialog" novalidate @submit.prevent="submit">
			<h2
				id="pq-plan-start-title"
				ref="heading"
				class="utrecht-heading-3"
				tabindex="-1">
				{{ words.startTitle }}
			</h2>
			<fieldset class="utrecht-form-fieldset">
				<legend class="utrecht-form-label">
					{{ words.startWhere }}
				</legend>
				<label class="pq-plan-start__choice">
					<input
						v-model="templateId"
						type="radio"
						value=""
						data-testid="plan-start-empty" />
					{{ words.emptyPlan }}
				</label>
				<label
					v-for="template in templates"
					:key="template.id"
					class="pq-plan-start__choice">
					<input
						v-model="templateId"
						type="radio"
						:value="template.id"
						data-testid="plan-start-template" />
					<strong>{{ template.title }}</strong>
					<span v-if="template.summary">{{ template.summary }}</span>
				</label>
			</fieldset>
			<div v-if="templateId === ''" class="utrecht-form-field">
				<label for="pq-plan-start-name" class="utrecht-form-label">
					{{ words.planName }}
				</label>
				<input
					id="pq-plan-start-name"
					v-model="title"
					class="utrecht-textbox"
					type="text"
					maxlength="200"
					data-testid="plan-start-name" />
			</div>
			<fieldset class="utrecht-form-fieldset">
				<legend class="utrecht-form-label">
					{{ words.withWhom }}
				</legend>
				<p v-if="contacts.length === 0" class="utrecht-paragraph">
					{{ words.noContacts }}
				</p>
				<label
					v-for="contact in contacts"
					:key="contact.id"
					class="pq-plan-start__choice">
					<input
						v-model="chosen"
						type="checkbox"
						:value="contact.id"
						data-testid="plan-start-contact" />
					{{ contact.displayName }}
				</label>
			</fieldset>
			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-plan-start__error"
				role="alert"
				data-testid="plan-start-problem">
				{{ problem }}
			</p>
			<div class="pq-plan-start__buttons">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy"
					data-testid="plan-start-submit">
					{{ words.start }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="plan-start-cancel"
					@click="$emit('cancel')">
					{{ words.cancel }}
				</button>
			</div>
		</form>
	</dialog>
</template>

<script>
import { contactsApi, plansApi } from '../../../shared/areaApi.js'
import { planWords } from '../../lib/plans.js'

/**
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */
export default {
	name: 'PlanStartModal',

	props: {
		/** The portal api (`fetchPlanTemplates`, `fetchContacts`, `planAction`). */
		api: { type: Object, required: true },
		/** The language, `nl` or `en`; the page's when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['started', 'cancel'],

	data() {
		return {
			templates: [],
			contacts: [],
			templateId: '',
			title: '',
			chosen: [],
			problem: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the page language.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		words() {
			return planWords(
				this.locale || globalThis.document?.documentElement?.lang || '',
			)
		},
	},

	/**
	 * Open as a modal and read the templates and the approved contacts.
	 *
	 * @return {Promise<void>} Resolves when read.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	async mounted() {
		const dialog = this.$refs.dialog
		if (typeof dialog?.showModal === 'function') {
			dialog.showModal()
		} else {
			dialog?.setAttribute('open', '')
		}
		this.$refs.heading?.focus()
		const [templates, overview] = await Promise.all([
			plansApi(this.api).fetchPlanTemplates(),
			contactsApi(this.api).fetchContacts(),
		])
		this.templates = templates
		this.contacts = Array.isArray(overview?.contacts) ? overview.contacts : []
	},

	/**
	 * Close the native dialog when the component goes.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	beforeUnmount() {
		if (
			this.$refs.dialog?.open
			&& typeof this.$refs.dialog.close === 'function'
		) {
			this.$refs.dialog.close()
		}
	},

	methods: {
		/**
		 * Start the plan. A refusal is told in words and the dialog stays open.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		async submit() {
			this.problem = ''
			this.busy = true
			const answer = await plansApi(this.api).planAction('start', {
				data: {
					templateId: this.templateId,
					title: this.title.trim(),
					contactIds: this.chosen,
				},
			})
			this.busy = false
			if (!answer.ok) {
				this.problem = this.words.failed
				return
			}
			this.$emit('started', answer.id)
		},
	},
}
</script>

<style scoped>
.pq-plan-start {
	max-inline-size: min(40rem, calc(100vw - 2rem));
	padding: var(--utrecht-space-block-lg, 1.5rem);
	color: var(--utrecht-document-color, inherit);
	background: var(--utrecht-document-background-color, Canvas);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
}

.pq-plan-start form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-plan-start__choice {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin-block: var(--utrecht-space-block-xs, 0.25rem);
}

.pq-plan-start input[type='text'] {
	inline-size: 100%;
}

.pq-plan-start__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-plan-start__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
