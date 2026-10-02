// SPDX-License-Identifier: EUPL-1.2
//
// The frame's half of the height negotiation (embedded-intake-form T05).
//
// 🔴 THE HOST PAGE CANNOT SEE INSIDE THIS FRAME. Cross-origin, its scripts
// cannot measure our content and ours cannot touch its layout, so unless the
// frame says how tall it is, nobody knows. An iframe sized by nothing renders
// at whatever the host's CSS left it, and on plenty of sites that is zero: a
// blank strip where a form should be, with nothing logged and nothing on
// screen to say why. The municipality finds out when somebody telephones.
//
// 🔴 SO THE MINIMUM IS DECLARED IN THE SNIPPET, NOT NEGOTIATED. `min-height`
// in the pasted iframe holds before the first message, holds if this script
// never runs, and holds if the site's web team pasted the iframe without the
// listener. The negotiation makes a long form taller. It is not what makes the
// form visible, and it must never be the only thing that does.
//
// 🔴 AND WE POST TO A NAMED TYPE. A host page's listener hears every frame on
// the page, so a bare `{ height: 900 }` from an advertisement would resize
// this one. The type is namespaced and the listener checks the origin.

/** The message type the snippet's listener accepts. Keep in step with PortalEmbedHeight::MESSAGE_TYPE. */
export const EMBED_HEIGHT_MESSAGE = 'portaliq:embed:height'

/** Never report below this. Keep in step with PortalEmbedGuard::MINIMUM_HEIGHT. */
export const EMBED_MINIMUM_HEIGHT = 480

/** A report above this has measured something other than itself. */
export const EMBED_MAXIMUM_HEIGHT = 20000

/**
 * The height to report for a measured value.
 *
 * Clamped at both ends. A form that measures itself at 40 pixels mid-render
 * would otherwise hide itself just as effectively as never reporting at all,
 * and one that reports sixty thousand leaves the host scrolling through empty
 * space for a minute.
 *
 * @param {number} measured The measured document height.
 * @return {number} The height to report.
 */
export function heightToReport(measured) {
	if (typeof measured !== 'number' || Number.isFinite(measured) === false) {
		return EMBED_MINIMUM_HEIGHT
	}

	return Math.max(
		EMBED_MINIMUM_HEIGHT,
		Math.min(EMBED_MAXIMUM_HEIGHT, Math.round(measured)),
	)
}

/**
 * Measure this document.
 *
 * @param {Document} doc The document.
 * @return {number} Its height in pixels.
 */
export function measureDocument(doc) {
	const body = doc?.body
	const html = doc?.documentElement
	if (!body || !html) {
		return EMBED_MINIMUM_HEIGHT
	}

	return Math.max(
		body.scrollHeight || 0,
		body.offsetHeight || 0,
		html.clientHeight || 0,
		html.scrollHeight || 0,
		html.offsetHeight || 0,
	)
}

/**
 * Start reporting this frame's height to whoever framed it.
 *
 * Does nothing when the page is not framed: posting to `window.parent` when
 * the parent is ourselves is harmless but pointless, and the guard makes that
 * explicit rather than incidental.
 *
 * @param {object} [options] Options.
 * @param {Window} [options.win] The window (test seam).
 * @param {Function} [options.onReport] Called with each reported height (test seam).
 * @return {Function} Stops reporting.
 */
export function startHeightReporting({
	win = typeof window === 'undefined' ? null : window,
	onReport = null,
} = {}) {
	if (win === null || win.parent === win) {
		return () => {}
	}

	let last = null

	const report = () => {
		const height = heightToReport(measureDocument(win.document))
		if (height === last) {
			// A host listener that is handed the same number forty times a
			// second is a host listener somebody will remove.
			return
		}

		last = height
		// '*' as the target origin: the frame does not know which of its
		// allowed origins framed it, and the payload carries nothing private.
		// The LISTENER is where the origin is checked, and that check is the
		// one that matters.
		win.parent.postMessage({ type: EMBED_HEIGHT_MESSAGE, height }, '*')
		if (onReport !== null) {
			onReport(height)
		}
	}

	report()
	win.addEventListener('load', report)
	win.addEventListener('resize', report)

	let observer = null
	if (typeof win.ResizeObserver === 'function' && win.document?.body) {
		observer = new win.ResizeObserver(report)
		observer.observe(win.document.body)
	}

	return () => {
		win.removeEventListener('load', report)
		win.removeEventListener('resize', report)
		if (observer !== null) {
			observer.disconnect()
		}
	}
}
