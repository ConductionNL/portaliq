<?php
// SPDX-License-Identifier: EUPL-1.2

/**
 * Portaliq Application
 *
 * Main application class for the Portaliq Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Portaliq\AppInfo
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
 *
 * @spec openspec/changes/example-change/tasks.md#task-N
 *   (file-level @spec tag — link back to the OpenSpec change that created or
 *   last modified this file. Multiple @spec tags allowed. Public methods SHOULD
 *   also carry their own @spec tag. ADR-003.)
 */

declare(strict_types=1);

namespace OCA\Portaliq\AppInfo;

use OCA\Portaliq\Dashboard\ExampleWidget;
use OCA\Portaliq\Listener\DeepLinkRegistrationListener;
use OCA\Portaliq\Mcp\ExampleToolProvider;
use OCA\Portaliq\Repair\InitializeSettings;
use OCA\Portaliq\Service\PortalJwtService;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

/**
 * Main application class for the Portaliq Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'portaliq';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function register(IRegistrationContext $context): void
    {
        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        // Initialize register and schemas on install/upgrade.
        $context->registerRepairStep(InitializeSettings::class);

        // Sample dashboard widget — see lib/Dashboard/ExampleWidget.php.
        // Delete this line and the ExampleWidget files if your app has no
        // dashboard widgets.
        $context->registerDashboardWidget(ExampleWidget::class);

        // AI Chat Companion (hydra ADR-034/035): expose this app's capabilities to the in-app AI
        // by registering an IMcpToolProvider under the alias OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}.
        // OpenRegister's McpToolsService discovers providers by this alias. See lib/Mcp/ExampleToolProvider.php.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::'.self::APP_ID,
            ExampleToolProvider::class
        );

        // Portal auth edge (ADR-046 / supplier-portal): the HMAC signing secret
        // comes from app config, never the request. Falls back to the instance
        // secret so the edge works out of the box in dev; set a dedicated
        // `jwt_signing_secret` (>= 16 chars) per deployment for production.
        $context->registerService(
            PortalJwtService::class,
            function (ContainerInterface $c): PortalJwtService {
                $config = $c->get(IConfig::class);
                $secret = (string) $config->getAppValue(self::APP_ID, 'jwt_signing_secret', '');
                if ($secret === '' || strlen($secret) < 16) {
                    $secret = (string) $config->getSystemValue('secret', str_pad(self::APP_ID, 32, '_'));
                }

                return new PortalJwtService(signingSecret: $secret);
            }
        );

    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
