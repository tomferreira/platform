<?php

namespace Oro\Bundle\UserBundle\Tests\Functional\API;

use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * @outputBuffering enabled
 * @dbIsolation
 */
class SoapUsersACLTest extends WebTestCase
{
    const USER_NAME = 'user_wo_permissions';
    const USER_PASSWORD = 'user_api_key';

    const DEFAULT_USER_ID = '1';

    public function setUp()
    {
        $this->initClient(array(), $this->generateWsseAuthHeader());
        $this->initSoapClient();
        $this->loadFixtures(array('Oro\Bundle\UserBundle\Tests\Functional\API\DataFixtures\LoadUserData'));
    }

    public function testWsseAccess()
    {
        $this->markTestIncomplete("ACL is not working for SOAP");
        $this->initSoapClient();
    }
}
