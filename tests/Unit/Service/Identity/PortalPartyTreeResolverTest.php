<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PartyTreeSource;
use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * portal-visibility-follows-the-party-tree REQ-PTV-001, REQ-PTV-003 and
 * REQ-PTV-004: a flat mandate stays flat, the group is read at the moment of
 * the request rather than remembered, and a group past the bound is refused
 * rather than truncated. The group comes from OpenRegister's tree read, so the
 * source answers here in that read's shape.
 *
 * @covers \OCA\Portaliq\Service\Identity\PortalPartyTreeResolver
 *
 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
 */
class PortalPartyTreeResolverTest extends TestCase {

	/**
	 * The answer the source gives, or null for "no party object".
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $answer = null;

	/**
	 * Every call made to the source.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	protected function setUp(): void {
		$this->answer = null;
		$this->calls = [];
		$this->config = [];
	}//end setUp()

	/**
	 * One node of OpenRegister's tree answer.
	 *
	 * @param string $kvk   The node's KvK number.
	 * @param int    $level OpenRegister's level, 0 for the root.
	 *
	 * @return array<string, mixed> The node.
	 */
	private static function node(string $kvk, int $level): array {
		return ['uuid' => 'uuid-'.$kvk, 'name' => 'Company '.$kvk, 'level' => $level, 'parent' => null, 'properties' => ['kvkNumber' => $kvk]];
	}//end node()

	/**
	 * A holding with two subsidiaries and one below the first.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private static function group(): array {
		return [
			'nodes' => [self::node('10000000', 0), self::node('20000000', 1), self::node('30000000', 1), self::node('40000000', 2)],
			'truncated' => false,
			'truncatedBy' => null,
		];
	}//end group()

	public function testAFlatMandateReachesOnlyTheEntityItNames(): void {
		$this->answer = self::group();

		$scope = $this->resolver()->entitiesFor(root: '10000000', reachesDown: false);

		$this->assertSame(['10000000'], $scope['entities']);
		$this->assertSame([], $this->calls, 'a flat mandate reads no relation at all');
	}//end testAFlatMandateReachesOnlyTheEntityItNames()

	public function testOneMandateReachesTheWholeGroup(): void {
		$this->answer = self::group();

		$scope = $this->resolver()->entitiesFor(root: '10000000', reachesDown: true);

		$this->assertSame(['10000000', '20000000', '30000000', '40000000'], $scope['entities']);
		$this->assertFalse($scope['refused']);
	}//end testOneMandateReachesTheWholeGroup()

	public function testTheTreeCarriesNamesAndLevelsFromOne(): void {
		$this->answer = self::group();

		$tree = $this->resolver()->treeFor(root: '10000000');

		$this->assertSame(
			[
				['identifier' => '10000000', 'name' => 'Company 10000000', 'level' => 1],
				['identifier' => '20000000', 'name' => 'Company 20000000', 'level' => 2],
				['identifier' => '30000000', 'name' => 'Company 30000000', 'level' => 2],
				['identifier' => '40000000', 'name' => 'Company 40000000', 'level' => 3],
			],
			$tree['nodes']
		);
	}//end testTheTreeCarriesNamesAndLevelsFromOne()

	public function testNoTreeRecordedReachesTheCompanyAlone(): void {
		$this->answer = null;

		$scope = $this->resolver()->entitiesFor(root: '10000000', reachesDown: true);

		$this->assertSame(['10000000'], $scope['entities']);
		$this->assertFalse($scope['refused']);
	}//end testNoTreeRecordedReachesTheCompanyAlone()

	public function testTheSourceIsNamedByRegisterSchemaAndField(): void {
		$this->answer = self::group();
		$this->resolver()->treeFor(root: '10000000');
		$this->assertSame(['dossiq', 'kvkCompany', 'kvkNumber', '10000000', 4], array_values($this->calls[0]));

		$this->config = ['party_tree_register' => 'crm', 'party_tree_schema' => 'organisation', 'party_tree_identity' => 'kvk'];
		$this->resolver()->treeFor(root: '10000000', bounds: ['maxDepth' => 2]);
		$this->assertSame(['crm', 'organisation', 'kvk', '10000000', 2], array_values($this->calls[1]));
	}//end testTheSourceIsNamedByRegisterSchemaAndField()

	public function testAGroupPastADepthBoundIsRefusedNotTruncated(): void {
		$this->answer = ['nodes' => [self::node('10000000', 0), self::node('20000000', 1)], 'truncated' => true, 'truncatedBy' => 'depth'];

		$scope = $this->resolver()->entitiesFor(root: '10000000', reachesDown: true, bounds: ['maxDepth' => 1]);

		$this->assertTrue($scope['refused']);
		$this->assertSame([], $scope['entities']);
		$this->assertSame(1, $scope['bound']['maxDepth']);
	}//end testAGroupPastADepthBoundIsRefusedNotTruncated()

	public function testAGroupLargerThanThePageSizeIsRefusedNotTruncated(): void {
		$this->answer = self::group();

		$scope = $this->resolver()->entitiesFor(root: '10000000', reachesDown: true, bounds: ['pageSize' => 3]);

		$this->assertTrue($scope['refused']);
		$this->assertSame([], $scope['entities']);
	}//end testAGroupLargerThanThePageSizeIsRefusedNotTruncated()

	public function testTheGroupIsReadOnEveryRequest(): void {
		$this->answer = self::group();
		$resolver = $this->resolver();
		$this->assertContains('30000000', $resolver->entitiesFor(root: '10000000', reachesDown: true)['entities']);

		// Sold this morning: nothing was cached, nothing is revoked.
		$this->answer = ['nodes' => [self::node('10000000', 0), self::node('20000000', 1)], 'truncated' => false, 'truncatedBy' => null];

		$this->assertNotContains('30000000', $resolver->entitiesFor(root: '10000000', reachesDown: true)['entities']);
		$this->assertCount(2, $this->calls);
	}//end testTheGroupIsReadOnEveryRequest()

	public function testANodeWithoutAnIdentityIsLeftOut(): void {
		$nameless = self::node('50000000', 1);
		$nameless['properties'] = [];
		$this->answer = ['nodes' => [self::node('10000000', 0), $nameless], 'truncated' => false, 'truncatedBy' => null];

		$this->assertSame(['10000000'], $this->resolver()->entitiesFor(root: '10000000', reachesDown: true)['entities']);
	}//end testANodeWithoutAnIdentityIsLeftOut()

	public function testAHeldMandateCarriesWhatItReaches(): void {
		$this->answer = self::group();
		$resolver = $this->resolver();

		$flat = $resolver->withReach(mandate: ['id' => 'a', 'onBehalfOf' => 'kvk:10000000', 'reach' => 'organisation']);
		$this->assertSame([], $flat['reachesTo']);
		$this->assertSame([], $this->calls, 'a flat mandate reads no tree');

		$group = $resolver->withReach(mandate: ['id' => 'b', 'onBehalfOf' => 'kvk:10000000', 'reach' => 'tree']);
		$this->assertSame('10000000', $this->calls[0]['identity'], 'read for the untyped number');
		$this->assertCount(4, $group['reachesTo']);
		$this->assertFalse($group['reachRefused']);

		$this->answer = ['nodes' => [], 'truncated' => true, 'truncatedBy' => 'cap'];
		$refused = $resolver->withReach(mandate: ['id' => 'c', 'onBehalfOf' => 'kvk:10000000', 'reach' => 'tree']);
		$this->assertTrue($refused['reachRefused']);
		$this->assertSame([], $refused['reachesTo']);
	}//end testAHeldMandateCarriesWhatItReaches()

	/**
	 * The resolver over a source double answering `$this->answer`.
	 *
	 * @return PortalPartyTreeResolver
	 */
	private function resolver(): PortalPartyTreeResolver {
		$source = $this->createMock(PartyTreeSource::class);
		$source->method('below')->willReturnCallback(
			function (string $register, string $schema, string $identityField, string $identity, int $maxDepth): ?array {
				$this->calls[] = ['register' => $register, 'schema' => $schema, 'identityField' => $identityField, 'identity' => $identity, 'maxDepth' => $maxDepth];
				return $this->answer;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new PortalPartyTreeResolver($source, $appConfig);
	}//end resolver()
}//end class
