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
 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateInterval;
use DateTimeImmutable;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Issues and redeems the one-time reference link.
 *
 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
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
	 * @param ScopedCollectionReaderInterface $reader Looks the link up by hash.
	 * @param PortalObjectWriter $writer Records and spends the link.
	 * @param ISecureRandom $random Mints the one-time secret.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
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
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
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
	 * @param string $caseRegister The register of the case collection the
	 *                             link opens (identity-ways-in-screens D2).
	 * @param string $caseSchema The schema of that collection.
	 *
	 * @return array{token: string, expiresAt: string}|null Null when the case
	 *         type does not admit the reference route, or the call is empty.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each field is stored
	 * on the link row; the reference session reads the case through them.
	 */
	public function issue(
		array $caseType,
		string $caseReference,
		string $email,
		string $organisation,
		string $caseRegister = '',
		string $caseSchema = '',
	): ?array {
		$request = new ReferenceLinkRequest(
			caseType: $caseType,
			caseReference: $caseReference,
			email: $email,
			organisation: $organisation,
			caseRegister: $caseRegister,
			caseSchema: $caseSchema
		);
		return $this->issueLink(request: $request);
	}//end issue()

	/**
	 * Issue a one-time link for a case, or refuse.
	 *
	 * @param ReferenceLinkRequest $request The case, the address and the tenant.
	 *
	 * @return array{token: string, expiresAt: string}|null Null when the case
	 *         type does not admit the reference route, or the call is empty.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function issueLink(ReferenceLinkRequest $request): ?array {
		if ($this->refuses(request: $request) === true) {
			return null;
		}

		$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		if ($token === '') {
			return null;
		}

		$now    = new DateTimeImmutable();
		$expiry = $now->add(new DateInterval(self::TTL));
		$sent   = ['tokenHash' => hash('sha256', $token), 'issuedAt' => $now->format(DATE_ATOM), 'expiresAt' => $expiry->format(DATE_ATOM)];
		if ($this->storeLink(request: $request, sent: $sent) === false) {
			return null;
		}

		return ['token' => $token, 'expiresAt' => $sent['expiresAt']];
	}//end issueLink()

	/**
	 * Whether a link request is refused: the case type does not admit the
	 * reference route, or the case, the address or the tenant is missing.
	 *
	 * @param ReferenceLinkRequest $request The case, the address and the tenant.
	 *
	 * @return bool
	 */
	private function refuses(ReferenceLinkRequest $request): bool {
		if ($this->admitsReference(caseType: $request->caseType) === false) {
			return true;
		}

		return $request->caseReference === '' || $request->email === '' || $request->organisation === '';
	}//end refuses()

	/**
	 * Write the link row.
	 *
	 * @param ReferenceLinkRequest $request The case, the address and the tenant.
	 * @param array{tokenHash: string, issuedAt: string, expiresAt: string} $sent The secret's hash and the link's moments.
	 *
	 * @return bool Whether the row was written.
	 */
	private function storeLink(ReferenceLinkRequest $request, array $sent): bool {
		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $request->organisation,
			data: [
				'caseReference' => $request->caseReference,
				'caseRegister' => $request->caseRegister,
				'caseSchema' => $request->caseSchema,
				'email' => $request->email,
				'organisation' => $request->organisation,
				'tokenHash' => $sent['tokenHash'],
				'state' => 'sent',
				'issuedAt' => $sent['issuedAt'],
				'expiresAt' => $sent['expiresAt'],
			]
		) !== null;
	}//end storeLink()

	/**
	 * Follow a link once.
	 *
	 * @param string $token The secret from the mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array{caseReference: string, organisation: string, register: string, schema: string, linkId: string}|null
	 *         Null when the link admits nobody: unknown, already used or
	 *         expired. `linkId` is for the session the link opens, never for
	 *         the answer.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function redeem(string $token, ?DateTimeImmutable $now = null): ?array {
		if ($token === '') {
			return null;
		}

		$moment = ($now ?? new DateTimeImmutable());
		$row = $this->liveRowFor(hash: hash('sha256', $token), moment: $moment);
		if ($row === null) {
			return null;
		}

		$id = TokenRows::identifierOf(row: $row);
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
			'register' => (string)($row['caseRegister'] ?? ''),
			'schema' => (string)($row['caseSchema'] ?? ''),
			'linkId' => $id,
		];
	}//end redeem()

	/**
	 * The expiry a link issued now would carry. The answer to a request that
	 * issues nothing carries it too, so the two answers cannot be told apart.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function nominalExpiry(): string {
		return (new DateTimeImmutable())->add(new DateInterval(self::TTL))->format(DATE_ATOM);
	}//end nominalExpiry()

	/**
	 * The unspent, unexpired link behind a token hash, or null.
	 *
	 * Used, expired and unknown all answer null. Which of the three it was is
	 * not the visitor's business, and telling them would make the endpoint an
	 * oracle for links other people hold.
	 *
	 * @param string $hash The token's hash.
	 * @param DateTimeImmutable $moment The moment to judge expiry against.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	private function liveRowFor(string $hash, DateTimeImmutable $moment): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'tokenHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);

		$row = TokenRows::matching(rows: $rows, hash: $hash);
		if ($row === null || (string)($row['state'] ?? '') !== 'sent') {
			return null;
		}

		$expiry = date_create_immutable((string)($row['expiresAt'] ?? ''));
		if ($expiry === false || $expiry <= $moment) {
			return null;
		}

		return $row;
	}//end liveRowFor()
}//end class
