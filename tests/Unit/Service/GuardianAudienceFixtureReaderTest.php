<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\LeafGuardianAudienceReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the interim audience-source seam: degrades to empty without
 * OpenRegister or a matching row (never an error), resolves a matching row's
 * shape, and enumerates guardians matching a target for the preflight.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#audience-source-seam
 */
class GuardianAudienceFixtureReaderTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * @param array<int, array<string, mixed>> $rows Rows `findAll` returns.
	 */
	private function fakeObjectService(array $rows): object {
		return new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows
			 */
			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = $config['filters'] ?? [];
				if (isset($filters['guardianRef']) === true) {
					return array_values(array_filter($this->rows, fn (array $r): bool => ($r['guardianRef'] ?? null) === $filters['guardianRef']));
				}

				return $this->rows;
			}//end findAll()
		};
	}//end fakeObjectService()

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		return $container;
	}//end container()

	public function testReturnsEmptyAudienceWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$reader = new GuardianAudienceFixtureReader($container, $this->createMock(LoggerInterface::class));
		$audience = $reader->resolveAudience('guardian-anna-devries');

		$this->assertSame('', $audience['schoolRef']);
		$this->assertSame([], $audience['groupRefs']);
	}//end testReturnsEmptyAudienceWhenOpenRegisterUnavailable()

	public function testReturnsEmptyAudienceForAGuardianWithNoFixtureRow(): void {
		$os = $this->fakeObjectService([
			['guardianRef' => 'guardian-anna-devries', 'schoolRef' => 'school-a', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-1'], 'photoConsent' => []],
		]);

		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));
		$audience = $reader->resolveAudience('guardian-jan-smit');

		$this->assertSame([], $audience['groupRefs']);
		$this->assertSame([], $audience['childRefs']);
	}//end testReturnsEmptyAudienceForAGuardianWithNoFixtureRow()

	/**
	 * Without a fixture row the school app's own audience answers; a fixture
	 * row still wins (news-audience-from-the-school-app).
	 *
	 * @spec openspec/changes/news-audience-from-the-school-app/tasks.md#T1
	 */
	public function testWithoutAFixtureRowTheSchoolAppAnswers(): void {
		$os = $this->fakeObjectService([
			['guardianRef' => 'guardian-anna-devries', 'schoolRef' => 'school-a', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-1'], 'photoConsent' => []],
		]);
		$leaf = $this->createMock(LeafGuardianAudienceReader::class);
		$leaf->method('resolveAudience')->willReturn(['schoolRef' => 'school-w', 'groupRefs' => ['groep-7'], 'childRefs' => ['vera'], 'photoConsent' => []]);

		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class), $leaf);

		$this->assertSame('school-w', $reader->resolveAudience('guardian-from-the-portal')['schoolRef']);
		$this->assertSame('school-a', $reader->resolveAudience('guardian-anna-devries')['schoolRef']);
	}//end testWithoutAFixtureRowTheSchoolAppAnswers()

	public function testResolvesAMatchingRow(): void {
		$os = $this->fakeObjectService([
			['guardianRef' => 'guardian-anna-devries', 'schoolRef' => 'school-a', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-1'], 'photoConsent' => ['child-1' => ['news' => true]]],
		]);

		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));
		$audience = $reader->resolveAudience('guardian-anna-devries');

		$this->assertSame('school-a', $audience['schoolRef']);
		$this->assertSame(['groep-5a'], $audience['groupRefs']);
		$this->assertTrue($audience['photoConsent']['child-1']['news']);
	}//end testResolvesAMatchingRow()

	public function testChildPhotoConsentFailsClosedForAnUnknownChildOrPurpose(): void {
		$os = $this->fakeObjectService([
			['guardianRef' => 'g1', 'childRefs' => ['child-1'], 'photoConsent' => ['child-1' => ['news' => true]]],
		]);

		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));

		$this->assertTrue($reader->childPhotoConsentGranted('child-1'));
		$this->assertFalse($reader->childPhotoConsentGranted('child-unknown'));
		$this->assertFalse($reader->childPhotoConsentGranted('child-1', 'website'), 'a different purpose is not granted just because news is');
	}//end testChildPhotoConsentFailsClosedForAnUnknownChildOrPurpose()

	public function testGuardiansMatchingEnumeratesEveryMatchingRowOnce(): void {
		$os = $this->fakeObjectService([
			['guardianRef' => 'guardian-anna-devries', 'schoolRef' => 'school-a', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-1']],
			['guardianRef' => 'guardian-piet-bakker', 'schoolRef' => 'school-a', 'groupRefs' => ['groep-3b'], 'childRefs' => ['child-2']],
			['guardianRef' => 'guardian-fatima-elamrani', 'schoolRef' => 'school-b', 'groupRefs' => ['groep-4c'], 'childRefs' => ['child-3']],
		]);

		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));
		$matched = $reader->guardiansMatching(['groupRefs' => ['groep-5a']]);

		$this->assertSame(['guardian-anna-devries'], $matched);
	}//end testGuardiansMatchingEnumeratesEveryMatchingRowOnce()

	public function testGuardianReachesGroup(): void {
		$os = $this->fakeObjectService([['guardianRef' => 'guardian-anna-devries', 'groupRefs' => ['groep-5a']]]);
		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));

		$this->assertTrue($reader->guardianReachesGroup('guardian-anna-devries', 'groep-5a'));
		$this->assertFalse($reader->guardianReachesGroup('guardian-anna-devries', 'groep-9z'));
		$this->assertFalse($reader->guardianReachesGroup('guardian-unknown', 'groep-5a'));
	}//end testGuardianReachesGroup()
}//end class
