<?php

namespace Oro\Bundle\NavigationBundle\Tests\Unit\Menu;

use Doctrine\Common\Cache\ArrayCache;
use Knp\Menu\MenuFactory;

use Oro\Component\DependencyInjection\ServiceLink;
use Oro\Bundle\NavigationBundle\Menu\AclAwareMenuFactoryExtension;

use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

use Doctrine\Common\Cache\CacheProvider;

/**
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AclAwareMenuFactoryExtensionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|RouterInterface
     */
    protected $router;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $securityFacade;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $securityFacadeLink;

    /**
     * @var MenuFactory
     */
    protected $factory;

    /**
     * @var AclAwareMenuFactoryExtension
     */
    protected $factoryExtension;

    /**
     * @var CacheProvider
     */
    protected $cache;

    /** @var LoggerInterface|\PHPUnit_Framework_MockObject_MockObject */
    protected $logger;

    /**
     * @var bool
     */
    protected $hasLoggedUser = true;

    protected function setUp()
    {
        $this->router = $this->getMockBuilder('Symfony\Component\Routing\RouterInterface')
            ->getMock();

        $this->securityFacade = $this->getMockBuilder('Oro\Bundle\SecurityBundle\SecurityFacade')
            ->disableOriginalConstructor()
            ->getMock();

        $this->securityFacade
            ->expects($this->any())
            ->method('hasLoggedUser')
            ->willReturn($this->hasLoggedUser);

        $this->securityFacade
            ->expects($this->any())
            ->method('getToken')
            ->willReturn($this->createMock('Symfony\Component\Security\Core\Authentication\Token\TokenInterface'));

        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->factoryExtension = new AclAwareMenuFactoryExtension(
            $this->router,
            $this->getSecurityFacadeLink($this->securityFacade)
        );
        $this->factoryExtension->setLogger($this->logger);

        $this->factory = new MenuFactory();
        $this->factory->addExtension($this->factoryExtension);
    }

    /**
     * @param \PHPUnit_Framework_MockObject_MockObject $securityFacade
     *
     * @return ServiceLink|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getSecurityFacadeLink(\PHPUnit_Framework_MockObject_MockObject $securityFacade)
    {
        $securityFacadeLink = $this
            ->getMockBuilder('Oro\Component\DependencyInjection\ServiceLink')
            ->disableOriginalConstructor()
            ->getMock();

        $securityFacadeLink
            ->expects($this->any())
            ->method('getService')
            ->willReturn($securityFacade);

        return $securityFacadeLink;
    }

    /**
     * @dataProvider optionsWithResourceIdDataProvider
     * @param array $options
     * @param boolean $isAllowed
     */
    public function testBuildOptionsWithResourceId($options, $isAllowed)
    {
        $this->securityFacade->expects($this->once())
            ->method('isGranted')
            ->with($options['acl_resource_id'])
            ->will($this->returnValue($isAllowed));

        $item = $this->factory->createItem('test', $options);
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        $this->assertEquals($isAllowed, $item->getExtra('isAllowed'));
    }

    /**
     * @return array
     */
    public function optionsWithResourceIdDataProvider()
    {
        return [
            'allowed' => [
                ['acl_resource_id' => 'test'],
                true
            ],
            'not allowed' => [
                ['acl_resource_id' => 'test'],
                false
            ],
            'allowed with uri' => [
                ['acl_resource_id' => 'test', 'uri' => '#'],
                true
            ],
            'not allowed with uri' => [
                ['acl_resource_id' => 'test', 'uri' => '#'],
                false
            ],
            'allowed with route' => [
                ['acl_resource_id' => 'test', 'route' => 'test'],
                true
            ],
            'not allowed with route' => [
                ['acl_resource_id' => 'test', 'route' => 'test'],
                false
            ],
            'allowed with route and uri' => [
                ['acl_resource_id' => 'test', 'uri' => '#', 'route' => 'test'],
                true
            ],
            'not allowed with route and uri' => [
                ['acl_resource_id' => 'test', 'uri' => '#', 'route' => 'test'],
                false
            ],
        ];
    }

    /**
     * @param array   $options
     * @param boolean $isAllowed
     *
     * @dataProvider optionsWithoutLoggedUser
     */
    public function testBuildOptionsWithoutLoggedUser($options, $isAllowed)
    {
        $securityFacade = $this->getMockBuilder('Oro\Bundle\SecurityBundle\SecurityFacade')
            ->disableOriginalConstructor()
            ->getMock();

        $securityFacade->expects($this->any())
            ->method('hasLoggedUser')
            ->willReturn(false);

        $factoryExtension = new AclAwareMenuFactoryExtension(
            $this->router,
            $this->getSecurityFacadeLink($securityFacade)
        );

        $factory = new MenuFactory();
        $factory->addExtension($factoryExtension);

        $item = $factory->createItem('test', $options);

        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        $this->assertEquals($isAllowed, $item->getExtra('isAllowed'));
    }

    /**
     * @return array
     */
    public function optionsWithoutLoggedUser()
    {
        return [
            'show non authorized' => [
                ['extras' => ['show_non_authorized' => true]],
                true,
            ],
            'do not show non authorized' => [
                ['extras' => []],
                false,
            ],
            'do not check access' => [
                ['check_access' => false, 'extras' => []],
                true,
            ],
        ];
    }

    public function testBuildOptionsWithRouteNotFound()
    {
        $options = ['route' => 'no-route'];

        $routeCollection = $this->getMockBuilder('Symfony\Component\Routing\RouteCollection')
            ->getMock();

        $routeCollection->expects($this->once())
            ->method('get')
            ->with('no-route')
            ->will($this->returnValue(null));

        $this->router->expects($this->once())
            ->method('getRouteCollection')
            ->will($this->returnValue($routeCollection));

        $this->securityFacade->expects($this->never())
            ->method('isClassMethodGranted');

        $item = $this->factory->createItem('test', $options);
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        $this->assertEquals(AclAwareMenuFactoryExtension::DEFAULT_ACL_POLICY, $item->getExtra('isAllowed'));
    }

    public function testBuildOptionsAlreadyProcessed()
    {
        $options = [
            'extras' => [
                'isAllowed' => false,
            ],
        ];

        $this->securityFacade->expects($this->never())
            ->method('hasLoggedUser');
        $this->factory->createItem('test', $options);
    }

    /**
     * @param array $options
     * @param bool $expected
     *
     * @dataProvider aclPolicyProvider
     */
    public function testDefaultPolicyOverride(array $options, $expected)
    {
        $item = $this->factory->createItem('test', $options);
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        $this->assertEquals($expected, $item->getExtra('isAllowed'));
    }

    /**
     * @return array
     */
    public function aclPolicyProvider()
    {
        return [
            [[], AclAwareMenuFactoryExtension::DEFAULT_ACL_POLICY],
            [['extras' => []], AclAwareMenuFactoryExtension::DEFAULT_ACL_POLICY],
            [['extras' => [AclAwareMenuFactoryExtension::ACL_POLICY_KEY => true]], true],
            [['extras' => [AclAwareMenuFactoryExtension::ACL_POLICY_KEY => false]], false],
        ];
    }

    public function testBuildOptionsWithUnknownUri()
    {
        $options = ['uri' => '#'];

        $this->router->expects($this->once())
            ->method('match')
            ->will($this->throwException(new ResourceNotFoundException('Route not found')));

        $this->securityFacade->expects($this->never())
            ->method('isClassMethodGranted');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Route not found', ['pathinfo' => '#']);

        $item = $this->factory->createItem('test', $options);
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        $this->assertEquals(AclAwareMenuFactoryExtension::DEFAULT_ACL_POLICY, $item->getExtra('isAllowed'));
    }

    /**
     * @dataProvider optionsWithRouteDataProvider
     * @param array   $options
     * @param boolean $isAllowed
     */
    public function testBuildOptionsWithRoute($options, $isAllowed)
    {
        $this->assertRouteByRouteNameCalls($isAllowed, $options['route'], 'controller', 'action', 1);

        $item = $this->factory->createItem('test', $options);
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        $this->assertEquals($isAllowed, $item->getExtra('isAllowed'));
    }

    /**
     * Assert ACL and route calls are present when route option is present.
     *
     * @param boolean $isAllowed
     * @param string  $routeName
     * @param string  $class
     * @param string  $method
     * @param int     $callsCount
     */
    protected function assertRouteByRouteNameCalls($isAllowed, $routeName, $class, $method, $callsCount)
    {
        $route = $this->getMockBuilder('Symfony\Component\Routing\Route')
            ->disableOriginalConstructor()
            ->getMock();

        if ($callsCount > 0) {
            $route->expects($this->exactly($callsCount))
                ->method('getDefault')
                ->with('_controller')
                ->will($this->returnValue($class . '::' . $method));
        } else {
            $route->expects($this->never())
                ->method('getDefault');
        }

        $routeCollection = $this->getMockBuilder('Symfony\Component\Routing\RouteCollection')
            ->getMock();

        if ($callsCount > 0) {
            $routeCollection->expects($this->exactly($callsCount))
                ->method('get')
                ->with($routeName)
                ->will($this->returnValue($route));
        } else {
            $routeCollection->expects($this->never())
                ->method('get');
        }

        $this->router->expects($this->exactly($callsCount))
            ->method('getRouteCollection')
            ->will($this->returnValue($routeCollection));

        $this->securityFacade->expects($this->once())
            ->method('isClassMethodGranted')
            ->with('controller', 'action')
            ->will($this->returnValue($isAllowed));
    }

    /**
     * @return array
     */
    public function optionsWithRouteDataProvider()
    {
        return [
            'allowed with route' => [
                ['route' => 'test'], true
            ],
            'not allowed with route' => [
                ['route' => 'test'], false
            ],
            'allowed with route and uri' => [
                ['uri' => '#', 'route' => 'test'], true
            ],
            'not allowed with route and uri' => [
                ['uri' => '#', 'route' => 'test'], false
            ],
        ];
    }

    /**
     * @dataProvider optionsWithUriDataProvider
     * @param array   $options
     * @param boolean $isAllowed
     */
    public function testBuildOptionsWithUri($options, $isAllowed)
    {
        $class = 'controller';
        $method = 'action';

        $this->router->expects($this->once())
            ->method('match')
            ->will($this->returnValue(['_controller' => $class . '::' . $method]));

        $this->securityFacade->expects($this->once())
            ->method('isClassMethodGranted')
            ->with($class, $method)
            ->will($this->returnValue($isAllowed));

        $item = $this->factory->createItem('test', $options);
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        $this->assertEquals($isAllowed, $item->getExtra('isAllowed'));
    }

    /**
     * @return array
     */
    public function optionsWithUriDataProvider()
    {
        return [
            'allowed with route and uri' => [
                ['uri' => '/test'], true
            ],
            'not allowed with route and uri' => [
                ['uri' => '/test'], false
            ],
        ];
    }

    public function testAclCacheByResourceId()
    {
        $options = ['acl_resource_id' => 'resource_id'];
        $this->securityFacade->expects($this->once())
            ->method('isGranted')
            ->with($options['acl_resource_id'])
            ->will($this->returnValue(true));

        for ($i = 0; $i < 2; $i++) {
            $item = $this->factory->createItem('test', $options);
            $this->assertTrue($item->getExtra('isAllowed'));
            $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
        }

        $this->assertAttributeCount(1, 'aclCache', $this->factoryExtension);
        $this->assertAttributeEquals([$options['acl_resource_id'] => true], 'aclCache', $this->factoryExtension);
    }

    public function testAclCacheByKey()
    {
        $options = ['route' => 'route_name'];

        $this->assertRouteByRouteNameCalls(true, 'route_name', 'controller', 'action', 2);

        $item = $this->factory->createItem('test', $options);
        $this->assertTrue($item->getExtra('isAllowed'));
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);

        $options['new_key'] = 'new_value';
        $item = $this->factory->createItem('test', $options);
        $this->assertTrue($item->getExtra('isAllowed'));
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);

        $this->assertAttributeCount(1, 'aclCache', $this->factoryExtension);
        $this->assertAttributeEquals(['controller::action' => true], 'aclCache', $this->factoryExtension);
    }

    /**
     * @dataProvider hasInCacheDataProvider
     * @param boolean $hasInCache
     */
    public function testUriCaching($hasInCache)
    {
        $cacheKey = md5('uri_acl:#');
        $globalCacheKey = md5(
            'global:' . serialize([true, true, null, null, '#', false, true, false, false, null])
        );

        $cache = $this->getMockBuilder('Doctrine\Common\Cache\ArrayCache')
            ->getMock();

        $cache->expects($this->exactly(1))
            ->method('contains')
            ->willReturnMap([
                [$globalCacheKey, false],
                [$cacheKey, $hasInCache],
            ]);

        if ($hasInCache) {
            $cache->expects($this->once())
                ->method('fetch')
                ->with($cacheKey)
                ->willReturnMap([
                    $this->returnValue('controller::action')
                ]);
        } else {
            $cache->expects($this->exactly(1))
                ->method('save')
                ->willReturnMap([
                    [$cacheKey, 'controller::action'],
                    [$globalCacheKey],
                ]);
        }

        $this->factoryExtension->setCache($cache);

        $options = ['uri' => '#'];

        if ($hasInCache) {
            $this->securityFacade->expects($this->never())
                ->method('isClassMethodGranted');

            $this->router->expects($this->never())
                ->method('match');
        } else {
            $this->router->expects($this->once())
                ->method('match')
                ->will($this->returnValue(['_controller' => 'controller::action']));

            $this->securityFacade->expects($this->once())
                ->method('isClassMethodGranted')
                ->with('controller', 'action')
                ->will($this->returnValue(true));
        }

        $item = $this->factory->createItem('test', $options);
        $this->assertTrue($item->getExtra('isAllowed'));
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
    }

    /**
     * @dataProvider hasInCacheDataProvider
     * @param boolean $hasInCache
     */
    public function testRouteCaching($hasInCache)
    {
        $params = ['id' => 20];
        $uriKey = md5('route_uri:route_name' . serialize($params));
        $aclKey = md5('route_acl:route_name');
        $globalCacheKey = md5(
            'global:' . serialize([true, true, 'route_name', $params, null, false, true, false, false, null])
        );

        $cache = $this->getMockBuilder('Doctrine\Common\Cache\ArrayCache')
            ->getMock();

        $cache->expects($this->exactly(2))
            ->method('contains')
            ->will(
                $this->returnValueMap(
                    [
                        [$globalCacheKey, false],
                        [$uriKey, $hasInCache],
                        [$aclKey, $hasInCache],
                    ]
                )
            );

        if ($hasInCache) {
            $cache->expects($this->exactly(2))
                ->method('fetch')
                ->will(
                    $this->returnValueMap(
                        [
                            [$uriKey, '/'],
                            [$aclKey, 'controller::action'],
                        ]
                    )
                );
        } else {
            $cache->expects($this->exactly(2))
                ->method('save')
                ->with(
                    $this->logicalOr(
                        $this->equalTo($aclKey),
                        $this->equalTo('controller::action'),
                        $this->equalTo($uriKey),
                        $this->equalTo('/'),
                        $this->equalTo($globalCacheKey)
                    )
                );
        }

        $this->factoryExtension->setCache($cache);

        $options = ['route' => 'route_name', 'routeParameters' => $params];

        $this->assertRouteByRouteNameCalls(true, 'route_name', 'controller', 'action', (int) !$hasInCache);

        $item = $this->factory->createItem('test', $options);
        $this->assertTrue($item->getExtra('isAllowed'));
        $this->assertInstanceOf('Knp\Menu\MenuItem', $item);
    }

    /**
     * @return array
     */
    public function hasInCacheDataProvider()
    {
        return [
            'in cache' => [true],
            'not in cache' => [false]
        ];
    }
}
