<?php

/**
 * Portaliq Portal Mandate Service
 *
 * What a portal identity may act for on behalf of an organisation, and which
 * of its mandates it is acting under right now.
 *
 * The default is the closed one: an identity with no mandate recorded sees
 * none of an organisation's cases. That is the whole point of the record. A
 * revoked or expired mandate grants nothing either, and is filtered here
 * rather than in each caller, so there is one place where "does this mandate
 * still count" is answered.
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

/**
 * Reads and resolves the mandates a portal identity holds.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalMandateService {
	/**
	 * The register the mandate lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a mandate.
	 */
	private const SCHEMA = 'portalMandate';

	/**
	 * A mandate covering only the entity it names. The default.
	 */
	public const REACH_ORGANISATION = 'organisation';

	/**
	 * A mandate covering the entity it names and those below it.
	 */
	public const REACH_TREE = 'tree';

	/**
	 * Row cap per identity. A person with more mandates than this has an
	 * administration problem, not a portal problem.
	 */
	private const ROW_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The subject-scoped OpenRegister reader.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The mandates this identity holds that still count.
	 *
	 * @param string $subjectRef The portal identity.
	 * @param string $organisation The tenant, or '' for every tenant.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array<int, array<string, mixed>> The live mandates, never null.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function mandatesFor(string $subjectRef, string $organisation = '', ?DateTimeImmutable $now = null): array {
		if ($subjectRef === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			limit: self::ROW_LIMIT
		);

		$moment = ($now ?? new DateTimeImmutable());
		$live = [];
		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['subjectRef'] ?? '') !== $subjectRef) {
				// The reader's scope filter is trusted to narrow, never to
				// answer: a mandate is the record that opens somebody else's
				// cases, so it is re-verified here.
				continue;
			}

			if ($organisation !== '' && ($row['organisation'] ?? '') !== $organisation) {
				continue;
			}

			if (($row['status'] ?? 'active') !== 'active') {
				continue;
			}

			if ($this->hasExpired(row: $row, now: $moment) === true) {
				continue;
			}

			$live[] = $row;
		}//end foreach

		return $live;
	}//end mandatesFor()

	/**
	 * The mandate the identity is acting under, out of the ones it holds.
	 *
	 * A named mandate must be one of their own, so naming somebody else's in
	 * the switch request changes nothing. With none named, the first is used,
	 * which is what a person holding exactly one mandate expects.
	 *
	 * @param array<int, array<string, mixed>> $mandates The live mandates.
	 * @param string $mandateId The mandate named by the switch, or ''.
	 *
	 * @return array<string, mixed>|null The active mandate, or null when the
	 *                                   identity holds none.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function activeMandate(array $mandates, string $mandateId = ''): ?array {
		if ($mandates === []) {
			return null;
		}

		if ($mandateId === '') {
			return $mandates[0];
		}

		foreach ($mandates as $mandate) {
			if ($this->mandateId(mandate: $mandate) === $mandateId) {
				return $mandate;
			}
		}

		return null;
	}//end activeMandate()

	/**
	 * The words shown beside a case this mandate grants, so a citizen can see
	 * why they may read it.
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 *
	 * @return array<string, mixed> The id, label, organisation and party.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function describe(array $mandate): array {
		$label = (string)($mandate['label'] ?? '');
		if ($label === '') {
			$label = (string)($mandate['onBehalfOf'] ?? $mandate['organisation'] ?? '');
		}

		return [
			'id' => $this->mandateId(mandate: $mandate),
			'label' => $label,
			'reach' => $this->reachOf(mandate: $mandate),
			'organisation' => (string)($mandate['organisation'] ?? ''),
			'onBehalfOf' => (string)($mandate['onBehalfOf'] ?? ''),
			'caseTypes' => array_values((array)($mandate['caseTypes'] ?? [])),
		];
	}//end describe()

	/**
	 * How far down the party tree this mandate reaches.
	 *
	 * Anything other than the declared wider reach is the narrow one: a
	 * mandate that says nothing, or says something nobody recognises, covers
	 * the entity it names and no other (REQ-PTV-001).
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function reachOf(array $mandate): string {
		if ((string)($mandate['reach'] ?? '') === self::REACH_TREE) {
			return self::REACH_TREE;
		}

		return self::REACH_ORGANISATION;
	}//end reachOf()

	/**
	 * Whether this mandate covers a case of the named type.
	 *
	 * An empty `caseTypes` covers every case of the party; a non-empty one
	 * covers only what it names, which is what makes a narrow mandate narrow.
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 * @param string $caseType The case's type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function covers(array $mandate, string $caseType): bool {
		$caseTypes = array_values((array)($mandate['caseTypes'] ?? []));
		if ($caseTypes === []) {
			return true;
		}

		return in_array($caseType, $caseTypes, true);
	}//end covers()

	/**
	 * The mandate's own identifier, flat or in `@self`.
	 *
	 * @param array<string, mixed> $mandate The mandate row.
	 *
	 * @return string
	 */
	public function mandateId(array $mandate): string {
		$self = ($mandate['@self'] ?? null);
		$candidates = [($mandate['uuid'] ?? null), ($mandate['id'] ?? null)];
		if (is_array($self) === true) {
			$candidates[] = ($self['uuid'] ?? null);
			$candidates[] = ($self['id'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end mandateId()

	/**
	 * Whether the mandate's expiry has passed.
	 *
	 * An unparseable expiry counts as expired: a date nobody can read is not
	 * a reason to open somebody else's cases.
	 *
	 * @param array<string, mixed> $row The mandate row.
	 * @param DateTimeImmutable $now The moment to judge against.
	 *
	 * @return bool
	 */
	private function hasExpired(array $row, DateTimeImmutable $now): bool {
		$expiresAt = (string)($row['expiresAt'] ?? '');
		if ($expiresAt === '') {
			return false;
		}

		$expiry = date_create_immutable($expiresAt);
		if ($expiry === false) {
			return true;
		}

		return $expiry <= $now;
	}//end hasExpired()
}//end class
