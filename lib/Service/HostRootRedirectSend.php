<?php

/**
 * Portaliq Host Root Redirect Send (portal-host-root-redirect)
 *
 * Ends a request with a redirect, from outside a controller.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The bare address of the instance is answered by core after every app has
 * booted, with a `Location` header of its own. A redirect sent from `boot()`
 * only holds when the request ends there, so this ends it, the same way
 * core ends its own early answers (setup, upgrade page).
 *
 * Kept apart from HostRootRedirect so tests can watch the redirect without
 * ending the test run.
 *
 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
 */
class HostRootRedirectSend {
	/**
	 * Answer 302 with this location and end the request.
	 *
	 * @param string $location A path on the same host.
	 *
	 * @return never
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression) Core answers the bare address after boot; only ending the request keeps this answer.
	 *
	 * @codeCoverageIgnore Ends the process.
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
	 */
	public function redirect(string $location): never {
		http_response_code(302);
		header('Location: ' . $location);
		exit();
	}//end redirect()
}//end class
