<?php

/**
 * Portaliq Traffic Raw Request Body.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The request body as bytes, bounded.
 *
 * The collector accepts `text/plain` so a browser on another origin can send
 * it as a simple request with no preflight. Nextcloud's IRequest parses
 * form and JSON bodies for the controller; a text body it leaves alone, so
 * the bytes are read here from the input stream, and never more than the
 * cap plus one, so an oversized post is refused without being buffered.
 *
 * A class rather than a function so the controller's tests can hand it a
 * body without a real request.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
 */
class RawRequestBody {

	/**
	 * Read up to `$max` bytes of the request body.
	 *
	 * @param int $max The most bytes accepted.
	 *
	 * @return string|null The body, or null when it exceeds `$max`.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
	 */
	public function read(int $max): ?string {
		$stream = fopen('php://input', 'rb');
		if ($stream === false) {
			return '';
		}

		$body = stream_get_contents($stream, $max + 1);
		fclose($stream);
		if ($body === false) {
			return '';
		}

		if (strlen($body) > $max) {
			return null;
		}

		return $body;
	}
}
