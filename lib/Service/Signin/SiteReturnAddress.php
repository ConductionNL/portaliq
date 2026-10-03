<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Signin
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Signin;

/**
 * Where a login started on the public site may return to.
 *
 * The site sends the page the resident was on (`/apps/portaliq/site?portal=
 * wilgenboom&route=/mijn/inbox`), so the login lands back on that page with
 * its portal named. Only an address on the site route itself is accepted: a
 * path, never a host, a scheme, a protocol-relative `//`, a backslash or a
 * fragment, so the return address can never send a bearer to another origin.
 * Anything else answers '' and the caller keeps its own default.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
final class SiteReturnAddress {

	/**
	 * The return address when it is a page of the site, else ''.
	 *
	 * The `/index.php` front controller is optional on both sides, so an
	 * instance with or without pretty URLs compares the same path.
	 *
	 * @param string $candidate The `returnTo` the site sent.
	 * @param string $sitePath  The site route's own path, from the URL generator.
	 *
	 * @return string The accepted address, or ''.
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	public function accept(string $candidate, string $sitePath): string {
		$sitePath = $this->withoutFrontController(path: $sitePath);
		if ($candidate === '' || $sitePath === '' || $sitePath[0] !== '/') {
			return '';
		}

		// Every way out of this origin or out of the path is refused before the
		// path is compared: `//host`, `\host`, a fragment, whitespace and
		// control characters. A scheme cannot pass the comparison below.
		if (preg_match('/[\\\\#\s\x00-\x1f\x7f]/', $candidate) === 1 || str_contains($candidate, '//') === true) {
			return '';
		}

		$path  = $candidate;
		$query = '';
		$mark  = strpos($candidate, '?');
		if ($mark !== false) {
			$path  = substr($candidate, 0, $mark);
			$query = substr($candidate, $mark);
		}

		if ($this->withoutFrontController(path: $path) !== $sitePath) {
			return '';
		}

		return $path . $query;
	}//end accept()

	/**
	 * A path without its leading `/index.php`.
	 *
	 * @param string $path The path.
	 *
	 * @return string The path.
	 */
	private function withoutFrontController(string $path): string {
		if (str_starts_with($path, '/index.php/') === true) {
			return substr($path, strlen('/index.php'));
		}

		return $path;
	}//end withoutFrontController()
}//end class
