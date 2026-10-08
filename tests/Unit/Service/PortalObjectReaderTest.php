<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFieldProjector;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OR-backed reader: it degrades to empty without OpenRegister, filters
 * on the scope field, and re-verifies every row so a foreign-subject object can
 * never leak. Contract v2 adds the server-side scopeClaim resolution matrix
 * (bare + dotted addressing, fail-closed empty on absent/malformed claims with
 * NO unscoped query) and the one-hop `via` join (dot-path per-row verification,
 * target membership, fail-closed on invalid/nested declarations). The
 * field-projection matrix proves the read-side `fields` whitelist: declared
 * properties + identifiers only, applied after verification on the direct AND
 * via paths, full rows without a declaration, identifiers-only on a malformed
 * one, and the single-row (detail) primitive `projectRow()` directly. The
 * reverse-scope-join matrix (v2.2) proves `via.match: 'scopeField'`: outer
 * rows matched by the collection's own scope field (scalar and array-element)
 * against the subject-resolved target set, empty set → zero rows, absent/null
 * scopeField excluded, tenant still enforced on the outer row, malformed
 * match fails the via closed, forward `match: 'id'` (explicit and absent)
 * unchanged, and projection applied to reverse-joined rows.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T6
 * @spec openspec/changes/archive/2026-09-07-field-projection/tasks.md#T1
 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
 */
class PortalObjectReaderTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	public function testReturnsEmptyWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$reader = new PortalObjectReader($container, $this->createMock(LoggerInterface::class), $this->projector());
		$this->assertSame([], $reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1'));

	}//end testReturnsEmptyWhenOpenRegisterUnavailable()

	public function testFiltersOnScopeAndDropsForeignRows(): void {
		$objectService = new class {

			/**
			 * @var array<string,mixed>
			 */
			public array $received = [];

			public string $register = '';

			public string $schema = '';

			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			public bool $rbac = true;

			public bool $multitenancy = true;

			/**
			 * @param array<string,mixed> $config
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->received = $config;
				$this->rbac = $_rbac;
				$this->multitenancy = $_multitenancy;
				// OR mistakenly returns a foreign row too — the reader must drop it.
				return [
					['subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine'],
					['subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Not mine'],
				];
			}//end findAll()
		};

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1');

		$this->assertCount(1, $rows);
		$this->assertSame('Mine', $rows[0]['title']);
		// register/schema are set via the setters, NOT leaked into filters.
		$this->assertSame('portaliq', $objectService->register);
		$this->assertSame('exampleDocument', $objectService->schema);
		$this->assertArrayNotHasKey('register', $objectService->received['filters']);
		// organisation is a multitenancy field, NOT an OR filter (it is only a
		// per-row check), so it must not appear in the query filters.
		$this->assertArrayNotHasKey('organisation', $objectService->received['filters']);
		$this->assertSame('s1', $objectService->received['filters']['subjectRef']);
		// Portal reads bypass OR's NC-user RBAC/multitenancy — Portaliq scopes.
		$this->assertFalse($objectService->rbac);
		$this->assertFalse($objectService->multitenancy);

	}//end testFiltersOnScopeAndDropsForeignRows()

	public function testDeclaredFilterNarrowsTheReadAndScopeStillWins(): void {
		$objectService = new class {

			/**
			 * @var array<string,mixed>
			 */
			public array $received = [];

			public string $register = '';

			public string $schema = '';

			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->received = $config;
				return [['subjectRef' => 's1', 'organisation' => 'org-1', 'ticketType' => 'request', 'title' => 'Mine']];
			}//end findAll()
		};

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'pipelinq',
			schema: 'ticket',
			scopeField: 'subjectRef',
			subjectRef: 's1',
			organisation: 'org-1',
			filter: ['ticketType' => 'request']
		);

		$this->assertCount(1, $rows);
		// The declared discriminator rides into the OR query as a narrowing filter.
		$this->assertSame('request', $objectService->received['filters']['ticketType']);
		// The scope filter is still present and applied last (always wins).
		$this->assertSame('s1', $objectService->received['filters']['subjectRef']);

	}//end testDeclaredFilterNarrowsTheReadAndScopeStillWins()

	public function testDeclaredFilterCannotOverrideTheScopeField(): void {
		$objectService = new class {

			/**
			 * @var array<string,mixed>
			 */
			public array $received = [];

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->received = $config;
				return [];
			}//end findAll()
		};

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		// A malicious declared filter tries to widen the read by re-pointing the
		// scope field at another subject; the scope filter is applied last, so
		// the subject's own value must survive.
		$reader->readCollection(
			register: 'pipelinq',
			schema: 'ticket',
			scopeField: 'subjectRef',
			subjectRef: 's1',
			organisation: 'org-1',
			filter: ['subjectRef' => 'someone-else']
		);

		$this->assertSame('s1', $objectService->received['filters']['subjectRef']);

	}//end testDeclaredFilterCannotOverrideTheScopeField()

	public function testClaimScopedReadResolvesTheClaimServerSide(): void {
		$objectService = $this->objectService(
			[
				'portalAccount' => [
					// A foreign account row OR mistakenly returns is dropped by
					// the per-row verification before any claim is read.
					['subjectRef' => 'someone-else', 'organisation' => 'org-1', 'claims' => ['pipelinq' => ['linkedContactId' => 'uuid-foreign']]],
					['subjectRef' => 's1', 'organisation' => 'org-1', 'claims' => ['pipelinq' => ['linkedContactId' => 'uuid-c1']]],
				],
				'crmDeal' => [
					['contact' => 'uuid-c1', 'organisation' => 'org-1', 'title' => 'Mine'],
					['contact' => 'uuid-other', 'organisation' => 'org-1', 'title' => 'Not mine'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'pipelinq',
			schema: 'crmDeal',
			scopeField: 'contact',
			subjectRef: 's1',
			organisation: 'org-1',
			limit: 200,
			scopeClaim: 'linkedContactId',
			contributingApp: 'pipelinq',
			via: null,
			audience: 'supplier'
		);

		$this->assertCount(1, $rows);
		$this->assertSame('Mine', $rows[0]['title']);
		// First query: the subject's OWN portalAccount, scoped by subjectRef +
		// audience; second: the collection, filtered by the RESOLVED claim.
		$this->assertCount(2, $objectService->calls);
		$this->assertSame('portalAccount', $objectService->calls[0]['schema']);
		$this->assertSame('s1', $objectService->calls[0]['config']['filters']['subjectRef']);
		$this->assertSame('supplier', $objectService->calls[0]['config']['filters']['audience']);
		$this->assertSame('uuid-c1', $objectService->calls[1]['config']['filters']['contact']);

	}//end testClaimScopedReadResolvesTheClaimServerSide()

	public function testDottedScopeClaimResolvesInTheExplicitNamespace(): void {
		$objectService = $this->objectService(
			[
				'portalAccount' => [
					['subjectRef' => 's1', 'organisation' => 'org-1', 'claims' => ['otherapp' => ['contactId' => 'uuid-x']]],
				],
				'crmDeal' => [
					['contact' => 'uuid-x', 'title' => 'Cross-app'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'pipelinq',
			schema: 'crmDeal',
			scopeField: 'contact',
			subjectRef: 's1',
			organisation: 'org-1',
			limit: 200,
			scopeClaim: 'otherapp.contactId',
			contributingApp: 'pipelinq',
			via: null,
			audience: 'supplier'
		);

		$this->assertCount(1, $rows);
		$this->assertSame('Cross-app', $rows[0]['title']);

	}//end testDottedScopeClaimResolvesInTheExplicitNamespace()

	public function testAbsentClaimYieldsEmptyWithoutAnUnscopedQuery(): void {
		$objectService = $this->objectService(
			[
				// The account exists but carries NO claim for the address.
				'portalAccount' => [
					['subjectRef' => 's1', 'claims' => []],
				],
				'crmDeal' => [
					['contact' => 'uuid-c1', 'title' => 'Must never surface'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'pipelinq',
			schema: 'crmDeal',
			scopeField: 'contact',
			subjectRef: 's1',
			organisation: '',
			limit: 200,
			scopeClaim: 'linkedContactId',
			contributingApp: 'pipelinq',
			via: null,
			audience: 'supplier'
		);

		$this->assertSame([], $rows);
		// Only the portalAccount lookup ran — the collection was NEVER queried.
		$this->assertCount(1, $objectService->calls);
		$this->assertSame('portalAccount', $objectService->calls[0]['schema']);

	}//end testAbsentClaimYieldsEmptyWithoutAnUnscopedQuery()

	public function testMalformedScopeClaimYieldsEmptyWithoutAnyQuery(): void {
		$objectService = $this->objectService(['crmDeal' => [['contact' => 'x']]]);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		foreach (['Bad Claim!', '.leadingDot', 'trailing.', 'UPPER.case', '9starts.withDigit'] as $malformed) {
			$rows = $reader->readCollection(
				register: 'pipelinq',
				schema: 'crmDeal',
				scopeField: 'contact',
				subjectRef: 's1',
				organisation: '',
				limit: 200,
				scopeClaim: $malformed,
				contributingApp: 'pipelinq',
				via: null,
				audience: 'supplier'
			);
			$this->assertSame([], $rows, "scopeClaim '{$malformed}' must fail closed");
		}

		$this->assertCount(0, $objectService->calls);

	}//end testMalformedScopeClaimYieldsEmptyWithoutAnyQuery()

	public function testViaJoinReturnsOnlyVerifiedTargets(): void {
		$objectService = $this->objectService(
			[
				'rol' => [
					// Verified: dot-path matches the subject; scalar target.
					['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
					// Foreign join row — dropped even though OR returned it.
					['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-2'], 'zaak' => 'z-2'],
					// Verified: array-form targetField (empty entries skipped).
					['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => ['z-3', '']],
				],
				'zaak' => [
					['uuid' => 'z-1', 'title' => 'Mine'],
					['id' => 'z-2', 'title' => 'Foreign case'],
					['@self' => ['uuid' => 'z-3'], 'title' => 'Envelope id'],
					['uuid' => 'z-9', 'title' => 'Unreferenced'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'zaken',
			schema: 'zaak',
			scopeField: 'irrelevantForVia',
			subjectRef: 'bsn-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'zaakafhandelapp',
			via: [
				'register' => 'zaken',
				'schema' => 'rol',
				'scopeField' => 'betrokkeneIdentificatie.inpBsn',
				'targetField' => 'zaak',
			],
			audience: 'citizen'
		);

		$this->assertSame(['Mine', 'Envelope id'], array_column($rows, 'title'));
		// Join pre-pass: filtered query-side (best-effort), row-capped at 500.
		$this->assertSame('rol', $objectService->calls[0]['schema']);
		$this->assertSame('bsn-1', $objectService->calls[0]['config']['filters']['betrokkeneIdentificatie.inpBsn']);
		$this->assertSame(500, $objectService->calls[0]['config']['limit']);
		// Both passes bypass OR RBAC/multitenancy — portaliq is the boundary.
		$this->assertFalse($objectService->calls[0]['rbac']);
		$this->assertFalse($objectService->calls[1]['rbac']);

	}//end testViaJoinReturnsOnlyVerifiedTargets()

	public function testInvalidOrNestedViaFailsClosedWithWarning(): void {
		$invalidShapes = [
			'not-an-array',
			['register' => 'zaken', 'schema' => 'rol', 'scopeField' => 'x'],
			['register' => 'zaken', 'schema' => 'rol', 'scopeField' => '', 'targetField' => 'zaak'],
			// One hop maximum: a nested via is contract-law invalid.
			[
				'register' => 'zaken',
				'schema' => 'rol',
				'scopeField' => 'x',
				'targetField' => 'zaak',
				'via' => ['register' => 'deeper'],
			],
		];

		foreach ($invalidShapes as $via) {
			$objectService = $this->objectService(['zaak' => [['uuid' => 'z-1', 'title' => 'Never']]]);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('warning');

			$reader = new PortalObjectReader($this->container($objectService), $logger, $this->projector());
			$rows = $reader->readCollection(
				register: 'zaken',
				schema: 'zaak',
				scopeField: 'x',
				subjectRef: 'bsn-1',
				organisation: '',
				limit: 200,
				scopeClaim: '',
				contributingApp: 'zaakafhandelapp',
				via: $via,
				audience: 'citizen'
			);

			$this->assertSame([], $rows);
			$this->assertCount(0, $objectService->calls);
		}//end foreach

	}//end testInvalidOrNestedViaFailsClosedWithWarning()

	public function testViaWithEmptyVerifiedJoinSetYieldsEmptyWithoutTargetRead(): void {
		$objectService = $this->objectService(
			[
				'rol' => [],
				'zaak' => [['uuid' => 'z-1', 'title' => 'Never']],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'zaken',
			schema: 'zaak',
			scopeField: 'x',
			subjectRef: 'bsn-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'zaakafhandelapp',
			via: [
				'register' => 'zaken',
				'schema' => 'rol',
				'scopeField' => 'inpBsn',
				'targetField' => 'zaak',
			],
			audience: 'citizen'
		);

		$this->assertSame([], $rows);
		// Only the join pre-pass ran; the target read was skipped.
		$this->assertCount(1, $objectService->calls);
		$this->assertSame('rol', $objectService->calls[0]['schema']);

	}//end testViaWithEmptyVerifiedJoinSetYieldsEmptyWithoutTargetRead()

	/**
	 * Reverse join happy path (scholiq parent): the subject (a guardian)
	 * resolves through the join to a set of learner refs, then outer
	 * `gradeEntry` rows are kept when THEIR OWN `learnerRef` (the collection's
	 * scopeField) is in that set — not when their id is. A foreign learner's
	 * grade and an unrelated grade are both dropped even though OR returned
	 * them.
	 */
	public function testReverseViaMatchesOuterRowsByScopeFieldValue(): void {
		$objectService = $this->objectService(
			[
				'learnerProfile' => [
					// Verified: the subject guardians this learner.
					['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a'],
					// Foreign guardian — dropped, so learner-x never enters the set.
					['guardianRefs' => ['guardian-9'], 'learnerRef' => 'learner-x'],
					['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-b'],
				],
				'gradeEntry' => [
					['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Math'],
					['id' => 'g-2', 'learnerRef' => 'learner-x', 'title' => 'Foreign learner'],
					['id' => 'g-3', 'learnerRef' => 'learner-b', 'title' => 'Science'],
					['id' => 'g-4', 'learnerRef' => 'learner-z', 'title' => 'Unrelated'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'scholiq',
			schema: 'gradeEntry',
			scopeField: 'learnerRef',
			subjectRef: 'guardian-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'scholiq',
			via: [
				'register' => 'scholiq',
				'schema' => 'learnerProfile',
				'scopeField' => 'guardianRefs',
				'targetField' => 'learnerRef',
				'match' => 'scopeField',
			],
			audience: 'parent'
		);

		// Kept because learnerRef ∈ {learner-a, learner-b}; g-2/g-4 dropped.
		$this->assertSame(['Math', 'Science'], array_column($rows, 'title'));
		// The join pre-pass queried the join schema, the outer read the target.
		$this->assertSame('learnerProfile', $objectService->calls[0]['schema']);
		$this->assertSame('gradeEntry', $objectService->calls[1]['schema']);

	}//end testReverseViaMatchesOuterRowsByScopeFieldValue()

	/**
	 * A multi-value scopeField matches when ANY of its elements is in the
	 * verified set (strict, element-wise) — and stays excluded when none is.
	 */
	public function testReverseViaArrayScopeFieldMatchesOnAnyElement(): void {
		$objectService = $this->objectService(
			[
				'learnerProfile' => [
					['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-b'],
				],
				'gradeEntry' => [
					// learner-b is one of several refs on this row → matched.
					['id' => 'g-1', 'learnerRefs' => ['learner-q', 'learner-b'], 'title' => 'Shared project'],
					// None of these refs is in the verified set → dropped.
					['id' => 'g-2', 'learnerRefs' => ['learner-q', 'learner-r'], 'title' => 'Other group'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'scholiq',
			schema: 'gradeEntry',
			scopeField: 'learnerRefs',
			subjectRef: 'guardian-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'scholiq',
			via: [
				'register' => 'scholiq',
				'schema' => 'learnerProfile',
				'scopeField' => 'guardianRefs',
				'targetField' => 'learnerRef',
				'match' => 'scopeField',
			],
			audience: 'parent'
		);

		$this->assertSame(['Shared project'], array_column($rows, 'title'));

	}//end testReverseViaArrayScopeFieldMatchesOnAnyElement()

	/**
	 * Security invariant: reverse match NEVER widens. An empty verified target
	 * set yields zero rows and the outer read is skipped entirely — the
	 * absence of matches can only ever exclude, never fall through to "all
	 * rows".
	 */
	public function testReverseViaEmptyVerifiedTargetSetYieldsZeroRows(): void {
		$objectService = $this->objectService(
			[
				// No profile links this guardian → empty target set.
				'learnerProfile' => [
					['guardianRefs' => ['guardian-9'], 'learnerRef' => 'learner-x'],
				],
				'gradeEntry' => [
					['id' => 'g-1', 'learnerRef' => 'learner-x', 'title' => 'Must never surface'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'scholiq',
			schema: 'gradeEntry',
			scopeField: 'learnerRef',
			subjectRef: 'guardian-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'scholiq',
			via: [
				'register' => 'scholiq',
				'schema' => 'learnerProfile',
				'scopeField' => 'guardianRefs',
				'targetField' => 'learnerRef',
				'match' => 'scopeField',
			],
			audience: 'parent'
		);

		$this->assertSame([], $rows);
		// Only the join pre-pass ran; the outer read was never issued.
		$this->assertCount(1, $objectService->calls);
		$this->assertSame('learnerProfile', $objectService->calls[0]['schema']);

	}//end testReverseViaEmptyVerifiedTargetSetYieldsZeroRows()

	/**
	 * Security invariant: an outer row whose scopeField is absent or null is
	 * excluded — a missing key normalises to no candidates, never a wildcard.
	 */
	public function testReverseViaExcludesRowsWithAbsentOrNullScopeField(): void {
		$objectService = $this->objectService(
			[
				'learnerProfile' => [
					['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a'],
				],
				'gradeEntry' => [
					['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Mine'],
					// scopeField entirely absent — excluded.
					['id' => 'g-2', 'title' => 'No scope key'],
					// scopeField present but null — excluded.
					['id' => 'g-3', 'learnerRef' => null, 'title' => 'Null scope key'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'scholiq',
			schema: 'gradeEntry',
			scopeField: 'learnerRef',
			subjectRef: 'guardian-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'scholiq',
			via: [
				'register' => 'scholiq',
				'schema' => 'learnerProfile',
				'scopeField' => 'guardianRefs',
				'targetField' => 'learnerRef',
				'match' => 'scopeField',
			],
			audience: 'parent'
		);

		$this->assertSame(['Mine'], array_column($rows, 'title'));

	}//end testReverseViaExcludesRowsWithAbsentOrNullScopeField()

	/**
	 * Security invariant: a `match` value that is neither 'id' nor
	 * 'scopeField' fails the whole via closed (invalid via → zero rows + a
	 * logged warning, no OR query) — exactly like a structurally invalid via.
	 */
	public function testReverseViaMalformedMatchFailsClosed(): void {
		foreach (['reverse', 'ID', 'scopefield', '', 42, true] as $badMatch) {
			$objectService = $this->objectService(
				[
					'learnerProfile' => [['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a']],
					'gradeEntry' => [['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Never']],
				]
			);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('warning');

			$reader = new PortalObjectReader($this->container($objectService), $logger, $this->projector());
			$rows = $reader->readCollection(
				register: 'scholiq',
				schema: 'gradeEntry',
				scopeField: 'learnerRef',
				subjectRef: 'guardian-1',
				organisation: '',
				limit: 200,
				scopeClaim: '',
				contributingApp: 'scholiq',
				via: [
					'register' => 'scholiq',
					'schema' => 'learnerProfile',
					'scopeField' => 'guardianRefs',
					'targetField' => 'learnerRef',
					'match' => $badMatch,
				],
				audience: 'parent'
			);

			$this->assertSame([], $rows, 'match value must fail closed');
			$this->assertCount(0, $objectService->calls);
		}//end foreach

	}//end testReverseViaMalformedMatchFailsClosed()

	/**
	 * Security invariant: the per-row tenant check on the OUTER rows is
	 * preserved in reverse mode. A grade for a verified learner but belonging
	 * to a different tenant is still dropped.
	 */
	public function testReverseViaTenantMismatchOnOuterRowExcluded(): void {
		$objectService = $this->objectService(
			[
				'learnerProfile' => [
					['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a', 'organisation' => 'org-1'],
				],
				'gradeEntry' => [
					['id' => 'g-1', 'learnerRef' => 'learner-a', 'organisation' => 'org-1', 'title' => 'Mine'],
					// Same verified learner, but a foreign tenant — dropped.
					['id' => 'g-2', 'learnerRef' => 'learner-a', 'organisation' => 'org-2', 'title' => 'Other tenant'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'scholiq',
			schema: 'gradeEntry',
			scopeField: 'learnerRef',
			subjectRef: 'guardian-1',
			organisation: 'org-1',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'scholiq',
			via: [
				'register' => 'scholiq',
				'schema' => 'learnerProfile',
				'scopeField' => 'guardianRefs',
				'targetField' => 'learnerRef',
				'match' => 'scopeField',
			],
			audience: 'parent'
		);

		$this->assertSame(['Mine'], array_column($rows, 'title'));

	}//end testReverseViaTenantMismatchOnOuterRowExcluded()

	/**
	 * Regression pin: an EXPLICIT `match: 'id'` is byte-for-byte the forward
	 * behaviour (and identical to omitting `match`) — outer rows matched by
	 * their OWN id/uuid, the existing zaak/rol scenario unchanged.
	 */
	public function testForwardViaWithExplicitIdMatchIsUnchanged(): void {
		$rows = [];
		foreach ([['match' => 'id'], []] as $matchKey) {
			$objectService = $this->objectService(
				[
					'rol' => [
						['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
						['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-2'], 'zaak' => 'z-2'],
						['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => ['z-3', '']],
					],
					'zaak' => [
						['uuid' => 'z-1', 'title' => 'Mine'],
						['id' => 'z-2', 'title' => 'Foreign case'],
						['@self' => ['uuid' => 'z-3'], 'title' => 'Envelope id'],
						['uuid' => 'z-9', 'title' => 'Unreferenced'],
					],
				]
			);

			$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
			$rows[] = $reader->readCollection(
				register: 'zaken',
				schema: 'zaak',
				scopeField: 'irrelevantForVia',
				subjectRef: 'bsn-1',
				organisation: '',
				limit: 200,
				scopeClaim: '',
				contributingApp: 'zaakafhandelapp',
				via: array_merge(
					[
						'register' => 'zaken',
						'schema' => 'rol',
						'scopeField' => 'betrokkeneIdentificatie.inpBsn',
						'targetField' => 'zaak',
					],
					$matchKey
				),
				audience: 'citizen'
			);
		}//end foreach

		// Explicit match:'id' and omitted match produce the identical forward
		// result — matched by the outer row's OWN id/uuid.
		$this->assertSame(['Mine', 'Envelope id'], array_column($rows[0], 'title'));
		$this->assertSame($rows[0], $rows[1]);

	}//end testForwardViaWithExplicitIdMatchIsUnchanged()

	/**
	 * Field projection still runs AFTER reverse filtering: reverse-joined rows
	 * are projected to the declared whitelist + identifier, exactly like the
	 * forward via path.
	 */
	public function testReverseViaProjectionAppliedToReverseJoinedRows(): void {
		$objectService = $this->objectService(
			[
				'learnerProfile' => [
					['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a'],
				],
				'gradeEntry' => [
					['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Math', 'grade' => '8', 'teacherNotes' => 'staff only'],
					['id' => 'g-9', 'learnerRef' => 'learner-z', 'title' => 'Unrelated', 'grade' => '4'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'scholiq',
			schema: 'gradeEntry',
			scopeField: 'learnerRef',
			subjectRef: 'guardian-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'scholiq',
			via: [
				'register' => 'scholiq',
				'schema' => 'learnerProfile',
				'scopeField' => 'guardianRefs',
				'targetField' => 'learnerRef',
				'match' => 'scopeField',
			],
			audience: 'parent',
			fields: ['title', 'grade']
		);

		// Reverse membership decides WHICH rows return; projection then decides
		// what each shows — teacherNotes and the scopeField are absent.
		$this->assertSame([['title' => 'Math', 'grade' => '8', 'id' => 'g-1']], $rows);

	}//end testReverseViaProjectionAppliedToReverseJoinedRows()

	public function testProjectionReturnsOnlyDeclaredFieldsPlusIdentifierAfterVerification(): void {
		$objectService = $this->objectService(
			[
				'booking' => [
					// A staff-only field that must never reach the portal, and
					// a foreign row that verification must still drop even
					// though fields are declared (projection ≠ authorisation).
					['id' => 'b-1', 'uuid' => 'u-1', 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine', 'status' => 'open', 'internalNotes' => 'staff only'],
					['id' => 'b-2', 'uuid' => 'u-2', 'subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Foreign', 'status' => 'open', 'internalNotes' => 'staff only'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'pipelinq',
			schema: 'booking',
			scopeField: 'subjectRef',
			subjectRef: 's1',
			organisation: 'org-1',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'pipelinq',
			via: null,
			audience: 'client',
			fields: ['title', 'status']
		);

		// Exactly the declared fields + the identifiers — nothing else. The
		// scopeField value (subjectRef) is NOT auto-included.
		$this->assertSame(
			[
				[
					'title' => 'Mine',
					'status' => 'open',
					'id' => 'b-1',
					'uuid' => 'u-1',
				],
			],
			$rows
		);

	}//end testProjectionReturnsOnlyDeclaredFieldsPlusIdentifierAfterVerification()

	public function testProjectionKeepsReducedEnvelopeIdentifierAndDropsUnknownDeclaredFields(): void {
		$objectService = $this->objectService(
			[
				'booking' => [
					// Identifier only inside the @self envelope, which also
					// carries metadata that must not leak through projection.
					['@self' => ['id' => 'b-1', 'uuid' => 'u-1', 'organisation' => 'org-1', 'owner' => 'admin'], 'subjectRef' => 's1', 'title' => 'Mine'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'pipelinq',
			schema: 'booking',
			scopeField: 'subjectRef',
			subjectRef: 's1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'pipelinq',
			via: null,
			audience: 'client',
			fields: ['title', 'notAProperty']
		);

		// Unknown declared fields simply project to absent (no error), and
		// the envelope reduces to its identifier members only.
		$this->assertSame(
			[
				[
					'title' => 'Mine',
					'@self' => [
						'id' => 'b-1',
						'uuid' => 'u-1',
					],
				],
			],
			$rows
		);

	}//end testProjectionKeepsReducedEnvelopeIdentifierAndDropsUnknownDeclaredFields()

	public function testNoFieldsDeclarationKeepsFullRows(): void {
		$objectService = $this->objectService(
			[
				'booking' => [
					['id' => 'b-1', 'subjectRef' => 's1', 'title' => 'Mine', 'internalNotes' => 'still here without a declaration'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection('pipelinq', 'booking', 'subjectRef', 's1');

		// Backward compatible: absent fields = the full verified row.
		$this->assertSame('still here without a declaration', $rows[0]['internalNotes']);
		$this->assertSame('s1', $rows[0]['subjectRef']);

	}//end testNoFieldsDeclarationKeepsFullRows()

	public function testMalformedFieldsDeclarationFailsClosedToIdentifiersOnly(): void {
		foreach (['title,status', ['', 123, null]] as $malformed) {
			$objectService = $this->objectService(
				[
					'booking' => [
						['id' => 'b-1', 'uuid' => 'u-1', 'subjectRef' => 's1', 'title' => 'Mine', 'internalNotes' => 'staff only'],
					],
				]
			);

			$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
			$rows = $reader->readCollection(
				register: 'pipelinq',
				schema: 'booking',
				scopeField: 'subjectRef',
				subjectRef: 's1',
				organisation: '',
				limit: 200,
				scopeClaim: '',
				contributingApp: 'pipelinq',
				via: null,
				audience: 'client',
				fields: $malformed
			);

			// A declared-but-malformed projection intent never fails open to
			// the full row — identifiers only.
			$this->assertSame([['id' => 'b-1', 'uuid' => 'u-1']], $rows);
		}//end foreach

	}//end testMalformedFieldsDeclarationFailsClosedToIdentifiersOnly()

	public function testProjectionAppliesOnTheViaPathAfterTargetVerification(): void {
		$objectService = $this->objectService(
			[
				'rol' => [
					['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
				],
				'zaak' => [
					['uuid' => 'z-1', 'title' => 'Mine', 'status' => 'open', 'behandelaarNotities' => 'staff only'],
					['uuid' => 'z-9', 'title' => 'Unreferenced', 'status' => 'open'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'zaken',
			schema: 'zaak',
			scopeField: 'irrelevantForVia',
			subjectRef: 'bsn-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'zaakafhandelapp',
			via: [
				'register' => 'zaken',
				'schema' => 'rol',
				'scopeField' => 'betrokkeneIdentificatie.inpBsn',
				'targetField' => 'zaak',
			],
			audience: 'citizen',
			fields: ['title']
		);

		// Join membership still decides WHICH rows return; projection then
		// decides what each verified row SHOWS.
		$this->assertSame([['title' => 'Mine', 'uuid' => 'z-1']], $rows);

	}//end testProjectionAppliesOnTheViaPathAfterTargetVerification()

	public function testProjectRowSingleObjectDetailSemantics(): void {
		$projector = $this->projector();

		$row = [
			'id' => 'd-1',
			'subjectRef' => 's1',
			'title' => 'Mine',
			'notes' => 'staff only',
		];

		// The public single-row primitive every detail read must call:
		// whitelist + identifier, null passes the row through whole.
		$this->assertSame(['title' => 'Mine', 'id' => 'd-1'], $projector->projectRow($row, ['title']));
		$this->assertSame($row, $projector->projectRow($row, null));
		// Declaring '@self' explicitly keeps the full envelope (whitelist
		// escape hatch documented in the design).
		$withSelf = ['@self' => ['id' => 'd-1', 'owner' => 'admin'], 'title' => 'Mine'];
		$this->assertSame($withSelf, $projector->projectRow($withSelf, ['@self', 'title']));

	}//end testProjectRowSingleObjectDetailSemantics()

	public function testReadObjectReturnsNullWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$reader = new PortalObjectReader($container, $this->createMock(LoggerInterface::class), $this->projector());
		$this->assertNull($reader->readObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'd-1'));

	}//end testReadObjectReturnsNullWhenOpenRegisterUnavailable()

	public function testReadObjectReturnsTheSubjectsOwnProjectedObject(): void {
		$objectService = $this->objectService(
			[
				'booking' => [
					['id' => 'b-1', 'uuid' => 'u-1', 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine', 'status' => 'open', 'internalNotes' => 'staff only'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$object = $reader->readObject(
			register: 'pipelinq',
			schema: 'booking',
			scopeField: 'subjectRef',
			subjectRef: 's1',
			id: 'b-1',
			organisation: 'org-1',
			fields: ['title', 'status']
		);

		// Projected to the whitelist + identifiers; the scopeField value and
		// staff-only notes never leave.
		$this->assertSame(['title' => 'Mine', 'status' => 'open', 'id' => 'b-1', 'uuid' => 'u-1'], $object);
		// The fetch is scoped register/schema, id-filtered, RBAC-bypassed.
		$this->assertSame('booking', $objectService->calls[0]['schema']);
		$this->assertSame('b-1', $objectService->calls[0]['config']['filters']['id']);
		$this->assertFalse($objectService->calls[0]['rbac']);

	}//end testReadObjectReturnsTheSubjectsOwnProjectedObject()

	/**
	 * ISOLATION: an object owned by a DIFFERENT subject is never returned,
	 * even though OpenRegister returned it for the requested id — the per-row
	 * ownership check drops it → null (→ 404, no oracle).
	 */
	public function testAStaffReadAsksOpenRegisterWithRbacOn(): void {
		$objectService = $this->objectService(
			['portalReport' => [['id' => 'r-1', 'uuid' => 'r-1', 'subject' => 'Melding', 'body' => 'Er klopt iets niet.']]]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$row = $reader->readObjectAsUser(register: 'portaliq', schema: 'portalReport', id: 'r-1');

		// The staff caller is a Nextcloud user, so OpenRegister judges the
		// read against their rights (portaliq#799); the portal reads stay off.
		$this->assertSame('Melding', $row['subject']);
		$this->assertTrue($objectService->calls[0]['rbac']);
		$this->assertFalse($objectService->calls[0]['multitenancy']);
		$this->assertNull($reader->readObjectAsUser(register: 'portaliq', schema: 'portalReport', id: ''));

	}//end testAStaffReadAsksOpenRegisterWithRbacOn()

	/**
	 * A read with no subject scope that names a tenant has only the tenant to
	 * go on, so a row without an organisation belongs to no tenant and is
	 * dropped, like one of another tenant (portaliq#801).
	 */
	public function testAnUnscopedTenantReadDropsARowWithoutAnOrganisation(): void {
		$objectService = $this->objectService(
			[
				'portalPoll' => [
					['id' => 'p-1', 'organisation' => 'org-a', 'audience' => 'parent'],
					['id' => 'p-2', 'organisation' => '', 'audience' => 'parent'],
					['id' => 'p-3', 'audience' => 'parent'],
					['id' => 'p-4', 'organisation' => 'org-b', 'audience' => 'parent'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(
			register: 'portaliq',
			schema: 'portalPoll',
			scopeField: '',
			subjectRef: '',
			organisation: 'org-a',
			filter: ['audience' => 'parent']
		);

		$this->assertSame(['p-1'], array_column($rows, 'id'));

	}//end testAnUnscopedTenantReadDropsARowWithoutAnOrganisation()

	/**
	 * A read scoped to the subject keeps the documented rule: a schema
	 * without an organisation is scoped by the subject reference alone.
	 */
	public function testASubjectScopedReadStillKeepsItsRowWithoutAnOrganisation(): void {
		$objectService = $this->objectService(
			['supplierTender' => [['id' => 't-1', 'subjectRef' => 's1'], ['id' => 't-2', 'subjectRef' => 's2']]]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection(register: 'procest', schema: 'supplierTender', scopeField: 'subjectRef', subjectRef: 's1', organisation: 'org-a');

		$this->assertSame(['t-1'], array_column($rows, 'id'));

	}//end testASubjectScopedReadStillKeepsItsRowWithoutAnOrganisation()

	/**
	 * A system read by id that names no tenant is left as it was.
	 */
	public function testAReadThatNamesNoTenantIsUnchanged(): void {
		$objectService = $this->objectService(['portalReport' => [['id' => 'r-1', 'uuid' => 'r-1', 'subject' => 'Melding']]]);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$this->assertSame('Melding', $reader->readObject(register: 'portaliq', schema: 'portalReport', scopeField: '', subjectRef: '', id: 'r-1')['subject']);

	}//end testAReadThatNamesNoTenantIsUnchanged()

	public function testReadObjectReturnsNullForAForeignOwnedObject(): void {
		$objectService = $this->objectService(
			[
				'booking' => [
					['id' => 'b-2', 'uuid' => 'u-2', 'subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Not mine'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$object = $reader->readObject(
			register: 'pipelinq',
			schema: 'booking',
			scopeField: 'subjectRef',
			subjectRef: 's1',
			id: 'b-2',
			organisation: 'org-1'
		);

		$this->assertNull($object);

	}//end testReadObjectReturnsNullForAForeignOwnedObject()

	/**
	 * ISOLATION: a different tenant's object with the subject's OWN scope value
	 * is still dropped by the per-row tenant check.
	 */
	public function testReadObjectReturnsNullForAForeignTenant(): void {
		$objectService = $this->objectService(
			[
				'booking' => [
					['id' => 'b-3', 'subjectRef' => 's1', 'organisation' => 'org-2', 'title' => 'Other tenant'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$object = $reader->readObject(
			register: 'pipelinq',
			schema: 'booking',
			scopeField: 'subjectRef',
			subjectRef: 's1',
			id: 'b-3',
			organisation: 'org-1'
		);

		$this->assertNull($object);

	}//end testReadObjectReturnsNullForAForeignTenant()

	public function testReadObjectReturnsNullForANonExistentId(): void {
		$objectService = $this->objectService(
			[
				'booking' => [
					['id' => 'b-1', 'subjectRef' => 's1', 'title' => 'Mine'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		// Requested id is not among the returned rows.
		$this->assertNull($reader->readObject('pipelinq', 'booking', 'subjectRef', 's1', 'does-not-exist'));
		// And an empty id fails closed without any query.
		$this->assertNull($reader->readObject('pipelinq', 'booking', 'subjectRef', 's1', ''));
		$this->assertCount(1, $objectService->calls);

	}//end testReadObjectReturnsNullForANonExistentId()

	/**
	 * ISOLATION: a claim-scoped single read resolves the claim server-side and
	 * only returns the object matching the RESOLVED claim value; an absent
	 * claim fails closed to null WITHOUT the object fetch.
	 */
	public function testReadObjectResolvesTheScopeClaimServerSide(): void {
		$objectService = $this->objectService(
			[
				'portalAccount' => [
					['subjectRef' => 's1', 'organisation' => 'org-1', 'claims' => ['pipelinq' => ['linkedContactId' => 'uuid-c1']]],
				],
				'crmDeal' => [
					['id' => 'd-1', 'contact' => 'uuid-c1', 'title' => 'Mine'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$object = $reader->readObject(
			register: 'pipelinq',
			schema: 'crmDeal',
			scopeField: 'contact',
			subjectRef: 's1',
			id: 'd-1',
			organisation: 'org-1',
			scopeClaim: 'linkedContactId',
			contributingApp: 'pipelinq',
			audience: 'supplier'
		);

		$this->assertSame('Mine', $object['title']);

	}//end testReadObjectResolvesTheScopeClaimServerSide()

	public function testReadObjectReturnsNullWhenScopeClaimAbsentWithoutFetch(): void {
		$objectService = $this->objectService(
			[
				'portalAccount' => [
					['subjectRef' => 's1', 'claims' => []],
				],
				'crmDeal' => [
					['id' => 'd-1', 'contact' => 'uuid-c1', 'title' => 'Must never surface'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$object = $reader->readObject(
			register: 'pipelinq',
			schema: 'crmDeal',
			scopeField: 'contact',
			subjectRef: 's1',
			id: 'd-1',
			organisation: '',
			scopeClaim: 'linkedContactId',
			contributingApp: 'pipelinq',
			audience: 'supplier'
		);

		$this->assertNull($object);
		// Only the portalAccount lookup ran — the object was NEVER fetched.
		$this->assertCount(1, $objectService->calls);
		$this->assertSame('portalAccount', $objectService->calls[0]['schema']);

	}//end testReadObjectReturnsNullWhenScopeClaimAbsentWithoutFetch()

	/**
	 * A via-collection single read passes the object through the SAME one-hop
	 * join membership as the list path: the subject's linked case is returned,
	 * a case the subject's join rows do not reference is null.
	 */
	public function testReadObjectViaCollectionVerifiesJoinMembership(): void {
		$objectService = $this->objectService(
			[
				'rol' => [
					['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
				],
				'zaak' => [
					['uuid' => 'z-1', 'title' => 'Mine'],
					['uuid' => 'z-9', 'title' => 'Unreferenced'],
				],
			]
		);
		$via = [
			'register' => 'zaken',
			'schema' => 'rol',
			'scopeField' => 'betrokkeneIdentificatie.inpBsn',
			'targetField' => 'zaak',
		];

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$mine = $reader->readObject(
			register: 'zaken',
			schema: 'zaak',
			scopeField: 'irrelevantForVia',
			subjectRef: 'bsn-1',
			id: 'z-1',
			organisation: '',
			via: $via,
			audience: 'citizen'
		);
		$this->assertSame('Mine', $mine['title']);

		// A case the subject's join rows do NOT reference → null (not visible).
		$foreign = $reader->readObject(
			register: 'zaken',
			schema: 'zaak',
			scopeField: 'irrelevantForVia',
			subjectRef: 'bsn-1',
			id: 'z-9',
			organisation: '',
			via: $via,
			audience: 'citizen'
		);
		$this->assertNull($foreign);

	}//end testReadObjectViaCollectionVerifiesJoinMembership()

	/**
	 * LIST MEMBERSHIP (portal-scope-list-membership): a direct collection
	 * scoped by a list field (learniq `Submission.learnerRefs`) returns the row
	 * whose list contains the subject's scoping value. Before this change the
	 * list was cast to "Array" and every such row was dropped.
	 */
	public function testListScopeFieldContainingTheRefIsReturned(): void {
		$objectService = $this->objectService(
			[
				'submission' => [
					['id' => 'sub-1', 'learnerRefs' => ['learner-2', 'learner-1'], 'assignmentId' => 'a-1'],
					['id' => 'sub-2', 'learnerRefs' => [7, 'learner-1'], 'assignmentId' => 'a-2'],
					['id' => 'sub-3', 'learnerRefs' => ['learner-2'], 'assignmentId' => 'a-3'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection('learniq', 'submission', 'learnerRefs', 'learner-1');

		$this->assertSame(['sub-1', 'sub-2'], array_column($rows, 'id'));
		// The query-side filter is unchanged: OpenRegister turns it into a
		// containment test for an array property.
		$this->assertSame('learner-1', $objectService->calls[0]['config']['filters']['learnerRefs']);

	}//end testListScopeFieldContainingTheRefIsReturned()

	/**
	 * ISOLATION: a list match does not skip the tenant check. A row from
	 * another tenant is dropped even when its list contains the value.
	 */
	public function testListScopeMatchStillEnforcesTheTenant(): void {
		$objectService = $this->objectService(
			[
				'submission' => [
					['id' => 'sub-5', 'learnerRefs' => ['learner-1'], 'organisation' => 'org-1'],
					['id' => 'sub-6', 'learnerRefs' => ['learner-1'], 'organisation' => 'org-2'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$rows = $reader->readCollection('learniq', 'submission', 'learnerRefs', 'learner-1', 'org-1');

		$this->assertSame(['sub-5'], array_column($rows, 'id'));

	}//end testListScopeMatchStillEnforcesTheTenant()

	/**
	 * ISOLATION: a list that does not contain the subject's value is dropped,
	 * even when OpenRegister returned it.
	 */
	public function testListScopeFieldWithoutTheRefIsDropped(): void {
		$objectService = $this->objectService(
			[
				'submission' => [
					['id' => 'sub-9', 'learnerRefs' => ['learner-2', 'learner-3']],
					// A near miss: a substring of the value is not the value.
					['id' => 'sub-10', 'learnerRefs' => ['learner-10']],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$this->assertSame([], $reader->readCollection('learniq', 'submission', 'learnerRefs', 'learner-1'));

	}//end testListScopeFieldWithoutTheRefIsDropped()

	/**
	 * FAIL CLOSED: an empty list belongs to nobody.
	 */
	public function testEmptyListScopeFieldIsDropped(): void {
		$objectService = $this->objectService(
			[
				'submission' => [
					['id' => 'sub-4', 'learnerRefs' => []],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
		$this->assertSame([], $reader->readCollection('learniq', 'submission', 'learnerRefs', 'learner-1'));

	}//end testEmptyListScopeFieldIsDropped()

	/**
	 * operate-portals-per-organisation REQ-OPO-002: for a schema declared
	 * organisation-scoped, a row without an organisation belongs to no tenant.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t04
	 */
	public function testRowWithoutOrganisationIsDropped(): void {
		$objectService = $this->objectService(
			[
				'portalMessage' => [
					['id' => 'm-1', 'subjectRef' => 's1', 'organisation' => 'org-a'],
					['id' => 'm-2', 'subjectRef' => 's1'],
					['id' => 'm-3', 'subjectRef' => 's1', 'organisation' => 'org-b'],
				],
			]
		);
		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$ids = array_column($reader->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1', 'org-a'), 'id');

		$this->assertSame(['m-1'], $ids, 'neither the row without a tenant nor another tenant\'s row');

	}//end testRowWithoutOrganisationIsDropped()

	/**
	 * REQ-OPO-002: a subject without an organisation reads nothing of an
	 * organisation-scoped schema, whatever the rows carry.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t04
	 */
	public function testSubjectWithoutOrganisationGetsNothing(): void {
		$objectService = $this->objectService(
			[
				'portalMessage' => [
					['id' => 'm-1', 'subjectRef' => 's1', 'organisation' => 'org-a'],
					['id' => 'm-2', 'subjectRef' => 's1'],
				],
			]
		);
		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$this->assertSame([], $reader->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1', ''));
		$this->assertNull($reader->readObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'm-1', ''));
		$this->assertNotNull($reader->readObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'm-1', 'org-a'), 'the same message with the right tenant');

	}//end testSubjectWithoutOrganisationGetsNothing()

	/**
	 * A subject-scoped schema keeps today\'s behaviour: the subject reference is
	 * globally unique, so a row without an organisation still reads.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t04
	 */
	public function testSubjectScopedSchemaUnchanged(): void {
		$objectService = $this->objectService(
			[
				'pushSubscription' => [
					['id' => 'p-1', 'subjectRef' => 's1'],
					['id' => 'p-2', 'subjectRef' => 's1', 'organisation' => 'org-b'],
				],
			]
		);
		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$this->assertSame(['p-1'], array_column($reader->readCollection('portaliq', 'pushSubscription', 'subjectRef', 's1', 'org-a'), 'id'));
		$this->assertSame(['p-1', 'p-2'], array_column($reader->readCollection('portaliq', 'pushSubscription', 'subjectRef', 's1', ''), 'id'), 'no tenant named, as before');

	}//end testSubjectScopedSchemaUnchanged()

	/**
	 * UNCHANGED: a single-value scope field matches exactly as before, an
	 * integer value still matches its decimal string, and a foreign value is
	 * still dropped.
	 */
	public function testSingleValueScopeFieldStillMatches(): void {
		$objectService = $this->objectService(
			[
				'exampleDocument' => [
					['id' => 'd-1', 'subjectRef' => 's1', 'organisation' => 'org-1'],
					['id' => 'd-2', 'subjectRef' => 's2', 'organisation' => 'org-1'],
				],
				'counter' => [
					['id' => 'c-1', 'ownerNumber' => 42],
					['id' => 'c-2', 'ownerNumber' => 43],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$this->assertSame(['d-1'], array_column($reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1'), 'id'));
		$this->assertSame(['c-1'], array_column($reader->readCollection('portaliq', 'counter', 'ownerNumber', '42'), 'id'));

	}//end testSingleValueScopeFieldStillMatches()

	/**
	 * FAIL CLOSED: every shape other than an equal single value or a list
	 * containing it is dropped, and an empty scoping value matches nothing,
	 * not even a row whose scope field is absent or empty.
	 */
	public function testOtherScopeShapesFailClosed(): void {
		$objectService = $this->objectService(
			[
				'submission' => [
					// An associative array (an object reference) is not a list.
					['id' => 'x-1', 'learnerRefs' => ['value' => 'learner-1']],
					// A nested list is not membership.
					['id' => 'x-2', 'learnerRefs' => [['learner-1']]],
					['id' => 'x-3', 'learnerRefs' => null],
					['id' => 'x-4'],
					['id' => 'x-5', 'learnerRefs' => true],
					['id' => 'x-6', 'learnerRefs' => ''],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$this->assertSame([], $reader->readCollection('learniq', 'submission', 'learnerRefs', 'learner-1'));
		// An empty scoping value: no query filter is sent, and the per-row
		// check must still return nothing (x-3, x-4 and x-6 would have matched
		// the old string cast).
		$this->assertSame([], $reader->readCollection('learniq', 'submission', 'learnerRefs', ''));

	}//end testOtherScopeShapesFailClosed()

	/**
	 * SINGLE READ: the detail read uses the same rule. An object whose list
	 * contains the subject's value is returned; a list without it, or an empty
	 * one, is the identical null (→ 404).
	 */
	public function testReadObjectMatchesAListScopeFieldByMembership(): void {
		$objectService = $this->objectService(
			[
				'learner-profile' => [
					['id' => 'p-1', 'guardianRefs' => ['g-1', 'g-2'], 'givenName' => 'Sam'],
					['id' => 'p-2', 'guardianRefs' => ['g-3'], 'givenName' => 'Noor'],
					['id' => 'p-3', 'guardianRefs' => [], 'givenName' => 'Lou'],
				],
			]
		);

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());

		$own = $reader->readObject(register: 'learniq', schema: 'learner-profile', scopeField: 'guardianRefs', subjectRef: 'g-2', id: 'p-1');
		$this->assertSame('Sam', $own['givenName']);
		$this->assertNull($reader->readObject(register: 'learniq', schema: 'learner-profile', scopeField: 'guardianRefs', subjectRef: 'g-2', id: 'p-2'));
		$this->assertNull($reader->readObject(register: 'learniq', schema: 'learner-profile', scopeField: 'guardianRefs', subjectRef: 'g-2', id: 'p-3'));

	}//end testReadObjectMatchesAListScopeFieldByMembership()

	/**
	 * site-mijn-omgeving-components REQ-SMO-023: a join row outside `when`
	 * grants nothing. The pupil's withdrawn enrolment in group 3B opens no
	 * session of 3B; the active one in 4A still does (the positive control).
	 *
	 * @return void
	 */
	public function testAJoinRowOutsideWhenGrantsNothing(): void {
		$rows = $this->readLiveJoin(
			[
				['learnerRef' => 'pupil-1', 'cohortId' => '3B', 'status' => 'withdrawn'],
				['learnerRef' => 'pupil-1', 'cohortId' => '4A', 'status' => 'active'],
			],
			['when' => ['field' => 'status', 'in' => ['active']]]
		);

		$this->assertSame(['4A lesson'], array_column($rows, 'title'));
	}//end testAJoinRowOutsideWhenGrantsNothing()

	/**
	 * An expired share grants nothing; an empty end date and a future one do.
	 *
	 * @return void
	 */
	public function testAnExpiredJoinRowGrantsNothing(): void {
		$rows = $this->readLiveJoin(
			[
				['learnerRef' => 'pupil-1', 'cohortId' => '3B', 'expiresAt' => '2020-10-01'],
				['learnerRef' => 'pupil-1', 'cohortId' => '4A', 'expiresAt' => ''],
				['learnerRef' => 'pupil-1', 'cohortId' => '5C', 'expiresAt' => '2999-01-01T00:00:00+00:00'],
			],
			['validUntilField' => 'expiresAt']
		);

		$this->assertSame(['4A lesson', '5C lesson'], array_column($rows, 'title'));
	}//end testAnExpiredJoinRowGrantsNothing()

	/**
	 * A malformed live-row member fails the whole via closed: zero rows and no
	 * query at all, like any other invalid via.
	 *
	 * @return void
	 */
	public function testAMalformedLiveRowMemberFailsClosed(): void {
		foreach ([
			['when' => ['field' => 'status']],
			['when' => ['field' => 'status', 'in' => []]],
			['when' => ['field' => 'status', 'in' => ['active'], 'notIn' => ['withdrawn']]],
			['when' => ['field' => '', 'in' => ['active']]],
			['when' => ['field' => 'status', 'in' => [['nested']]]],
			['validUntilField' => ''],
			['validUntilField' => 7],
		] as $extra) {
			$objectService = $this->liveJoinObjectService([['learnerRef' => 'pupil-1', 'cohortId' => '4A', 'status' => 'active']]);
			$rows = $this->liveJoinReader($objectService)->readCollection(
				register: 'school',
				schema: 'session',
				scopeField: 'cohortId',
				subjectRef: 'pupil-1',
				via: $this->liveJoinVia($extra),
				audience: 'student'
			);

			$this->assertSame([], $rows, 'malformed: ' . json_encode($extra));
			$this->assertCount(0, $objectService->calls);
		}
	}//end testAMalformedLiveRowMemberFailsClosed()

	/**
	 * The single read honours the same rule: a session of a withdrawn
	 * enrolment does not open by id.
	 *
	 * @return void
	 */
	public function testASingleReadThroughAWithdrawnJoinRowIsNull(): void {
		$objectService = $this->liveJoinObjectService([['learnerRef' => 'pupil-1', 'cohortId' => '3B', 'status' => 'withdrawn']]);

		$row = $this->liveJoinReader($objectService)->readObject(
			register: 'school',
			schema: 'session',
			scopeField: 'cohortId',
			subjectRef: 'pupil-1',
			id: 's-3b',
			via: $this->liveJoinVia(['when' => ['field' => 'status', 'in' => ['active']]]),
			audience: 'student'
		);

		$this->assertNull($row);
	}//end testASingleReadThroughAWithdrawnJoinRowIsNull()

	/**
	 * Read sessions through a reverse enrolment join with extra via members.
	 *
	 * @param array<int, array<string, mixed>> $enrolments The join rows.
	 * @param array<string, mixed>             $extra      The live-row members.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readLiveJoin(array $enrolments, array $extra): array {
		return $this->liveJoinReader($this->liveJoinObjectService($enrolments))->readCollection(
			register: 'school',
			schema: 'session',
			scopeField: 'cohortId',
			subjectRef: 'pupil-1',
			via: $this->liveJoinVia($extra),
			audience: 'student'
		);
	}//end readLiveJoin()

	/**
	 * The fake object service for the live-join tests.
	 *
	 * @param array<int, array<string, mixed>> $enrolments The join rows.
	 *
	 * @return object
	 */
	private function liveJoinObjectService(array $enrolments): object {
		return $this->objectService(
			[
				'enrolment' => $enrolments,
				'session' => [
					['uuid' => 's-3b', 'cohortId' => '3B', 'title' => '3B lesson'],
					['uuid' => 's-4a', 'cohortId' => '4A', 'title' => '4A lesson'],
					['uuid' => 's-5c', 'cohortId' => '5C', 'title' => '5C lesson'],
				],
			]
		);
	}//end liveJoinObjectService()

	/**
	 * The reader under test.
	 *
	 * @param object $objectService The fake object service.
	 *
	 * @return PortalObjectReader
	 */
	private function liveJoinReader(object $objectService): PortalObjectReader {
		return new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), $this->projector());
	}//end liveJoinReader()

	/**
	 * A reverse join from the pupil's enrolments to sessions of the cohort.
	 *
	 * @param array<string, mixed> $extra The live-row members.
	 *
	 * @return array<string, mixed>
	 */
	private function liveJoinVia(array $extra): array {
		return array_merge(
			[
				'register' => 'school',
				'schema' => 'enrolment',
				'scopeField' => 'learnerRef',
				'targetField' => 'cohortId',
				'match' => 'scopeField',
			],
			$extra
		);
	}//end liveJoinVia()

	/**
	 * An ObjectService stand-in serving canned rows per schema and recording
	 * every call (register/schema context, config, rbac/multitenancy flags).
	 */
	private function objectService(array $returnsPerSchema): object {
		return new class($returnsPerSchema) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			public array $calls = [];

			private string $register = '';

			private string $schema = '';

			public function __construct(
				private array $returns,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = [
					'register' => $this->register,
					'schema' => $this->schema,
					'config' => $config,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
				];
				return ($this->returns[$this->schema] ?? []);
			}//end findAll()

			/**
			 * Fetch a single canned row by id/uuid (OR's by-identifier read).
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id, string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true): ?array {
				$this->calls[] = [
					'register' => $register,
					'schema' => $schema,
					'config' => ['filters' => ['id' => $id]],
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
				];
				foreach (($this->returns[$schema] ?? []) as $row) {
					if (in_array($id, [($row['id'] ?? null), ($row['uuid'] ?? null)], true) === true) {
						return $row;
					}
				}

				return null;
			}//end find()
		};

	}//end objectService()

	private function container(object $objectService): ContainerInterface {
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		return $mock;
	}//end container()

	/**
	 * The real field projector the reader shapes its verified rows with — the
	 * projection semantics stay exercised end-to-end through readCollection()
	 * and readObject(), exactly as before the collaborator was split out.
	 */
	private function projector(): PortalFieldProjector {
		return new PortalFieldProjector($this->createMock(LoggerInterface::class));
	}//end projector()
}//end class
