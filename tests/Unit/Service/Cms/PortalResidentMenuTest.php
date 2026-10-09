<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalResidentMenu;
use PHPUnit\Framework\TestCase;

/**
 * The resident menu's public projection: plain text, item names only, and
 * the declared limits.
 *
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-a-declared-menu-item-may-carry-the-boards-word
 */
class PortalResidentMenuTest extends TestCase {

	/**
	 * A card label that is not text, a group without a title and an item
	 * that is not a name are left out; an empty menu projects nothing.
	 *
	 * @return void
	 */
	public function testWhatIsNotTextOrANameIsLeftOut(): void {
		$menu = new PortalResidentMenu();

		$this->assertSame([], $menu->project(portal: []));
		$this->assertSame(
			['groups' => [['title' => 'Zaken', 'items' => ['cases']]]],
			$menu->project(portal: ['residentMenu' => [
				'cardLabel' => ['not' => 'text'],
				'groups'    => [
					['title' => ['x'], 'items' => ['tasks']],
					'not a group',
					['title' => ' Zaken ', 'items' => ['cases', 'with space', 7]],
				],
			]])
		);
	}//end testWhatIsNotTextOrANameIsLeftOut()

	/**
	 * At most 12 groups of 20 items, and at most 20 left-out items, each once.
	 *
	 * @return void
	 */
	public function testTheLimitsHold(): void {
		$items   = array_map(static fn (int $n): string => 'item-'.$n, range(1, 25));
		$groups  = array_fill(0, 15, ['title' => 'Groep', 'items' => $items]);
		$leave   = array_merge(['tasks', 'tasks'], $items);
		$project = (new PortalResidentMenu())->project(portal: ['residentMenu' => ['groups' => $groups, 'leaveOut' => $leave]]);

		$this->assertCount(12, $project['groups']);
		$this->assertCount(20, $project['groups'][0]['items']);
		$this->assertSame(array_merge(['tasks'], array_slice($items, 0, 18)), $project['leaveOut']);
	}//end testTheLimitsHold()

	/**
	 * A declared item may carry the board's word; a label that is not text
	 * or too long leaves the bare name, an item that is no name is dropped.
	 *
	 * @return void
	 */
	public function testAnItemMayCarryALabel(): void {
		$project = (new PortalResidentMenu())->project(portal: ['residentMenu' => ['groups' => [[
			'title' => 'Mijn Vaartveld',
			'items' => [
				'overview',
				['item' => 'messages', 'label' => ' Berichten '],
				['item' => 'learniq:grades', 'label' => str_repeat('x', 61)],
				['item' => 'with space', 'label' => 'Kapot'],
				['label' => 'Geen naam'],
			],
		]]]]);

		$this->assertSame(
			['overview', ['item' => 'messages', 'label' => 'Berichten'], 'learniq:grades'],
			$project['groups'][0]['items']
		);
	}//end testAnItemMayCarryALabel()

	/**
	 * The person block keeps a collection as `app:id` and at most four field
	 * names; without either it is left out.
	 *
	 * @return void
	 */
	public function testThePersonBlockNamesACollectionAndFields(): void {
		$menu = new PortalResidentMenu();

		$this->assertSame(
			['person' => ['collection' => 'learniq:studentEnrolments', 'fields' => ['a', 'b', 'c', 'd']]],
			$menu->project(portal: ['residentMenu' => ['person' => [
				'collection' => 'learniq:studentEnrolments',
				'fields'     => ['a', 'b', 'not a field', 'c', 'd', 'e'],
			]]])
		);
		$this->assertSame([], $menu->project(portal: ['residentMenu' => ['person' => ['collection' => 'noapp', 'fields' => ['a']]]]));
		$this->assertSame([], $menu->project(portal: ['residentMenu' => ['person' => ['collection' => 'learniq:x', 'fields' => []]]]));
	}//end testThePersonBlockNamesACollectionAndFields()

	/**
	 * A second address is lower case letters, digits and hyphens, and names
	 * an item; `overview` and malformed entries are left out.
	 *
	 * @return void
	 */
	public function testSecondAddressesNameAnItem(): void {
		$project = (new PortalResidentMenu())->project(portal: ['residentMenu' => ['routes' => [
			'berichten'  => 'messages',
			'documenten' => 'learniq:documents',
			'Hoofdletter' => 'messages',
			'thuis'      => 'overview',
			'kapot'      => 'with space',
		]]]);

		$this->assertSame(['routes' => ['berichten' => 'messages', 'documenten' => 'learniq:documents']], $project);
	}//end testSecondAddressesNameAnItem()
}//end class
