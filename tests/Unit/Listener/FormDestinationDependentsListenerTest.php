<?php

/**
 * Portaliq announces each published form binding that writes into a schema
 * about to change, and sets a broken one back to draft (decisions 179, 181).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCA\Portaliq\Listener\FormDestinationDependentsListener;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Notification\IManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The event, the schema, the dependent and the check that asks are
 * OpenRegister's real classes: a faked event would let a wrong accessor pass.
 */
#[CoversClass(FormDestinationDependentsListener::class)]
#[UsesClass(DestinationMapping::class)]
final class FormDestinationDependentsListenerTest extends TestCase {
	/**
	 * The updates the listener asked the writer for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Whether the writer refuses.
	 *
	 * @var bool
	 */
	private bool $writeFails = false;

	/**
	 * Skip where OpenRegister's event cannot be loaded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(FormDestinationDependentsEvent::class) === false || class_exists(FormDependentsCheck::class) === false) {
			$this->markTestSkipped('OpenRegister with FormDestinationDependentsEvent is not loadable here (set PORTALIQ_OPENREGISTER_LIB to a current openregister lib/).');
		}

		$this->written    = [];
		$this->writeFails = false;
	}//end setUp()

	/**
	 * The schema `case` as it is about to be saved.
	 *
	 * @param array<string, mixed> $properties Its properties.
	 *
	 * @return Schema
	 */
	private function proposed(array $properties): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setUuid('11111111-2222-4333-8444-555555555555');
		$schema->setProperties($properties);

		return $schema;
	}//end proposed()

	/**
	 * A published binding that writes into `case`.
	 *
	 * @param array<string, mixed> $extra Keys to replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function binding(array $extra = []): array {
		return $extra + [
			'id' => 'b-1',
			'@self' => ['owner' => 'anouk'],
			'portal' => 'zuiddrecht',
			'route' => '/melding',
			'status' => 'published',
			'destination' => ['register' => 'dossiq', 'schema' => 'case', 'fixed' => ['channel' => 'portal']],
		];
	}//end binding()

	/**
	 * The listener over the given published bindings.
	 *
	 * @param array<int, array<string, mixed>> $bindings The published bindings of every portal.
	 *
	 * @return FormDestinationDependentsListener
	 */
	private function listener(array $bindings): FormDestinationDependentsListener {
		$resolver = $this->createMock(PortalFormBindingResolver::class);
		$resolver->method('allPublishedBindings')->willReturn($bindings);
		$resolver->method('render')->willReturnCallback(
			static function (array $binding): array {
				if (($binding['intakeKind'] ?? 'hosted') === 'external') {
					return ['kind' => PortalFormBindingResolver::KIND_EXTERNAL, 'resolvesToNoForm' => false];
				}

				if (($binding['route'] ?? '') === '/leeg') {
					return ['kind' => PortalFormBindingResolver::KIND_HOSTED, 'resolvesToNoForm' => true];
				}

				return [
					'kind' => PortalFormBindingResolver::KIND_HOSTED,
					'resolvesToNoForm' => false,
					'formName' => 'Melding openbare ruimte',
					'fields' => [
						['name' => 'onderwerp', 'label' => 'Onderwerp', 'type' => 'text', 'required' => true],
						['name' => 'toelichting', 'label' => 'Toelichting', 'type' => 'textarea'],
					],
				];
			}
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, $data): ?array {
				$this->written[] = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'id' => $id, 'data' => $data];

				return $this->writeFails ? null : (['id' => $id] + (array)$data);
			}
		);

		return new FormDestinationDependentsListener($resolver, new DestinationMapping(), $writer, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * OpenRegister's own check, whose dispatcher hands the event to the listener.
	 *
	 * @param FormDestinationDependentsListener $listener The listener.
	 *
	 * @return FormDependentsCheck
	 */
	private function check(FormDestinationDependentsListener $listener): FormDependentsCheck {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				$listener->handle($event);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		return new FormDependentsCheck($dispatcher, new FormDestinationValidator($l10n, new FormFieldRules()), $config, $this->createMock(IManager::class), $this->createMock(LoggerInterface::class));
	}//end check()

	/**
	 * One form per published binding of this schema, with what a submit of it sends.
	 *
	 * @return void
	 */
	public function testEachPublishedBindingOfTheSchemaIsAnnounced(): void {
		$event = new FormDestinationDependentsEvent($this->proposed(['onderwerp' => ['type' => 'string'], 'toelichting' => ['type' => 'string'], 'channel' => ['type' => 'string']]));

		$this->listener([
			self::binding(),
			self::binding(['id' => 'b-2', 'portal' => 'noord', 'destination' => ['register' => 'dossiq', 'schema' => '11111111-2222-4333-8444-555555555555']]),
			self::binding(['id' => 'b-3', 'destination' => ['register' => 'dossiq', 'schema' => 'ticket']]),
			self::binding(['id' => 'b-4', 'intakeKind' => 'external']),
			self::binding(['id' => 'b-5', 'route' => '/leeg']),
			self::binding(['id' => '', '@self' => []]),
			self::binding(['id' => 'b-7', 'destination' => []]),
		])->handle($event);

		$forms = $event->getForms();
		$this->assertSame(['b-1', 'b-2'], array_map(static fn ($form): string => $form->id, $forms), 'another schema, an external form, a binding without a form, without an id and without a destination are left out');

		$form = $forms[0];
		$this->assertSame('portaliq', $form->app);
		$this->assertSame('Melding openbare ruimte', $form->title);
		$this->assertSame('anouk', $form->author);
		$this->assertSame('public', $form->audience);
		$this->assertSame(['onderwerp', 'toelichting'], array_column($form->mapping['fields'], 'property'));
		$this->assertSame(['channel' => 'portal'], $form->mapping['fixed']);
		$this->assertIsCallable($form->unpublish);
	}//end testEachPublishedBindingOfTheSchemaIsAnnounced()

	/**
	 * A legacy binding names its destination with caseRegister and caseSchema, and has no owner.
	 *
	 * @return void
	 */
	public function testABindingFromBeforeTheUpgradeIsFoundToo(): void {
		$event   = new FormDestinationDependentsEvent($this->proposed(['onderwerp' => ['type' => 'string']]));
		$binding = ['uuid' => 'b-9', 'portal' => 'zuiddrecht', 'route' => '/oud', 'status' => 'published', 'caseRegister' => 'dossiq', 'caseSchema' => 'case'];

		$this->listener([$binding])->handle($event);

		$this->assertCount(1, $event->getForms());
		$this->assertSame('b-9', $event->getForms()[0]->id);
		$this->assertNull($event->getForms()[0]->author);
	}//end testABindingFromBeforeTheUpgradeIsFoundToo()

	/**
	 * Asked by OpenRegister's own check: a schema that still takes the form's answers affects nothing.
	 *
	 * @return void
	 */
	public function testASchemaChangeThatKeepsTheFormWholeAffectsNothing(): void {
		$check = $this->check($this->listener([self::binding()]));

		$assessment = $check->assess($this->proposed(['onderwerp' => ['type' => 'string'], 'toelichting' => ['type' => 'string'], 'channel' => ['type' => 'string']]));

		$this->assertSame([], $assessment['affected']);
		$this->assertFalse($assessment['refused']);
	}//end testASchemaChangeThatKeepsTheFormWholeAffectsNothing()

	/**
	 * Asked by OpenRegister's own check: dropping a property the form writes
	 * names the binding, and applying the save sets that binding to draft.
	 *
	 * @return void
	 */
	public function testABreakingSchemaChangeUnpublishesTheBinding(): void {
		$check = $this->check($this->listener([self::binding()]));

		$assessment = $check->assess($this->proposed(['onderwerp' => ['type' => 'string'], 'channel' => ['type' => 'string']]));

		$this->assertSame(['b-1'], array_column($assessment['affected'], 'id'));
		$this->assertSame('portaliq', $assessment['affected'][0]['app']);
		$this->assertContains(FormDestinationValidator::PROPERTY_UNKNOWN, array_column($assessment['affected'][0]['findings'], 'code'));
		$this->assertSame([], $this->written, 'nothing is written while the save is only being judged');

		$listed = $check->apply($assessment);

		$this->assertSame('unpublished', $listed[0]['outcome']);
		$this->assertSame(
			[['register' => 'portaliq', 'schema' => 'portalFormBinding', 'scopeField' => 'portal', 'subjectRef' => 'zuiddrecht', 'id' => 'b-1', 'data' => ['status' => 'draft']]],
			$this->written
		);
	}//end testABreakingSchemaChangeUnpublishesTheBinding()

	/**
	 * A binding that could not be set to draft is reported as such, not as unpublished.
	 *
	 * @return void
	 */
	public function testAFailedUnpublishIsReported(): void {
		$this->writeFails = true;
		$check            = $this->check($this->listener([self::binding()]));

		$listed = $check->apply($check->assess($this->proposed(['onderwerp' => ['type' => 'string'], 'channel' => ['type' => 'string']])));

		$this->assertSame('unpublish-failed', $listed[0]['outcome']);

		$event = new FormDestinationDependentsEvent($this->proposed(['onderwerp' => ['type' => 'string']]));
		$this->listener([self::binding()])->handle($event);
		$this->expectException(RuntimeException::class);
		($event->getForms()[0]->unpublish)();
	}//end testAFailedUnpublishIsReported()

	/**
	 * Another event is not looked at, and a read that fails does not break the schema save.
	 *
	 * @return void
	 */
	public function testAnotherEventAndAFailingReadLeaveTheSaveAlone(): void {
		$resolver = $this->createMock(PortalFormBindingResolver::class);
		$resolver->expects($this->once())->method('allPublishedBindings')->willThrowException(new RuntimeException('register not installed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$listener = new FormDestinationDependentsListener($resolver, new DestinationMapping(), $this->createMock(PortalObjectWriter::class), $logger);

		$listener->handle(new Event());

		$event = new FormDestinationDependentsEvent($this->proposed([]));
		$listener->handle($event);
		$this->assertSame([], $event->getForms());
	}//end testAnotherEventAndAFailingReadLeaveTheSaveAlone()
}//end class
