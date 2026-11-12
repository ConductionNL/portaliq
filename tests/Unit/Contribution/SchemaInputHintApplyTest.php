<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\SchemaInputHintNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The schema hints added to an action, read straight from the normaliser.
 */
#[CoversClass(SchemaInputHintNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\SchemaFieldProperty::class)]
#[UsesClass(\OCA\Portaliq\Contribution\ValueLabelsNormaliser::class)]
class SchemaInputHintApplyTest extends TestCase {
	/**
	 * Every whitelisted field gets exactly the input, value type and options recorded for it.
	 *
	 * @return void
	 */
	public function testEveryFieldIsAsRecorded(): void {
		$n = new SchemaInputHintNormaliser();
		$def    = ['properties' => [
			'when'   => ['type' => 'string', 'format' => 'date-time'],
			'mail'   => ['type' => 'integer', 'format' => 'email'],
			'count'  => ['type' => 'integer'],
			'amount' => ['type' => 'number', 'format' => 'weird'],
			'agree'  => ['type' => 'boolean'],
			'kind'   => ['type' => 'string', 'enum' => ['a-b', 'c_d', 3, '', null, true]],
			'tone'   => ['oneOf' => [['const' => 'x', 'title' => 'Ex'], ['const' => 2, 'title' => 'Two'], ['const' => '', 'title' => 'E'], ['const' => 'y'], 'z'], 'enum' => ['q']],
			'bare'   => ['oneOf' => [['const' => 'y']], 'enum' => ['q-r']],
			'given'  => ['enum' => ['a']],
			'file'   => ['type' => 'integer', 'enum' => ['a']],
			'odd'    => 'x',
			'long'   => ['enum' => range(1, 120)],
			'none'   => ['type' => 'object'],
			'typed'  => ['type' => ['integer']],
		]];
		$action = ['id' => 'a', 'fieldConfigs' => ['kind' => ['label' => 'Kind', 'valueLabels' => ['a-b' => 'Aybee', '3' => 'Three']], 'tone' => ['valueLabels' => ['x' => 'Ecks']], 'file' => ['type' => 'file']], 'optionsProviders' => ['given' => ['type' => 'remote']]];
		$white = ['when', 'mail', 'count', 'amount', 'agree', 'kind', 'tone', 'bare', 'given', 'file', 'odd', 'long', 'none', 'typed', 'missing'];

		$out  = $n->apply(action: $action, whitelist: $white, definition: $def);
		$long = $out['optionsProviders']['long']['options'];
		$this->assertCount(100, $long);
		$this->assertSame(['value' => '100', 'label' => '100'], $long[99]);
		unset($out['optionsProviders']['long']);
		$this->assertSame('{"id":"a","fieldConfigs":{"kind":{"label":"Kind","valueLabels":{"a-b":"Aybee","3":"Three"}},"tone":{"valueLabels":{"x":"Ecks"}},"file":{"type":"file"},"when":{"size":"medium","input":"datetime"},"mail":{"size":"medium","input":"email","valueType":"integer"},"count":{"size":"medium","input":"number","valueType":"integer"},"amount":{"size":"medium","input":"number","valueType":"number"},"agree":{"size":"medium","valueType":"boolean"}},"optionsProviders":{"given":{"type":"remote"},"kind":{"type":"static","options":[{"value":"a-b","label":"Aybee"},{"value":"c_d","label":"C d"},{"value":"3","label":"Three"}]},"tone":{"type":"static","options":[{"value":"x","label":"Ecks"},{"value":"2","label":"Two"}]},"bare":{"type":"static","options":[{"value":"q-r","label":"Q r"}]}}}', json_encode($out));

		$this->assertSame(
			'{"fieldConfigs":{"count":{"size":"medium","input":"number","valueType":"integer"}},"optionsProviders":"x"}',
			json_encode($n->apply(action: ['fieldConfigs' => 'x', 'optionsProviders' => 'x'], whitelist: ['count', 'kind'], definition: $def))
		);
		$this->assertSame($action, $n->apply(action: $action, whitelist: $white, definition: null));
		$this->assertSame($action, $n->apply(action: $action, whitelist: $white, definition: ['properties' => 'x']));
	}//end testEveryFieldIsAsRecorded()
}//end class
