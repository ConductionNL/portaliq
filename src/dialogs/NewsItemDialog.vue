<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  NewsItemDialog: write or change one news item and choose who it is for.

  Its own file per ADR-004's modal-isolation rule. Opened by the News screen
  (src/views/NewsAuthoring.vue). It closes with the form when saved, or with
  nothing when cancelled; the screen does the save, so a refusal from the
  server is shown where the list is.

  The school and group choices come from the school app (GET
  /api/news/audiences). When the school app offers no choices, the screen
  asks for the school's or groups' reference instead.

  @spec openspec/changes/staff-news-screen/tasks.md#T4
-->
<template>
	<NcDialog
		:name="item ? t('portaliq', 'Change news item') : t('portaliq', 'New news item')"
		size="normal"
		data-testid="news-item-dialog"
		@closing="$emit('close', null)">
		<div class="news-item">
			<NcTextField
				v-model="form.title"
				:label="t('portaliq', 'Title')"
				data-testid="news-item-title" />
			<NcTextArea
				v-model="form.body"
				:label="t('portaliq', 'Text')"
				:helperText="t('portaliq', 'Parents read this text in the portal.')"
				resize="vertical"
				data-testid="news-item-body" />

			<fieldset v-if="form.audience !== 'children'" class="news-item__audience">
				<legend>{{ t('portaliq', 'Who is this news for?') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="form.audience"
					type="radio"
					value="school"
					name="news-item-audience"
					data-testid="news-item-audience-school">
					{{ t('portaliq', 'The whole school') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.audience"
					type="radio"
					value="groups"
					name="news-item-audience"
					data-testid="news-item-audience-groups">
					{{ t('portaliq', 'One or more groups') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcNoteCard v-else type="info">
				{{ t('portaliq', 'This news item is for specific children. You can change the text, not who it is for.') }}
			</NcNoteCard>

			<template v-if="form.audience === 'school'">
				<NcSelect
					v-if="options.schools.length > 0"
					v-model="school"
					:options="options.schools"
					label="label"
					:inputLabel="t('portaliq', 'School')"
					:clearable="false"
					data-testid="news-item-school" />
				<NcTextField
					v-else
					v-model="form.schoolRef"
					:label="t('portaliq', 'School reference')"
					:helperText="t('portaliq', 'The school app offers no list of schools. Enter the school\'s reference.')"
					data-testid="news-item-school-ref" />
			</template>
			<NcSelect
				v-else-if="form.audience === 'groups'"
				v-model="groups"
				:options="options.groups"
				label="label"
				multiple
				:taggable="options.groups.length === 0"
				:inputLabel="t('portaliq', 'Groups')"
				:placeholder="options.groups.length === 0 ? t('portaliq', 'Enter a group reference') : ''"
				data-testid="news-item-groups" />

			<ul v-if="missing.length > 0" class="news-item__missing" role="alert">
				<li v-for="sentence in missing" :key="sentence">
					{{ t('portaliq', sentence) }}
				</li>
			</ul>
		</div>

		<template #actions>
			<NcButton @click="$emit('close', null)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="news-item-save"
				@click="confirm">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { emptyForm, formFromItem, missingFields } from '../lib/newsAuthoring.js'

export default {
	name: 'NewsItemDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The news item to change, or null for a new one. */
		item: {
			type: Object,
			default: null,
		},

		/** The school and group choices, `{schools, groups}` of `{id, label}`. */
		options: {
			type: Object,
			default: () => ({ schools: [], groups: [] }),
		},
	},

	emits: ['close'],

	data() {
		return {
			form: this.item ? formFromItem(this.item) : emptyForm(),
			missing: [],
		}
	},

	computed: {
		/** The chosen school as an option, read and written through the form. */
		school: {
			/**
			 * @return {object|null}
			 * @spec openspec/changes/staff-news-screen/tasks.md#T4
			 */
			get() {
				return this.optionFor(this.options.schools, this.form.schoolRef)
			},
			/**
			 * @param {object|null} option The chosen school.
			 * @spec openspec/changes/staff-news-screen/tasks.md#T4
			 */
			set(option) {
				this.form.schoolRef = option?.id || ''
			},
		},

		/** The chosen groups as options, read and written through the form. */
		groups: {
			/**
			 * @return {Array<object>}
			 * @spec openspec/changes/staff-news-screen/tasks.md#T4
			 */
			get() {
				return this.form.groupRefs.map((ref) => this.optionFor(this.options.groups, ref))
			},
			/**
			 * @param {Array} selected The chosen groups.
			 * @spec openspec/changes/staff-news-screen/tasks.md#T4
			 */
			set(selected) {
				this.form.groupRefs = (selected || [])
					.map((option) => (typeof option === 'string' ? option : option?.id || option?.label || ''))
					.filter((ref) => ref !== '')
			},
		},
	},

	/**
	 * A single school needs no choosing.
	 *
	 * @spec openspec/changes/staff-news-screen/tasks.md#T4
	 */
	created() {
		if (!this.item && this.options.schools.length === 1) {
			this.form.schoolRef = this.options.schools[0].id
		}
	},

	methods: {
		t,

		/**
		 * The option for a reference, or one showing the reference itself.
		 *
		 * @param {Array} list The options.
		 * @param {string} ref The reference.
		 * @return {object|null}
		 *
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		optionFor(list, ref) {
			if (!ref) {
				return null
			}
			return list.find((option) => option.id === ref) || { id: ref, label: ref }
		},

		/**
		 * Close with the form, or say what is missing.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		confirm() {
			this.missing = missingFields(this.form)
			if (this.missing.length > 0) {
				return
			}
			this.$emit('close', { ...this.form })
		},
	},
}
</script>

<style scoped>
.news-item {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.news-item__audience {
	border: none;
	margin: 0;
	padding: 0;
}

.news-item__audience legend {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline);
}

.news-item__missing {
	color: var(--color-error-text);
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
}
</style>
