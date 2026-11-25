<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Files an author lists on a page (site-nlds-widget-palette T9, design D1
	row 30): each a Den Haag file item named after the file, opening its
	address from the media library. Only an address the site may link to is
	kept; the list is page content, so the widget is public and reads nothing.
-->
<template>
	<section
		class="nl-file-list"
		:aria-labelledby="heading ? headingId : undefined"
		data-testid="nl-file-list">
		<h2 v-if="heading" :id="headingId" class="utrecht-heading-2">
			{{ heading }}
		</h2>
		<ul v-if="list.length > 0" class="nl-file-list__items">
			<FileItem
				v-for="(file, index) in list"
				:key="index"
				:name="file.label"
				:line="file.line"
				@open="open(file)" />
		</ul>
	</section>
</template>

<script>
import FileItem from '../../components/mijn/FileItem.vue'
import { authoredFiles } from '../ownData.js'

import '@utrecht/heading-2-css/dist/index.css'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
 */
export default {
	name: 'NlFileList',

	components: { FileItem },

	props: {
		/** The heading. */
		heading: { type: String, default: '' },
		/** The files: `{label, href, line?}`. */
		files: { type: Array, default: () => [] },
	},

	computed: {
		/**
		 * @return {Array<object>} The files with a label and a usable address.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		list() {
			return authoredFiles(this.files)
		},

		/**
		 * @return {string} An id for the heading.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		headingId() {
			return `nl-file-list-${this.heading.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
		},
	},

	methods: {
		/**
		 * Open a file at its address.
		 *
		 * @param {{href: string}} file The file.
		 * @return {void}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		open(file) {
			window.location.assign(file.href)
		},
	},
}
</script>
