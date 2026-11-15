<?php

/**
 * Portaliq Portal Reference Case Service
 *
 * The case behind a reference link. A case number and an address open one
 * case only when the address is the one recorded on that case. Without that
 * check anyone who knows or guesses a case number reads the case (#796).
 *
 * The case app declares both halves on its case collection, for the client
 * audience: `referenceField` names the field holding the case number and
 * `referenceAddressField` the field holding the address the case belongs to.
 * A collection that declares either one not at all opens nothing. Portaliq
 * never guesses which field of somebody else's case holds an address.
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
 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalReferenceCaseServiceInterface;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;

/**
 * Finds the one case a reference link or a reference session may open.
 *
 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
 */
class PortalReferenceCaseService implements PortalReferenceCaseServiceInterface {
	/**
	 * The audience a reference session reads as: a resident.
	 */
	public const AUDIENCE = 'client';

	/**
	 * The register the portal's form bindings live in.
	 */
	private const BINDING_REGISTER = 'portaliq';

	/**
	 * The schema recording a form binding: which case type a portal serves
	 * and where its cases are filed.
	 */
	private const BINDING_SCHEMA = 'portalFormBinding';

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistryInterface $registry The case apps' collection declarations.
	 * @param ScopedCollectionReaderInterface $reader Reads the binding and the one case.
	 */
	public function __construct(
		private readonly PortalContributionRegistryInterface $registry,
		private readonly ScopedCollectionReaderInterface $reader,
	) {
	}//end __construct()

	/**
	 * The case collection a link may be issued for, when the address given
	 * is the address recorded on the case. Null on every other outcome:
	 * no such case, two cases on one number, another address, or a case app
	 * that declares no address field.
	 *
	 * @param string $portal The portal slug.
	 * @param string $register The case type's register.
	 * @param string $schema The case type's schema.
	 * @param string $caseType The case type.
	 * @param string $caseReference The case number.
	 * @param string $email The address the link would go to.
	 * @param string $organisation The tenant.
	 *
	 * @return array{register: string, schema: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the request's own
	 * fields, each part of what must match before anything is issued.
	 */
	public function linkableCase(
		string $portal,
		string $register,
		string $schema,
		string $caseType,
		string $caseReference,
		string $email,
		string $organisation,
	): ?array {
		$target = $this->caseCollectionFor(portal: $portal, triple: [$register, $schema, $caseType]);
		if ($target === null || $this->lacksCaseOrEmail(caseReference: $caseReference, email: $email) === true) {
			return null;
		}

		if ($this->addressMatches(target: $target, caseReference: $caseReference, email: $email, organisation: $organisation) === false) {
			return null;
		}

		return $target;
	}//end linkableCase()

	/**
	 * Whether the request names no case number or no address.
	 *
	 * @param string $caseReference The case number.
	 * @param string $email         The address the link would go to.
	 *
	 * @return bool
	 */
	private function lacksCaseOrEmail(string $caseReference, string $email): bool {
		return $caseReference === '' || $email === '';
	}//end lacksCaseOrEmail()

	/**
	 * Whether the address given is the address recorded on the one case with
	 * this number in the case collection.
	 *
	 * @param array{register: string, schema: string} $target        The case collection.
	 * @param string                                  $caseReference The case number.
	 * @param string                                  $email         The address the link would go to.
	 * @param string                                  $organisation  The tenant.
	 *
	 * @return bool
	 */
	private function addressMatches(array $target, string $caseReference, string $email, string $organisation): bool {
		$match = $this->declaration(register: $target['register'], schema: $target['schema'], organisation: $organisation);
		if ($match === null) {
			return false;
		}

		return $this->recordedAddressMatches(declared: $match, caseReference: $caseReference, email: $email, organisation: $organisation);
	}//end addressMatches()

	/**
	 * Whether the declared address field of the one case with this number
	 * holds the address given; false when the case app declares no address field.
	 *
	 * @param array{app: string, collection: array<string, mixed>} $declared      The declaration.
	 * @param string                                               $caseReference The case number.
	 * @param string                                               $email         The address the link would go to.
	 * @param string                                               $organisation  The tenant.
	 *
	 * @return bool
	 */
	private function recordedAddressMatches(array $declared, string $caseReference, string $email, string $organisation): bool {
		$addressField = (string)($declared['collection']['referenceAddressField'] ?? '');
		if ($addressField === '') {
			return false;
		}

		$row = $this->onlyRow(match: $declared, caseReference: $caseReference, organisation: $organisation, fields: null);
		if ($row === null || $this->sameAddress(recorded: $row[$addressField] ?? null, given: $email) === false) {
			return false;
		}

		return true;
	}//end recordedAddressMatches()

	/**
	 * The one case a reference session reads, projected the way the case
	 * app declared, or null.
	 *
	 * @param array<string, mixed> $reference The resolved reference session.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function read(array $reference): ?array {
		$caseReference = (string)($reference['caseReference'] ?? '');
		$organisation = (string)($reference['organisation'] ?? '');
		if ($caseReference === '') {
			return null;
		}

		$match = $this->declaration(
			register: (string)($reference['register'] ?? ''),
			schema: (string)($reference['schema'] ?? ''),
			organisation: $organisation
		);
		if ($match === null) {
			return null;
		}

		return $this->onlyRow(
			match: $match,
			caseReference: $caseReference,
			organisation: $organisation,
			fields: ($match['collection']['fields'] ?? null)
		);
	}//end read()

	/**
	 * Where a portal's case type files its cases: the case register and
	 * schema of the published binding of this portal that names exactly this
	 * case type. The same bindings are the portal's scope for the route.
	 *
	 * @param string $portal The portal slug.
	 * @param array{0: string, 1: string, 2: string} $triple The case type's register, schema and id.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function caseCollectionFor(string $portal, array $triple): ?array {
		if ($portal === '' || in_array('', $triple, true) === true) {
			return null;
		}

		return $this->firstTarget(rows: $this->bindingRows(portal: $portal), portal: $portal, triple: $triple);
	}//end caseCollectionFor()

	/**
	 * The published binding rows of a portal.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, mixed>
	 */
	private function bindingRows(string $portal): array {
		return $this->reader->readCollection(
			register: self::BINDING_REGISTER,
			schema: self::BINDING_SCHEMA,
			scopeField: 'portal',
			subjectRef: $portal,
			organisation: '',
			limit: 200,
			filter: ['portal' => $portal, 'status' => 'published']
		);
	}//end bindingRows()

	/**
	 * The case collection the first matching binding names, or null.
	 *
	 * @param array<int, mixed>                      $rows   The binding rows.
	 * @param string                                 $portal The portal slug.
	 * @param array{0: string, 1: string, 2: string} $triple The case type.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function firstTarget(array $rows, string $portal, array $triple): array|null {
		foreach ($rows as $row) {
			$target = $this->targetOf(row: $row, portal: $portal, triple: $triple);
			if ($target !== null) {
				return $target;
			}
		}

		return null;
	}//end firstTarget()

	/**
	 * The case collection one binding names, when it is this portal's
	 * published binding for exactly this case type.
	 *
	 * @param mixed $row The binding row.
	 * @param string $portal The portal slug.
	 * @param array{0: string, 1: string, 2: string} $triple The case type.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function targetOf(mixed $row, string $portal, array $triple): ?array {
		if (is_array($row) === false || ($row['portal'] ?? '') !== $portal || ($row['status'] ?? '') !== 'published') {
			return null;
		}

		$named = [(string)($row['typeRegister'] ?? ''), (string)($row['typeSchema'] ?? ''), (string)($row['typeId'] ?? '')];
		$target = ['register' => (string)($row['caseRegister'] ?? ''), 'schema' => (string)($row['caseSchema'] ?? '')];
		if ($named !== $triple || $target['register'] === '' || $target['schema'] === '') {
			return null;
		}

		return $target;
	}//end targetOf()

	/**
	 * The client collection over this register and schema that declares a
	 * `referenceField`, with the app that declared it.
	 *
	 * @param string $register The case register.
	 * @param string $schema The case schema.
	 * @param string $organisation The tenant.
	 *
	 * @return array{app: string, collection: array<string, mixed>}|null
	 */
	private function declaration(string $register, string $schema, string $organisation): ?array {
		if ($register === '' || $schema === '') {
			return null;
		}

		$aggregate = $this->registry->aggregateFor(
			subject: ['audience' => self::AUDIENCE, 'trust' => 'low', 'organisation' => $organisation, 'subjectRef' => '']
		);

		return $this->declaredIn(aggregate: $aggregate, register: $register, schema: $schema);
	}//end declaration()

	/**
	 * The first collection of an aggregate that declares a `referenceField`
	 * over this register and schema, with its app.
	 *
	 * @param array<string, mixed> $aggregate The aggregated manifest.
	 * @param string               $register  The case register.
	 * @param string               $schema    The case schema.
	 *
	 * @return array{app: string, collection: array<string, mixed>}|null
	 */
	private function declaredIn(array $aggregate, string $register, string $schema): array|null {
		foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
			$collection = $this->referenceCollectionOf(contribution: $contribution, register: $register, schema: $schema);
			if ($collection !== null) {
				return ['app' => (string)($contribution['app'] ?? ''), 'collection' => $collection];
			}
		}

		return null;
	}//end declaredIn()

	/**
	 * The first collection of a contribution that declares a `referenceField`
	 * over this register and schema, or null.
	 *
	 * @param mixed  $contribution The contribution.
	 * @param string $register     The case register.
	 * @param string $schema       The case schema.
	 *
	 * @return array<string, mixed>|null
	 */
	private function referenceCollectionOf(mixed $contribution, string $register, string $schema): array|null {
		foreach ((array)($contribution['collections'] ?? []) as $collection) {
			if ($this->declaresReference(collection: $collection, register: $register, schema: $schema) === true) {
				return $collection;
			}
		}

		return null;
	}//end referenceCollectionOf()

	/**
	 * Whether a collection is over this register and schema and declares a
	 * `referenceField`.
	 *
	 * @param mixed  $collection The collection.
	 * @param string $register   The case register.
	 * @param string $schema     The case schema.
	 *
	 * @return bool
	 */
	private function declaresReference(mixed $collection, string $register, string $schema): bool {
		return (is_array($collection) === false
			|| (string)($collection['register'] ?? '') !== $register
			|| (string)($collection['schema'] ?? '') !== $schema
			|| (string)($collection['referenceField'] ?? '') === '') === false;
	}//end declaresReference()

	/**
	 * The single row whose reference field equals the case number. Two rows
	 * on one number open neither.
	 *
	 * @param array{app: string, collection: array<string, mixed>} $match The declaration.
	 * @param string $caseReference The case number.
	 * @param string $organisation The tenant.
	 * @param mixed $fields The projection, or null for the whole row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function onlyRow(array $match, string $caseReference, string $organisation, mixed $fields): ?array {
		$collection = $match['collection'];
		$referenceField = (string)$collection['referenceField'];

		$rows = $this->reader->readCollection(
			register: (string)$collection['register'],
			schema: (string)$collection['schema'],
			scopeField: $referenceField,
			subjectRef: $caseReference,
			organisation: $organisation,
			limit: 2,
			contributingApp: $match['app'],
			audience: self::AUDIENCE,
			fields: $fields,
			filter: (array)($collection['filter'] ?? [])
		);

		if (count($rows) !== 1 || is_array($rows[0] ?? null) === false) {
			return null;
		}

		return $rows[0];
	}//end onlyRow()

	/**
	 * Whether the address given is the address recorded, ignoring case and
	 * surrounding space. An empty or non-string recorded value matches nothing.
	 *
	 * @param mixed $recorded The case's address value.
	 * @param string $given The address asked with.
	 *
	 * @return bool
	 */
	private function sameAddress(mixed $recorded, string $given): bool {
		if (is_string($recorded) === false || trim($recorded) === '') {
			return false;
		}

		return hash_equals(strtolower(trim($recorded)), strtolower(trim($given)));
	}//end sameAddress()
}//end class
