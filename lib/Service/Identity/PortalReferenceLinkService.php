<?php

/**
 * Portaliq Portal Reference Link Service
 *
 * The route for a melding: a case number and a verified address, a one-time
 * link, and no account at all. Offered only for a case type that declares the
 * `reference` identity kind, so a vergunning that declares `account` never
 * gets one, and refused the second time a link is followed.
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Issues and redeems the one-time reference link.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalReferenceLinkService {
	/**
	 * The identity kind this route belongs to.
	 */
	public const KIND_REFERENCE = 'reference';

	/**
	 * The identity kind requiring a portal session.
	 */
	public const KIND_ACCOUNT = 'account';

	/**
	 * The register the link lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a reference link.
	 */
	private const SCHEMA = 'portalReferenceLink';

	/**
	 * How long a reference link admits anybody.
	 */
	private const TTL = 'P1D';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Looks the link up by hash.
	 * @param PortalObjectWriter $writer Records and spends the link.
	 * @param ISecureRandom $random Mints the one-time secret.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Whether a case type admits the reference route.
	 *
	 * An absent or empty declaration means `account` only: the closed answer,
	 * because a case type that never considered the question has not agreed
	 * to hand its cases out on a case number.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function admitsReference(array $caseType): bool {
		$kinds = ($caseType['portalIdentityKind'] ?? []);
		if (is_array($kinds) === false) {
			return false;
		}

		return in_array(self::KIND_REFERENCE, $kinds, true);
	}//end admitsReference()

	/**
	 * Issue a one-time link for a case, or refuse.
	 *
	 * @param array<string, mixed> $caseType The case's type, for its kinds.
	 * @param string $caseReference The case number.
	 * @param string $email The address the link is sent to.
	 * @param string $organisation The tenant.
	 *
	 * @return array{token: string, expiresAt: string}|null Null when the case
	 *         type does not admit the reference route, or the call is empty.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function issue(array $caseType, string $caseReference, string $email, string $organisation): ?array {
		if ($this->admitsReference(caseType: $caseType) === false) {
			return null;
		}

		if ($caseReference === '' || $email === '' || $organisation === '') {
			return null;
		}

		$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		if ($token === '') {
			return null;
		}

		$now = new DateTimeImmutable();
		$expiry = $now->add(new \DateInterval(self::TTL));

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: [
				'caseReference' => $caseReference,
				'email' => $email,
				'organisation' => $organisation,
				'tokenHash' => hash('sha256', $token),
				'state' => 'sent',
				'issuedAt' => $now->format(DATE_ATOM),
				'expiresAt' => $expiry->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return null;
		}

		return ['token' => $token, 'expiresAt' => $expiry->format(DATE_ATOM)];
	}//end issue()

	/**
	 * Follow a link once.
	 *
	 * @param string $token The secret from the mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array{caseReference: string, organisation: string}|null Null when
	 *         the link admits nobody: unknown, already used or expired.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function redeem(string $token, ?DateTimeImmutable $now = null): ?array {
		if ($token === '') {
			return null;
		}

		$hash = hash('sha256', $token);
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'tokenHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);

		$row = null;
		foreach ($rows as $candidate) {
			if (is_array($candidate) === true && hash_equals((string)($candidate['tokenHash'] ?? ''), $hash) === true) {
				$row = $candidate;
				break;
			}
		}

		if ($row === null || (string)($row['state'] ?? '') !== 'sent') {
			// A used link and an unknown link are refused identically: the
			// difference is not the visitor's business.
			return null;
		}

		$moment = ($now ?? new DateTimeImmutable());
		$expiry = date_create_immutable((string)($row['expiresAt'] ?? ''));
		if ($expiry === false || $expiry <= $moment) {
			return null;
		}

		$id = (string)($row['uuid'] ?? $row['id'] ?? '');
		if ($id === '') {
			$self = (array)($row['@self'] ?? []);
			$id = (string)($self['uuid'] ?? $self['id'] ?? '');
		}

		if ($id === '') {
			return null;
		}

		$spent = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['state' => 'used', 'usedAt' => $moment->format(DATE_ATOM)]
		);
		if ($spent === null) {
			// The link is spent by the write, so a write that did not land
			// admits nobody: otherwise the same link would work twice.
			return null;
		}

		return [
			'caseReference' => (string)($row['caseReference'] ?? ''),
			'organisation' => (string)($row['organisation'] ?? ''),
		];
	}//end redeem()
}//end class
