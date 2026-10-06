<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A short lookup: a few fields in one row and a button that opens a page
	with the answers in its address (site-matches-the-zuiddrecht-boards, board
	Contentpagina "Postcode / Huisnummer / Toon mijn afvalkalender").

	A GET FORM, on purpose: it stores nothing and asks for nothing personal
	beyond what the page it opens needs to answer; the values travel as query
	parameters, which is what a lookup is. The page it opens must be in this
	site or on the web; anything else renders the fields without a working
	button, never a form posting to nowhere.
-->
<template>
	<form
		v-if="target"
		class="nl-lookup-form"
		:action="target.href"
		method="get"
		data-testid="nl-lookup-form"
		@submit="submit">
		<p v-if="heading" class="utrecht-paragraph nl-lookup-form__heading">
			{{ heading }}
		</p>
		<div class="nl-lookup-form__row">
			<div
				v-for="field in safeFields"
				:key="field.name"
				class="nl-lookup-form__field">
				<label
					class="utrecht-form-label nl-lookup-form__label"
					:for="idOf(field)"
					>{{ field.label }}</label
				>
				<input
					:id="idOf(field)"
					v-model="values[field.name]"
					class="utrecht-textbox utrecht-textbox--html-input nl-lookup-form__input"
					type="text"
					:name="field.name"
					:autocomplete="field.autocomplete || 'off'"
					:style="{ inlineSize: field.width }" />
			</div>
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action nl-lookup-form__button">
				{{ buttonLabel }}
			</button>
		</div>
	</form>
	<div v-else class="nl-lookup-form" data-testid="nl-lookup-form-plain">
		<p v-if="heading" class="utrecht-paragraph nl-lookup-form__heading">
			{{ heading }}
		</p>
	</div>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/form-label-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/textbox-css/dist/index.css'

/** The most fields one row holds. */
const MAX_FIELDS = 4

/**
 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-lookup-form-opens-a-page-with-its-answers-in-the-address
 */
export default {
	name: 'NlLookupForm',

	props: {
		/** A line above the fields. */
		heading: { type: String, default: '' },
		/** The fields: `{name, label, value?, width?, autocomplete?}`, four at most. */
		fields: { type: Array, default: () => [] },
		/** The words on the button. */
		buttonLabel: { type: String, default: 'Zoeken' },
		/** The page that answers, in this site or on the web. */
		href: { type: String, default: '' },
	},

	emits: ['navigate'],

	data() {
		return {
			/** What the visitor typed, by field name. */
			values: Object.fromEntries(
				this.safeFieldsOf(this.fields).map((field) => [
					field.name,
					field.value,
				]),
			),
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The fields with a name and a label, four at most.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-lookup-form-opens-a-page-with-its-answers-in-the-address
		 */
		safeFields() {
			return this.safeFieldsOf(this.fields)
		},

		/**
		 * @return {{href: string, route: string}|null} The page that answers.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-lookup-form-opens-a-page-with-its-answers-in-the-address
		 */
		target() {
			return authoredLink(this.href)
		},
	},

	methods: {
		/**
		 * The fields an author declared, held to a name, a label and a width
		 * in rem or ch; the rest is left out.
		 *
		 * @param {Array} fields The authored fields.
		 * @return {Array<object>} The fields that render.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-lookup-form-opens-a-page-with-its-answers-in-the-address
		 */
		safeFieldsOf(fields) {
			return (Array.isArray(fields) ? fields : [])
				.map((field) => ({
					name: String(field?.name ?? '')
						.trim()
						.replace(/[^a-zA-Z0-9_-]/g, ''),
					label: String(field?.label ?? '').trim(),
					value: String(field?.value ?? ''),
					width: /^\d+(\.\d+)?(rem|ch)$/.test(String(field?.width ?? ''))
						? String(field.width)
						: null,
					autocomplete: /^[a-z-]+$/.test(String(field?.autocomplete ?? ''))
						? String(field.autocomplete)
						: '',
				}))
				.filter((field) => field.name !== '' && field.label !== '')
				.slice(0, MAX_FIELDS)
		},

		/**
		 * @param {object} field A field.
		 * @return {string} An id for its input.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-lookup-form-opens-a-page-with-its-answers-in-the-address
		 */
		idOf(field) {
			return `nl-lookup-${field.name}`
		},

		/**
		 * A page of this site opens in the site, the answers in its address.
		 *
		 * @param {Event} event The submit.
		 * @return {void}
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-lookup-form-opens-a-page-with-its-answers-in-the-address
		 */
		submit(event) {
			if (!staysInSite(event, this.target)) {
				return
			}
			event.preventDefault()
			const query = new URLSearchParams(
				this.safeFields.map((field) => [
					field.name,
					this.values[field.name] ?? '',
				]),
			).toString()
			this.$emit('navigate', `${this.target.route}?${query}`)
		},
	},
}
</script>

<style scoped>
/* Tokens only (tests/widget-tokens.spec.mjs). */
.nl-lookup-form {
	padding: 1.5rem;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(
		--nldesign-color-primary-light,
		var(--utrecht-color-grey-95, transparent)
	);
}

.nl-lookup-form__heading {
	margin: 0 0 0.75rem;
}

.nl-lookup-form__row {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
	align-items: flex-end;
}

.nl-lookup-form__field {
	display: flex;
	flex-direction: column;
	gap: 0.375rem;
}

.nl-lookup-form__label {
	font-weight: 600;
}

.nl-lookup-form .utrecht-textbox.nl-lookup-form__input {
	inline-size: 10rem;
	max-inline-size: 100%;
	block-size: 3rem;
	box-sizing: border-box;
}

.nl-lookup-form .utrecht-button.nl-lookup-form__button {
	min-block-size: 3rem;
}
</style>
