<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Antwoorden" under an inbox message that its app lets the resident answer
	(inbox-reply-with-attachments). The form opens in place, not in a modal: the
	subject (prefilled), the text, the action's file fields, "Versturen" and
	"Annuleren". The server builds the reply from the message; the files go in
	afterwards, and one that fails is named while the reply stays.
-->
<template>
	<div class="pq-inbox-reply" data-testid="inbox-reply">
		<button
			v-if="!open"
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			data-testid="inbox-reply-open"
			@click="show">
			{{ words.reply }}
		</button>
		<form v-else class="pq-inbox-reply__form" novalidate @submit.prevent="send">
			<h4
				:id="`${base}-heading`"
				ref="heading"
				class="utrecht-heading-4"
				tabindex="-1">
				{{ words.replyTo.split('{subject}').join(message.subject || '') }}
			</h4>
			<div
				v-for="question in questions"
				:key="question.name"
				class="pq-inbox-reply__question">
				<label
					class="utrecht-form-label"
					:for="`${base}-${question.name}`"
					>{{ question.label }}</label
				>
				<textarea
					v-if="question.long"
					:id="`${base}-${question.name}`"
					v-model="values[question.name]"
					class="utrecht-textarea"
					rows="5"
					:aria-invalid="errors[question.name] ? 'true' : 'false'" />
				<input
					v-else
					:id="`${base}-${question.name}`"
					v-model="values[question.name]"
					class="utrecht-textbox"
					type="text"
					:aria-invalid="errors[question.name] ? 'true' : 'false'" />
				<p
					v-if="errors[question.name]"
					class="utrecht-form-field-error-message"
					role="alert">
					{{ errors[question.name] }}
				</p>
			</div>
			<div
				v-for="field in fileFieldsOf"
				:key="field.name"
				class="pq-inbox-reply__question">
				<p :id="`${base}-${field.name}-label`" class="utrecht-form-label">
					{{ field.label }}
				</p>
				<FileUpload
					:id="`${base}-${field.name}`"
					:files="files[field.name] || []"
					:multiple="field.multiple"
					:accept="field.accept"
					:labelledBy="`${base}-${field.name}-label`"
					:buttonLabel="words.chooseFile"
					:limitText="limitText(field)"
					:disabled="sending"
					@pick="files = { ...files, [field.name]: $event }" />
			</div>
			<p
				v-if="failed"
				class="utrecht-paragraph"
				role="alert"
				data-testid="inbox-reply-failed">
				{{ words.couldNot }}
			</p>
			<div class="pq-inbox-reply__actions">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="sending"
					data-testid="inbox-reply-send">
					{{ words.send }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					:disabled="sending"
					data-testid="inbox-reply-cancel"
					@click="open = false">
					{{ words.cancel }}
				</button>
			</div>
		</form>
		<p
			v-if="notice"
			class="utrecht-paragraph"
			role="status"
			data-testid="inbox-reply-notice">
			{{ notice }}
		</p>
	</div>
</template>

<script>
import FileUpload from '../forms/FileUpload.vue'
import {
	replyFileFields,
	replyQuestions,
	replyStart,
	sendReply,
	sentMessage,
} from '../../pages/inbox/reply.js'

// This component loads on demand, so its words are its own rather than part of the
// site's shared bundle, which has a size budget.
const STRINGS = {
	nl: {
		reply: 'Antwoorden',
		replyTo: 'Antwoord op: {subject}',
		chooseFile: 'Bestand kiezen',
		largest: 'Grootste bestand: {size} MB',
		couldNot: 'Uw antwoord kon niet worden verstuurd. Probeer het opnieuw.',
		send: 'Versturen',
		cancel: 'Annuleren',
		sent: 'Uw antwoord is verstuurd.',
		partial:
			'Uw antwoord is verstuurd, maar {name} kon niet worden toegevoegd. Voeg het opnieuw toe bij de zaak.',
	},
	en: {
		reply: 'Reply',
		replyTo: 'Reply to: {subject}',
		chooseFile: 'Choose a file',
		largest: 'Largest file: {size} MB',
		couldNot: 'Your reply could not be sent. Try again.',
		send: 'Send',
		cancel: 'Cancel',
		sent: 'Your reply has been sent.',
		partial:
			'Your reply has been sent, but {name} could not be added. Add it again from the case.',
	},
}

/**
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
 */
export default {
	name: 'InboxReply',
	components: { FileUpload },
	props: {
		/** The message being answered, with its `_source`. */
		message: { type: Object, required: true },
		/** The reply declaration from `message._source.reply`. */
		reply: { type: Object, required: true },
		/** The portal api (`replyToMessage`, `uploadFieldFile`). */
		api: { type: Object, required: true },
		/** The language, `nl` or `en`. */
		locale: { type: String, default: 'nl' },
		/** A stable id for the form's elements. */
		idBase: { type: String, default: 'pq-inbox-reply' },
	},

	data() {
		return {
			open: false,
			sending: false,
			failed: false,
			notice: '',
			values: {},
			files: {},
			errors: {},
		}
	},

	computed: {
		/**
		 * @return {string} The id prefix.
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
		 */
		base() {
			return this.idBase
		},

		/**
		 * @return {Array<object>} The questions.
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
		 */
		questions() {
			return replyQuestions(this.reply)
		},

		/**
		 * @return {object} The words in the page language.
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
		 */
		words() {
			return STRINGS[
				String(this.locale).toLowerCase().startsWith('en') ? 'en' : 'nl'
			]
		},

		/**
		 * @return {Array<object>} The file fields.
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
		 */
		fileFieldsOf() {
			return replyFileFields(this.reply)
		},
	},

	methods: {
		/**
		 * Open the form with the subject filled in and put focus on its heading.
		 *
		 * @return {void}
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
		 */
		show() {
			this.values = replyStart(this.message, this.reply)
			this.files = {}
			this.errors = {}
			this.failed = false
			this.notice = ''
			this.open = true
			this.$nextTick(() => {
				if (this.$refs.heading) {
					this.$refs.heading.focus()
				}
			})
		},

		/**
		 * The size limit of a file field as a sentence.
		 *
		 * @param {{maxSizeMb: number}} field The file field.
		 * @return {string} The sentence, or ''.
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
		 */
		limitText(field) {
			return field.maxSizeMb > 0
				? this.words.largest.split('{size}').join(String(field.maxSizeMb))
				: ''
		},

		/**
		 * Send the reply and its files; close with a confirmation, or say which
		 * file was not added while keeping the reply.
		 *
		 * @return {Promise<void>} Resolves when sent or refused.
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t06
		 */
		async send() {
			this.sending = true
			this.failed = false
			this.errors = {}
			const result = await sendReply(
				this.api,
				this.message,
				this.reply,
				this.values,
				this.files,
			)
			this.sending = false
			if (!result.ok) {
				this.errors = result.errors
				this.failed = Object.keys(result.errors).length === 0
				return
			}
			this.open = false
			this.notice = sentMessage(result.failed, {
				sent: this.words.sent,
				partial: this.words.partial,
			})
		},
	},
}
</script>
