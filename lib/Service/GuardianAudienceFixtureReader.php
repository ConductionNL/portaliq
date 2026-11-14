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
 * @spec openspec/changes/archive/2026-10-09-news-and-newsletter-authoring/design.md#audience-source-seam
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\Contracts\GuardianAudienceFixtureReaderInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a guardian's audience (school/group/child + photo consent) from
 * the interim fixture register.
 *
 * @spec openspec/changes/archive/2026-10-09-news-and-newsletter-authoring/design.md#audience-source-seam
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- NewsAudienceMatcher::matches() is
 * deliberately the ONE stateless match predicate every caller (this class,
 * NewsFeedReader) shares, so the rule can never fork between the enumeration
 * path here and the read path there.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- one reader over one
 * interim fixture register (audience enumeration plus consent); it is
 * replaced as a whole when the real audience source lands, so splitting it
 * now would only spread the seam.
 */
class GuardianAudienceFixtureReader implements GuardianAudienceFixtureReaderInterface {
	use ObjectRowNormaliser;
	use ObjectServiceLookup;

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
	 * @spec openspec/changes/archive/2026-10-09-news-and-newsletter-authoring/design.md#audience-source-seam
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

		return $this->audienceOf(row: $row) + ['photoConsent' => $this->nestedBoolMap(value: $row['photoConsent'] ?? [])];
	}//end resolveAudience()

	/**
	 * Whether a guardian's own audience includes a group.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $groupRef The group to check.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-28-guardian-direct-messages/design.md#participation-model
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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
	 */
	public function childPhotoConsentGranted(string $childRef, string $purpose = self::PURPOSE_NEWS): bool {
		if ($childRef === '') {
			return false;
		}

		$rows = $this->everyFixtureRow(failure: 'Portaliq: guardian audience fixture consent lookup failed');
		if ($rows === null) {
			return false;
		}

		return $this->consentGrantedIn(rows: $rows, consent: ['childRef' => $childRef, 'purpose' => $purpose]);
	}//end childPhotoConsentGranted()

	/**
	 * Every guardian audience fixture row, or null when they cannot be read.
	 *
	 * @param string $failure The warning a failed read is logged under.
	 *
	 * @return array<int, mixed>|null
	 */
	private function everyFixtureRow(string $failure): ?array {
		return (new FixtureRowReader(objectService: $this->objectService(), logger: $this->logger))->everyRow(
			source: ['register' => self::REGISTER, 'schema' => self::SCHEMA],
			failure: $failure
		);
	}//end everyFixtureRow()

	/**
	 * Whether the rows mention the child and none of them withholds the
	 * purpose: one guardian withholding withholds for the child, and no row
	 * mentioning the child at all fails closed.
	 *
	 * @param array<int, mixed>                        $rows    The fixture rows.
	 * @param array{childRef: string, purpose: string} $consent The child and the consent purpose.
	 *
	 * @return bool
	 */
	private function consentGrantedIn(array $rows, array $consent): bool {
		$mentioned = false;
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			if (in_array($consent['childRef'], (new NonEmptyStrings())->from(value: $normalised['childRefs'] ?? []), true) === false) {
				continue;
			}

			$granted = $this->nestedBoolMap(value: $normalised['photoConsent'] ?? []);
			if (($granted[$consent['childRef']][$consent['purpose']] ?? false) !== true) {
				// One guardian withholding withholds for the child.
				return false;
			}

			$mentioned = true;
		}

		// No fixture row mentions this child at all — fail closed.
		return $mentioned;
	}//end consentGrantedIn()

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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 * @spec openspec/changes/archive/2026-10-07-guardian-enumeration-from-the-school-app/tasks.md#T2
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
		$rows = $this->everyFixtureRow(failure: 'Portaliq: guardian audience fixture enumeration failed');
		if ($rows === null) {
			return ['matched' => [], 'covered' => []];
		}

		$matched = [];
		$covered = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			$guardianRef = (string)($normalised['guardianRef'] ?? '');
			$covered[]   = $guardianRef;
			if (NewsAudienceMatcher::matches(target: $target, audience: $this->audienceOf(row: $normalised)) === true
				&& $this->isNewGuardian(matched: $matched, guardianRef: $guardianRef) === true
			) {
				$matched[] = $guardianRef;
			}
		}

		return ['matched' => $matched, 'covered' => $covered];
	}//end fixtureGuardiansMatching()

	/**
	 * The audience a fixture row grants: school, groups and children.
	 *
	 * @param array<string, mixed> $row The normalised fixture row.
	 *
	 * @return array{schoolRef: string, groupRefs: array<int, string>, childRefs: array<int, string>}
	 */
	private function audienceOf(array $row): array {
		return [
			'schoolRef' => (string)($row['schoolRef'] ?? ''),
			'groupRefs' => (new NonEmptyStrings())->from(value: $row['groupRefs'] ?? []),
			'childRefs' => (new NonEmptyStrings())->from(value: $row['childRefs'] ?? []),
		];
	}//end audienceOf()

	/**
	 * Whether a guardian is one not matched yet.
	 *
	 * @param array<int, string> $matched     The guardians matched so far.
	 * @param string             $guardianRef The guardian.
	 *
	 * @return bool
	 */
	private function isNewGuardian(array $matched, string $guardianRef): bool {
		return $guardianRef !== '' && in_array($guardianRef, $matched, true) === false;
	}//end isNewGuardian()

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
}//end class
