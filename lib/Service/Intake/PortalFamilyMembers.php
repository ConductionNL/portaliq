<?php

/**
 * Portaliq Family Members (data-lookups-and-checks-in-forms)
 *
 * The partner and children a signed-in resident can choose in a form, read
 * from the BRP through OpenRegister's BrpPersonProvider. DigiD only: the BSN
 * comes from the resident's own account, never from the request. A member is
 * shown by name, relation and birth year, and referred to by an opaque
 * reference the server recomputes on submit.
 *
 * @category Intake
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\PortalAccountService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists and re-checks the family members of the signed-in resident.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
 */
class PortalFamilyMembers {
	/**
	 * OpenRegister's person lookup.
	 */
	private const PERSON_PROVIDER = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\BrpPersonProvider';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts  Reads the account's identity.
	 * @param ContainerInterface   $container Resolves the BRP provider.
	 * @param DutchFormats         $formats   Checks the BSN.
	 * @param LoggerInterface      $logger    Logs a lookup that failed.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly ContainerInterface $container,
		private readonly DutchFormats $formats,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The resident's partner and children living at the resident's address,
	 * or null when the register cannot be asked.
	 *
	 * Null covers: no account, a login that is not DigiD, no BSN, a provider
	 * that is missing or down. An empty list means the BRP answered and holds
	 * nobody on that address.
	 *
	 * @param string $subjectRef The session's subject.
	 *
	 * @return array<int, array{ref: string, name: string, relation: string, birthYear: string}>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function forSubject(string $subjectRef): ?array {
		return $this->membersOf(subjectRef: $subjectRef, atHomeOnly: true);
	}//end forSubject()

	/**
	 * The resident's partner and children wherever they live, or null when the
	 * register cannot be asked.
	 *
	 * @param string $subjectRef The session's subject.
	 *
	 * @return array<int, array{ref: string, name: string, relation: string, birthYear: string}>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function allForSubject(string $subjectRef): ?array {
		return $this->membersOf(subjectRef: $subjectRef, atHomeOnly: false);
	}//end allForSubject()

	/**
	 * The resident's partner and children, optionally only those at the resident's address.
	 *
	 * @param string $subjectRef The session's subject.
	 * @param bool   $atHomeOnly Keep only members living at the resident's address.
	 *
	 * @return array<int, array{ref: string, name: string, relation: string, birthYear: string}>|null
	 */
	private function membersOf(string $subjectRef, bool $atHomeOnly): ?array {
		$bsn = $this->bsnOf(subjectRef: $subjectRef);
		if ($bsn === null) {
			return null;
		}

		$person = $this->person(bsn: $bsn);
		if ($person === null) {
			return null;
		}

		$home    = $this->addressKey(person: $person);
		$members = [];
		foreach (['partners' => 'partner', 'kinderen' => 'child'] as $key => $relation) {
			foreach ((array)($person[$key] ?? []) as $index => $member) {
				$context = ['subjectRef' => $subjectRef, 'relation' => $relation, 'index' => (int)$index, 'home' => $home, 'atHomeOnly' => $atHomeOnly];
				$entry   = $this->entryOf(member: $member, context: $context);
				if ($entry !== null) {
					$members[] = $entry;
				}
			}
		}

		return $members;
	}//end membersOf()

	/**
	 * One member as the form offers it, or null when it is not to be offered.
	 *
	 * @param mixed                $member  The BRP's member.
	 * @param array<string, mixed> $context The `subjectRef`, `relation`, `index`, `home` address key and `atHomeOnly` flag.
	 *
	 * @return array{ref: string, name: string, relation: string, birthYear: string}|null
	 */
	private function entryOf(mixed $member, array $context): ?array {
		if (is_array($member) === false) {
			return null;
		}

		// An address that cannot be compared is not "the same".
		if ($context['atHomeOnly'] === true && ($context['home'] === '' || $this->addressKey(person: $member) !== $context['home'])) {
			return null;
		}

		$name = trim((string)($member['naam']['volledigeNaam'] ?? ''));
		if ($name === '') {
			return null;
		}

		return [
			'ref' => $this->refOf(subjectRef: $context['subjectRef'], relation: $context['relation'], member: $member, index: $context['index']),
			'name' => $name,
			'relation' => $context['relation'],
			'birthYear' => substr((string)($member['geboorte']['datum']['datum'] ?? $member['geboorte']['datum']['jaar'] ?? ''), 0, 4),
		];
	}//end entryOf()

	/**
	 * The references among those given that are NOT the resident's family now.
	 *
	 * A BRP that cannot be asked refuses every reference: nothing is believed
	 * on the strength of what the browser says.
	 *
	 * @param string   $subjectRef The session's subject.
	 * @param string[] $refs       The chosen references.
	 *
	 * @return string[] The references that do not hold.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function forged(string $subjectRef, array $refs): array {
		$family = $this->allForSubject(subjectRef: $subjectRef);
		$known  = [];
		foreach ((array)$family as $member) {
			$known[$member['ref']] = true;
		}

		$forged = [];
		foreach ($refs as $ref) {
			if (is_string($ref) === false || isset($known[$ref]) === false) {
				$forged[] = $this->label(ref: $ref);
			}
		}

		return $forged;
	}//end forged()

	/**
	 * The text to show for a reference that does not hold.
	 *
	 * @param mixed $ref The reference as given.
	 *
	 * @return string
	 */
	private function label(mixed $ref): string {
		if (is_scalar($ref) === true) {
			return (string)$ref;
		}

		return '';
	}//end label()

	/**
	 * The BSN of a DigiD account, or null.
	 *
	 * @param string $subjectRef The session's subject.
	 *
	 * @return string|null
	 */
	private function bsnOf(string $subjectRef): ?string {
		if ($subjectRef === '') {
			return null;
		}

		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null || in_array(($account['identityType'] ?? ''), ['digid', 'eidas'], true) === false) {
			return null;
		}

		return $this->formats->normalise(format: 'bsn', value: (string)($account['identityRef'] ?? ''));
	}//end bsnOf()

	/**
	 * The BRP record of a BSN, or null.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return array<string, mixed>|null
	 */
	private function person(string $bsn): ?array {
		try {
			$provider = $this->container->get(self::PERSON_PROVIDER);
			$answer   = (array)$provider->lookupByBsn($bsn);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: family members cannot be read', ['cause' => 'provider_missing']);

			return null;
		}

		if (($answer['unavailable'] ?? false) === true || is_array($answer['results'][0] ?? null) === false) {
			$this->logger->warning('Portaliq: family members cannot be read', ['cause' => (string)($answer['cause'] ?? 'unavailable')]);

			return null;
		}

		return $answer['results'][0];
	}//end person()

	/**
	 * A comparable key for where someone lives, or ''.
	 *
	 * @param array<string, mixed> $person A BRP person or family member.
	 *
	 * @return string
	 */
	private function addressKey(array $person): string {
		$address = (array)(($person['verblijfplaats'] ?? [])['verblijfadres'] ?? []);
		$postcode = strtoupper(str_replace(' ', '', (string)($address['postcode'] ?? '')));
		$number   = (string)($address['huisnummer'] ?? '');
		if ($postcode === '' || $number === '') {
			return '';
		}

		$letter   = strtoupper((string)($address['huisletter'] ?? ''));
		$addition = (string)($address['huisnummertoevoeging'] ?? '');

		return $postcode . '|' . $number . '|' . $letter . '|' . $addition;
	}//end addressKey()

	/**
	 * The opaque reference of one member.
	 *
	 * @param string               $subjectRef The session's subject.
	 * @param string               $relation   `partner` or `child`.
	 * @param array<string, mixed> $member     The BRP member.
	 * @param int                  $index      The member's place in the BRP list.
	 *
	 * @return string
	 */
	private function refOf(string $subjectRef, string $relation, array $member, int $index): string {
		$identity = (string)($member['burgerservicenummer'] ?? '');
		if ($identity === '') {
			$identity = (string)($member['naam']['volledigeNaam'] ?? '') . '|' . (string)($member['geboorte']['datum']['datum'] ?? '') . '|' . $index;
		}

		return $relation . '-' . substr(hash('sha256', $subjectRef . '|' . $relation . '|' . $identity), 0, 20);
	}//end refOf()
}//end class
