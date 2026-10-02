<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-rowaction pq-signing"
		:aria-label="label"
		data-testid="signing-dialog">
		<h3 ref="heading" class="utrecht-heading-3" tabindex="-1">
			{{ label }}{{ documentName ? `: ${documentName}` : '' }}
		</h3>

		<p v-if="view.state === 'loading'" class="utrecht-paragraph" role="status">
			{{ translate('Loading the document…') }}
		</p>

		<p
			v-if="view.state === 'unavailable'"
			class="utrecht-paragraph pq-rowaction__notice"
			data-testid="signing-unavailable">
			{{ translate('This document cannot be shown here.') }}
		</p>

		<div v-if="view.state === 'shown'" class="pq-signing__document">
			<object
				v-if="view.inline"
				:data="view.href"
				:type="view.mimeType"
				class="pq-signing__preview"
				:aria-label="documentName">
				<p class="utrecht-paragraph">
					{{ translate('This document cannot be shown here.') }}
				</p>
			</object>
			<p v-else class="utrecht-paragraph pq-rowaction__notice">
				{{
					translate(
						'This document is too large to show here. Download it to read it.',
					)
				}}
			</p>
			<p class="utrecht-paragraph">
				<a
					class="utrecht-link"
					:href="view.href"
					:download="view.name"
					data-testid="signing-download">
					{{
						translate('Download {documentName}', {
							documentName: view.name,
						})
					}}
				</a>
			</p>
		</div>

		<p v-if="view.state === 'shown' && !done" class="pq-signing__read">
			<input
				:id="`signing-read-${rowId}`"
				v-model="read"
				type="checkbox"
				class="utrecht-checkbox utrecht-checkbox--html-input"
				data-testid="signing-read" />
			<label
				class="utrecht-form-label utrecht-form-label--checkbox"
				:for="`signing-read-${rowId}`">
				{{ translate('I have read this document and I sign it.') }}
			</label>
		</p>

		<div class="pq-rowaction__buttons">
			<button
				v-if="view.state === 'shown' && !done"
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="signing-submit"
				:disabled="!read || busy"
				@click="sign">
				{{ busy ? translate('Please wait…') : label }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				:disabled="busy"
				@click="$emit('close')">
				{{ done ? translate('Close') : translate('Cancel') }}
			</button>
		</div>

		<p
			class="utrecht-paragraph pq-rowaction__status"
			role="status"
			data-testid="signing-status">
			{{ message ? translate(message.key, message.vars) : '' }}
		</p>
	</section>
</template>

<script>
import { documentView, outcome } from '../../../shared/signing.js'
import { rowIdOf, translatorOr } from '../../components/c/forms.js'

import '@utrecht/checkbox-css/dist/index.css'

/**
 * Sign a document from its row (the Vue port of SigningDialog.jsx). The
 * document comes through the contribution's `viewDocument` row action and
 * shows with a download link. Signing stays disabled until the resident ticks
 * that they read it; only then is `{consent: true}` forwarded. Without a
 * `viewDocument` action there is nothing to read, so nothing to sign.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-document-must-be-signable-and-declinable-from-its-row-req-srp-029
 */
export default {
	name: 'SigningDialog',

	props: {
		/** The resolved `sign` row action. */
		action: { type: Object, required: true },
		/** The resolved `viewDocument` row action, or null. */
		viewAction: { type: Object, default: null },
		/** The collection the row belongs to. */
		collection: { type: Object, required: true },
		/** The row. */
		row: { type: Object, required: true },
		/** The portal api (`forwardRowAction`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/** A document view to show without fetching (test seam). */
		initialDocument: { type: Object, default: null },
	},

	emits: ['done', 'close'],

	data() {
		let view = { state: 'unavailable' }
		if (this.initialDocument) {
			view = this.initialDocument
		} else if (this.viewAction) {
			view = { state: 'loading' }
		}
		return {
			view,
			read: false,
			busy: false,
			message: null,
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		rowId() {
			return rowIdOf(this.row)
		},

		label() {
			return this.translate(this.action.label || 'Sign')
		},

		documentName() {
			return (
				(this.view.state === 'shown' && this.view.name)
				|| (this.row && this.row.documentName)
				|| ''
			)
		},

		done() {
			return this.message !== null && this.message.done === true
		},
	},

	async mounted() {
		if (this.$refs.heading) {
			this.$refs.heading.focus()
		}
		if (this.initialDocument || !this.viewAction || !this.rowId) {
			return
		}
		const result = await this.api.forwardRowAction(
			this.collection,
			this.rowId,
			this.viewAction.id,
		)
		this.view = documentView(result)
	},

	methods: {
		/**
		 * Send the signature and show the answer.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-document-must-be-signable-and-declinable-from-its-row-req-srp-029
		 */
		async sign() {
			this.busy = true
			const result = await this.api.forwardRowAction(
				this.collection,
				this.rowId,
				this.action.id,
				{ consent: true },
			)
			const answer = outcome('sign', result, this.documentName)
			this.busy = false
			this.message = answer
			if (answer.done) {
				this.$emit('done')
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

.pq-signing__preview {
	inline-size: 100%;
	min-block-size: 24rem;
}

.pq-signing__read {
	display: flex;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
