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
		<h3 :id="headingId" class="utrecht-heading-3">
			{{ email ? t('E-mail addresses') : t('Phone numbers') }}
		</h3>
		<p v-if="entries.length === 0" class="utrecht-paragraph">
			{{
				email
					? t('You have no e-mail address on your account.')
					: t('You have no phone number on your account.')
			}}
		</p>
		<ul v-else class="utrecht-unordered-list pq-account__list">
			<li
				v-for="entry in entries"
				:key="entry.value"
				class="utrecht-unordered-list__item"
				:data-testid="`address-${kind}`">
				<NoTranslate :value="entry.value" />
				<strong v-if="entry.preferred"> ({{ t('Preferred') }})</strong>
				<em v-if="email && !entry.confirmed">
					({{ t('Waiting for confirmation') }})</em
				>
				<span class="pq-e-buttons">
					<button
						v-if="email && !entry.confirmed"
						type="button"
						class="utrecht-button utrecht-button--subtle"
						@click="act('add', kind, entry.value)">
						{{ t('Send the link again') }}
					</button>
					<button
						v-if="!entry.preferred && (!email || entry.confirmed)"
						type="button"
						class="utrecht-button utrecht-button--subtle"
						@click="act('prefer', kind, entry.value)">
						{{ t('Make preferred') }}
					</button>
					<button
						type="button"
						class="utrecht-button utrecht-button--subtle"
						@click="act('remove', kind, entry.value)">
						{{ t('Remove') }}
					</button>
				</span>
			</li>
		</ul>
		<form class="pq-account__add" @submit.prevent="add">
			<label :for="inputId" class="utrecht-form-label">
				{{ email ? t('New e-mail address') : t('New phone number') }}
			</label>
			<input
				:id="inputId"
				v-model="draft"
				class="utrecht-textbox"
				:type="email ? 'email' : 'tel'"
				:autocomplete="email ? 'email' : 'tel'"
				required />
			<button
				type="submit"
				class="utrecht-button utrecht-button--secondary-action">
				{{ email ? t('Add e-mail address') : t('Add phone number') }}
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
		return { draft: '' }
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
	},

	methods: {
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
			}
		},
	},
}
</script>

<style scoped>
.pq-account__addresses {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-account__list li {
	margin-block-end: var(--utrecht-space-block-xs, 0.25rem);
}

.pq-account__add {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-e-buttons {
	display: inline-flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-xs, 0.25rem);
	margin-inline-start: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
