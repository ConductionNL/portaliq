<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalAccessibility: the accessibility measurement and the audit the
  portal's statement rests on, as a widget on the portal's own page
  (site-accessibility-statement REQ-SAS-001 and REQ-SAS-003).

  "Measure accessibility" opens each page of the portal in a hidden frame in
  this browser, runs axe-core on the site root and stores what it found.
  axe-core loads only when the button is pressed, as an admin chunk. A page
  that cannot be framed is stored as not measured. Status A or B needs an
  audit; the server refuses the save without one.

  @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
  @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
-->
<template>
	<div class="portal-accessibility" data-testid="portal-accessibility">
		<NcLoadingIcon v-if="state === 'loading'" />
		<NcNoteCard v-else-if="state === 'error'" type="error">
			{{ t('portaliq', 'The accessibility settings could not be loaded.') }}
		</NcNoteCard>
		<template v-else>
			<p data-testid="portal-accessibility-status">
				{{ statusLine }}
			</p>
			<p v-if="statement.measurement" data-testid="portal-accessibility-last">
				{{
					t(
						'portaliq',
						'Last measured on {date}: {issues} known issues, {skipped} pages not measured.',
						{
							date: shortDate(statement.measurement.measuredAt),
							issues: statement.issues.length,
							skipped: statement.measurement.pagesNotMeasured,
						},
					)
				}}
			</p>
			<p v-else>
				{{ t('portaliq', 'This portal has not been measured yet.') }}
			</p>
			<p>
				<a :href="statementAddress" target="_blank" rel="noopener">
					{{ t('portaliq', 'Open the public statement') }}
				</a>
			</p>
			<NcButton
				variant="primary"
				:disabled="measuring"
				data-testid="portal-accessibility-measure"
				@click="measure">
				{{ t('portaliq', 'Measure accessibility') }}
			</NcButton>
			<p v-if="measuring" role="status">
				{{ t('portaliq', 'Measuring page {done} of {total}.', progress) }}
			</p>

			<fieldset class="portal-accessibility__audit">
				<legend>{{ t('portaliq', 'Independent audit') }}</legend>
				<p>
					{{
						t(
							'portaliq',
							'Status A or B needs an audit by an independent party, no older than three years.',
						)
					}}
				</p>
				<NcTextField
					v-model="audit.party"
					:label="t('portaliq', 'Audited by')"
					data-testid="portal-accessibility-party" />
				<NcTextField
					v-model="audit.date"
					type="date"
					:label="t('portaliq', 'Audit date')"
					data-testid="portal-accessibility-date" />
				<NcTextField
					v-model="audit.reportUrl"
					:label="t('portaliq', 'Link to the report (https)')"
					data-testid="portal-accessibility-report" />
				<NcCheckboxRadioSwitch
					v-for="option in resultOptions"
					:key="option.value"
					:modelValue="audit.result"
					:value="option.value"
					type="radio"
					name="portal-accessibility-result"
					@update:modelValue="audit.result = $event">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextField
				v-model="registerUrl"
				:label="
					t(
						'portaliq',
						'Entry in the register of accessibility statements (https)',
					)
				"
				data-testid="portal-accessibility-register" />
			<NcTextArea
				v-model="addedPages"
				:label="
					t('portaliq', 'Other pages to measure, one site route per line')
				"
				data-testid="portal-accessibility-pages" />
			<NcNoteCard v-if="notice" :type="noticeType">
				{{ notice }}
			</NcNoteCard>
			<NcButton
				:disabled="saving"
				data-testid="portal-accessibility-save"
				@click="save">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	auditProblem,
	AXE_TAGS,
	createAccessibilityApi,
	frameAddress,
	measureRun,
	SITE_ROOT,
} from '../lib/accessibilityMeasure.js'

/** How long a page may take to show its site root, in milliseconds. */
const PAGE_TIMEOUT = 20000

export default {
	name: 'PortalAccessibility',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			state: 'loading',
			statement: { status: null, issues: [], measurement: null },
			pages: [],
			audit: { party: '', date: '', reportUrl: '', result: '' },
			registerUrl: '',
			addedPages: '',
			measuring: false,
			progress: { done: 0, total: 0 },
			saving: false,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		/**
		 * @return {string} The portal's slug.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},

		/**
		 * @return {string} The site's address on this instance.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		siteBase() {
			return generateUrl('/apps/portaliq/site')
		},

		/**
		 * @return {string} The public statement of this portal.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
		 */
		statementAddress() {
			return `${this.siteBase}?portal=${encodeURIComponent(this.slug)}&route=${encodeURIComponent('/toegankelijkheid')}`
		},

		/**
		 * @return {string} The status the statement shows, in words.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
		 */
		statusLine() {
			const status = this.statement.status
			if (!status) {
				return t(
					'portaliq',
					'The statement shows no status until the portal is measured.',
				)
			}
			if (this.statement.automatedOnly) {
				return t(
					'portaliq',
					'The statement shows status {status}. Without an audit it cannot show A or B.',
					{ status },
				)
			}
			return t(
				'portaliq',
				'The statement shows status {status}, from the audit.',
				{ status },
			)
		},

		/**
		 * @return {Array<{value: string, label: string}>} The audit results.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
		 */
		resultOptions() {
			return [
				{ value: '', label: t('portaliq', 'No audit') },
				{ value: 'A', label: t('portaliq', 'A: fully compliant') },
				{ value: 'B', label: t('portaliq', 'B: partly compliant') },
				{ value: 'C', label: t('portaliq', 'C: first measures taken') },
				{ value: 'D', label: t('portaliq', 'D: no measures taken') },
			]
		},
	},

	/**
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	created() {
		this.api = createAccessibilityApi({
			get: (url) => axios.get(url),
			put: (url, body) => axios.put(url, body),
			post: (url, body) => axios.post(url, body),
			url: (path, params) => generateUrl('/apps/portaliq' + path, params),
		})
		this.load()
	},

	methods: {
		/**
		 * @param {object} view What the server answered.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
		 */
		apply(view) {
			this.statement = view?.statement || this.statement
			this.pages = view?.pages || []
			this.audit = {
				party: '',
				date: '',
				reportUrl: '',
				result: '',
				...(view?.audit || {}),
			}
			this.registerUrl = String(view?.registerUrl || '')
			this.addedPages = (view?.addedPages || []).join('\n')
		},

		/**
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		async load() {
			const result = await this.api.load(this.slug)
			this.state = result.ok ? 'ready' : 'error'
			if (result.ok) {
				this.apply(result.data)
			}
		},

		/**
		 * @param {string} value An ISO date-time.
		 * @return {string} The date in the browser's language.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
		 */
		shortDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime())
				? String(value || '')
				: date.toLocaleDateString()
		},

		/**
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
		 */
		async save() {
			const problem = auditProblem(this.audit, new Date())
			if (problem !== '') {
				this.say('error', this.auditMessage(problem))
				return
			}
			this.saving = true
			const result = await this.api.save(this.slug, {
				audit: this.audit,
				registerUrl: this.registerUrl,
				pages: this.addedPages
					.split('\n')
					.map((line) => line.trim())
					.filter(Boolean),
			})
			this.saving = false
			if (result.ok) {
				this.apply(result.data)
				this.say(
					'success',
					t('portaliq', 'The accessibility settings are saved.'),
				)
				return
			}
			this.say('error', this.auditMessage(result.error))
		},

		/**
		 * @param {string} error The refusal.
		 * @return {string} The refusal in words.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
		 */
		auditMessage(error) {
			switch (String(error).replace(/^audit_/, '')) {
				case 'incomplete':
					return t(
						'portaliq',
						'Status A or B needs the party, the date and an https link to the audit report.',
					)
				case 'expired':
					return t(
						'portaliq',
						'This audit is older than three years. It no longer supports status A or B.',
					)
				case 'register_url_invalid':
					return t(
						'portaliq',
						'The register entry must be an https address.',
					)
				default:
					return t(
						'portaliq',
						'The accessibility settings could not be saved.',
					)
			}
		},

		/**
		 * @param {string} type The note type.
		 * @param {string} text The note.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		say(type, text) {
			this.noticeType = type
			this.notice = text
		},

		/**
		 * Measure every page and store the run.
		 *
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		async measure() {
			this.measuring = true
			this.progress = { done: 0, total: this.pages.length }
			const axe = (await import('axe-core')).default
			const run = await measureRun(
				this.pages,
				{
					axeVersion: axe.version,
					theme: String(this.objectData?.theme || ''),
					open: (route) => this.openInFrame(route),
					run: (root) => this.runInFrame(root, axe.source),
				},
				(done, total) => {
					this.progress = { done, total }
				},
			)
			this.closeFrame()
			const result = await this.api.store(this.slug, run)
			this.measuring = false
			if (!result.ok) {
				this.say(
					'error',
					t('portaliq', 'The measurement could not be stored.'),
				)
				return
			}
			this.say(
				'success',
				t(
					'portaliq',
					'The measurement is stored. The statement now follows it.',
				),
			)
			await this.load()
		},

		/**
		 * Load one site route in a hidden frame and resolve with its site
		 * root, or reject with the reason in words.
		 *
		 * @param {string} route The site route.
		 * @return {Promise<Element>}
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		openInFrame(route) {
			this.closeFrame()
			const frame = document.createElement('iframe')
			frame.setAttribute('aria-hidden', 'true')
			frame.tabIndex = -1
			frame.title = t('portaliq', 'Accessibility measurement')
			frame.className = 'portal-accessibility__frame'
			this.frame = frame
			const started = Date.now()
			return new Promise((resolve, reject) => {
				const look = () => {
					let doc
					try {
						doc = frame.contentDocument
					} catch {
						doc = null
					}
					if (!doc) {
						reject(
							new Error(
								t(
									'portaliq',
									'The page refused to load in a frame.',
								),
							),
						)
						return
					}
					const root = doc.querySelector(SITE_ROOT)
					if (root) {
						resolve(root)
						return
					}
					if (Date.now() - started > PAGE_TIMEOUT) {
						reject(
							new Error(
								t(
									'portaliq',
									'The page showed no site content within 20 seconds.',
								),
							),
						)
						return
					}
					setTimeout(look, 250)
				}
				frame.addEventListener('load', look, { once: true })
				frame.src = frameAddress(this.siteBase, this.slug, route)
				document.body.appendChild(frame)
			})
		},

		/**
		 * Run axe-core inside the frame, on its site root.
		 *
		 * @param {Element} root The site root inside the frame.
		 * @param {string} source axe-core's source.
		 * @return {Promise<object>} axe-core's result.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		async runInFrame(root, source) {
			const doc = root.ownerDocument
			const view = doc.defaultView
			if (!view.axe) {
				const address = URL.createObjectURL(
					new Blob([source], { type: 'text/javascript' }),
				)
				await new Promise((resolve, reject) => {
					const script = doc.createElement('script')
					script.nonce = doc.querySelector('script[nonce]')?.nonce || ''
					script.src = address
					script.onload = resolve
					script.onerror = reject
					doc.head.appendChild(script)
				})
				URL.revokeObjectURL(address)
			}
			return view.axe.run(root, { runOnly: { type: 'tag', values: AXE_TAGS } })
		},

		/**
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
		 */
		closeFrame() {
			if (this.frame) {
				this.frame.remove()
				this.frame = null
			}
		},
	},
}
</script>

<style scoped>
.portal-accessibility__audit {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-block: 8px;
}
</style>

<style>
/* The frame lives on document.body, outside this component's scope. */
.portal-accessibility__frame {
	position: fixed;
	inset-inline-start: -10000px;
	inset-block-start: 0;
	width: 1280px;
	height: 900px;
	border: 0;
}
</style>
