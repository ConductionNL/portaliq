<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\LeafGuardianAudienceReader;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A guardian's audience comes from the collections the school app declares
 * in `guardianAudience`, read through the subject-scoped reader.
 *
 * @spec openspec/changes/news-audience-from-the-school-app/tasks.md#T1
 */
class LeafGuardianAudienceReaderTest extends TestCase {

	/**
	 * Children, school and groups come from the declared collections, read
	 * for the guardian in the parent audience.
	 */
	public function testTheDeclaredCollectionsGiveTheAudience(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->expects($this->once())->method('aggregateFor')
			->with($this->callback(static fn (array $subject): bool => $subject['subjectRef'] === 'g-1' && $subject['audience'] === 'parent'))
			->willReturn(['contributions' => [$this->learniq()]]);

		$reads = [];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef) use (&$reads): array {
				$reads[] = [$schema, $subjectRef];
				if ($schema === 'learner-profile') {
					return [['id' => 'vera', 'schoolId' => 'school-w'], ['id' => 'daan', 'schoolId' => 'school-w']];
				}

				return [['id' => 'e-1', 'cohortId' => 'groep-7'], ['id' => 'e-2', 'cohortId' => 'groep-5'], ['id' => 'e-3', 'cohortId' => 'groep-7']];
			}
		);

		$audience = (new LeafGuardianAudienceReader($registry, $reader, $this->createMock(LoggerInterface::class)))->resolveAudience('g-1');

		$this->assertSame(
			['schoolRef' => 'school-w', 'groupRefs' => ['groep-7', 'groep-5'], 'childRefs' => ['vera', 'daan'], 'photoConsent' => []],
			$audience
		);
		$this->assertSame([['learner-profile', 'g-1'], ['enrolment', 'g-1']], $reads);
	}//end testTheDeclaredCollectionsGiveTheAudience()

	/**
	 * No declaration, or no child, is no audience.
	 */
	public function testNoDeclarationOrNoChildIsNoAudience(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturnOnConsecutiveCalls(
			['contributions' => [['app' => 'other', 'collections' => []]]],
			['contributions' => [$this->learniq()]]
		);
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);
		$leaf = new LeafGuardianAudienceReader($registry, $reader, $this->createMock(LoggerInterface::class));

		$this->assertNull($leaf->resolveAudience('g-1'));
		$this->assertNull($leaf->resolveAudience('g-1'));
		$this->assertNull($leaf->resolveAudience(''));
	}//end testNoDeclarationOrNoChildIsNoAudience()

	/**
	 * A contribution shaped like learniq's parent contribution.
	 *
	 * @return array<string, mixed>
	 */
	private function learniq(): array {
		return [
			'app' => 'learniq',
			'guardianAudience' => [
				'children' => 'parentChildren',
				'schoolField' => 'schoolId',
				'groups' => ['collection' => 'parentGroupMemberships', 'field' => 'cohortId'],
			],
			'collections' => [
				['id' => 'parentChildren', 'register' => 'learniq', 'schema' => 'learner-profile', 'scopeField' => 'guardianRefs', 'scopeClaim' => 'guardianRef'],
				['id' => 'parentGroupMemberships', 'register' => 'learniq', 'schema' => 'enrolment', 'scopeField' => 'learnerRef', 'scopeClaim' => 'guardianRef', 'via' => ['register' => 'learniq']],
			],
		];
	}//end learniq()
}//end class
