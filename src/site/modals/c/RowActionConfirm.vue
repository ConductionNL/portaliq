<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-rowaction"
		:aria-label="label"
		data-testid="rowaction-confirm">
		<h3 ref="heading" class="utrecht-heading-3" tabindex="-1">
			{{ label }}
		</h3>
		<p
			v-if="notice !== ''"
			class="utrecht-paragraph pq-rowaction__notice"
			role="status">
			{{ notice }}
		</p>
		<!-- The contribution's own confirmation words, never reworded. -->
		<p
			v-if="action.confirmText && message === ''"
			class="utrecht-paragraph pq-rowaction__confirm-text"
			data-testid="rowaction-confirm-text">
			{{ action.confirmText }}
		</p>
		<div
			v-for="input in shownInputs"
			:key="input.name"
			class="pq-rowaction__input">
			<label class="utrecht-form-label" :for="`rowaction-input-${input.name}`">{{
				input.label
			}}</label>
			<input
				:id="`rowaction-input-${input.name}`"
				v-model="values[input.name]"
				:type="input.type"
				class="utrecht-textbox utrecht-textbox--html-input"
				:required="input.required"
				:aria-required="input.required ? 'true' : undefined"
				:aria-invalid="errors[input.name] ? 'true' : undefined"
				:aria-describedby="errors[input.name] ? `rowaction-error-${input.name}` : undefined"
				:data-testid="`rowaction-input-${input.name}`" />
			<p
				v-if="errors[input.name]"
				:id="`rowaction-error-${input.name}`"
				class="utrecht-form-field-error-message"
				role="alert"
				:data-testid="`rowaction-error-${input.name}`">
				{{ errors[input.name] }}
			</p>
		</div>
		<div class="pq-rowaction__buttons">
			<button
				v-if="message === ''"
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="rowaction-continue"
				@click="confirm">
				{{ busy ? translate('Please wait…') : translate('Continue') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				:disabled="busy"
				@click="$emit('close')">
				{{ message === '' ? translate('Cancel') : translate('Close') }}
			</button>
		</div>
		<p
			class="utrecht-paragraph pq-rowaction__status"
			role="status"
			data-testid="rowaction-status">
			{{ message }}
		</p>
		<p v-if="link !== ''" class="pq-rowaction__link">
			<label class="utrecht-form-label" :for="`rowaction-link-${action.id}`">{{
				translate('Link')
			}}</label>
			<input
				:id="`rowaction-link-${action.id}`"
				type="text"
				class="utrecht-textbox utrecht-textbox--html-input utrecht-textbox--read-only"
				readonly
				:value="link"
				data-testid="rowaction-link"
				@focus="$event.target.select()" />
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				@click="copyLink">
				{{ translate('Copy link') }}
			</button>
		</p>
	</section>
</template>

<script>
import { rowInputsOf, rowNotice, runRowAction } from '../../../shared/rowAction.js'
import { translatorOr } from '../../components/c/forms.js'

/**
 * The browser navigation, kept apart so a caller or test can pass its own.
 *
 * @param {string} url An absolute https URL, already checked by redirectTarget.
 */
function goTo(url) {
	window.location.assign(url)
}

/**
 * The confirm step of an endpoint row action (the Vue port of
 * RowActionConfirm.jsx). The resident reads what they are about to do and the
 * row's notice before anything is sent. On confirm the row-scoped forward
 * runs, and the browser follows a checked redirect or the answer shows in one
 * line, with any link the action answered.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-endpoint-row-action-must-confirm-before-it-runs-req-srp-026
 */
export default {
	name: 'RowActionConfirm',

	props: {
		/** The resolved endpoint row action. */
		action: { type: Object, required: true },
		/** The collection the row belongs to. */
		collection: { type: Object, required: true },
		/** The row. */
		row: { type: Object, required: true },
		/** The portal api (`forwardRowAction`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/** Where a checked redirect goes; the browser by default. */
		navigate: { type: Function, default: goTo },
	},

	emits: ['done', 'close'],

	data() {
		return {
			busy: false,
			message: '',
			link: '',
			values: {},
			errors: {},
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		label() {
			return this.action.label || this.action.id
		},

		notice() {
			return rowNotice(this.collection, this.row)
		},

		/**
		 * @return {Array<object>} The inputs the row declares for this action.
		 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-inputs-its-row-declares-req-rai-002
		 */
		inputs() {
			return rowInputsOf(this.action, this.row)
		},

		/**
		 * @return {Array<object>} The inputs on screen: none once the action has run.
		 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-inputs-its-row-declares-req-rai-002
		 */
		shownInputs() {
			return this.message === '' ? this.inputs : []
		},
	},

	mounted() {
		if (this.$refs.heading) {
			this.$refs.heading.focus()
		}
	},

	methods: {
		/**
		 * Run the forward and handle its answer.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-endpoint-row-action-must-confirm-before-it-runs-req-srp-026
		 */
		async confirm() {
			this.busy = true
			this.errors = {}
			const answers = this.answers()
			const { redirect, messageKey, link, message, errors } =
				await runRowAction(
					this.api,
					this.collection,
					this.row,
					this.action,
					answers,
				)
			if (redirect) {
				this.navigate(redirect)
				return
			}
			this.busy = false
			// A refusal that names inputs shows under each and keeps the dialog
			// open (REQ-RAI-003).
			if (errors) {
				this.errors = this.said(errors)
				return
			}
			// The target's own sentence, else the contribution's, else ours.
			this.message =
				message
				|| (messageKey === 'Done.' ? this.action.successText : '')
				|| this.translate(messageKey)
			this.link = link || ''
			this.$emit('done')
		},

		/**
		 * What the resident typed, under the body key the action declares.
		 *
		 * @return {object} `{}` for an action without row inputs.
		 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-inputs-its-row-declares-req-rai-002
		 */
		answers() {
			const into = this.action.rowInputs?.into
			if (!into || this.inputs.length === 0) {
				return {}
			}
			const typed = {}
			for (const input of this.inputs) {
				const value = String(this.values[input.name] ?? '').trim()
				if (value !== '') {
					typed[input.name] = value
				}
			}
			return { [into]: typed }
		},

		/**
		 * The target's messages by input; the server's own `required` reads as
		 * a sentence.
		 *
		 * @param {object} errors Input name to message.
		 * @return {object} Input name to sentence.
		 */
		said(errors) {
			const out = {}
			for (const [name, text] of Object.entries(errors)) {
				out[name] = text === 'required' ? this.translate('This field is required.') : text
			}
			return out
		},

		copyLink() {
			if (typeof navigator !== 'undefined' && navigator.clipboard) {
				navigator.clipboard.writeText(this.link)
			}
		},
	},
}
</script>

<style scoped>
.pq-rowaction {
	margin-block: var(--utrecht-space-block-md, 1rem);
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-60, currentcolor);
}

.pq-rowaction__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-rowaction__status:empty {
	display: none;
}

.pq-rowaction__link {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
