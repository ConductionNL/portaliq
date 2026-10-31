<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  FormEmbedDialog: "Put this form on your website".

  Its own file per ADR-004's modal-isolation rule. Spawned by the Insert form
  row action of the request forms list. It shows the iframe code, the websites
  that may show the form, and the optional height listener.

  @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
-->
<template>
	<CnDialog
		:look="look"
		:name="t('portaliq', 'Put the form on your website')"
		size="normal"
		data-testid="form-embed"
		@closing="$emit('close', null)">
		<div class="form-embed__body">
			<p v-if="formName" class="form-embed__form">
				{{ formName }}
			</p>
			<template v-if="description.embeddable">
				<p>
					{{
						t(
							'portaliq',
							'Put this form on a page of your own website. Visitors fill it in there; a submission becomes a real case, with a case number and a confirmation.',
						)
					}}
				</p>
				<NcTextArea
					:modelValue="description.snippet"
					:label="t('portaliq', 'Code for your website')"
					readonly
					rows="4"
					data-testid="form-embed-snippet" />
				<NcButton data-testid="form-embed-copy" @click="copy">
					{{
						copied ? t('portaliq', 'Copied') : t('portaliq', 'Copy code')
					}}
				</NcButton>
				<h3 class="form-embed__heading">
					{{ t('portaliq', 'Websites that may show the form') }}
				</h3>
				<ul class="form-embed__origins" data-testid="form-embed-origins">
					<li v-for="origin in description.origins" :key="origin">
						{{ origin }}
					</li>
				</ul>
				<p class="form-embed__hint">
					{{
						t(
							'portaliq',
							'On any other website the browser does not show the form.',
						)
					}}
				</p>
			</template>
			<NcNoteCard v-else type="warning" data-testid="form-embed-refused">
				{{
					t(
						'portaliq',
						'This form cannot be put on another website yet. Add the website addresses that may show it.',
					)
				}}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton variant="primary" @click="$emit('close', null)">
				{{ t('portaliq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'FormEmbedDialog',

	components: {
		NcButton,
		CnDialog,
		NcNoteCard,
		NcTextArea,
	},

	props: {
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
		},

		/** The name of the form the entry opens. */
		formName: {
			type: String,
			default: '',
		},

		/** What the server says about embedding this form. */
		description: {
			type: Object,
			default: () => ({ embeddable: false, origins: [], snippet: '' }),
		},
	},

	emits: ['close'],

	data() {
		return { copied: false }
	},

	methods: {
		t,

		/**
		 * Copy the code to the clipboard.
		 *
		 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
		 */
		async copy() {
			try {
				await navigator.clipboard.writeText(this.description.snippet || '')
				this.copied = true
			} catch {
				this.copied = false
			}
		},
	},
}
</script>

<style scoped>
.form-embed__body {
	padding: 0 16px 8px;
}

.form-embed__form {
	font-weight: 600;
}

.form-embed__heading {
	margin-top: 16px;
}

.form-embed__hint {
	color: var(--color-text-maxcontrast);
}
</style>
