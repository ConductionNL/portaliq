<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The follow-up of a report with the receipt code alone (intake-report-pages
	REQ-IRP-002, board MeldingGesprek). The reporter types the code, reads the
	messages the handler made visible and the terms, and answers. The code
	lives in this component's state for the answer call only; a wrong code and
	an unknown code read the same. The block sends no traffic event.
-->
<template>
	<div class="pq-report-thread" data-testid="report-thread-block">
		<form
			v-if="thread === null"
			class="pq-report-thread__open"
			novalidate
			data-testid="report-thread-open"
			@submit.prevent="open">
			<div class="utrecht-form-field">
				<label :for="ids.code" class="utrecht-form-label">{{
					words.yourCode
				}}</label>
				<input
					:id="ids.code"
					v-model="codeDraft"
					class="utrecht-textbox"
					type="text"
					autocomplete="off"
					spellcheck="false"
					data-testid="report-thread-code" />
			</div>
			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-report-thread__problem"
				role="alert"
				data-testid="report-thread-problem">
				{{ problem }}
			</p>
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="report-thread-submit">
				{{ words.openThread }}
			</button>
			<p class="utrecht-paragraph">
				{{ words.keepCodeShort }}
			</p>
		</form>
		<template v-else>
			<div class="pq-report-thread__main">
				<p class="utrecht-paragraph">
					{{ words.hereWithCode }}
				</p>
				<h2 class="utrecht-heading-3" data-testid="report-thread-subject">
					{{ thread.report.subject || words.yourReport }}
				</h2>
				<p v-if="thread.report.receivedAt" class="utrecht-paragraph">
					{{ fill(words.receivedOn, { date: day(thread.report.receivedAt) }) }}
				</p>
				<p class="utrecht-paragraph pq-report-thread__body">
					{{ thread.report.body }}
				</p>
				<section aria-labelledby="pq-report-thread-talk">
					<h2 id="pq-report-thread-talk" class="utrecht-heading-3">
						{{ words.conversation }}
					</h2>
					<ol class="pq-report-thread__messages">
						<li
							v-for="(message, index) in thread.messages"
							:key="index"
							class="pq-report-thread__message"
							:data-author="message.author"
							data-testid="report-thread-message">
							<p class="pq-report-thread__who">
								<strong>{{ authorOf(message) }}</strong>
								<span>{{ moment(message.writtenAt) }}</span>
							</p>
							<p class="utrecht-paragraph">
								{{ message.body }}
							</p>
						</li>
					</ol>
				</section>
				<form
					class="pq-report-thread__answer"
					novalidate
					data-testid="report-thread-answer"
					@submit.prevent="answer">
					<div class="utrecht-form-field">
						<label :for="ids.answer" class="utrecht-form-label">{{
							words.answer
						}}</label>
						<p :id="ids.answerHint" class="utrecht-form-field-description">
							{{ words.answerHint }}
						</p>
						<textarea
							:id="ids.answer"
							v-model="reply"
							class="utrecht-textarea"
							rows="4"
							:aria-describedby="ids.answerHint"
							data-testid="report-thread-reply" />
					</div>
					<p
						v-if="problem !== ''"
						class="utrecht-paragraph pq-report-thread__problem"
						role="alert"
						data-testid="report-thread-problem">
						{{ problem }}
					</p>
					<p
						v-if="notice !== ''"
						class="utrecht-paragraph"
						role="status"
						data-testid="report-thread-notice">
						{{ notice }}
					</p>
					<button
						type="submit"
						class="utrecht-button utrecht-button--primary-action"
						:disabled="busy"
						data-testid="report-thread-send">
						{{ words.sendAnswer }}
					</button>
				</form>
			</div>
			<aside class="pq-report-thread__aside">
				<section v-if="terms.length > 0" class="pq-report-thread__card">
					<h2 class="utrecht-heading-4">
						{{ words.expect }}
					</h2>
					<dl data-testid="report-thread-terms">
						<template v-for="term in terms" :key="term.title">
							<dt>{{ term.title }}</dt>
							<dd>{{ term.state }}</dd>
							<dd>{{ term.due }}</dd>
						</template>
					</dl>
					<p class="utrecht-paragraph">
						{{ words.termsSource }}
					</p>
				</section>
				<p class="utrecht-paragraph">
					{{ words.noTraceThread }}
				</p>
				<p class="utrecht-paragraph">
					{{ words.keepCodeShort }}
				</p>
			</aside>
		</template>
	</div>
</template>

<script>
import { authBaseFrom } from '../lib/authApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import {
	fillReport,
	reportApi,
	reportWords,
	termLines,
} from '../lib/reportApi.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/paragraph-css/dist/index.css'

let instances = 0

/**
 * @spec openspec/changes/intake-report-pages/tasks.md#t06
 */
export default {
	name: 'ReportThreadBlock',

	props: {
		/** The serving portal's slug, supplied by the host. */
		portal: { type: String, default: '' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
		/** The report calls (test seam); built from the page's api base otherwise. */
		apiOverride: { type: Object, default: null },
	},

	data() {
		instances++
		return {
			uid: instances,
			codeDraft: '',
			code: '',
			thread: null,
			reply: '',
			busy: false,
			problem: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the reader's language.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t10
		 */
		words() {
			return reportWords(pageLocale(this.locale))
		},

		/**
		 * @return {Record<string, string>} Element ids unique on the page.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t06
		 */
		ids() {
			const id = (part) => `pq-report-thread-${this.uid}-${part}`
			return { code: id('code'), answer: id('answer'), answerHint: id('answer-hint') }
		},

		/**
		 * @return {object} The report calls of this portal.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t06
		 */
		api() {
			return (
				this.apiOverride
				|| reportApi(authBaseFrom(resolveApiBase()), this.portal)
			)
		},

		/**
		 * @return {Array<{title: string, state: string, due: string}>} The terms in words.
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-follows-up-with-the-code-alone-req-irp-002
		 */
		terms() {
			return (this.thread?.terms || []).map((term) =>
				termLines(term, this.words, (iso) => this.day(iso)),
			)
		},
	},

	/**
	 * The code leaves with the page.
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-follows-up-with-the-code-alone-req-irp-002
	 */
	beforeUnmount() {
		this.code = ''
		this.codeDraft = ''
	},

	methods: {
		fill: fillReport,

		/**
		 * Open the thread the typed code belongs to. A wrong code and an
		 * unknown one get the same sentence.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-follows-up-with-the-code-alone-req-irp-002
		 */
		async open() {
			this.problem = ''
			const code = this.codeDraft.trim().toUpperCase()
			if (code === '') {
				this.problem = this.words.wrongCode
				return
			}
			this.busy = true
			const thread = await this.api.thread(code)
			this.busy = false
			if (thread === null) {
				this.problem = this.words.wrongCode
				return
			}
			this.code = code
			this.codeDraft = ''
			this.thread = thread
		},

		/**
		 * Send the answer, then read the thread again.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-follows-up-with-the-code-alone-req-irp-002
		 */
		async answer() {
			this.problem = ''
			this.notice = ''
			const body = this.reply.trim()
			if (body === '') {
				return
			}
			this.busy = true
			const written = await this.api.answer(this.code, body)
			if (!written) {
				this.busy = false
				this.problem = this.words.answerFailed
				return
			}
			this.reply = ''
			this.notice = this.words.answerSent
			this.thread = (await this.api.thread(this.code)) || this.thread
			this.busy = false
		},

		/**
		 * @param {{author: string, authorName?: string}} message A message.
		 * @return {string} "U" for the reporter, else the handler's name.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t06
		 */
		authorOf(message) {
			if (message.author === 'reporter') {
				return this.words.you
			}
			return message.authorName || this.words.organisation
		},

		/**
		 * @param {string} iso A moment.
		 * @return {string} "2 oktober 2026", or '' for none.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t06
		 */
		day(iso) {
			const when = new Date(iso)
			if (!iso || Number.isNaN(when.getTime())) {
				return ''
			}
			return new Intl.DateTimeFormat(this.intlLocale(), {
				day: 'numeric',
				month: 'long',
				year: 'numeric',
			}).format(when)
		},

		/**
		 * @param {string} iso A moment.
		 * @return {string} "2 oktober 2026, 10:12", or '' for none.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t06
		 */
		moment(iso) {
			const when = new Date(iso)
			if (!iso || Number.isNaN(when.getTime())) {
				return ''
			}
			const time = new Intl.DateTimeFormat(this.intlLocale(), {
				hour: '2-digit',
				minute: '2-digit',
			}).format(when)
			return `${this.day(iso)}, ${time}`
		},

		/**
		 * @return {string} The locale dates are written in.
		 */
		intlLocale() {
			return pageLocale(this.locale) === 'en' ? 'en-GB' : 'nl-NL'
		},
	},
}
</script>

<style scoped>
.pq-report-thread {
	display: grid;
	gap: var(--utrecht-space-block-lg, 1.5rem);
	grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

.pq-report-thread__open {
	grid-column: 1 / -1;
	max-inline-size: 32rem;
}

@media (max-width: 48rem) {
	.pq-report-thread {
		grid-template-columns: minmax(0, 1fr);
	}
}

.pq-report-thread__open > * + *,
.pq-report-thread__main > * + *,
.pq-report-thread__answer > * + *,
.pq-report-thread__aside > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-report-thread textarea,
.pq-report-thread input[type='text'] {
	inline-size: 100%;
}

.pq-report-thread__messages {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-report-thread__message {
	border-inline-start: 4px solid var(--utrecht-color-grey-80, currentcolor);
	padding-block: var(--utrecht-space-block-sm, 0.5rem);
	padding-inline-start: var(--utrecht-space-inline-md, 1rem);
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-report-thread__message[data-author='reporter'] {
	border-inline-start-color: var(--utrecht-color-blue-40, currentcolor);
}

.pq-report-thread__who {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin: 0;
}

.pq-report-thread__body {
	white-space: pre-line;
}

.pq-report-thread__card {
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 4px);
	padding: var(--utrecht-space-block-md, 1rem);
}

.pq-report-thread__problem {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
