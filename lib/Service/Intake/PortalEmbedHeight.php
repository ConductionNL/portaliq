<?php

/**
 * How tall the frame is on somebody else's page, and what happens when it never says.
 *
 * 🔴 AN EMBED WITH NO FLOOR COLLAPSES AND EXPLAINS NOTHING. An iframe whose
 * height nobody sets renders at the browser's default, and one sized purely
 * from a `postMessage` that never arrives renders at whatever the host's CSS
 * left it: on plenty of sites that is zero. The visitor sees a blank strip
 * where a form should be. Nothing errors, nothing logs, the host page looks
 * fine, and the municipality finds out when somebody phones to say the form is
 * missing.
 *
 * So the minimum is DECLARED, in the snippet itself, as `min-height`. It holds
 * before the first message, it holds if the message never comes, and it holds
 * if the host's listener was never installed. The negotiation makes a long form
 * taller; it is not what makes it visible.
 *
 * 🔴 AND THE FRAME NEVER SHRINKS BELOW IT. A form that reports 40 pixels
 * because it measured itself mid-render would otherwise hide itself just as
 * effectively as no message at all.
 *
 * 🔴 THE HOST IS NEVER TRUSTED TO SET THE HEIGHT. The listener only accepts a
 * message whose `origin` is the frame's own origin and whose shape is this
 * protocol's, because `window.onmessage` hears from every frame on the page,
 * including ones the site owner did not put there.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Portaliq.app
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * The height protocol, and the snippet that speaks it.
 */
class PortalEmbedHeight {

	/**
	 * The message type the frame posts and the host listens for.
	 *
	 * Namespaced, because a host page's listener hears every frame on the page
	 * and a bare `{height: 900}` from an advertisement would resize this one.
	 *
	 * @var string
	 */
	public const MESSAGE_TYPE = 'portaliq:embed:height';

	/**
	 * The height below which the frame is never sized.
	 *
	 * @var int
	 */
	public const MINIMUM_HEIGHT = PortalEmbedGuard::MINIMUM_HEIGHT;

	/**
	 * The height above which a reported value is treated as a mistake.
	 *
	 * A form reporting sixty thousand pixels has measured something other than
	 * itself, and honouring it leaves the host page scrolling through empty
	 * space for a minute.
	 *
	 * @var int
	 */
	public const MAXIMUM_HEIGHT = 20000;

	/**
	 * The height to render for a reported value.
	 *
	 * @param mixed $reported What the frame said, if anything.
	 *
	 * @return int The height to use, never below the declared minimum.
	 */
	public function heightFor(mixed $reported): int {
		if (is_int($reported) === false && is_float($reported) === false) {
			// A missing, null or non-numeric report is the no-message case, and
			// the no-message case is the declared minimum rather than zero.
			return self::MINIMUM_HEIGHT;
		}

		$height = (int)round((float)$reported);
		if ($height < self::MINIMUM_HEIGHT) {
			return self::MINIMUM_HEIGHT;
		}

		return min($height, self::MAXIMUM_HEIGHT);
	}//end heightFor()

	/**
	 * Whether a received message is this protocol's, from the frame's own origin.
	 *
	 * @param array<string, mixed> $message      The message data.
	 * @param string               $origin       The origin it arrived from.
	 * @param string               $frameOrigin  The origin the frame was loaded from.
	 *
	 * @return bool True when it may be honoured.
	 */
	public function accepts(array $message, string $origin, string $frameOrigin): bool {
		if ($frameOrigin === '' || $origin !== $frameOrigin) {
			return false;
		}

		return ((string)($message['type'] ?? '') === self::MESSAGE_TYPE);
	}//end accepts()

	/**
	 * The snippet a site owner pastes, with the floor and the listener in it.
	 *
	 * The listener is offered but not required: paste the iframe alone and the
	 * form still works at the declared minimum with an inner scrollbar. That is
	 * the property that makes this safe to hand to somebody else's web team.
	 *
	 * @param string $frameUrl The frame's absolute url.
	 * @param string $title    The accessible name of the frame.
	 *
	 * @return array{iframe: string, listener: string, minimumHeight: int}
	 */
	public function snippet(string $frameUrl, string $title): array {
		$url = htmlspecialchars($frameUrl, ENT_QUOTES);
		$safeTitle = htmlspecialchars($title, ENT_QUOTES);
		$origin = $this->originOf(url: $frameUrl);

		$iframe = sprintf(
			'<iframe id="portaliq-form" src="%s" title="%s" loading="lazy" '
			.'style="width:100%%;border:0;min-height:%dpx"></iframe>',
			$url,
			$safeTitle,
			self::MINIMUM_HEIGHT
		);

		$listener = sprintf(
			"<script>\n"
			."window.addEventListener('message', function (event) {\n"
			."  if (event.origin !== %s) { return; }\n"
			."  var data = event.data || {};\n"
			."  if (data.type !== %s) { return; }\n"
			."  var frame = document.getElementById('portaliq-form');\n"
			."  if (!frame) { return; }\n"
			."  frame.style.height = Math.max(%d, Math.min(%d, parseInt(data.height, 10) || 0)) + 'px';\n"
			."});\n"
			."</script>",
			json_encode($origin, JSON_UNESCAPED_SLASHES),
			json_encode(self::MESSAGE_TYPE, JSON_UNESCAPED_SLASHES),
			self::MINIMUM_HEIGHT,
			self::MAXIMUM_HEIGHT
		);

		return ['iframe' => $iframe, 'listener' => $listener, 'minimumHeight' => self::MINIMUM_HEIGHT];
	}//end snippet()

	/**
	 * The origin of a url, for the listener's own check.
	 *
	 * @param string $url The frame url.
	 *
	 * @return string The origin, or an empty string.
	 */
	private function originOf(string $url): string {
		$parts = parse_url($url);
		if (is_array($parts) === false) {
			return '';
		}

		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower((string)($parts['host'] ?? ''));
		if ($scheme === '' || $host === '') {
			return '';
		}

		$port = ($parts['port'] ?? null);
		if ($port === null) {
			return $scheme.'://'.$host;
		}

		return $scheme.'://'.$host.':'.(int)$port;
	}//end originOf()
}//end class
