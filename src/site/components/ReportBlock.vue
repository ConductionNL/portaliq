<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A report without an account, on a public page (intake-report-pages
	REQ-IRP-001, board MeldenZonderAccount). The visitor describes what
	happened and may say who they are; the block solves the portal's own
	challenge and files through POST /portal/api/reports. The receipt code is
	shown once and kept in this component's state only. The block sends no
	traffic event.
-->
<template>
	<div class="pq-report" data-testid="report-block">
		<div class="pq-report__main">
			<p v-if="intro" class="utrecht-paragraph">
				{{ intro }}
			</p>
			<div v-if="code !== ''" role="status" data-testid="report-code-panel">
				<h2 class="utrecht-heading-3">
					{{ words.yourCode }}
				</h2>
				<p class="utrecht-paragraph">
					<strong>{{ words.keepCode }}</strong>
				</p>
				<input
					:value="code"
					class="utrecht-textbox pq-report__code"
					readonly
					:aria-label="words.yourCode"
					data-testid="report-code"
					@focus="$event.target.select()" />
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="report-copy"
					@click="copy">
					{{ words.copy }}
				</button>
				<p v-if="copied" class="utrecht-paragraph" role="status">
					{{ words.copied }}
				</p>
				<p class="utrecht-paragraph">
					{{ words.codeOnce }}
				</p>
			</div>
			<form
				v-else
				class="pq-report__form"
				novalidate
				data-testid="report-form"
				@submit.prevent="send">
				<div class="utrecht-form-field">
					<label :for="ids.body" class="utrecht-form-label">{{
						words.whatHappened
					}}</label>
					<p :id="ids.bodyHint" class="utrecht-form-field-description">
						{{ words.whatHappenedHint }}
					</p>
					<textarea
						:id="ids.body"
						v-model="body"
						class="utrecht-textarea"
						rows="6"
						required
						:aria-describedby="ids.bodyHint"
						:aria-invalid="problem === words.empty ? 'true' : undefined"
						data-testid="report-body" />
				</div>
				<div class="utrecht-form-field">
					<label :for="ids.whenWhere" class="utrecht-form-label">
						{{ words.whenWhere }} <span>{{ words.optional }}</span>
					</label>
					<input
						:id="ids.whenWhere"
						v-model="whenWhere"
						class="utrecht-textbox"
						type="text"
						data-testid="report-when-where" />
				</div>
				<fieldset class="pq-report__contact">
					<legend class="utrecht-form-label">
						{{ words.contactTitle }} <span>{{ words.optional }}</span>
					</legend>
					<p class="utrecht-form-field-description">
						{{ words.contactHint }}
					</p>
					<div class="utrecht-form-field">
						<label :for="ids.name" class="utrecht-form-label">
							{{ words.name }} <span>{{ words.optional }}</span>
						</label>
						<input
							:id="ids.name"
							v-model="name"
							class="utrecht-textbox"
							type="text"
							autocomplete="off"
							data-testid="report-name" />
					</div>
					<div class="utrecht-form-field">
						<label :for="ids.reach" class="utrecht-form-label">
							{{ words.reach }} <span>{{ words.optional }}</span>
						</label>
						<input
							:id="ids.reach"
							v-model="reach"
							class="utrecht-textbox"
							type="text"
							autocomplete="off"
							data-testid="report-reach" />
					</div>
				</fieldset>
				<input
					v-model="trap"
					class="pq-report__trap"
					type="text"
					name="website"
					tabindex="-1"
					autocomplete="off"
					aria-hidden="true" />
				<p
					v-if="problem !== ''"
					class="utrecht-paragraph pq-report__problem"
					role="alert"
					data-testid="report-problem">
					{{ problem }}
				</p>
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy"
					data-testid="report-send">
					{{ busy ? words.sending : words.send }}
				</button>
			</form>
		</div>
		<aside class="pq-report__aside">
			<section v-if="threadRoute" class="pq-report__card">
				<h2 class="utrecht-heading-4">
					{{ words.alreadyReported }}
				</h2>
				<p class="utrecht-paragraph">
					{{ words.comeBack }}
				</p>
				<a
					class="utrecht-link"
					:href="threadRoute"
					data-testid="report-thread-link">{{ words.toThread }}</a>
			</section>
			<section v-if="expectations.length > 0" class="pq-report__card">
				<h2 class="utrecht-heading-4">
					{{ words.expect }}
				</h2>
				<ul class="utrecht-unordered-list">
					<li v-for="line in expectations" :key="line">
						{{ line }}
					</li>
				</ul>
			</section>
			<p class="utrecht-paragraph pq-report__note">
				{{ words.noTrace }}
			</p>
		</aside>
	</div>
</template>

<script>
import { authBaseFrom } from '../lib/authApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import { contactOf, reportApi, reportWords } from '../lib/reportApi.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/paragraph-css/dist/index.css'

let instances = 0

/**
 * @spec openspec/changes/intake-report-pages/tasks.md#t05
 */
export default {
	name: 'ReportBlock',

	props: {
		/** The case type whose report declaration governs the report. */
		caseType: { type: String, default: '' },
		/** The register the case type lives in. */
		register: { type: String, default: '' },
		/** The schema the case type lives in. */
		schema: { type: String, default: '' },
		/** A line above the form. */
		intro: { type: String, default: '' },
		/** The page that holds the follow-up block, linked under "Al gemeld?". */
		threadRoute: { type: String, default: '' },
		/** What the reporter may expect, one line each. */
		expectations: { type: Array, default: () => [] },
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
			body: '',
			whenWhere: '',
			name: '',
			reach: '',
			trap: '',
			busy: false,
			problem: '',
			code: '',
			copied: false,
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
		 * @spec openspec/changes/intake-report-pages/tasks.md#t05
		 */
		ids() {
			const id = (part) => `pq-report-${this.uid}-${part}`
			return {
				body: id('body'),
				bodyHint: id('body-hint'),
				whenWhere: id('when-where'),
				name: id('name'),
				reach: id('reach'),
			}
		},

		/**
		 * @return {object} The report calls of this portal.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t05
		 */
		api() {
			return (
				this.apiOverride
				|| reportApi(authBaseFrom(resolveApiBase()), this.portal)
			)
		},
	},

	/**
	 * The code leaves with the page: nothing keeps it once the block is gone.
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-files-a-report-on-a-public-page-and-keeps-a-code-req-irp-001
	 */
	beforeUnmount() {
		this.code = ''
	},

	methods: {
		/**
		 * File the report and show the code once.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-a-reporter-files-a-report-on-a-public-page-and-keeps-a-code-req-irp-001
		 */
		async send() {
			this.problem = ''
			if (this.body.trim() === '') {
				this.problem = this.words.empty
				return
			}
			const report = { body: this.body.trim() }
			if (this.whenWhere.trim() !== '') {
				report.whenWhere = this.whenWhere.trim()
			}
			this.busy = true
			const answer = await this.api.file({
				caseType: this.caseType,
				register: this.register,
				schema: this.schema,
				report,
				contact: contactOf(this.name, this.reach),
				trap: this.trap,
			})
			this.busy = false
			if (!answer.ok || answer.code === '') {
				this.problem =
					answer.error === 'challenge_failed'
						? this.words.challengeFailed
						: this.words.failed
				return
			}
			this.code = answer.code
			this.body = ''
			this.whenWhere = ''
			this.name = ''
			this.reach = ''
		},

		/**
		 * Copy the code to the clipboard.
		 *
		 * @return {Promise<void>} Resolves when copied.
		 * @spec openspec/changes/intake-report-pages/tasks.md#t05
		 */
		async copy() {
			try {
				await globalThis.navigator?.clipboard?.writeText(this.code)
				this.copied = true
			} catch {
				this.copied = false
			}
		},
	},
}
</script>

<style scoped>
.pq-report {
	display: grid;
	gap: var(--utrecht-space-block-lg, 1.5rem);
	grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

@media (max-width: 48rem) {
	.pq-report {
		grid-template-columns: minmax(0, 1fr);
	}
}

.pq-report__form > * + *,
.pq-report__contact > * + *,
.pq-report__aside > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-report textarea,
.pq-report input[type='text'] {
	inline-size: 100%;
}

.pq-report__contact {
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 4px);
	padding: var(--utrecht-space-block-md, 1rem);
}

.pq-report__card {
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 4px);
	padding: var(--utrecht-space-block-md, 1rem);
}

.pq-report__code {
	font-family: var(--utrecht-code-font-family, monospace);
	font-size: var(--utrecht-heading-3-font-size, 1.5rem);
	letter-spacing: 0.1em;
}

.pq-report__trap {
	position: absolute;
	inset-inline-start: -10000px;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
}

.pq-report__problem {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
