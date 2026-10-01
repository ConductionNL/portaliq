// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Slice c of the site parity port: forms and actions. Slice b's contributed
 * page (`ContributionPage.vue`) mounts these where PageView.jsx mounted their
 * React originals. Every component loads on first use (the site bundle sits at
 * its size budget), so importing this file costs a few hundred bytes.
 *
 * Every component takes `t`, the page's `t(key, vars)`; without one it shows
 * the English source string. The strings are in `src/site/pages/c/strings.js`.
 * `api` is the portal api (`src/portal/lib/portalApi.js` `createPortalApi()`,
 * later `src/shared/portalApi.js`). Events are Vue emits.
 *
 * Where PageView.jsx used each one:
 *
 * - `action` / `cta` block            -> `<ActionBlock :block :contribution :api :t @submitted>`
 * - detail block, `propose-change`    -> `<ProposalQueue :action :row :api :t>`
 * - detail block, attached actions    -> `<AttachedActions :collection :row :api :t>`
 * - table, endpoint row action press  -> `<RowActionDialog :action :row-actions :collection :row :api :t @done @close>`
 * - table, `type: update` row action  -> `runRowTransition(api, action, row)`, then reload the collection
 * - table, which buttons a row shows  -> `tableRowActions(...)`, `offersRowAction(action, row)`, `isEndpointRowAction(action)`
 *
 * @typedef {(key: string, vars?: Record<string, string|number>) => string} Translate
 *
 * @typedef {object} SchemaFormProps
 * @property {object} action The normalised `create`/`update` action (`id`, `fields`, `fieldConfigs`, `optionsProviders`, `submitLabel`, `successMessage`).
 * @property {object} api `createObject`, `uploadFieldFile`, `fetchOptions`.
 * @property {Translate} [t] The translator.
 * Emits `submitted(object, action)` after a save.
 *
 * @typedef {object} SchemaFieldProps
 * @property {string} id The input id. @property {string} field The field. @property {string} label The label.
 * @property {object} [config] The field config. @property {string} [input] From `fieldInput()`.
 * @property {Array<{value: string, label: string}>} [options] Select options. @property {string} [modelValue] The value (v-model).
 * @property {File[]} [files] Picked files. @property {number} [fileKey] Bump to clear the file input.
 * @property {string} [error] The inline error. @property {Translate} [t] The translator.
 * Emits `update:modelValue(value)` and `pick(FileList)`.
 *
 * @typedef {object} ActionBlockProps
 * @property {object} block `{type: 'action'|'cta', action, label?}`.
 * @property {object} contribution The contribution (`app`, `actions`).
 * @property {object} api The portal api. @property {Translate} [t] The translator.
 * @property {(url: string) => void} [navigate] Where a checked redirect goes.
 * Emits `submitted(object, action)` from its form.
 *
 * @typedef {object} ActionButtonProps
 * @property {object} action The endpoint action. @property {string} [app] The contributing app.
 * @property {string} [label] The button text. @property {boolean} [requireEndpoint] Disable without `endpoint`.
 * @property {object|null} [api] `forwardAction`; the site session when null. @property {Translate} [t] The translator.
 * @property {(url: string) => void} [navigate] Where a checked redirect goes.
 *
 * @typedef {object} ProposeChangeFormProps
 * @property {object} action The `propose-change` action. @property {object|null} row The record.
 * @property {(changes: Array<{property: string, proposedValue: string}>, note: string) => Promise<{ok: boolean}>} send Sends it.
 * @property {Translate} [t] The translator. Emits `cancel` and `sent(result)`.
 *
 * @typedef {object} ProposalQueueProps
 * @property {object} action The `propose-change` action. @property {object|null} row The record.
 * @property {object} api `fetchMyProposals`, `proposeChange`, `withdrawProposal`. @property {Translate} [t] The translator.
 *
 * @typedef {object} AttachedActionsProps
 * @property {object} collection The collection (`attachedActions`). @property {object|null} row The record.
 * @property {object|null} api `forwardRowAction`. @property {Translate} [t] The translator.
 *
 * @typedef {object} RowActionDialogProps
 * @property {object} action The pressed endpoint row action. @property {object[]} [rowActions] All resolved row actions.
 * @property {object} collection The collection. @property {object} row The row. @property {object} api `forwardRowAction`.
 * @property {Translate} [t] The translator. @property {(url: string) => void} [navigate] Where a checked redirect goes.
 * Emits `done` (reload the collection) and `close` (drop the pending action).
 *
 * The three dialogs in `src/site/modals/c/` (RowActionConfirm, SigningDialog,
 * DeclineDialog) take the RowActionDialog props, minus `rowActions`; the
 * signing dialog takes `viewAction` instead.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */

import { defineAsyncComponent } from 'vue'

export const SchemaForm = defineAsyncComponent(() => import('./SchemaForm.vue'))
export const SchemaField = defineAsyncComponent(() => import('./SchemaField.vue'))
export const ActionBlock = defineAsyncComponent(() => import('./ActionBlock.vue'))
export const ActionButton = defineAsyncComponent(() => import('./ActionButton.vue'))
export const ProposeChangeForm = defineAsyncComponent(
	() => import('./ProposeChangeForm.vue'),
)
export const ProposalQueue = defineAsyncComponent(
	() => import('./ProposalQueue.vue'),
)
export const AttachedActions = defineAsyncComponent(
	() => import('./AttachedActions.vue'),
)
export const RowActionDialog = defineAsyncComponent(
	() => import('./RowActionDialog.vue'),
)

export { runRowTransition } from './forms.js'
export { isEndpointRowAction, offersRowAction } from '../../../shared/rowAction.js'
export { tableRowActions } from '../../../shared/signing.js'
export { default as strings } from '../../pages/c/strings.js'
