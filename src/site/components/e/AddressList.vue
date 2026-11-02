<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One kind of contact address on "My account" (identity-profile-page T07):
	the list with the preferred one marked, and a form to add one. Ported from
	AddressList in the React portal's AccountPage.jsx. An e-mail address is
	used for nothing until its confirmation link is followed.
-->
<template>
	<section class="pq-account__addresses" :aria-labelledby="headingId">
		<div class="pq-address__head">
			<h2 :id="headingId" class="utrecht-heading-3">
				{{ email ? t('E-mail addresses') : t('Phone numbers') }}
			</h2>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action pq-address__open"
				:aria-expanded="adding ? 'true' : 'false'"
				:aria-controls="formId"
				:data-testid="`address-open-${kind}`"
				@click="toggle">
				<svg
					class="pq-button-icon"
					viewBox="0 0 24 24"
					aria-hidden="true"
					focusable="false">
					<path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z" />
				</svg>
				{{ email ? t('Add e-mail address') : t('Add phone number') }}
			</button>
		</div>
		<p v-if="entries.length === 0" class="utrecht-paragraph">
			{{
				email
					? t('You have no e-mail address on your account.')
					: t('You have no phone number on your account.')
			}}
		</p>
		<ul v-else class="pq-address__list">
			<li
				v-for="entry in entries"
				:key="entry.value"
				class="pq-address__row"
				:data-testid="`address-${kind}`">
				<NoTranslate class="pq-address__value" :value="entry.value" />
				<span
					v-if="entry.preferred"
					class="pq-address__pill pq-address__pill--preferred">
					{{ t('Preferred') }}
				</span>
				<span
					v-if="email && !entry.confirmed"
					class="pq-address__pill pq-address__pill--waiting">
					{{ t('Waiting for confirmation') }}
				</span>
				<span class="pq-address__actions">
					<button
						v-if="email && !entry.confirmed"
						type="button"
						class="pq-address__action"
						@click="act('add', kind, entry.value)">
						{{ t('Send the link again') }}
					</button>
					<button
						v-if="!entry.preferred && (!email || entry.confirmed)"
						type="button"
						class="pq-address__action"
						@click="act('prefer', kind, entry.value)">
						{{ t('Make preferred') }}
					</button>
					<button
						type="button"
						class="pq-address__action"
						@click="act('remove', kind, entry.value)">
						{{ t('Remove') }}
					</button>
				</span>
			</li>
		</ul>
		<form
			v-if="adding"
			:id="formId"
			class="pq-account__add"
			:data-testid="`address-form-${kind}`"
			@submit.prevent="add">
			<label :for="inputId" class="utrecht-form-label">
				{{ email ? t('New e-mail address') : t('New phone number') }}
			</label>
			<input
				:id="inputId"
				ref="input"
				v-model="draft"
				class="utrecht-textbox"
				:type="email ? 'email' : 'tel'"
				:autocomplete="email ? 'email' : 'tel'"
				required />
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action">
				{{ t('Save') }}
			</button>
		</form>
	</section>
</template>

<script>
import NoTranslate from '../NoTranslate.vue'

export default {
	name: 'AddressList',

	components: { NoTranslate },

	props: {
		/** `email` or `phone`. */
		kind: {
			type: String,
			required: true,
		},

		/** The addresses of this kind: `{value, preferred, confirmed}`. */
		entries: {
			type: Array,
			default: () => [],
		},

		/** The translator `t(key, vars)`. */
		t: {
			type: Function,
			required: true,
		},

		/** Runs an address action `(action, kind, value)` and resolves to whether it worked. */
		act: {
			type: Function,
			required: true,
		},
	},

	data() {
		return { draft: '', adding: false }
	},

	computed: {
		email() {
			return this.kind === 'email'
		},

		headingId() {
			return `pq-account-${this.kind}`
		},

		inputId() {
			return `pq-account-add-${this.kind}`
		},

		/**
		 * The id of the add form, for the open button's aria-controls.
		 *
		 * @spec openspec/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 * @return {string} The form id.
		 */
		formId() {
			return `pq-account-add-form-${this.kind}`
		},
	},

	methods: {
		/**
		 * Show or hide the form to add an address; once shown, the focus
		 * goes to its field.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		toggle() {
			this.adding = !this.adding
			if (this.adding) {
				this.$nextTick(() => this.$refs.input?.focus?.())
			}
		},

		/**
		 * Add the typed address; the field empties once it worked.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		async add() {
			const ok = await this.act('add', this.kind, this.draft)
			if (ok) {
				this.draft = ''
				this.adding = false
			}
		},
	},
}
</script>

<style scoped>
.pq-account__addresses {
	margin-block-end: var(--utrecht-space-block-xl, 2rem);
}

/* The heading with its "+ add" button at the end of the same line. */
.pq-address__head {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	justify-content: space-between;
	margin-block-end: var(--utrecht-space-block-sm, 0.75rem);
}

.pq-address__head .utrecht-heading-3 {
	margin-block: 0;
}

.pq-address__list {
	display: grid;
	gap: var(--utrecht-space-block-xs, 0.5rem);
	list-style: none;
	margin: 0 0 var(--utrecht-space-block-sm, 0.75rem);
	padding: 0;
}

/* Each address a row on a hairline card: the address, its state as a
   pill, then what can be done with it as links. */
.pq-address__row {
	align-items: center;
	border: 1px solid var(--nldesign-color-border, #d3d8df);
	border-radius: var(--nldesign-website-border-radius, 4px);
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem) var(--utrecht-space-inline-md, 1rem);
	padding: 0.75rem 1rem;
}

.pq-address__value {
	font-weight: var(--utrecht-typography-weight-scale-bold, 600);
	margin-inline-end: auto;
}

.pq-address__pill {
	border-radius: var(--nldesign-website-badge-border-radius, 14px);
	font-size: 0.8125rem;
	font-weight: var(--utrecht-typography-weight-scale-bold, 600);
	line-height: 1.5rem;
	padding-inline: 0.625rem;
}

.pq-address__pill--preferred {
	background: var(
		--nldesign-component-status-badge-success-background-color,
		#e7f3ea
	);
	color: var(--nldesign-component-status-badge-success-color, #1e6b2a);
}

.pq-address__pill--waiting {
	background: var(
		--nldesign-component-status-badge-warning-background-color,
		#fff4de
	);
	color: var(--nldesign-component-status-badge-warning-color, #7a4f00);
}

.pq-address__actions {
	display: inline-flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-md, 1rem);
}

/* An action on an address reads as a link; it stays a button because it
   acts in place. */
.pq-address__action {
	background: none;
	border: 0;
	color: var(--utrecht-link-color, var(--nldesign-color-primary, LinkText));
	cursor: pointer;
	font: inherit;
	font-weight: var(--utrecht-typography-weight-scale-bold, 600);
	min-block-size: 1.5rem;
	padding: 0;
	text-decoration: underline;
}

.pq-address__action:hover {
	color: var(
		--utrecht-link-hover-color,
		var(--nldesign-color-primary-hover, LinkText)
	);
}

.pq-address__action:focus-visible {
	outline: 2px solid var(--utrecht-focus-outline-color, currentColor);
	outline-offset: 2px;
}

.pq-account__add {
	align-items: end;
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-account__add .utrecht-form-label {
	flex-basis: 100%;
}
</style>
