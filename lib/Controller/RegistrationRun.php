<?php

/**
 * Portaliq Registration Run
 *
 * The state of one self-registration while `PortalIdentityController` walks
 * it: the portal being visited and the address registering, the name to
 * greet them by, the contact invitation they followed and the challenge
 * proof they sent. It never leaves the controller that builds it.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One self-registration in progress.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */
final class RegistrationRun {

	/**
	 * The name to greet them by.
	 *
	 * @var string
	 */
	public string $displayName = '';

	/**
	 * The secret of a contact invitation the visitor followed, or ''.
	 *
	 * @var string
	 */
	public string $contactInvitation = '';

	/**
	 * The challenge nonce they were issued, their solution to it, the expiry
	 * issued with it and this instance's signature over it.
	 *
	 * @var array{nonce: string, solution: string, expiresAt: int, signature: string}
	 */
	public array $challenge = ['nonce' => '', 'solution' => '', 'expiresAt' => 0, 'signature' => ''];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $site  The portal being visited.
	 * @param string               $email The address registering.
	 */
	public function __construct(
		public readonly array $site,
		public readonly string $email,
	) {
	}//end __construct()
}//end class
