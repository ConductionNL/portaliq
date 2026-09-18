<?php

/**
 * Portaliq Portal Embed Guard
 *
 * Which origins may frame a portal form, and what the frame's
 * `frame-ancestors` says.
 *
 * The list is the boundary, and it fails closed in the direction that matters:
 * a form whose `allowedOrigins` is empty serves to nobody. It never becomes a
 * wildcard, because a wildcard is how an intake form ends up framed inside a
 * phishing page that collects the answers on the way past.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Decides who may frame a form, and describes the embed to an administrator.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalEmbedGuard {
	/**
	 * The height the frame starts at when no message has arrived yet.
	 */
	public const MINIMUM_HEIGHT = 480;

	/**
	 * The origins a binding allows, normalised to scheme and host.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function allowedOrigins(array $binding): array {
		$declared = ($binding['allowedOrigins'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$origins = [];
		foreach ($declared as $origin) {
			$normalised = $this->normalise(origin: (string)(is_string($origin) === true ? $origin : ''));
			if ($normalised !== '' && in_array($normalised, $origins, true) === false) {
				$origins[] = $normalised;
			}
		}

		return $origins;
	}//end allowedOrigins()

	/**
	 * Whether this binding may be framed at all.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function isEmbeddable(array $binding): bool {
		return $this->allowedOrigins(binding: $binding) !== [];
	}//end isEmbeddable()

	/**
	 * Whether a framing origin is on the list.
	 *
	 * The comparison is on scheme and host together, so `http://` is not the
	 * same origin as `https://`, and a host that merely ends with an allowed
	 * one does not pass.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 * @param string $origin The framing origin, as the request carries it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function allows(array $binding, string $origin): bool {
		$normalised = $this->normalise(origin: $origin);
		if ($normalised === '') {
			return false;
		}

		return in_array($normalised, $this->allowedOrigins(binding: $binding), true);
	}//end allows()

	/**
	 * The `frame-ancestors` value for this binding. Never a wildcard, and
	 * `'none'` when the list is empty.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function frameAncestors(array $binding): string {
		$origins = $this->allowedOrigins(binding: $binding);
		if ($origins === []) {
			return "'none'";
		}

		return implode(' ', $origins);
	}//end frameAncestors()

	/**
	 * The snippet an administrator copies, and the origins named beside it.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 * @param string $frameUrl The absolute URL of this form's frame route.
	 *
	 * @return array{embeddable: bool, origins: array<int, string>, snippet: string, listener?: string, minimumHeight?: int}
	 *         `snippet` is empty when the form is not embeddable yet, so the
	 *         admin cannot copy something that would only render a message.
	 *         `listener` is the optional resize half: pasting the iframe alone
	 *         still shows the form, at the declared minimum height.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function snippetFor(array $binding, string $frameUrl): array {
		$origins = $this->allowedOrigins(binding: $binding);
		if ($origins === [] || $frameUrl === '') {
			return ['embeddable' => false, 'origins' => [], 'snippet' => ''];
		}

		// The iframe and the listener come from PortalEmbedHeight, so the floor
		// in the pasted markup and the floor the frame reports are one number
		// rather than two that can drift apart.
		$parts = (new PortalEmbedHeight())->snippet(
			frameUrl: $frameUrl,
			title: (string)($binding['route'] ?? 'formulier')
		);

		return [
			'embeddable' => true,
			'origins' => $origins,
			'snippet' => $parts['iframe'],
			'listener' => $parts['listener'],
			'minimumHeight' => $parts['minimumHeight'],
		];
	}//end snippetFor()

	/**
	 * Normalise an origin to `scheme://host[:port]`, or '' when it is not one.
	 *
	 * @param string $origin The origin as declared or as sent.
	 *
	 * @return string
	 */
	private function normalise(string $origin): string {
		$origin = trim($origin);
		if ($origin === '') {
			return '';
		}

		$parts = parse_url($origin);
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
			return $scheme . '://' . $host;
		}

		return $scheme . '://' . $host . ':' . (int)$port;
	}//end normalise()
}//end class
