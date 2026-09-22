<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;

/**
 * portal-visibility-follows-the-party-tree REQ-PTV-001, REQ-PTV-003 and
 * REQ-PTV-004: a flat mandate stays flat, the group is read at the moment of
 * the request rather than remembered, every read carries a limit, and a group
 * past the bound is refused rather than truncated.
 *
 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
 */
class PortalPartyTreeResolverTest extends TestCase {

	/**
	 * The recorded parent-child relations, keyed by parent.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $tree = [];

	/**
	 * Every limit the reader was called with.
	 *
	 * @var array<int, int>
	 */
	private array $limits = [];

	protected function setUp(): void {
		$this->tree = [];
		$this->limits = [];

	}//end setUp()

	public function testAFlatMandateReachesOnlyTheEntityItNames(): void {
		$this->tree = ['parent' => ['sub-1', 'sub-2']];
		$resolver = $this->resolver();

		$scope = $resolver->entitiesFor(root: 'parent', reachesDown: false);

		$this->assertSame(['parent'], $scope['entities']);
		// A flat mandate reads no relation at all.
		$this->assertSame([], $this->limits);

	}//end testAFlatMandateReachesOnlyTheEntityItNames()

	public function testOneMandateReachesTheWholeGroup(): void {
		$this->tree = ['parent' => ['sub-1', 'sub-2'], 'sub-1' => ['sub-1-a']];
		$resolver = $this->resolver();

		$scope = $resolver->entitiesFor(root: 'parent', reachesDown: true);

		$this->assertSame(['parent', 'sub-1', 'sub-2', 'sub-1-a'], $scope['entities']);
		$this->assertFalse($scope['refused']);

	}//end testOneMandateReachesTheWholeGroup()

	public function testASiblingIsNotBelow(): void {
		$this->tree = ['holding' => ['first', 'second'], 'first' => ['first-sub']];
		$resolver = $this->resolver();

		$scope = $resolver->entitiesFor(root: 'first', reachesDown: true);

		$this->assertSame(['first', 'first-sub'], $scope['entities']);
		$this->assertNotContains('second', $scope['entities']);

	}//end testASiblingIsNotBelow()

	public function testASubsidiarySoldTodayIsGoneToday(): void {
		$this->tree = ['parent' => ['sub-1', 'sub-2']];
		$resolver = $this->resolver();
		$this->assertContains('sub-2', $resolver->entitiesFor(root: 'parent', reachesDown: true)['entities']);

		// The group is re-read on the next request; nothing was cached and no
		// grant is revoked.
		$this->tree = ['parent' => ['sub-1']];

		$this->assertNotContains('sub-2', $resolver->entitiesFor(root: 'parent', reachesDown: true)['entities']);

	}//end testASubsidiarySoldTodayIsGoneToday()

	public function testASubsidiaryAcquiredTodayIsVisibleToday(): void {
		$this->tree = ['parent' => ['sub-1']];
		$resolver = $this->resolver();
		$resolver->entitiesFor(root: 'parent', reachesDown: true);

		$this->tree = ['parent' => ['sub-1', 'sub-new']];

		$this->assertContains('sub-new', $resolver->entitiesFor(root: 'parent', reachesDown: true)['entities']);

	}//end testASubsidiaryAcquiredTodayIsVisibleToday()

	public function testAGroupDeeperThanTheBoundIsRefusedNotTruncated(): void {
		$this->tree = ['l0' => ['l1'], 'l1' => ['l2'], 'l2' => ['l3'], 'l3' => ['l4']];
		$resolver = $this->resolver();

		$scope = $resolver->entitiesFor(root: 'l0', reachesDown: true, bounds: ['maxDepth' => 2]);

		$this->assertTrue($scope['refused']);
		$this->assertSame([], $scope['entities']);
		$this->assertSame(2, $scope['bound']['maxDepth']);

	}//end testAGroupDeeperThanTheBoundIsRefusedNotTruncated()

	public function testALevelWiderThanThePageSizeIsRefusedNotTruncated(): void {
		$this->tree = ['parent' => ['a', 'b', 'c']];
		$resolver = $this->resolver();

		$scope = $resolver->entitiesFor(root: 'parent', reachesDown: true, bounds: ['pageSize' => 2]);

		$this->assertTrue($scope['refused']);
		$this->assertSame([], $scope['entities']);

	}//end testALevelWiderThanThePageSizeIsRefusedNotTruncated()

	public function testEveryReadCarriesALimit(): void {
		$this->tree = ['parent' => ['sub-1'], 'sub-1' => ['sub-1-a']];
		$resolver = $this->resolver();

		$resolver->entitiesFor(root: 'parent', reachesDown: true);

		$this->assertNotSame([], $this->limits);
		foreach ($this->limits as $limit) {
			$this->assertGreaterThan(0, $limit);
			$this->assertLessThanOrEqual(PortalPartyTreeResolver::DEFAULT_PAGE_SIZE, $limit);
		}

	}//end testEveryReadCarriesALimit()

	public function testACycleInTheRecordedRelationsDoesNotWalkForever(): void {
		$this->tree = ['a' => ['b'], 'b' => ['a']];
		$resolver = $this->resolver();

		$scope = $resolver->entitiesFor(root: 'a', reachesDown: true);

		$this->assertSame(['a', 'b'], $scope['entities']);
		$this->assertFalse($scope['refused']);

	}//end testACycleInTheRecordedRelationsDoesNotWalkForever()

	/**
	 * The resolver over a reader answering the recorded relations.
	 *
	 * @return PortalPartyTreeResolver
	 */
	private function resolver(): PortalPartyTreeResolver {
		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []): array {
				$this->limits[] = $limit;
				$rows = [];
				foreach (($this->tree[$subjectRef] ?? []) as $child) {
					$rows[] = ['slug' => $child, 'parent' => $subjectRef];
				}

				return array_slice($rows, 0, $limit);
			}
		);

		return new PortalPartyTreeResolver($reader);
	}//end resolver()

}//end class
