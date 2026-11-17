/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Measuring text contrast against what is actually painted behind it.
 *
 * `tests/site-surfaces.spec.mjs` runs this over a rendered page; the unit
 * spec `tests/site-contrast.spec.mjs` pins the rule itself. One definition of
 * "fails AA": a second copy would drift, and the drifted copy would be the one
 * reporting a page as readable.
 *
 * It is written as ONE self-contained function on purpose: the rendered-page
 * check injects it into a browser by stringifying it, so it may not close over
 * anything in this module's scope. That is why the helpers are nested.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */

/**
 * Every text element under `root` whose contrast falls below AA.
 *
 * The backdrop of an element is the first ancestor (the element included) that
 * actually PAINTS a background, never the nearest named band. Translucent text
 * is composited over that backdrop before it is measured.
 *
 * @param {object} [root] The element to audit; defaults to the document body.
 * @param {object} [view] Supplies `getComputedStyle`; defaults to the window.
 * @return {{failures: Array<object>, measured: number}} The findings.
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */
export function auditContrast(root, view) {
	const win = view || globalThis
	const scope = root || win.document.body
	const styleOf = (el) => win.getComputedStyle(el)

	// `rgb()` / `rgba()` to [r, g, b, a]. The alpha is the part an obvious
	// implementation drops.
	const parse = (colour) => {
		const n = (String(colour || '').match(/[\d.]+/g) || ['0', '0', '0']).map(
			Number,
		)
		return [n[0] || 0, n[1] || 0, n[2] || 0, n.length > 3 ? n[3] : 1]
	}

	const luminance = ([r, g, b]) => {
		const channel = (c) => {
			c /= 255
			return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
		}
		return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
	}

	// Translucent text is composited over its backdrop: rgba(255,255,255,0.22)
	// read as opaque white scores 17.85:1 on a navy band it can barely be seen on.
	const composite = (fg, bg) => {
		const [fr, fgreen, fb, fa] = fg
		const [br, bgreen, bb] = bg
		return [
			fr * fa + br * (1 - fa),
			fgreen * fa + bgreen * (1 - fa),
			fb * fa + bb * (1 - fa),
		]
	}

	// The backdrop is the nearest ancestor that paints one. Nearly-transparent
	// scrims (alpha <= 0.3) are not the surface a reader perceives; when nothing
	// paints, the page's own background is the answer, then white.
	const backdrop = (el) => {
		for (let node = el; node; node = node.parentElement) {
			const bg = styleOf(node).backgroundColor
			if (!bg || bg === 'transparent') {
				continue
			}
			const [, , , alpha] = parse(bg)
			if (alpha > 0.3) {
				return bg
			}
		}
		const body = win.document && win.document.body
		const pageBg = body ? styleOf(body).backgroundColor : ''
		return pageBg && parse(pageBg)[3] > 0.3 ? pageBg : 'rgb(255, 255, 255)'
	}

	const failures = []
	let measured = 0

	for (const el of scope.querySelectorAll(
		'h1,h2,h3,h4,h5,h6,p,a,span,button,label,li,dt,dd,td,th',
	)) {
		// Decoration is exempt: it carries no text a reader has to make out.
		if (el.closest('[aria-hidden="true"]')) {
			continue
		}

		const text = (el.textContent || '').trim()
		if (!text || el.children.length) {
			continue
		}

		const box = el.getBoundingClientRect()
		if (box.width < 4 || box.height < 4) {
			continue
		}

		const style = styleOf(el)
		const on = backdrop(el)
		const behind = parse(on)
		const front = composite(parse(style.color), behind)
		const [lighter, darker] = [luminance(front), luminance(behind)].sort(
			(a, b) => b - a,
		)
		const ratio = (lighter + 0.05) / (darker + 0.05)
		measured++

		// AA for body text. Large text is allowed 3:1; treating it as 4.5 only
		// over-reports, which is the safe direction for a check.
		if (ratio < 4.5) {
			failures.push({
				ratio: Number(ratio.toFixed(2)),
				text: text.slice(0, 30),
				colour: style.color,
				on,
			})
		}
	}

	return { failures, measured }
}
