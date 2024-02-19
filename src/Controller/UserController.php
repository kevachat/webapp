<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Doctrine\ORM\EntityManagerInterface;

use App\Entity\Pool;

class UserController extends AbstractController
{
    private $_algorithm = PASSWORD_BCRYPT;

    private $_options =
    [
        'cost' => 12
    ];

    #[Route(
        '/user',
        name: 'user_index',
        methods:
        [
            'GET'
        ]
    )]
    public function index(
        ?Request $request
    ): Response
    {
        return $this->redirectToRoute(
            'user_login'
        );
    }

    #[Route(
        '/user/list',
        name: 'user_list',
        methods:
        [
            'GET'
        ]
    )]
    public function list(
        ?Request $request
    ): Response
    {
        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        $memory = md5(
            sprintf(
                '%s.UserController::list:list',
                __DIR__,
            ),
        );

        if (!$list = $memcached->get($memory))
        {
            $list = [];

            // Check client connection
            if ($client = $this->_client())
            {
                // Check users database accessible
                if ($namespace = $this->_namespace($client))
                {
                    // Collect usernames
                    foreach ((array) $client->kevaFilter($namespace) as $user)
                    {
                        // Check record valid
                        if (empty($user['key']) || empty($user['height']))
                        {
                            continue;
                        }

                        // Skip values with meta keys
                        if (str_starts_with($user['key'], '_'))
                        {
                            continue;
                        }

                        // Validate username regex
                        if (!preg_match($this->getParameter('app.add.user.name.regex'), $user['key']))
                        {
                            continue;
                        }

                        // Get room stats
                        $total = 0;
                        $rooms = [];

                        foreach ((array) $client->kevaListNamespaces() as $value)
                        {
                            if (empty($value['namespaceId']))
                            {
                                continue;
                            }

                            if (empty($value['displayName']))
                            {
                                continue;
                            }

                            if (str_starts_with($value['displayName'], '_'))
                            {
                                continue;
                            }

                            $posts = 0;

                            foreach ((array) $client->kevaFilter($value['namespaceId'], sprintf('^([\d]+@%s|%s)$', $user['key'], $user['key'])) as $post)
                            {
                                $total++;
                                $posts++;

                                $rooms[$value['displayName']] = $posts;
                            }
                        }

                        $list[] =
                        [
                            'name'    => $user['key'],
                            'address' => $client->getAccountAddress(
                                $user['key']
                            ),
                            'total'   => $total,
                            'rooms'   => $rooms,
                        ];
                    }
                }
            }

            // Sort by height
            array_multisort(
                array_column(
                    $list,
                    'total'
                ),
                SORT_DESC,
                $list
            );

            // Cache result
            $memcached->set(
                $memory,
                $list
            );
        }

        // RSS
        if ('rss' === $request->get('feed'))
        {
            $response = new Response();

            $response->headers->set(
                'Content-Type',
                'text/xml'
            );

            return $this->render(
                'default/user/list.rss.twig',
                [
                    'list'    => $list,
                    'request' => $request
                ],
                $response
            );
        }

        // HTML
        return $this->render(
            'default/user/list.html.twig',
            [
                'list'    => $list,
                'request' => $request
            ]
        );
    }

    #[Route(
        '/join',
        name: 'user_join',
        methods:
        [
            'GET'
        ]
    )]
    public function join(
        ?Request $request
    ): Response
    {
        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Create token
        $token = crc32(
            microtime(true) + rand()
        );

        $memcached->add(
            $token,
            time()
        );

        // Check user session does not exist to continue
        if (!empty($request->cookies->get('KEVACHAT_SESSION')))
        {
            // Redirect to logout
            return $this->redirectToRoute(
                'user_logout'
            );
        }

        return $this->render(
            'default/user/join.html.twig',
            [
                'request' => $request,
                'token'   => $token,
                'cost'    => $this->getParameter('app.add.user.cost.kva')
            ]
        );
    }

    #[Route(
        '/login',
        name: 'user_login',
        methods:
        [
            'GET'
        ]
    )]
    public function login(
        ?Request $request
    ): Response
    {
        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Create token
        $token = crc32(
            microtime(true) + rand()
        );

        $memcached->add(
            $token,
            time()
        );

        // Check user session does not exist to continue
        if (!empty($request->cookies->get('KEVACHAT_SESSION')))
        {
            // Redirect to logout
            return $this->redirectToRoute(
                'user_logout'
            );
        }

        return $this->render(
            'default/user/login.html.twig',
            [
                'request' => $request,
                'token'   => $token
            ]
        );
    }

    #[Route(
        '/logout',
        name: 'user_logout',
        methods:
        [
            'GET'
        ]
    )]
    public function logout(
        ?Request $request
    ): Response
    {
        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Make sure cookies exist
        if (!empty($request->cookies->get('KEVACHAT_SESSION')) && preg_match('/[A-z0-9]{32}/', $request->cookies->get('KEVACHAT_SESSION')))
        {
            // Delete from memory
            $memcached->delete($session);

            // Delete cookies
            setcookie('KEVACHAT_SESSION', '', -1, '/');
            setcookie('KEVACHAT_SIGN', '', -1, '/');
        }

        // Redirect to main page
        return $this->redirectToRoute(
            'user_login'
        );
    }

    #[Route(
        '/join',
        name: 'user_add',
        methods:
        [
            'POST'
        ]
    )]
    public function add(
        Request $request,
        TranslatorInterface $translator,
        EntityManagerInterface $entity
    ): Response
    {
        // Check maintenance mode disabled
        if ($this->getParameter('app.maintenance'))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Maintenance, please try again later!')
                ]
            );
        }

        // Check user session does not exist to continue
        if (!empty($request->cookies->get('KEVACHAT_SESSION')))
        {
            // Redirect to logout
            return $this->redirectToRoute(
                'user_logout'
            );
        }

        // Trim extra spaces from username
        $username = trim(
            $request->get('username')
        );

        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Create IP delay record
        $memory = md5(
            sprintf(
                '%s.UserController::add:add.user.remote.ip.delay:%s',
                __DIR__,
                $request->getClientIp(),
            ),
        );

        // Validate form token
        if ($memcached->get($request->get('token')))
        {
            $memcached->delete(
                $request->get('token')
            );
        }

        else
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Session token expired')
                ]
            );
        }

        // Validate remote IP limits
        if ($delay = (int) $memcached->get($memory))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => sprintf(
                        $translator->trans('Please wait %s seconds before register new username!'),
                        (int) $this->getParameter('app.add.user.remote.ip.delay') - (time() - $delay)
                    )
                ]
            );
        }

        // Check client connection
        if (!$client = $this->_client())
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not connect wallet database!')
                ]
            );
        }

        // Check users database accessible
        if (!$namespace = $this->_namespace($client))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not access user database locked by transaction, try again later!')
                ]
            );
        }

        // Validate kevacoin key requirements
        if (mb_strlen($username) < 1 || mb_strlen($username) > 520)
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username length out of KevaCoin protocol limits!')
                ]
            );
        }

        // Validate system username values
        if (in_array(mb_strtolower($username), ['anon','anonymous']))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username reserved for anon messages!')
                ]
            );
        }

        // Validate meta NS
        if (str_starts_with($username, '_'))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username contain meta format!')
                ]
            );
        }

        // Validate username regex
        if (!preg_match($this->getParameter('app.add.user.name.regex'), $username))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => sprintf(
                        $translator->trans('Username does not match node requirements: %s!'),
                        $this->getParameter('app.add.user.name.regex')
                    )
                ]
            );
        }

        // Validate username blacklist (reserved)
        if (in_array(mb_strtolower($username), array_map('mb_strtolower', (array) explode('|', $this->getParameter('app.add.user.name.blacklist')))))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username reserved by node!')
                ]
            );
        }

        // Validate username exist
        if ($this->_hash($client, $namespace, $username))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username already taken!')
                ]
            );
        }

        // Validate password length
        if (mb_strlen($request->get('password')) <= 6)
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Please, provide stronger password!')
                ]
            );
        }

        // Validate passwords match
        if ($request->get('password') !== $request->get('repeat'))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Password repeat incorrect!')
                ]
            );
        }

        // Generate password hash
        if (!$hash = password_hash($request->get('password'), $this->_algorithm, $this->_options))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not generate password hash!')
                ]
            );
        }

        // User registration has commission cost, send message to pending payment pool
        if ($this->getParameter('app.add.user.cost.kva'))
        {
            if ($address = $client->getNewAddress($this->getParameter('app.kevacoin.pool.account')))
            {
                $time = time();

                $pool = new Pool();

                $pool->setTime(
                    $time
                );

                $pool->setSent(
                    0
                );

                $pool->setExpired(
                    0
                );

                $pool->setCost(
                    $this->getParameter('app.add.user.cost.kva')
                );

                $pool->setAddress(
                    $address
                );

                $pool->setNamespace(
                    $namespace
                );

                $pool->setKey(
                    $username
                );

                $pool->setValue(
                    $hash
                );

                $entity->persist(
                    $pool
                );

                $entity->flush();

                // Redirect back to room
                return $this->redirectToRoute(
                    'user_add',
                    [
                        'username' => $request->get('username'),
                        'warning'  => sprintf(
                            $translator->trans('To complete registration, send %s KVA to %s'),
                            $this->getParameter('app.add.user.cost.kva'),
                            $address
                        )
                    ]
                );
            }

            else
            {
                return $this->redirectToRoute(
                    'user_add',
                    [
                        'username' => $request->get('username'),
                        'error'    => $translator->trans('Could not init registration address!')
                    ]
                );
            }
        }

        // Auth success, add user to DB
        if (!$client->kevaPut($namespace, $username, $hash))
        {
            return $this->redirectToRoute(
                'user_add',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not create user in blockchain!')
                ]
            );
        }

        // Register event time
        $memcached->set(
            $memory,
            time(),
            (int) $this->getParameter('app.add.user.remote.ip.delay') // auto remove on cache expire
        );

        // Auth success, create user session
        $session = md5(
            sprintf(
                '%s.%s.%s',
                $request->get('username'),
                $request->getClientIp(),
                rand()
            )
        );

        // Save session to memory
        if (!$memcached->set($session, $request->get('username'), (int) time() + $this->getParameter('app.session.default.timeout')))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not save user session!')
                ]
            );
        }

        // Save session to user cookies
        if (!setcookie('KEVACHAT_SESSION', $session, time() + $this->getParameter('app.session.default.timeout'), '/'))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not create session cookie!')
                ]
            );
        }

        // Redirect to main page
        return $this->redirectToRoute(
            'room_index'
        );

        // Redirect to login page
        return $this->redirectToRoute(
            'user_login',
            [
                'username' => $request->get('username')
            ]
        );
    }

    #[Route(
        '/login',
        name: 'user_auth',
        methods:
        [
            'POST'
        ]
    )]
    public function auth(
        Request $request,
        TranslatorInterface $translator
    ): Response
    {
        // Check maintenance mode disabled
        if ($this->getParameter('app.maintenance'))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Maintenance, please try again later!')
                ]
            );
        }

        // Check user session does not exist to continue
        if (!empty($request->cookies->get('KEVACHAT_SESSION')))
        {
            // Redirect to logout
            return $this->redirectToRoute(
                'user_logout'
            );
        }

        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Validate form token
        if ($memcached->get($request->get('token')))
        {
            $memcached->delete(
                $request->get('token')
            );
        }

        else
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Session token expired')
                ]
            );
        }

        // Check client connection
        if (!$client = $this->_client())
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not connect wallet database!')
                ]
            );
        }

        // Check username namespace accessible
        if (!$namespace = $this->_namespace($client))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not access user database locked by transaction, try again later!')
                ]
            );
        }

        // Trim extra spaces from username
        $username = trim(
            $request->get('username')
        );

        // Validate kevacoin key requirements
        if (mb_strlen($username) < 1 || mb_strlen($username) > 520)
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username length out of KevaCoin protocol limits!')
                ]
            );
        }

        // Validate meta NS
        if (str_starts_with($username, '_'))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username contain meta format!')
                ]
            );
        }

        // Validate username regex
        if (!preg_match($this->getParameter('app.add.user.name.regex'), $username))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => sprintf(
                        $translator->trans('Username does not match node requirements: %s!'),
                        $this->getParameter('app.add.user.name.regex')
                    )
                ]
            );
        }

        // Validate username exist
        if (!$hash = $this->_hash($client, $namespace, $username))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Username not found!')
                ]
            );
        }

        // Validate password
        if (!password_verify($request->get('password'), $hash))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Password invalid!')
                ]
            );
        }

        // Validate password algo
        if (password_needs_rehash($hash, $this->_algorithm, $this->_options))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Password needs rehash!')
                ]
            );
        }

        // Auth success, create user session
        $session = md5(
            sprintf(
                '%s.%s.%s',
                $request->get('username'),
                $request->getClientIp(),
                rand()
            )
        );

        // Save session to memory
        if (!$memcached->set($session, $request->get('username'), (int) time() + $this->getParameter('app.session.default.timeout')))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not save user session!')
                ]
            );
        }

        // Save session to user cookies
        if (!setcookie('KEVACHAT_SESSION', $session, time() + $this->getParameter('app.session.default.timeout'), '/'))
        {
            return $this->redirectToRoute(
                'user_login',
                [
                    'username' => $request->get('username'),
                    'error'    => $translator->trans('Could not create session cookie!')
                ]
            );
        }

        // Redirect to main page
        return $this->redirectToRoute(
            'room_index'
        );
    }

    private function _client(): \Kevachat\Kevacoin\Client
    {
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        return $client;
    }

    private function _namespace(
        \Kevachat\Kevacoin\Client $client
    ): ?string
    {
        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            if ($value['displayName'] === '_KEVACHAT_USERS_')
            {
                return $value['namespaceId'];
            }
        }

        return null;
    }

    private function _hash(
        \Kevachat\Kevacoin\Client $client,
        string $namespace,
        string $username
    ): ?string
    {
        if ($user = $client->kevaGet($namespace, $username))
        {
            if (!empty($user['value']))
            {
                return (string) $user['value'];
            }
        }

        return null;
    }
}