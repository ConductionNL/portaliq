<?php

/**
 * Guardian Audience Fixture Reader
 *
 * INTERIM stand-in for learniq's `portal-contribution-guardian-audiences`
 * change (another lane, in flight — see openspec/changes/
 * news-and-newsletter-authoring/design.md "Audience source seam"). Resolves
 * a guardian's own school/group/child audience and per-child photo consent
 * from the `guardianAudienceFixture` schema (register `portaliq`). Every
 * call site that needs a guardian's audience goes through THIS class rather
 * than querying the fixture schema directly, so swapping the fixture for
 * learniq's real collection later is a change to this one file.
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
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#audience-source-seam
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a guardian's audience (school/group/child + photo consent) from
 * the interim fixture register.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#audience-source-seam
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- NewsAudienceMatcher::matches() is
 * deliberately the ONE stateless match predicate every caller (this class,
 * NewsFeedReader) shares, so the rule can never fork between the enumeration
 * path here and the read path there.
 */
class GuardianAudienceFixtureReader {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'guardianAudienceFixture';

	/**
	 * The one photo-consent PURPOSE this change models (Parnassys's own
	 * vocabulary lists "schoolgids, website, nieuwsbrief, social media,
	 * Parro" as separate purposes — findings 2.8). News items and
	 * newsletters both fall under in-app communication, so this change
	 * models that single purpose; the others are out of scope until the
	 * real learniq contribution ships full per-purpose data.
	 */
	public const PURPOSE_NEWS = 'news';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 * @param LeafGuardianAudienceReader|null $leafAudience The school app's own audience, when no fixture row exists.
	 * @param GuardianAccountDirectory|null $accounts The active guardian accounts the school app resolves, for guardiansMatching().
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?LeafGuardianAudienceReader $leafAudience=null,
		private readonly ?GuardianAccountDirectory $accounts=null,
	) {
	}//end __construct()

	/**
	 * Resolve one guardian's audience. Fail-closed EMPTY (never an error, never
	 * throws) when the fixture has no row for this subject or OpenRegister is
	 * unavailable — the same "unresolvable → empty" discipline the
	 * contribution-contract's `scopeClaim`/`via` paths already use.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array{schoolRef: string, groupRefs: array<int, string>, childRefs: array<int, string>, photoConsent: array<string, array<string, bool>>}
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/design.md#audience-source-seam
	 */
	public function resolveAudience(string $subjectRef): array {
		$empty = ['schoolRef' => '', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []];

		if ($subjectRef === '') {
			return $empty;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return $empty;
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(
				config: ['filters' => ['guardianRef' => $subjectRef], 'limit' => 1, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: guardian audience fixture read failed', ['reason' => $e->getMessage()]);
			return $empty;
		}

		if (is_array($rows) === false || count($rows) === 0) {
			// No fixture row: ask the school app that serves the guardian
			// (news-audience-from-the-school-app). A fixture row still wins,
			// so the seeded demo guardians keep their audience.
			return ($this->leafAudience?->resolveAudience(subjectRef: $subjectRef) ?? $empty);
		}

		$row = $this->normalise(row: $rows[0]);
		if ($row === null) {
			return $empty;
		}

		return [
			'schoolRef' => (string)($row['schoolRef'] ?? ''),
			'groupRefs' => $this->stringList(value: $row['groupRefs'] ?? []),
			'childRefs' => $this->stringList(value: $row['childRefs'] ?? []),
			'photoConsent' => $this->nestedBoolMap(value: $row['photoConsent'] ?? []),
		];
	}//end resolveAudience()

	/**
	 * Whether a guardian's own audience includes a group.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $groupRef The group to check.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
	 */
	public function guardianReachesGroup(string $subjectRef, string $groupRef): bool {
		if ($groupRef === '') {
			return false;
		}

		return in_array($groupRef, $this->resolveAudience(subjectRef: $subjectRef)['groupRefs'], true);
	}//end guardianReachesGroup()

	/**
	 * Whether photo consent for a PURPOSE is granted for one child, in one
	 * guardian's resolved audience. An absent entry is WITHHELD (ADR-005
	 * fail-closed) — never treated as granted.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $childRef The child to check.
	 * @param string $purpose The consent purpose (default: {@see self::PURPOSE_NEWS}).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
	 */
	public function photoConsentGranted(string $subjectRef, string $childRef, string $purpose = self::PURPOSE_NEWS): bool {
		$audience = $this->resolveAudience(subjectRef: $subjectRef);
		return ($audience['photoConsent'][$childRef][$purpose] ?? false) === true;
	}//end photoConsentGranted()

	/**
	 * Whether photo consent for a PURPOSE is granted for a child, independent
	 * of which guardian is reading — the gate applies to the ITEM (any
	 * targeted child without granted consent withholds the photo for every
	 * reader), not to one guardian's own view. Scans every fixture row
	 * carrying this child: consent is granted only when at least one row
	 * mentions the child and none of them withholds it, so one guardian
	 * withholding wins over another granting. An absent child or an absent
	 * purpose entry fails closed to WITHHELD, exactly like an explicit
	 * `false`.
	 *
	 * @param string $childRef The child to check.
	 * @param string $purpose The consent purpose (default: {@see self::PURPOSE_NEWS}).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
	 */
	public function childPhotoConsentGranted(string $childRef, string $purpose = self::PURPOSE_NEWS): bool {
		if ($childRef === '') {
			return false;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: guardian audience fixture consent lookup failed', ['reason' => $e->getMessage()]);
			return false;
		}

		if (is_array($rows) === false) {
			return false;
		}

		$mentioned = false;
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			$childRefs = $this->stringList(value: $normalised['childRefs'] ?? []);
			if (in_array($childRef, $childRefs, true) === false) {
				continue;
			}

			$consent = $this->nestedBoolMap(value: $normalised['photoConsent'] ?? []);
			if (($consent[$childRef][$purpose] ?? false) !== true) {
				// One guardian withholding withholds for the child.
				return false;
			}

			$mentioned = true;
		}

		// No fixture row mentions this child at all — fail closed.
		return $mentioned;
	}//end childPhotoConsentGranted()

	/**
	 * Every guardian subjectRef whose audience intersects a target (school OR
	 * group OR child, any match) — the count/enumeration primitive the
	 * newsletter preflight, the newsletter send check and the emergency push
	 * all call, so the preflight count can never drift from the actual
	 * delivery set.
	 *
	 * Fixture rows are matched first, and a guardian with a fixture row is
	 * matched on that row only, the same precedence resolveAudience() keeps.
	 * Every other active guardian account is then resolved through the school
	 * app (guardian-enumeration-from-the-school-app).
	 *
	 * @param array{schoolRef?: string, groupRefs?: array<int, string>, childRefs?: array<int, string>} $target The target.
	 *
	 * @return array<int, string> Distinct guardian subjectRefs.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 * @spec openspec/changes/guardian-enumeration-from-the-school-app/tasks.md#T2
	 */
	public function guardiansMatching(array $target): array {
		$fixture = $this->fixtureGuardiansMatching(target: $target);

		return array_values(
			array_unique(
				array_merge(
					$fixture['matched'],
					($this->accounts?->guardiansMatching(target: $target, exclude: $fixture['covered']) ?? [])
				)
			)
		);
	}//end guardiansMatching()

	/**
	 * The fixture guardians matching a target, and every guardian the fixture
	 * holds a row for at all.
	 *
	 * @param array{schoolRef?: string, groupRefs?: array<int, string>, childRefs?: array<int, string>} $target The target.
	 *
	 * @return array{matched: array<int, string>, covered: array<int, string>}
	 */
	private function fixtureGuardiansMatching(array $target): array {
		$none = ['matched' => [], 'covered' => []];
		$objectService = $this->objectService();
		if ($objectService === null) {
			return $none;
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: guardian audience fixture enumeration failed', ['reason' => $e->getMessage()]);
			return $none;
		}

		if (is_array($rows) === false) {
			return $none;
		}

		$matched = [];
		$covered = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			$covered[] = (string)($normalised['guardianRef'] ?? '');
			$audience = [
				'schoolRef' => (string)($normalised['schoolRef'] ?? ''),
				'groupRefs' => $this->stringList(value: $normalised['groupRefs'] ?? []),
				'childRefs' => $this->stringList(value: $normalised['childRefs'] ?? []),
			];

			if (NewsAudienceMatcher::matches(target: $target, audience: $audience) === true) {
				$guardianRef = (string)($normalised['guardianRef'] ?? '');
				if ($guardianRef !== '' && in_array($guardianRef, $matched, true) === false) {
					$matched[] = $guardianRef;
				}
			}
		}

		return ['matched' => $matched, 'covered' => $covered];
	}//end fixtureGuardiansMatching()

	/**
	 * Coerce a value to a list of strings, dropping anything else.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<int, string>
	 */
	private function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$list = [];
		foreach ($value as $item) {
			if (is_string($item) === true && $item !== '') {
				$list[] = $item;
			}
		}

		return $list;
	}//end stringList()

	/**
	 * Coerce a value to a map of string => (map of string => bool) — the
	 * `{childRef: {purpose: granted}}` consent shape. A malformed inner value
	 * (not an array) yields an empty inner map for that child, which reads as
	 * "no purpose granted" — fail-closed, never an error.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function nestedBoolMap(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$map = [];
		foreach ($value as $childRef => $purposes) {
			if (is_string($childRef) === false) {
				continue;
			}

			$inner = [];
			if (is_array($purposes) === true) {
				foreach ($purposes as $purpose => $granted) {
					if (is_string($purpose) === true) {
						$inner[$purpose] = ($granted === true);
					}
				}
			}

			$map[$childRef] = $inner;
		}

		return $map;
	}//end nestedBoolMap()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end normalise()

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
