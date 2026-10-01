<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\NewsAudienceOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The News screen's school and group choices come from the school app's
 * `guardianAudience` declaration: an explicit source, or the `$ref` the
 * declared field carries.
 *
 * @spec openspec/changes/staff-news-screen/tasks.md#T2
 */
class NewsAudienceOptionsTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The groups follow `enrolment.cohortId`'s `$ref` to the cohorts, read
	 * with OpenRegister's access rules on; without a school source there
	 * are no school choices.
	 */
	public function testGroupsFollowTheFieldReference(): void {
		$os = $this->objectService(
			properties: ['enrolment' => ['cohortId' => ['type' => 'string', '$ref' => 'Cohort']], 'learner-profile' => ['schoolId' => ['type' => 'string']]],
			rows: ['cohort' => [['id' => 'g10', 'name' => 'Groep 10'], ['id' => 'g7', 'name' => 'Groep 7'], ['id' => 'g7', 'name' => 'Groep 7'], ['name' => 'no id']]]
		);

		$options = (new NewsAudienceOptions($this->registry($this->learniq()), $this->container($os), $this->createMock(LoggerInterface::class)))->options();

		$this->assertSame([['id' => 'g7', 'label' => 'Groep 7'], ['id' => 'g10', 'label' => 'Groep 10']], $options['groups']);
		$this->assertSame([], $options['schools']);
		$this->assertSame([['learniq', 'cohort', true]], $os->reads);
	}//end testGroupsFollowTheFieldReference()

	/**
	 * An explicit `schoolOptions` source gives the school choices.
	 */
	public function testAnExplicitSchoolSourceGivesTheSchools(): void {
		$contribution = $this->learniq();
		$contribution['guardianAudience']['schoolOptions'] = ['schema' => 'school'];
		$os = $this->objectService(
			properties: ['enrolment' => ['cohortId' => ['type' => 'string']]],
			rows: ['school' => [['id' => 's1', 'name' => 'De Wilgenboom']]]
		);

		$options = (new NewsAudienceOptions($this->registry($contribution), $this->container($os), $this->createMock(LoggerInterface::class)))->options();

		$this->assertSame([['id' => 's1', 'label' => 'De Wilgenboom']], $options['schools']);
		$this->assertSame([], $options['groups']);
	}//end testAnExplicitSchoolSourceGivesTheSchools()

	/**
	 * No declaration, or no OpenRegister, means no choices and no error.
	 */
	public function testNothingDeclaredMeansNoChoices(): void {
		$none = ['schools' => [], 'groups' => []];
		$plain = ['app' => 'other', 'collections' => []];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$this->assertSame($none, (new NewsAudienceOptions($this->registry($plain), $container, $this->createMock(LoggerInterface::class)))->options());
		$this->assertSame($none, (new NewsAudienceOptions($this->registry($this->learniq()), $container, $this->createMock(LoggerInterface::class)))->options());
	}//end testNothingDeclaredMeansNoChoices()

	/**
	 * A fake ObjectService: schema properties per slug, rows per slug, and a
	 * log of the reads with their access-rule flag.
	 *
	 * @param array<string, array<string, mixed>> $properties Properties per schema slug.
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema slug.
	 */
	private function objectService(array $properties, array $rows): object {
		return new class($properties, $rows) {
			/** @var array<int, array{0: string, 1: string, 2: bool}> */
			public array $reads = [];

			private string $register = '';

			private string $schema = '';

			/**
			 * @param array<string, array<string, mixed>> $properties
			 * @param array<string, array<int, array<string, mixed>>> $rows
			 */
			public function __construct(
				private array $properties,
				private array $rows,
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

			public function getCurrentSchemaEntity(): object {
				$properties = ($this->properties[$this->schema] ?? []);
				return new class($properties) {
					/**
					 * @param array<string, mixed> $properties
					 */
					public function __construct(
						private array $properties,
					) {
					}//end __construct()

					/** @return array<string, mixed> */
					public function getProperties(): array {
						return $this->properties;
					}//end getProperties()
				};
			}//end getCurrentSchemaEntity()

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = [$this->register, $this->schema, $_rbac];
				return ($this->rows[$this->schema] ?? []);
			}//end findAll()
		};
	}//end objectService()

	/**
	 * @param array<string, mixed> $contribution The one contribution.
	 */
	private function registry(array $contribution): PortalContributionRegistry {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [$contribution]]);

		return $registry;
	}//end registry()

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		return $container;
	}//end container()

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
				['id' => 'parentChildren', 'register' => 'learniq', 'schema' => 'learner-profile'],
				['id' => 'parentGroupMemberships', 'register' => 'learniq', 'schema' => 'enrolment'],
			],
		];
	}//end learniq()
}//end class
