<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use PHPUnit\Framework\TestCase;

/**
 * portal-cms-content-model task 3: the pre-write hook is wired for terms. The
 * relation rule itself is tested in GlossaryRelationsTest, which needs no
 * OpenRegister classes.
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
 */
class GlossaryRelationGuardListenerTest extends TestCase {

	public function testTheGuardIsRegisteredOnTheCreatingAndUpdatingEvents(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertMatchesRegularExpression(
			'/ObjectCreatingEvent::class, ObjectUpdatingEvent::class\] as \$event\) \{\s+\$context->registerEventListener\(\$event, GlossaryRelationGuardListener::class\)/',
			$source
		);
	}//end testTheGuardIsRegisteredOnTheCreatingAndUpdatingEvents()
}//end class
