<?php

/**
 * Unit tests for TrafficRecordingMask.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficRecordingMask;
use PHPUnit\Framework\TestCase;

/**
 * Nothing a person wrote or read survives the mask, whatever the
 * recorder sent.
 */
class TrafficRecordingMaskTest extends TestCase {

	/**
	 * A snapshot from a recorder that leaked: raw text, a typed value, an
	 * alt, a data attribute, a script and an image. Only lengths and
	 * layout come out.
	 *
	 * @return void
	 */
	public function testASnapshotKeepsLengthsAndLayoutOnly(): void {
		$events = (new TrafficRecordingMask())->events(events: [[
			'k' => 's',
			't' => 12,
			'w' => 1280,
			'h' => 720.4,
			'n' => [
				'n' => 'html',
				'a' => ['lang' => 'nl', 'data-user' => 'Jan Jansen'],
				'c' => [
					['n' => 'script', 'c' => [['l' => 500, 'text' => 'alert(1)']]],
					['n' => 'h1', 'a' => ['class' => 'title', 'title' => 'Jan'], 'c' => [['l' => 11, 'text' => 'Jan Jansen!']]],
					['n' => 'input', 'a' => ['type' => 'text', 'placeholder' => 'Uw BSN', 'value' => '123456789'], 'v' => 9, 'value' => '123456789'],
					['n' => 'img', 'a' => ['src' => 'https://x/jan.jpg', 'alt' => 'Jan', 'width' => 40]],
					['n' => 'link', 'a' => ['rel' => 'stylesheet', 'href' => 'https://portal.example/site.css']],
					['n' => 'a', 'a' => ['href' => 'https://x/?token=secret', 'class' => 'cta']],
					['n' => 'div', 'a' => ['style' => 'background: url(https://x/track.gif); color: red']],
					['n' => 'style', 's' => '@import "x.css"; .a { background: url("t.png") } .b { color: blue }'],
					['n' => 'b<b>', 'c' => []],
				],
			],
		]]);

		$this->assertCount(1, $events);
		$flat = (string)json_encode($events);
		$this->assertStringNotContainsString('Jan', $flat);
		$this->assertStringNotContainsString('123456789', $flat);
		$this->assertStringNotContainsString('BSN', $flat);
		$this->assertStringNotContainsString('alert', $flat);
		$this->assertStringNotContainsString('jan.jpg', $flat);
		$this->assertStringNotContainsString('secret', $flat);
		$this->assertStringNotContainsString('url(', $flat);
		$this->assertStringNotContainsString('@import', $flat);
		$this->assertStringNotContainsString('data-user', $flat);

		$root = $events[0]['n'];
		$this->assertSame(['k' => 's', 't' => 12, 'w' => 1280, 'h' => 720.4], array_diff_key($events[0], ['n' => true]));
		$this->assertSame(['lang' => 'nl'], $root['a']);
		$this->assertSame(['l' => 0], $root['c'][0], 'a script becomes an empty text node');
		$this->assertSame(['n' => 'h1', 'a' => ['class' => 'title'], 'c' => [['l' => 11]]], $root['c'][1]);
		$this->assertSame(['n' => 'input', 'a' => ['type' => 'text'], 'v' => 9, 'c' => []], $root['c'][2]);
		$this->assertSame(['width' => '40'], $root['c'][3]['a']);
		$this->assertSame('https://portal.example/site.css', $root['c'][4]['a']['href'], 'a stylesheet link keeps its address');
		$this->assertSame(['class' => 'cta'], $root['c'][5]['a'], 'an anchor loses its href');
		$this->assertSame('background: none; color: red', $root['c'][6]['a']['style']);
		$this->assertSame(' .a { background: none } .b { color: blue }', $root['c'][7]['s']);
		$this->assertSame(['l' => 0], $root['c'][8], 'an impossible tag becomes nothing');
	}//end testASnapshotKeepsLengthsAndLayoutOnly()


	/**
	 * The other kinds keep their numbers, a navigation keeps its path
	 * without the query, and an unknown kind is dropped.
	 *
	 * @return void
	 */
	public function testMovesClicksScrollsAndNavigationsKeepNumbersOnly(): void {
		$events = (new TrafficRecordingMask())->events(events: [
			['k' => 'm', 't' => 100, 'x' => 10, 'y' => 20, 'target' => 'Jan'],
			['k' => 'c', 't' => 200, 'x' => 10.5, 'y' => -3],
			['k' => 'r', 't' => 300, 'x' => 0, 'y' => 400],
			['k' => 'v', 't' => 400, 'w' => 800, 'h' => 600],
			['k' => 'n', 't' => 500, 'p' => '/contact?bsn=123#top'],
			['k' => 'x', 't' => 600, 'payload' => 'anything'],
			'not an event',
		]);

		$this->assertSame([
			['k' => 'm', 't' => 100, 'x' => 10, 'y' => 20],
			['k' => 'c', 't' => 200, 'x' => 10.5, 'y' => 0],
			['k' => 'r', 't' => 300, 'x' => 0, 'y' => 400],
			['k' => 'v', 't' => 400, 'w' => 800, 'h' => 600],
			['k' => 'n', 't' => 500, 'p' => '/contact'],
		], $events);
	}//end testMovesClicksScrollsAndNavigationsKeepNumbersOnly()
}//end class
