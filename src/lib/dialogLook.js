/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Open a dialog in the app's look.
 *
 * `spawnDialog` mounts a dialog on its own, outside CnAppRoot, so the look
 * CnAppRoot provides does not reach it and CnDialog falls back to the
 * Nextcloud look. The dialog gets the manifest's look as its `look` prop
 * instead: the board dialog (header, width, radius, shadow) under
 * `look: "board"`, Nextcloud's own dialog otherwise.
 *
 * @spec openspec/changes/admin-dialogs-board-look/specs/admin-dialogs-board-look/spec.md#requirement-a-spawned-dialog-takes-the-apps-look
 */

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import bundledManifest from '../manifest.json'

/** The look every portaliq dialog is drawn in: `board` or `nextcloud`. */
export const DIALOG_LOOK = bundledManifest.look === 'board' ? 'board' : 'nextcloud'

/**
 * Spawn a dialog with the app's look.
 *
 * @param {object} dialog The dialog component.
 * @param {object} [props] Its props; an explicit `look` wins.
 * @return {Promise<unknown>} What the dialog closes with.
 * @spec openspec/changes/admin-dialogs-board-look/specs/admin-dialogs-board-look/spec.md#requirement-a-spawned-dialog-takes-the-apps-look
 */
export function spawnLookDialog(dialog, props = {}) {
	return spawnDialog(dialog, { look: DIALOG_LOOK, ...props })
}
