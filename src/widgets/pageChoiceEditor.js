// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the two page-choice editors share (PortalNavigation on a portal's
 * page, PortalAccountPages on an account's page): the stored and edited
 * lists, loading and saving through one URL, the "changed" check and the
 * notices. Each editor keeps its own template, texts and test ids, and
 * spreads `pageChoiceEditor({...})` into its options.
 *
 * The editor provides a computed `owner` (the portal slug or the account's
 * subject reference); the editor is empty while that is ''.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The shared options of one page-choice editor.
 *
 * @param {object} options What differs per editor.
 * @param {string} options.key The response property holding the list (`audiences`, `pages`).
 * @param {string} options.field The request property a save sends (`navigation`, `hiddenPages`).
 * @param {(owner: string, generateUrl: (path: string) => string) => string} options.url The read and write URL.
 * @param {(list: Array<object>) => Array<object>} options.shape The stored shape of a list, sent on save and compared for "changed".
 * @param {(list: Array<object>) => Array<object>} options.copy A deep enough copy of a list.
 * @param {() => string} options.loadError The message when the list cannot be read.
 * @return {object} Component options.
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
export function pageChoiceEditor({ key, field, url, shape, copy, loadError }) {
	return {
		props: {
			/** The portal or account the detail page shows. */
			objectData: {
				type: Object,
				default: null,
			},
		},

		data() {
			return {
				saved: [],
				items: [],
				loading: true,
				saving: false,
				error: '',
				notice: '',
			}
		},

		computed: {
			/**
			 * Whether the edited list differs from what is stored.
			 *
			 * @return {boolean}
			 */
			dirty() {
				return JSON.stringify(shape(this.items)) !== JSON.stringify(shape(this.saved))
			},
		},

		watch: {
			/**
			 * Load again when the detail page hands over another object.
			 *
			 * @return {void}
			 */
			owner() {
				this.load()
			},
		},

		mounted() {
			this.load()
		},

		methods: {
			t,

			/**
			 * Read the list.
			 *
			 * @return {Promise<void>}
			 */
			async load() {
				this.loading = true
				this.error = ''
				if (this.owner === '') {
					this.loading = false
					return
				}
				try {
					const { data } = await axios.get(url(this.owner, generateUrl))
					this.accept(data[key] || [])
				} catch {
					this.error = loadError()
				} finally {
					this.loading = false
				}
			},

			/**
			 * Take a list from the server as the stored state.
			 *
			 * @param {Array<object>} list The list.
			 * @return {void}
			 */
			accept(list) {
				this.saved = copy(list)
				this.items = copy(list)
			},

			/**
			 * Note an edit: the last "saved" notice no longer holds.
			 *
			 * @return {void}
			 */
			edited() {
				this.notice = ''
			},

			/**
			 * Save the list.
			 *
			 * @return {Promise<void>}
			 */
			async save() {
				this.saving = true
				this.error = ''
				try {
					const { data } = await axios.put(url(this.owner, generateUrl), {
						[field]: shape(this.items),
					})
					this.accept(data[key] || [])
					this.notice = t('portaliq', 'Your choices are saved.')
				} catch {
					this.error = t('portaliq', 'Your choices could not be saved. Try again.')
				} finally {
					this.saving = false
				}
			},
		},
	}
}
