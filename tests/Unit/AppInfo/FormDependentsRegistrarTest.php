<?php

/**
 * Portaliq's answer to OpenRegister's question about dependent forms is
 * registered where the app registers its listeners (decision 179).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\AppInfo;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\AppInfo\FormDependentsRegistrar;
use OCA\Portaliq\Listener\FormDestinationDependentsListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * A listener with a full test suite and no registration answers nothing: the
 * schema save would be judged without portaliq's forms and nobody would see
 * it. So the wiring is asserted from the caller, the app's own `register()`.
 *
 * No coverage metadata on purpose: the second test runs the whole of
 * `Application::register()`, which touches every registrar the app has.
 */
final class FormDependentsRegistrarTest extends TestCase {
	/**
	 * The event OpenRegister dispatches, as `FormDependentsCheck::assess()` names it.
	 */
	private const EVENT = 'OCA\\OpenRegister\\Event\\FormDestinationDependentsEvent';

	/**
	 * A registration context that writes down every event listener it is given.
	 *
	 * @param array<int, array{0: string, 1: string}> $recorded Filled with event and listener.
	 *
	 * @return IRegistrationContext
	 */
	private function recordingContext(array &$recorded): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')
			->willReturnCallback(
				static function (string $event, string $listener, int $priority = 0) use (&$recorded): void {
					$recorded[] = [$event, $listener];
				}
			);

		return $context;
	}//end recordingContext()

	/**
	 * The registrar puts the listener on exactly the event OpenRegister dispatches.
	 *
	 * @return void
	 */
	public function testTheRegistrarPutsTheListenerOnOpenRegistersEvent(): void {
		$recorded = [];

		(new FormDependentsRegistrar())->register($this->recordingContext($recorded));

		$this->assertSame([[self::EVENT, FormDestinationDependentsListener::class]], $recorded);
		$this->assertTrue(is_subclass_of(FormDestinationDependentsListener::class, IEventListener::class));
	}//end testTheRegistrarPutsTheListenerOnOpenRegistersEvent()

	/**
	 * The event name is a class OpenRegister really has, and really dispatches.
	 *
	 * A listener on a name nothing emits never runs. This reads OpenRegister's
	 * own source, so it needs an OpenRegister checkout beside the test run.
	 *
	 * @return void
	 */
	public function testOpenRegisterDispatchesThatEvent(): void {
		if (class_exists(self::EVENT) === false) {
			$this->markTestSkipped('OpenRegister with FormDestinationDependentsEvent is not loadable here (set PORTALIQ_OPENREGISTER_LIB to a current openregister lib/).');
		}

		$event  = new ReflectionClass(self::EVENT);
		$source = (string)file_get_contents(dirname((string)$event->getFileName()) . '/../Service/Form/FormDependentsCheck.php');

		$this->assertStringContainsString('new FormDestinationDependentsEvent(', $source);
		$this->assertStringContainsString('dispatchTyped($event)', $source);
		$this->assertTrue($event->hasMethod('addForm'));
		$this->assertTrue($event->hasMethod('getSchema'));
	}//end testOpenRegisterDispatchesThatEvent()

	/**
	 * The app's own `register()` reaches the registrar: the listener is among what the app registers.
	 *
	 * The app is built without its constructor, which needs a running
	 * Nextcloud; `register()` itself only talks to the context it is handed.
	 *
	 * @return void
	 */
	public function testTheAppRegistersTheListener(): void {
		$recorded = [];
		$app      = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		try {
			$app->register($this->recordingContext($recorded));
		} catch (Throwable $e) {
			$this->fail('Application::register() did not run to its end: ' . $e->getMessage());
		}

		$this->assertContains(
			[self::EVENT, FormDestinationDependentsListener::class],
			$recorded,
			'The app registers no listener for FormDestinationDependentsEvent, so OpenRegister judges a schema change without portaliq\'s forms.'
		);
	}//end testTheAppRegistersTheListener()
}//end class
