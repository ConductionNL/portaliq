<?php

/**
 * Contract of BrokerLogin
 *
 * The public surface of BrokerLogin that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of BrokerLogin.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BrokerLoginInterface {

	/**
	 * Complete a login: the minted bearer and where to land, or null when no
	 * state row of this route was spent.
	 *
	 * A failure after the row is spent answers an empty token with the row's
	 * return address, so the failure message shows on the portal the login
	 * started from (portal-broker-login-keeps-the-portal). The reason is
	 * still not told.
	 *
	 * @param string $state The relay state integriq handed back.
	 * @param string $code  The one-time code.
	 *
	 * @return array{token: string, returnTo: string}|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-envelope-becomes-an-ordinary-portal-session-req-bel-005
	 */
	public function complete(string $state, string $code): ?array;

	/**
	 * The address to send the browser to, or null when this organisation does
	 * not route this provider to a complete broker.
	 *
	 * @param string $org         The organisation slug.
	 * @param string $provider    The provider.
	 * @param string $returnTo    The portal path to land on once signed in.
	 * @param string $callbackUrl Portaliq's absolute broker callback address.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-broker-start-binds-the-login-to-one-organisation-and-one-provider-req-bel-002
	 */
	public function start(string $org, string $provider, string $returnTo, string $callbackUrl): ?string;
}//end interface
