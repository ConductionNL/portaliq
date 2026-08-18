/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Measuring text contrast against what is actually painted behind it.
 *
 * ONE IMPLEMENTATION, TWO CONSUMERS. `tests/site-surfaces.spec.mjs` runs this
 * over a rendered page in CI, and the page editor runs it over the canvas as an
 * author works (task 5.2). A second copy would drift, and the drifted copy
 * would be the one telling an author their page is fine.
 *
 * It is written as ONE self-contained function on purpose: the test injects it
 * into a browser by stringifying it, so it may not close over anything in this
 * module's scope. That constraint is why the helpers are nested rather than
 * exported separately.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */

/**
 * Every text node under `root` whose contrast falls below AA.
 *
 * @param {object} root The element to audit; defaults to the document body.
 * @return {object} `{failures, measured}`.
 */
export function auditContrast(root) {
	const scope = root || document.body

	// `rgb()` / `rgba()` to [r, g, b, a]. The ALPHA is the part that matters and
	// the part an obvious implementation drops.
	const parse = (colour) => {
		const n = (colour.match(/[\d.]+/g) || ['0', '0', '0']).map(Number)
		return [n[0] || 0, n[1] || 0, n[2] || 0, n.length > 3 ? n[3] : 1]
	}

	const luminance = ([r, g, b]) => {
		const channel = (c) => {
			c /= 255
			return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
		}
		return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
	}

	// TRANSLUCENT TEXT IS COMPOSITED OVER ITS BACKDROP BEFORE IT IS MEASURED.
	//
	// Without this, `rgba(255,255,255,0.22)` is read as opaque white and scores
	// 17.85:1 against a navy band it can barely be seen on. That is not a
	// hypothetical: this footer is built from white at 0.55 to 0.85, and a
	// deliberate break to 0.22 passed the check until the compositing was
	// added. A contrast check that ignores alpha reports the colour somebody
	// typed, not the colour anybody sees.
	const composite = (fg, bg) => {
		const [fr, fg_, fb, fa] = fg
		const [br, bg_, bb] = bg
		return [
			fr * fa + br * (1 - fa),
			fg_ * fa + bg_ * (1 - fa),
			fb * fa + bb * (1 - fa),
		]
	}

	// THE BACKDROP IS THE NEAREST ANCESTOR THAT ACTUALLY PAINTS ONE.
	//
	// Not the nearest NAMED band: comparing against that produced a false
	// failure in this codebase once already, and the "fix" for it made a
	// working form invisible. Nearly-transparent scrims are skipped too — an 8%
	// white overlay is not the surface a reader perceives, the band under it is
	// — and when nothing paints, the page's own background is the answer rather
	// than an assumed white, which is what manufactured a 1.0 ratio for white
	// text on a dark footer.
	const backdrop = (el) => {
		for (let node = el; node; node = node.parentElement) {
			const bg = getComputedStyle(node).backgroundColor
			const alpha = parseFloat((bg.match(/[\d.]+\)$/) || ['1)'])[0])
			if (bg && !/rgba\(0, 0, 0, 0\)/.test(bg) && alpha > 0.3) {
				return bg
			}
		}
		return getComputedStyle(document.body).backgroundColor || 'rgb(255,255,255)'
	}

	const failures = []
	let measured = 0

	for (const el of scope.querySelectorAll(
		'h1,h2,h3,h4,p,a,span,button,li,dt,dd',
	)) {
		// Decoration is exempt: it carries no text a reader has to make out.
		if (el.closest('[aria-hidden="true"]')) continue

		const text = (el.textContent || '').trim()
		if (!text || el.children.length) continue

		const box = el.getBoundingClientRect()
		if (box.width < 4 || box.height < 4) continue

		const style = getComputedStyle(el)
		const behind = parse(backdrop(el))
		const front = composite(parse(style.color), behind)
		const [lighter, darker] = [luminance(front), luminance(behind)].sort(
			(a, b) => b - a,
		)
		const ratio = (lighter + 0.05) / (darker + 0.05)
		measured++

		// AA for body text. Large text is allowed 3:1, and treating it as 4.5
		// only ever over-reports — which is the safe direction for a check.
		if (ratio < 4.5) {
			failures.push({
				ratio: Number(ratio.toFixed(2)),
				text: text.slice(0, 30),
				colour: style.color,
				on: backdrop(el),
			})
		}
	}

	return { failures, measured }
}
