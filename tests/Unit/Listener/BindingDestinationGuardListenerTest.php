<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Listener\BindingDestinationGuardListener;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * submit-creates-the-case-directly REQ-PIFO-007 and decision 189: a binding
 * saved as `published` is refused while its destination has findings; a
 * draft saves with them; another schema's write is never looked at.
 *
 * @covers \OCA\Portaliq\Listener\BindingDestinationGuardListener
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */
class BindingDestinationGuardListenerTest extends TestCase {

	private const FINDING = ['property' => 'communicationChannel', 'code' => 'required-unmapped', 'message' => 'Nobody asks for the channel.'];

	public function testPublishingABindingWithFindingsIsRefusedAndNamesThem(): void {
		$errors = $this->guard(findings: [self::FINDING])->refusalFor(schema: '17', binding: ['status' => 'published']);

		$this->assertNotNull($errors);
		$this->assertSame([self::FINDING], $errors['findings']);
		$this->assertStringContainsString('cannot be published', $errors['message']);
	}//end testPublishingABindingWithFindingsIsRefusedAndNamesThem()

	public function testADraftBindingSavesWithItsFindings(): void {
		$this->assertNull($this->guard(findings: [self::FINDING], checks: false)->refusalFor(schema: '17', binding: ['status' => 'draft']));
	}//end testADraftBindingSavesWithItsFindings()

	public function testAPublishedBindingWithoutFindingsSaves(): void {
		$this->assertNull($this->guard(findings: [])->refusalFor(schema: '17', binding: ['status' => 'published']));
	}//end testAPublishedBindingWithoutFindingsSaves()

	public function testAnotherSchemasWriteIsNotLookedAt(): void {
		$this->assertNull($this->guard(findings: [self::FINDING], checks: false)->refusalFor(schema: '99', binding: ['status' => 'published']));
	}//end testAnotherSchemasWriteIsNotLookedAt()

	public function testTheGuardIsRegisteredOnTheCreatingAndUpdatingEvents(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertMatchesRegularExpression(
			'/ObjectCreatingEvent::class, ObjectUpdatingEvent::class\] as \$event\) \{\s+\$context->registerEventListener\(\$event, BindingDestinationGuardListener::class\)/',
			$source
		);
	}//end testTheGuardIsRegisteredOnTheCreatingAndUpdatingEvents()

	/**
	 * The guard over a binding schema with id 17.
	 *
	 * @param array<int, array<string, mixed>> $findings What the check answers.
	 * @param bool                             $checks   Whether the check must run.
	 *
	 * @return BindingDestinationGuardListener
	 */
	private function guard(array $findings, bool $checks = true): BindingDestinationGuardListener {
		$schema = new class {
			public function getId(): int {
				return 17;
			}
		};
		$mapper = new class($schema) {
			public function __construct(private object $schema) {
			}

			public function findByApplicationAndSlug(string $slug, string $application): ?object {
				return ($slug === 'portalFormBinding' && $application === 'portaliq') ? $this->schema : null;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('render')->willReturn(['fields' => []]);

		$intake = $this->createMock(DestinationIntake::class);
		$intake->expects($checks ? $this->once() : $this->never())->method('check')->willReturn($findings);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new BindingDestinationGuardListener($container, $bindings, $intake, $l10n, $this->createMock(LoggerInterface::class));
	}//end guard()
}//end class
