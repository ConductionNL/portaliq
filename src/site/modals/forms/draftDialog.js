// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Opening and closing the draft dialogs (form-save-and-resume-dialogs) as
// modals, with a fallback for a browser without showModal.

/**
 * Open a dialog as a modal and put focus on its heading.
 *
 * @param {HTMLDialogElement|undefined} dialog The dialog.
 * @param {HTMLElement|undefined} heading Its heading.
 * @return {void}
 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-saving-a-form-first-says-what-saving-does-req-fsr-001
 */
export function openDialog(dialog, heading) {
	if (typeof dialog?.showModal === 'function') {
		dialog.showModal()
	} else if (typeof dialog?.setAttribute === 'function') {
		dialog.setAttribute('open', '')
	}
	if (typeof heading?.focus === 'function') {
		heading.focus()
	}
}

/**
 * Close a dialog that is open.
 *
 * @param {HTMLDialogElement|undefined} dialog The dialog.
 * @return {void}
 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-saving-a-form-first-says-what-saving-does-req-fsr-001
 */
export function closeDialog(dialog) {
	if (dialog?.open && typeof dialog.close === 'function') {
		dialog.close()
	}
}
