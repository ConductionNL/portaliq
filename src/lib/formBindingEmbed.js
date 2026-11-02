/**
 * The "Insert form" row action of the request forms list: fetch what a website
 * puts on a page to show the form, and hand it to the dialog.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

/**
 * The binding row without the object's own bookkeeping keys.
 *
 * @param {object} row The list row.
 * @return {object} The binding.
 */
function bindingOf(row) {
	const binding = {}
	for (const [key, value] of Object.entries(row || {})) {
		if (!key.startsWith('@') && key !== 'id' && key !== 'uuid') {
			binding[key] = value
		}
	}
	return binding
}

/**
 * Build the row-action handler.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<{data: object}>} deps.post POSTs JSON.
 * @param {(path: string) => string} deps.generateUrl Nextcloud's URL generator.
 * @param {(props: object) => unknown} deps.openDialog Opens the embed dialog.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {(text: string) => string} deps.translate Translator.
 * @return {{openFormBindingEmbed: (payload: {item: object}) => Promise<boolean>}}
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
export function createFormBindingEmbed({
	post,
	generateUrl,
	openDialog,
	notifyError,
	translate,
}) {
	return {
		/**
		 * Open the embed dialog for the row's binding.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>} False when the description could not be loaded.
		 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
		 */
		async openFormBindingEmbed({ item }) {
			let description
			try {
				const response = await post(
					generateUrl('/apps/portaliq/api/form-bindings/embed-snippet'),
					{ binding: bindingOf(item) },
				)
				description = response?.data || {}
			} catch {
				notifyError(
					translate('Could not load the code for this form. Try again.'),
				)
				return false
			}
			openDialog({
				formName: item?.formName || '',
				route: item?.route || '',
				description,
			})
			return true
		},
	}
}
