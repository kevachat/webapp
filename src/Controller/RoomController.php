<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

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

            // Calculate room totals
            $total = 0;

            foreach ((array) $client->kevaFilter($value['namespaceId']) as $post)
            {
                // Skip values with meta keys
                if (str_starts_with($post['key'], '_'))
                {
                    continue;
                }

                // Require valid kevachat meta
                if ($this->_post($post))
                {
                    $total++;
                }
            }

            // Add to room list
            $list[] =
            [
                'namespace' => $value['namespaceId'],
                'total'     => $total,
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
        Request $request
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
        TranslatorInterface $translator
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
        if (1 > $client->getBalance())
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'mode'      => $request->get('mode'),
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'sign'      => $request->get('sign'),
                    'error'     => sprintf(
                        $translator->trans('Insufficient funds, wallet: %s'),
                        $this->getParameter('app.kevacoin.boost.address')
                    ),
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

        // Send message to DHT
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
        TranslatorInterface $translator
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
        if (1 > $client->getBalance())
        {
            return $this->redirectToRoute(
                'room_list',
                [
                    'mode'  => $request->get('mode'),
                    'name'  => $name,
                    'error' => sprintf(
                        $translator->trans('Insufficient funds, wallet: %s'),
                        $this->getParameter('app.kevacoin.boost.address')
                    )
                ]
            );
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
}