<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-rowaction"
		:aria-label="label"
		data-testid="rowaction-confirm">
		<h3
			ref="heading"
			class="utrecht-heading-3"
			tabindex="-1">
			{{ label }}
		</h3>
		<p
			v-if="notice !== ''"
			class="utrecht-paragraph pq-rowaction__notice"
			role="status">
			{{ notice }}
		</p>
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
			<label class="utrecht-form-label" :for="`rowaction-link-${action.id}`">{{ translate('Link') }}</label>
			<input
				:id="`rowaction-link-${action.id}`"
				type="text"
				class="utrecht-textbox utrecht-textbox--html-input utrecht-textbox--read-only"
				readonly
				:value="link"
				data-testid="rowaction-link"
				@focus="$event.target.select()">
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
import { rowNotice, runRowAction } from '../../../shared/rowAction.js'
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
			const { redirect, messageKey, link } = await runRowAction(this.api, this.collection, this.row, this.action)
			if (redirect) {
				this.navigate(redirect)
				return
			}
			this.busy = false
			this.message = this.translate(messageKey)
			this.link = link || ''
			this.$emit('done')
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
	border: var(--utrecht-border-width-sm, 1px) solid var(--utrecht-color-grey-60, currentcolor);
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
