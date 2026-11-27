<?php

/**
 * Tests for the rows endpoint of the Mail templates screen (mail-templates-admin-screen T09).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-mail-templates-admin-screen/tasks.md#t09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MailTemplateRowsController;
use OCA\Portaliq\Service\Mail\MailTemplateList;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\Portaliq\Controller\MailTemplateRowsController
 */
class MailTemplateRowsControllerTest extends TestCase {
	/**
	 * The rows come from the list, with their total, and only an admin reads them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-mail-templates-admin-screen/tasks.md#t09
	 */
	public function testRowsAnswerEveryKindWithItsTotalForAnAdminOnly(): void {
		$list = $this->createConfiguredMock(
			originalClassName: MailTemplateList::class,
			configuration: ['rows' => [['id' => 'default-invitation'], ['id' => 'r1']]]
		);

		$controller = new MailTemplateRowsController(request: $this->createMock(originalClassName: IRequest::class), list: $list);
		$expected   = ['results' => [['id' => 'default-invitation'], ['id' => 'r1']], 'total' => 2];
		self::assertSame(expected: $expected, actual: $controller->index()->getData());

		$method = new ReflectionMethod(MailTemplateRowsController::class, 'index');
		self::assertNotEmpty(actual: $method->getAttributes(AuthorizedAdminSetting::class));
		self::assertEmpty(actual: $method->getAttributes(NoAdminRequired::class));
		self::assertEmpty(actual: $method->getAttributes(PublicPage::class));
	}//end testRowsAnswerEveryKindWithItsTotalForAnAdminOnly()
}//end class
