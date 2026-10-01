// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Drives an Options API component in plain node, with no DOM: `instance()`
// builds the `this` its methods see (props, data, computed getters, bound
// methods and a recording `$emit`), and `inState()` wraps a component so it
// renders with chosen data and without running its loading hook. Used by the
// site page specs (site-reaches-portal-parity, slice d) together with
// render-sfc.mjs.

/**
 * The default of each declared prop.
 *
 * @param {object} props The component's `props` option.
 * @return {object} Name to default.
 */
function propDefaults(props = {}) {
	const out = {}
	for (const [name, spec] of Object.entries(props)) {
		const value = spec?.default
		out[name] = typeof value === 'function' && spec.type !== Function ? value() : (value ?? null)
	}
	return out
}

/**
 * A component's `this`, without Vue's reactivity.
 *
 * @param {object} component The component options.
 * @param {object} props The props.
 * @return {object} The instance, with `emitted` listing every `$emit`.
 */
export function instance(component, props = {}) {
	const ctx = { ...propDefaults(component.props), ...props, emitted: [] }
	ctx.$emit = (name, value) => ctx.emitted.push([name, value])
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), enumerable: true })
	}
	Object.assign(ctx, component.data ? component.data.call(ctx) : {})
	for (const [name, method] of Object.entries(component.methods || {})) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * The component with chosen data and no `created` or `mounted` hook.
 *
 * @param {object} component The component options.
 * @param {object} state Data to merge over the component's own.
 * @return {object} The wrapped component.
 */
export function inState(component, state = {}) {
	return {
		...component,
		data() {
			return { ...(component.data ? component.data.call(this) : {}), ...state }
		},
		created() {},
		mounted() {},
	}
}

/**
 * The identity translator: English source strings with interpolation.
 *
 * @param {string} key The English string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The string.
 */
export function t(key, vars) {
	let text = key
	for (const [name, value] of Object.entries(vars || {})) {
		text = text.split(`{${name}}`).join(String(value))
	}
	return text
}
