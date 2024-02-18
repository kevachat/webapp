<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Doctrine\ORM\EntityManagerInterface;

use App\Entity\Pool;

class RoomController extends AbstractController
{
    #[Route(
        '/',
        name: 'room_index',
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
            'room_namespace',
            [
                'mode'      => $request->get('mode'),
                'namespace' => $request->get('namespace') ? $request->get('namespace') : $this->getParameter('app.kevacoin.room.namespace.default'),
                '_fragment' => 'latest'
            ]
        );
    }

    #[Route(
        '/room/list',
        name: 'room_list',
        methods:
        [
            'GET'
        ]
    )]
    public function list(
        ?Request $request
    ): Response
    {
        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        // Get room list
        $list = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            // Skip system namespaces
            if (str_starts_with($value['displayName'], '_'))
            {
                continue;
            }

            // Add to room list
            $list[$value['namespaceId']] = // keep unique
            [
                'namespace' => $value['namespaceId'],
                'total'     => $this->_total(
                    $value['namespaceId']
                ),
                'pinned'    => in_array(
                    $value['namespaceId'],
                    (array) explode(
                        '|',
                        $this->getParameter('app.kevacoin.room.namespaces.pinned')
                    )
                )
            ];
        }

        // Get rooms contain pending data
        foreach ((array) $client->kevaPending() as $value)
        {
            // Add to room list
            $list[$value['namespace']] = // keep unique
            [
                'namespace' => $value['namespace'],
                'total'     => $this->_total(
                    $value['namespace']
                ),
                'pinned'    => in_array(
                    $value['namespaceId'],
                    (array) explode(
                        '|',
                        $this->getParameter('app.kevacoin.room.namespaces.pinned')
                    )
                )
            ];
        }

        // Sort by name
        array_multisort(
            array_column(
                $list,
                'total'
            ),
            SORT_DESC,
            $list
        );

        // RSS
        if ('rss' === $request->get('feed'))
        {
            $response = new Response();

            $response->headers->set(
                'Content-Type',
                'text/xml'
            );

            return $this->render(
                'default/room/list.rss.twig',
                [
                    'list'    => $list,
                    'request' => $request
                ],
                $response
            );
        }

        // HTML
        return $this->render(
            'default/room/list.html.twig',
            [
                'list'    => $list,
                'request' => $request
            ]
        );
    }

    #[Route(
        '/room/{namespace}/{txid}',
        name: 'room_namespace',
        requirements:
        [
            'namespace' => '^N[A-z0-9]{33}$',
            'txid' => '^[A-z0-9]{64}$',
        ],
        defaults:
        [
            'txid' => null,
        ],
        methods:
        [
            'GET'
        ]
    )]
    public function room(
        Request $request,
        EntityManagerInterface $entity
    ): Response
    {
        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        // Get room feed
        $feed = [];

        // Get pending from payment pool
        /*
        foreach ($entity->getRepository(Pool::class)->findBy(
            [
                'namespace' => $request->get('namespace'),
                'sent'      => 0,
                'expired'   => 0
            ]
        ) as $pending)
        {
            // Require valid kevachat meta
            if ($data = $this->_post(
                [
                    'key'   => $pending->getKey(),
                    'value' => $pending->getValue(),
                    'txid'  => hash( // @TODO tmp solution as required for tree building
                        'sha256',
                        rand()
                    )
                ]
            ))
            {
                // Detect parent post
                preg_match('/^@([A-z0-9]{64})\s/i', $data->message, $mention);
                $feed[$data->id] =
                [
                    'id'      => $data->id,
                    'user'    => $data->user,
                    'icon'    => $data->icon,
                    'time'    => $data->time,
                    'parent'  => isset($mention[1]) ? $mention[1] : null,
                    'message' => trim(
                        preg_replace( // remove mention from folded message
                            '/^@([A-z0-9]{64})\s/i',
                            '',
                            $data->message
                        )
                    ),
                    'pending' => true,
                    'pool'    =>
                    [
                        'cost'    => $pending->getCost(),
                        'address' => $pending->getAddress(),
                        'expires' => $pending->getTime() + $this->getParameter('app.pool.timeout')
                    ]
                ];
            }
        }
        */

        // Get pending paradise
        foreach ((array) $client->kevaPending() as $pending) // @TODO relate to this room
        {
            // Ignore pending posts from other rooms
            if ($pending['namespace'] !== $request->get('namespace'))
            {
                continue;
            }

            // Ignore everything in pending queue but keva_put nodes
            if ($pending['op'] !== 'keva_put')
            {
                continue;
            }

            // Skip values with meta keys
            if (str_starts_with($pending['key'], '_'))
            {
                continue;
            }

            // Require valid kevachat meta
            if ($data = $this->_post($pending))
            {
                // Detect parent post
                preg_match('/^@([A-z0-9]{64})\s/i', $data->message, $mention);

                $feed[$data->id] =
                [
                    'id'      => $data->id,
                    'user'    => $data->user,
                    'icon'    => $data->icon,
                    'time'    => $data->time,
                    'parent'  => isset($mention[1]) ? $mention[1] : null,
                    'message' => trim(
                        preg_replace( // remove mention from folded message
                            '/^@([A-z0-9]{64})\s/i',
                            '',
                            $data->message
                        )
                    ),
                    'pending' => true
                ];
            }
        }

        // Get regular posts
        foreach ((array) $client->kevaFilter($request->get('namespace')) as $post)
        {
            // Skip values with meta keys
            if (str_starts_with($post['key'], '_'))
            {
                continue;
            }

            // Require valid kevachat meta
            if ($data = $this->_post($post))
            {
                // Detect parent post
                preg_match('/^@([A-z0-9]{64})\s/i', $data->message, $mention);

                $feed[$data->id] =
                [
                    'id'      => $data->id,
                    'user'    => $data->user,
                    'icon'    => $data->icon,
                    'time'    => $data->time,
                    'parent'  => isset($mention[1]) ? $mention[1] : null,
                    'message' => trim(
                        preg_replace( // remove mention from folded message
                            '/^@([A-z0-9]{64})\s/i',
                            '',
                            $data->message
                        )
                    ),
                    'pending' => false
                ];
            }
        }

        // Sort posts by newest on top
        array_multisort(
            array_column(
                $feed,
                'time'
            ),
            SORT_ASC,
            $feed
        );

        // Init folding &data pool
        $fold = $feed;

        // Build threading tree
        $tree = $this->_tree(
            $fold
        );

        // RSS
        if ('rss' === $request->get('feed'))
        {
            $response = new Response();

            $response->headers->set(
                'Content-Type',
                'text/xml'
            );

            return $this->render(
                'default/room/index.rss.twig',
                [
                    'feed'    => $feed,
                    'request' => $request
                ],
                $response
            );
        }

        // HTML
        return $this->render(
            'default/room/index.html.twig',
            [
                'feed'    => $feed,
                'tree'    => $tree,
                'request' => $request
            ]
        );
    }

    #[Route(
        '/room/{namespace}',
        name: 'room_post',
        requirements:
        [
            'namespace' => '^N[A-z0-9]{33}$',
        ],
        methods:
        [
            'POST'
        ]
    )]
    public function post(
        Request $request,
        TranslatorInterface $translator,
        EntityManagerInterface $entity
    ): Response
    {
        // Check maintenance mode disabled
        if ($this->getParameter('app.maintenance'))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => $this->getParameter('app.maintenance'),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        $memory = md5(
            sprintf(
                '%s.RoomController::post:add.post.remote.ip.delay:%s',
                __DIR__,
                $request->getClientIp(),
            ),
        );

        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        // Check namespace available on this wallet
        /* @TODO disabled because of namespace hidden in kevaListNamespaces on pending transactions available
        $rooms = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $rooms[] = $value['namespaceId'];
        }

        if (!in_array($request->get('namespace'), $rooms))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => $translator->trans('Namespace not found on this node!'),
                    '_fragment' => 'latest'
                ]
            );
        }
        */

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
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => $translator->trans('Session token expired'),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Validate access to the room namespace
        if
        (
            // Ignore this rule for is moderators
            !in_array(
                $request->getClientIp(),
                (array) explode('|', $this->getParameter('app.moderator.remote.ip'))
            ) &&

            // Check namespace writable or user is moderator
            in_array(
                $request->get('namespace'),
                (array) explode('|', $this->getParameter('app.kevacoin.room.namespaces.readonly'))
            )
        )
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => $translator->trans('Namespace for read only!'),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Deny requests from banned remote hosts
        if (in_array($request->getClientIp(), (array) explode('|', $this->getParameter('app.add.post.remote.ip.denied'))))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error' => sprintf(
                        $translator->trans('Access denied for host %s!'),
                        $request->getClientIp()
                    ),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Validate remote IP regex
        if (!preg_match($this->getParameter('app.add.post.remote.ip.regex'), $request->getClientIp()))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error' => sprintf(
                        $translator->trans('Access restricted for host %s!'),
                        $request->getClientIp()
                    ),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Validate kevacoin value requirements
        if (mb_strlen($request->get('message')) < 1 || mb_strlen($request->get('message')) > 3072)
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => $translator->trans('Message length out of KevaCoin protocol limits'),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Validate message regex
        if (!preg_match($this->getParameter('app.add.post.value.regex'), $request->get('message')))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => sprintf(
                        $translator->trans('Message does not match node requirements: %s'),
                        $this->getParameter('app.add.post.value.regex')
                    ),
                    '_fragment' => 'latest'
                ]
            );
        }

        /// Validate remote IP limits
        if ($delay = (int) $memcached->get($memory))
        {
            // Error
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => sprintf(
                        $translator->trans('Please wait %s seconds before post new message!'),
                        (int) $this->getParameter('app.add.post.remote.ip.delay') - (time() - $delay)
                    ),
                    '_fragment' => 'latest'
                ]
            );
        }

        /// Validate funds available yet
        if (!$client->getBalance())
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => $translator->trans('Insufficient funds'),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Check user session exist
        $username = 'anon';

        if ($request->get('sign') === 'username' && !empty($request->cookies->get('KEVACHAT_SESSION')) && preg_match('/[A-z0-9]{32}/', $request->cookies->get('KEVACHAT_SESSION')))
        {
            // Check username exist for this session
            if ($value = $memcached->get($request->cookies->get('KEVACHAT_SESSION')))
            {
                $username = $value;
            }
        }

        // Save sign version to cookies
        if (in_array((string) $request->get('sign'), ['anon', 'username']))
        {
            setcookie(
                'KEVACHAT_SIGN',
                $request->get('sign'),
                time() + $this->getParameter('app.session.default.timeout'),
                '/'
            );
        }

        // Post has commission cost
        if ($this->getParameter('app.add.post.cost.kva'))
        {
            // Send message by account balance on available
            if (
                $username != 'anon'
                &&
                $client->getBalance(
                     $username,
                     $this->getParameter('app.pool.confirmations')
                ) >= $this->getParameter('app.add.post.cost.kva')
            ) {
                if (
                    $txid = $client->kevaPut(
                        $request->get('namespace'),
                        sprintf(
                            '%s@%s',
                            time(), // @TODO save timestamp as part of key to keep timing actual for the chat feature
                            $username
                        ),
                        $request->get('message')
                    )
                )
                {
                    // Send amount to profit address
                    $client->sendToAddress(
                        $this->getParameter('app.kevacoin.profit.address'),
                        $this->getParameter('app.add.post.cost.kva'),
                        $txid,
                        null,
                        true // subtract from amount
                    );

                    // Register event time
                    $memcached->set(
                        $memory,
                        time(),
                        (int) $this->getParameter('app.add.post.remote.ip.delay') // auto remove on cache expire
                    );

                    // Redirect back to room
                    return $this->redirectToRoute(
                        'room_namespace',
                        [
                            'mode'      => $request->get('mode'),
                            'namespace' => $request->get('namespace'),
                            'sign'      => $request->get('sign'),
                            'error'     => null,
                            'message'   => null,
                            '_fragment' => 'latest'
                        ]
                    );
                }
            }

            // Send message to pending payment pool
            else
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
                    $this->getParameter('app.add.post.cost.kva')
                );

                $pool->setAddress(
                    $address = $client->getNewAddress(
                        $this->getParameter('app.kevacoin.pool.account')
                    )
                );

                $pool->setNamespace(
                    $request->get('namespace')
                );

                $pool->setKey(
                    sprintf(
                        '%s@%s',
                        $time,
                        $username
                    )
                );

                $pool->setValue(
                    $request->get('message')
                );

                $entity->persist(
                    $pool
                );

                $entity->flush();

                // Register event time
                $memcached->set(
                    $memory,
                    time(),
                    (int) $this->getParameter('app.add.post.remote.ip.delay') // auto remove on cache expire
                );

                // Redirect back to room
                return $this->redirectToRoute(
                    'room_namespace',
                    [
                        'mode'      => $request->get('mode'),
                        'namespace' => $request->get('namespace'),
                        'sign'      => $request->get('sign'),
                        'message'   => null,
                        'error'     => null,
                        'warning'   => sprintf(
                            $translator->trans('Pending %s KVA to %s (expires at %s)'),
                            $this->getParameter('app.add.post.cost.kva'),
                            $address,
                            date(
                                'c',
                                $this->getParameter('app.pool.timeout') + $time
                            )
                        ),
                        '_fragment' => 'latest'
                    ]
                );
            }
        }

        // Post has zero cost, send message to DHT
        else
        {
            if (
                $client->kevaPut(
                    $request->get('namespace'),
                    sprintf(
                        '%s@%s',
                        time(), // @TODO save timestamp as part of key to keep timing actual for the chat feature
                        $username
                    ),
                    $request->get('message')
                )
            )
            {
                // Register event time
                $memcached->set(
                    $memory,
                    time(),
                    (int) $this->getParameter('app.add.post.remote.ip.delay') // auto remove on cache expire
                );

                // Redirect back to room
                return $this->redirectToRoute(
                    'room_namespace',
                    [
                        'mode'      => $request->get('mode'),
                        'namespace' => $request->get('namespace'),
                        'sign'      => $request->get('sign'),
                        'error'     => null,
                        'message'   => null,
                        '_fragment' => 'latest'
                    ]
                );
            }
        }

        // Something went wrong, return error message
        return $this->redirectToRoute(
            'room_namespace',
            [
                'mode'      => $request->get('mode'),
                'namespace' => $request->get('namespace'),
                'message'   => $request->get('message'),
                'sign'      => $request->get('sign'),
                'error'     => $translator->trans('Internal error! Please feedback'),
                '_fragment' => 'latest'
            ]
        );
    }

    #[Route(
        '/room/add',
        name: 'room_add',
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
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => $this->getParameter('app.maintenance'),
                    '_fragment' => 'latest'
                ]
            );
        }

        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        $memory = md5(
            sprintf(
                '%s.RoomController::add:add.room.remote.ip.delay:%s',
                __DIR__,
                $request->getClientIp(),
            ),
        );

        // Trim extra spaces from room name
        $name = trim(
            $request->get('name')
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
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => $translator->trans('Session token expired')
                ]
            );
        }

        // Validate kevacoin key requirements
        if (mb_strlen($name) < 1 || mb_strlen($name) > 520)
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => $translator->trans('Name length out of KevaCoin protocol limits')
                ]
            );
        }

        // Validate room name regex
        if (!preg_match($this->getParameter('app.add.room.keva.ns.value.regex'), $name))
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => sprintf(
                        $translator->trans('Room name does not match node requirements: %s'),
                        $this->getParameter('app.add.room.keva.ns.value.regex')
                    )
                ]
            );
        }

        // Validate meta NS
        if (str_starts_with($name, '_'))
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => $translator->trans('Could not create namespace in meta area')
                ]
            );
        }

        // Check room name not added before
        $rooms = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $rooms[$value['namespaceId']] = mb_strtolower($value['displayName']);
        }

        if (in_array(mb_strtolower($name), $rooms))
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => $translator->trans('Room with same name already exists on this node!')
                ]
            );
        }

        // Deny requests from banned remote hosts
        if (in_array($request->getClientIp(), (array) explode('|', $this->getParameter('app.add.room.remote.ip.denied'))))
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => sprintf(
                        $translator->trans('Access denied for host %s!'),
                        $request->getClientIp()
                    )
                ]
            );
        }

        // Validate remote IP regex
        if (!preg_match($this->getParameter('app.add.room.remote.ip.regex'), $request->getClientIp()))
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => sprintf(
                        $translator->trans('Access restricted for host %s!'),
                        $request->getClientIp()
                    )
                ]
            );
        }

        // Validate remote IP limits
        if ($delay = (int) $memcached->get($memory))
        {
            // Error
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => sprintf(
                        $translator->trans('Please wait for %s seconds before add new room!'),
                        (int) $this->getParameter('app.add.room.remote.ip.delay') - (time() - $delay)
                    )
                ]
            );
        }

        // Validate funds available yet
        if (!$client->getBalance())
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => $translator->trans('Insufficient funds')
                ]
            );
        }

        // Room registration has commission cost, send to pending payment pool
        if ($this->getParameter('app.add.room.cost.kva'))
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
                    $this->getParameter('app.add.room.cost.kva')
                );

                $pool->setAddress(
                    $address
                );

                $pool->setNamespace(
                    ''
                );

                $pool->setKey(
                    '_KEVA_NS_'
                );

                $pool->setValue(
                    $name
                );

                $entity->persist(
                    $pool
                );

                $entity->flush();

                // Redirect back to room
                return $this->redirectToRoute(
                    'room_list',
                    [
                        'mode'  => $request->get('mode'),
                        'name'  => $name,
                        'warning' => sprintf(
                            $translator->trans('To complete, send %s KVA to %s'),
                            $this->getParameter('app.add.room.cost.kva'),
                            $address
                        )
                    ]
                );
            }

            else
            {
                return $this->redirectToRoute(
                    'room_list',
                    [
                        'username' => $request->get('username'),
                        'error'    => $translator->trans('Could not init registration address!')
                    ]
                );
            }
        }

        // Send message to DHT
        if ($namespace = $client->kevaNamespace($name))
        {
            // Register event time
            $memcached->set(
                $memory,
                time(),
                (int) $this->getParameter('app.add.room.remote.ip.delay') // auto remove on cache expire
            );

            // Reset rooms list cache
            $memcached->delete(
                md5(
                    sprintf(
                        '%s.RoomController::list:rooms',
                        __DIR__
                    ),
                )
            );

            // Redirect to new room
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $namespace['namespaceId'],
                    'error'     => null,
                    'message'   => null,
                    '_fragment' => 'latest'
                ]
            );
        }

        // Something went wrong, return error message
        return $this->redirectToRoute(
            'room_list',
            [
                'mode'  => $request->get('mode'),
                'name'  => $name,
                'error' => $translator->trans('Internal error! Please feedback')
            ]
        );
    }

    private function _post(array $data): ?object
    {
        // Validate key format allowed in settings
        if (!preg_match((string) $this->getParameter('app.add.post.key.regex'), $data['key'], $matches))
        {
            return null;
        }

        // Timestamp required in key
        if (empty($matches[1]))
        {
            return null;
        }

        // Username required in key
        if (empty($matches[2]))
        {
            return null;
        }

        // Legacy usernames backport
        if (!preg_match((string) $this->getParameter('app.add.user.name.regex'), $matches[2]))
        {
            $matches[2] = 'anon';
        }

        // Validate value format allowed in settings
        if (!preg_match((string) $this->getParameter('app.add.post.value.regex'), $data['value']))
        {
            return null;
        }

        return (object)
        [
            'id'      => $data['txid'],
            'time'    => $matches[1],
            'user'    => $matches[2],
            'icon'    => $this->_identicon($matches[2]),
            'message' => $data['value']
        ];
    }

    private function _identicon(mixed $value): ?string
    {
        if ($value === 'anon')
        {
            return null;
        }

        $identicon = new \Jdenticon\Identicon();

        $identicon->setValue($value);

        $identicon->setSize(12);

        $identicon->setStyle(
            [
                'backgroundColor' => 'rgba(255, 255, 255, 0)',
                'padding' => 0
            ]
        );

        return $identicon->getImageDataUri('webp');
    }

    private function _tree(
        array &$feed,
        ?string $parent = null
    ): array
    {
        $tree = [];

        foreach ($feed as $post)
        {
            if ($post['parent'] == $parent)
            {
                $children = $this->_tree(
                    $feed,
                    $post['id']
                );

                if ($children)
                {
                    $post['tree'] = $children;
                }

                $tree[$post['id']] = $post;

                unset(
                    $feed[$post['id']]
                );
            }
        }

        return $tree;
    }

    private function _total(string $namespace): int
    {
        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        $raw = [];

        // Get pending
        foreach ((array) $client->kevaPending() as $pending)
        {
            // Ignore other namespaces
            if ($pending['namespace'] != $namespace)
            {
                continue;
            }

            $raw[] = $pending;
        }

        // Get records
        foreach ((array) $client->kevaFilter($namespace) as $record)
        {
            $raw[] = $record;
        }

        // Count begin
        $total = 0;

        foreach ($raw as $data)
        {
            // Is valid post
            if ($this->_post($data))
            {
                $total++;
            }
        }

        return $total;
    }
}