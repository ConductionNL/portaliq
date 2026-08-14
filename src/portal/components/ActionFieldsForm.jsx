// SPDX-License-Identifier: EUPL-1.2
//
// Inline, labelled field-collection form for a `create` or `endpoint` action
// that declares a `fields` whitelist (portal-spa-nl-design-system-styling —
// replaces the previous `window.prompt()` loop, the same un-themeable,
// unlabelled, un-testable native-dialog pattern ADR-004 already bans for the
// Vue surface). Every input is a `FormFieldTextbox` — label + input are
// programmatically associated by the Utrecht component itself, so there is
// no placeholder-only labelling, and the form is keyboard-submittable
// (Enter key on the last field, or the explicit submit button).

import { Button, FormFieldTextbox } from '@utrecht/component-library-react'
import React, { useState } from 'react'

/**
 *
 * @param root0
 * @param root0.fields
 * @param root0.t
 * @param root0.onSubmit
 * @param root0.onCancel
 */
export default function ActionFieldsForm({ fields, t, onSubmit, onCancel }) {
	const [values, setValues] = useState({})

	/**
	 *
	 * @param field
	 * @param value
	 */
	function handleChange(field, value) {
		setValues((v) => ({ ...v, [field]: value }))
	}

	/**
	 *
	 * @param event
	 */
	function handleSubmit(event) {
		event.preventDefault()
		onSubmit(values)
	}

	return (
		<form className="portaliq-action-form" onSubmit={handleSubmit}>
			{fields.map((field) => (
				<FormFieldTextbox
					key={field}
					label={t('{field}?', { field })}
					value={values[field] || ''}
					onChange={(event) => handleChange(field, event.target.value)}
				/>
			))}
			<div className="portaliq-action-form__buttons">
				<Button type="submit" appearance="primary-action-button">{t('Confirm')}</Button>
				<Button type="button" appearance="subtle-button" onClick={onCancel}>{t('Cancel')}</Button>
			</div>
		</form>
	)
}
