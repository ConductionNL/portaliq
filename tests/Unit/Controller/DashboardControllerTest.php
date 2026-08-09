<?php

/**
 * DashboardController contract tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\DashboardController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Wire contract for `GET /` (`dashboard#page`) and the history-mode deep-link
 * catch-all (`dashboard#catchAll`).
 *
 * The SPA is a Vue history-mode app, so EVERY in-app deep link is served by the
 * same template as the index. That is the contract: `catchAll()` must render
 * the identical app/template pair as `page()`, because a deep link that
 * rendered a different template — or a different app's template — would 404 or
 * boot the wrong bundle on reload.
 *
 * The assertions are on the ITEM: the app name and template name the response
 * actually carries. Asserting merely that a `TemplateResponse` came back would
 * stay green if the template name changed to something that does not exist.
 *
 * @covers \OCA\Portaliq\Controller\DashboardController
 */
class DashboardControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var DashboardController
     */
    private DashboardController $controller;

    /**
     * Build the controller with a mocked request.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new DashboardController($this->createMock(IRequest::class));
    }//end setUp()

    /**
     * `GET /` renders portaliq's own `index` template.
     *
     * @return void
     */
    public function testPageRendersThePortaliqIndexTemplate(): void
    {
        $response = $this->controller->page();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('portaliq', $response->getApp());
        $this->assertSame('index', $response->getTemplateName());
    }//end testPageRendersThePortaliqIndexTemplate()

    /**
     * A deep link renders the SAME app/template pair, so a browser reload on an
     * in-app route boots the same SPA rather than 404ing.
     *
     * @return void
     */
    public function testCatchAllRendersTheSameTemplateAsPage(): void
    {
        $page     = $this->controller->page();
        $catchAll = $this->controller->catchAll();

        $this->assertSame($page->getApp(), $catchAll->getApp());
        $this->assertSame($page->getTemplateName(), $catchAll->getTemplateName());
        $this->assertSame('index', $catchAll->getTemplateName());
    }//end testCatchAllRendersTheSameTemplateAsPage()
}//end class
