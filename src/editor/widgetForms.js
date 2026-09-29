/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which control configures a widget: the shared form, the field list, or JSON.
 *
 * The shared nextcloud-vue registry (`dashboardWidgetRegistry`) lists a `form`
 * component for many widget keys (`CnTextWidgetForm`, `CnImageWidgetForm`, ...).
 * Those forms are what every dashboard in the fleet configures a widget with,
 * so a page does the same. The forms speak `content`; a page stores `props`.
 * The mapping is a plain copy both ways, and it lives here so no host maps it
 * differently.
 *
 * The public site blocks (`hero`, `section`, `cardGrid`, ...) render through a
 * different component than any dashboard widget, so they never borrow a
 * dashboard form, even under the same key. They keep the fields read from
 * their own props, and a key with neither is edited as one JSON object.
 *
 * The registry is handed in: importing it here would pull the whole component
 * library into every test and every consumer of this module.
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
 */

/**
 * The shared configuration form for a key, or null.
 *
 * @param {string} key The widget key.
 * @param {object} registry The shared widget registry.
 * @return {object|null} The form component.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
 */
export function sharedFormFor(key, registry) {
	if (!registry || !Object.hasOwn(registry, key)) {
		return null
	}
	return registry[key]?.form || null
}

/**
 * How the inspector configures a key.
 *
 * @param {string} key The widget key.
 * @param {object} context The lookups.
 * @param {object} context.registry The shared widget registry.
 * @param {Function} context.isPublic (key) => whether the site renders it.
 * @param {Array<object>} context.fields The fields read from the component.
 * @return {'form'|'fields'|'json'} The mode.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
 */
export function inspectorModeFor(key, { registry, isPublic, fields }) {
	if (!isPublic(key) && sharedFormFor(key, registry)) {
		return 'form'
	}
	return fields && fields.length ? 'fields' : 'json'
}

/**
 * The placement handed to a shared form, in the shape `CnAddWidgetModal` uses.
 *
 * @param {object} widget The placement.
 * @return {{type: string, content: object}} The form's editing widget.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
 */
export function formWidgetFor(widget) {
	return { type: widget.widgetKey, content: { ...(widget.props || {}) } }
}

/**
 * The props to store for what a shared form emitted.
 *
 * @param {object} content The form's `update:content` payload.
 * @return {object} The props.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
 */
export function propsFromFormContent(content) {
	return JSON.parse(JSON.stringify(content || {}))
}
