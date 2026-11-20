/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Measuring text contrast against what is actually painted behind it
 * (portal-theme-blocks-and-contributed-pages REQ-PTB-013).
 *
 * ONE IMPLEMENTATION, MORE THAN ONE CONSUMER. `tests/site-surfaces.spec.mjs`
 * runs it over a rendered page, and a page editor can run it over its canvas.
 * A second copy would drift, and the drifted copy would be the one telling an
 * author their page is fine.
 *
 * It is written as ONE self-contained function on purpose: the surface check
 * injects it into a browser by stringifying it, so it may not close over
 * anything in this module's scope. That is why the helpers are nested.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-chrome-must-meet-wcag-21-aa-on-what-is-actually-painted-req-ptb-013
 */

/**
 * Every text node under `root` whose contrast falls below WCAG 2.1 AA: 4.5:1,
 * or 3:1 for large text (24px, or 18.66px at weight 700 and above).
 *
 * @param {object} [root] The element to audit; defaults to the document body.
 * @return {{failures: Array<object>, measured: number}} The findings.
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-chrome-must-meet-wcag-21-aa-on-what-is-actually-painted-req-ptb-013
 */
export function auditContrast(root) {
	const scope = root || document.body

	// `rgb()` / `rgba()` to [r, g, b, a]. The ALPHA is the part that matters
	// and the part an obvious implementation drops.
	const parse = (colour) => {
		const n = (String(colour || '').match(/[\d.]+/g) || ['0', '0', '0']).map(Number)
		return [n[0] || 0, n[1] || 0, n[2] || 0, n.length > 3 ? n[3] : 1]
	}

	const luminance = ([r, g, b]) => {
		const channel = (c) => {
			const v = c / 255
			return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
		}
		return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
	}

	// TRANSLUCENT TEXT IS COMPOSITED OVER ITS BACKDROP BEFORE IT IS MEASURED.
	// Read as opaque, `rgba(255,255,255,0.22)` scores 17.85:1 on a navy band
	// it can barely be seen on.
	const composite = (front, back) => {
		const alpha = front[3]
		return [0, 1, 2].map((i) => front[i] * alpha + back[i] * (1 - alpha))
	}

	// THE BACKDROP IS THE NEAREST ANCESTOR THAT ACTUALLY PAINTS ONE, not the
	// nearest named band. Scrims of 30% alpha or less are skipped: the band
	// under them is what a reader perceives. When nothing paints, the page's
	// own background answers, else white.
	const backdrop = (el) => {
		for (let node = el; node; node = node.parentElement) {
			const colour = parse(getComputedStyle(node).backgroundColor)
			if (colour[3] > 0.3) {
				return colour
			}
		}
		return [255, 255, 255, 1]
	}

	const isLarge = (style) => {
		const size = parseFloat(style.fontSize) || 0
		const weight = parseInt(style.fontWeight, 10) || 400
		return size >= 24 || (size >= 18.66 && weight >= 700)
	}

	const failures = []
	let measured = 0

	for (const el of scope.querySelectorAll('h1,h2,h3,h4,h5,h6,p,a,span,button,li,dt,dd,label')) {
		// Decoration is exempt: it carries no text a reader has to make out.
		if (el.closest('[aria-hidden="true"]')) {
			continue
		}

		const text = (el.textContent || '').trim()
		if (!text || el.children.length > 0) {
			continue
		}

		const box = el.getBoundingClientRect()
		if (box.width < 4 || box.height < 4) {
			continue
		}

		const style = getComputedStyle(el)
		const behind = backdrop(el)
		const front = composite(parse(style.color), behind)
		const [lighter, darker] = [luminance(front), luminance(behind)].sort((a, b) => b - a)
		const ratio = (lighter + 0.05) / (darker + 0.05)
		const required = isLarge(style) ? 3 : 4.5
		measured++

		if (ratio < required) {
			failures.push({
				ratio: Number(ratio.toFixed(2)),
				required,
				text: text.slice(0, 30),
				colour: style.color,
			})
		}
	}

	return { failures, measured }
}
