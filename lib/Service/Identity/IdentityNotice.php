<?php

/**
 * Portaliq Identity Notice
 *
 * One informing identity mail to send: which notice, to whom, for which
 * tenant and portal, and the moment its line names. It carries no secret
 * and no link.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeInterface;

/**
 * An informing mail: which notice, to whom and when.
 *
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */
final class IdentityNotice {
	/**
	 * Constructor.
	 *
	 * @param string                    $template     The notice key.
	 * @param string                    $email        The recipient.
	 * @param string                    $organisation The tenant.
	 * @param array<string, mixed>|null $portal       The portal, or null to look it up.
	 * @param DateTimeInterface         $moment       The moment the line names.
	 */
	public function __construct(
		public readonly string $template,
		public readonly string $email,
		public readonly string $organisation,
		public readonly ?array $portal,
		public readonly DateTimeInterface $moment,
	) {
	}//end __construct()

	/**
	 * The same notice for the given portal.
	 *
	 * @param array<string, mixed>|null $portal The portal the notice speaks for.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function withPortal(?array $portal): self {
		return new self(
			template: $this->template,
			email: $this->email,
			organisation: $this->organisation,
			portal: $portal,
			moment: $this->moment
		);
	}//end withPortal()
}//end class
