<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A text AI may have translated (translated-message-notice, decision D24).
	With a labelled translation the reader sees the translation, then a notice
	"Translated by AI from Dutch" in an aside landmark, with a mark and text
	(never colour alone), and a button that shows the original in place.
	Without one, the text shows as written and no notice appears.
-->
<template>
	<LinkedText
		v-if="!labelled && partsOf"
		:as="as"
		:class="bodyClass"
		:parts="partsOf(text)"
		@navigate="$emit('navigate', $event)" />
	<component :is="as" v-else-if="!labelled" :class="bodyClass">
		{{ text }}
	</component>
	<div v-else class="pq-translated">
		<LinkedText
			v-if="partsOf"
			:as="as"
			:class="bodyClass"
			:lang="translation.targetLanguage"
			:parts="partsOf(translation.text)"
			@navigate="$emit('navigate', $event)" />
		<component
			:is="as"
			v-else
			:class="bodyClass"
			:lang="translation.targetLanguage">
			{{ translation.text }}
		</component>
		<aside class="pq-ai-notice" :aria-label="t('AI translation')">
			<span class="pq-ai-notice__mark" aria-hidden="true">AI</span>
			<span class="pq-ai-notice__text">{{ notice }}</span>
			<span
				v-if="translation.disclosure"
				class="pq-ai-notice__disclosure"
				:lang="translation.disclosureLanguage || undefined">
				{{ translation.disclosure }}
			</span>
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle pq-ai-notice__toggle"
				:aria-expanded="open ? 'true' : 'false'"
				:aria-controls="originalElementId"
				@click="open = !open">
				{{
					open ? t('Hide the original text') : t('Show the original text')
				}}
			</button>
		</aside>
		<blockquote
			:id="originalElementId"
			class="pq-translated__original"
			:lang="sourceLanguage"
			:hidden="!open">
			<template v-if="originalTitle">
				<p class="utrecht-paragraph pq-translated__original-title">
					{{ originalTitle }}
				</p>
				<LinkedText
					v-if="partsOf"
					as="p"
					class="utrecht-paragraph"
					:parts="partsOf(text)"
					@navigate="$emit('navigate', $event)" />
				<p v-else class="utrecht-paragraph">{{ text }}</p>
			</template>
			<template v-else-if="partsOf">
				<LinkedText
					:parts="partsOf(text)"
					@navigate="$emit('navigate', $event)" />
			</template>
			<template v-else>{{ text }}</template>
		</blockquote>
	</div>
</template>

<script>
import {
	isLabelledTranslation,
	noticeText,
	originalId,
} from '../../pages/inbox/translation.js'
import LinkedText from './LinkedText.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
 */
export default {
	name: 'TranslatedText',

	components: { LinkedText },

	props: {
		/** The text as written. */
		text: { type: String, default: '' },
		/** The reader's translation entry, if any. */
		translation: { type: Object, default: null },
		/** The translator. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** A stable id, used to wire the button to the original. */
		id: { type: [String, Number], required: true },
		/** Class of the shown text. */
		bodyClass: { type: String, default: 'utrecht-paragraph pq-message__body' },
		/** Start with the original shown. */
		defaultOpen: { type: Boolean, default: false },
		/** A title translated with the text: the original shows it above the text. */
		originalTitle: { type: String, default: '' },
		/** The element the shown text renders in; a heading keeps its level. */
		as: { type: String, default: 'p' },
		/**
		 * Optional: text to parts (`{text}` or a named link), so the addresses
		 * in a text show as links (REQ-NAP-013). Without it the text is plain.
		 */
		partsOf: { type: Function, default: null },
	},

	emits: ['navigate'],

	data() {
		return { open: this.defaultOpen }
	},

	computed: {
		/**
		 * @return {boolean} Whether a labelled translation is there.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
		 */
		labelled() {
			return isLabelledTranslation(this.translation)
		},

		/**
		 * @return {string} The notice sentence.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
		 */
		notice() {
			return noticeText(this.translation, this.t, this.locale)
		},

		/**
		 * @return {string} The id of the original's element.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
		 */
		originalElementId() {
			return originalId(this.id)
		},

		/**
		 * @return {string|undefined} The original's language, never an invented one.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-machine-translation-must-say-so-req-srp-034
		 */
		sourceLanguage() {
			const source = this.translation?.sourceLanguage
			return source && source !== 'und' ? source : undefined
		},
	},
}
</script>

<style scoped>
.pq-ai-notice {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-block: 4px 8px;
	font-size: 0.9em;
	color: var(--utrecht-paragraph-color, inherit);
}

.pq-ai-notice__mark {
	padding: 0 6px;
	border: 1px solid currentcolor;
	border-radius: 4px;
	font-weight: bold;
}

.pq-translated__original {
	margin: 0 0 8px;
	padding-inline-start: 12px;
	border-inline-start: 3px solid var(--utrecht-color-grey-60, currentcolor);
}
</style>
