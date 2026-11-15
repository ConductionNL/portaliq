<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalUserDisplayNames;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Which fields read as a user's name, and what a value that names no user
 * becomes (contribution-user-display-name).
 *
 * @spec openspec/changes/contribution-user-display-name/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
 */
class PortalUserDisplayNamesTest extends TestCase {
	/**
	 * A collection with one user column and one plain column.
	 */
	private const COLLECTION = [
		'columns' => [
			['field' => 'title', 'render' => 'text'],
			['field' => 'teacher', 'render' => 'user'],
			['field' => 'teacher', 'render' => 'user'],
			['render' => 'user'],
			'not-a-column',
		],
	];

	/**
	 * Only `render: "user"` columns are user fields, each once.
	 *
	 * @return void
	 */
	public function testOnlyUserColumnsAreUserFields(): void {
		$this->assertSame(['teacher'], (new PortalUserDisplayNames())->userFields(collection: self::COLLECTION));
		$this->assertSame([], (new PortalUserDisplayNames())->userFields(collection: ['columns' => [['field' => 'a']]]));
		$this->assertSame([], (new PortalUserDisplayNames())->userFields(collection: []));
	}//end testOnlyUserColumnsAreUserFields()

	/**
	 * A user id becomes the name, a list of ids a list of names, and anything
	 * that names no user becomes '', never itself.
	 *
	 * @return void
	 */
	public function testValuesBecomeNamesAndNothingElseLeaks(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['t1', 'Meester Jansen'], ['t2', 'Juf Bakker'], ['gone', null]]);
		$names = new PortalUserDisplayNames(users: $users);

		$rows = $names->rows(
			rows: [
				['title' => 't1', 'teacher' => 't1'],
				['title' => 'b', 'teacher' => ['t1', 't2', 'gone']],
				['title' => 'c', 'teacher' => 'gone'],
				['title' => 'd', 'teacher' => 42],
				['title' => 'e', 'teacher' => null],
				['title' => 'f'],
				'not-a-row',
			],
			collection: self::COLLECTION
		);

		$this->assertSame(['title' => 't1', 'teacher' => 'Meester Jansen'], $rows[0]);
		$this->assertSame(['Meester Jansen', 'Juf Bakker', ''], $rows[1]['teacher']);
		$this->assertSame('', $rows[2]['teacher']);
		$this->assertSame('', $rows[3]['teacher']);
		$this->assertNull($rows[4]['teacher']);
		$this->assertSame(['title' => 'f'], $rows[5]);
		$this->assertSame('not-a-row', $rows[6]);
	}//end testValuesBecomeNamesAndNothingElseLeaks()

	/**
	 * Without a user manager every user value is '', and a collection without
	 * a user column is answered untouched.
	 *
	 * @return void
	 */
	public function testWithoutAUserManagerNothingLeaks(): void {
		$names = new PortalUserDisplayNames();

		$this->assertSame(['title' => 'x', 'teacher' => ''], $names->row(row: ['title' => 'x', 'teacher' => 't1'], collection: self::COLLECTION));
		$this->assertSame(['teacher' => 't1'], $names->row(row: ['teacher' => 't1'], collection: ['columns' => [['field' => 'teacher']]]));
	}//end testWithoutAUserManagerNothingLeaks()
}//end class
