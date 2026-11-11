<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\TaskTitleTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sanitising a tasks block's `titleTemplate`.
 */
#[CoversClass(TaskTitleTemplate::class)]
class TaskTitleTemplateTest extends TestCase {
	/**
	 * Every template is kept or dropped exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryTemplateIsAsRecorded(): void {
		$template = new TaskTitleTemplate();
		$fields   = ['fields' => ['name', 'date_1']];
		$cases    = [
			[' Visit {name} on {date_1} ', $fields, ['titleTemplate' => 'Visit {name} on {date_1}']],
			['Visit {name} and {other}', $fields, []],
			['Visit {other}', [], ['titleTemplate' => 'Visit {other}']],
			['Visit {other}', ['fields' => 'name'], ['titleTemplate' => 'Visit {other}']],
			['Visit nobody', $fields, []],
			['Visit {1name}', $fields, []],
			['   ', $fields, []],
			[7, $fields, []],
			[null, $fields, []],
			[str_repeat('é', 194).'{name}', $fields, ['titleTemplate' => str_repeat('é', 194).'{name}']],
			[str_repeat('é', 195).'{name}', $fields, []],
		];
		foreach ($cases as [$declared, $collection, $expected]) {
			$this->assertSame($expected, $template->keys(declared: $declared, collection: $collection));
		}
	}//end testEveryTemplateIsAsRecorded()
}//end class
