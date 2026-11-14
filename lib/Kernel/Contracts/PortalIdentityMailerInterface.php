<?php

/**
 * Contract of PortalIdentityMailer
 *
 * The public surface of PortalIdentityMailer that other components call, so they depend
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

use DateTimeInterface;

/**
 * Contract of PortalIdentityMailer.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalIdentityMailerInterface {

	/**
	 * The one-time link to follow one case (identity-ways-in-screens D1).
	 */
	public const TEMPLATE_REFERENCE_LINK = 'reference-link';

	/**
	 * The invitation into the portal (identity-staff-account-screens D2).
	 */
	public const TEMPLATE_INVITATION = 'invitation';

	/**
	 * The confirmation of a new address (identity-profile-page D1).
	 */
	public const TEMPLATE_EMAIL_CONFIRMATION = 'email-confirmation';

	/**
	 * The activation link of a self-registration (identity-ways-in-screens D4).
	 */
	public const TEMPLATE_REGISTRATION_ACTIVATION = 'registration-activation';

	/**
	 * The invitation of a waiting account an app provisioned: the person
	 * follows the link, signs in, and the waiting account joins theirs
	 * (invitation-secret-joins-the-signed-in-account).
	 */
	public const TEMPLATE_ACCOUNT_INVITATION = 'account-invitation';

	/**
	 * The invitation to act on someone's behalf (site-mandates-the-represented-manage).
	 */
	public const TEMPLATE_MANDATE_INVITATION = 'mandate-invitation';

	/**
	 * Mail one secret to the address it belongs to, inside its link.
	 *
	 * Never throws. A mail that did not leave answers false and is logged by
	 * template only: not the secret, not the link and not the address.
	 *
	 * @param string $template One of the TEMPLATE_* constants.
	 * @param string $email The address the secret belongs to.
	 * @param string $secret The plain one-time secret.
	 * @param string $organisation The tenant the secret belongs to.
	 * @param array<string, mixed>|null $portal The portal the request came
	 *                                          through, or null to look up
	 *                                          the organisation's one portal.
	 * @param array<string, string> $details Who wrote the mail's invitation: `inviter` (a name)
	 *                                       and `message` (their words), both optional.
	 *
	 * @return bool True when the mail left.
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	public function send(string $template, string $email, string $secret, string $organisation, ?array $portal = null, array $details = []): bool;

	/**
	 * Tell an `email` account's address that it was just used to sign in
	 * (security review L4): the portal, the moment, and who to contact when
	 * it was not them. No secret and no link.
	 *
	 * Never throws.
	 *
	 * @param string            $email        The account's sign-in address.
	 * @param string            $organisation The tenant.
	 * @param array<string, mixed>|null $portal The portal signed in to.
	 * @param DateTimeInterface $moment       When.
	 *
	 * @return bool True when the mail left.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-never-reaches-a-log-an-answer-or-the-traffic-store-req-iwi-012
	 */
	public function sendSignedInNotice(string $email, string $organisation, ?array $portal, DateTimeInterface $moment): bool;
}//end interface
