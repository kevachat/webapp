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
                'namespace' => $request->get('namespace') ? $request->get('namespace') : $this->getParameter('app.kevacoin.room.namespace.default')
            ]
        );
    }

    #[Route(
        '/room/{namespace}/{txid}',
        name: 'room_namespace',
        requirements:
        [
            'namespace' => '^[A-z0-9]{34}$',
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

        // Set title
        $name = $request->get('namespace');

        foreach ((array) $client->kevaListNamespaces() as $namespace)
        {
            // Get current room namespace (could be third-party)
            if ($namespace['namespaceId'] == $request->get('namespace'))
            {
                $name = $namespace['displayName'];

                break;
            }
        }

        // Check for external rooms reading allowed in config
        if (
            !in_array(
                $request->get('namespace'),
                (array) explode('|', $this->getParameter('app.kevacoin.room.namespaces'))
            )
            &&
            $this->getParameter('app.kevacoin.room.namespace.external') === 'false'
        ) {
            // @TODO process to error page instead of redirect to default room
            return $this->redirectToRoute(
                'room_index'
            );
        }

        // Get room feed
        $feed = [];

        // Get pending paradise
        foreach ((array) $client->kevaPending() as $pending)
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
            if (false !== stripos($pending['key'], '_KEVA_'))
            {
                continue;
            }

            // Require valid kevachat meta
            if ($data = $this->_post($pending))
            {
                $feed[] =
                [
                    'id'        => $data->id,
                    'user'      => $data->user,
                    'icon'      => $data->icon,
                    'message'   => $data->message,
                    'timestamp' => $data->time,
                    'time'      => date('c', $data->time),
                    'pending'   => true
                ];
            }
        }

        // Get regular posts
        foreach ((array) $client->kevaFilter($request->get('namespace')) as $post)
        {
            // Skip values with meta keys
            if (false !== stripos($post['key'], '_KEVA_'))
            {
                continue;
            }

            // Require valid kevachat meta
            if ($data = $this->_post($post))
            {
                $feed[] =
                [
                    'id'        => $data->id,
                    'user'      => $data->user,
                    'icon'      => $data->icon,
                    'message'   => $data->message,
                    'timestamp' => $data->time,
                    'time'      => date('c', $data->time),
                    'pending'   => false
                ];
            }
        }

        // Sort posts by newest on top
        array_multisort(
            array_column(
                $feed,
                'time'
            ),
            SORT_DESC,
            $feed
        );

        // RSS
        if ('RSS' === $request->get('feed'))
        {
            return $this->render(
                'default/room/index.rss.twig',
                [
                    'name'    => $name,
                    'feed'    => $feed,
                    'request' => $request
                ]
            );
        }

        // HTML
        return $this->render(
            'default/room/index.html.twig',
            [
                'name'    => $name,
                'feed'    => $feed,
                'request' => $request
            ]
        );
    }

    #[Route(
        '/room/{namespace}',
        name: 'room_post',
        requirements:
        [
            'namespace' => '^[A-z0-9]{34}$',
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
        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        $memory = [
            'app.add.post.remote.ip.delay' => md5(
                sprintf(
                    'kevachat.app.add.post.remote.ip.delay:%s.%s',
                    $this->getParameter('app.name'),
                    $request->getClientIp(),
                ),
            ),
        ];

        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        // Get local namespaces
        $namespaces = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $namespaces[] = $value['namespaceId'];
        }

        // Check namespace exist for this wallet
        if (!in_array($request->get('namespace'), $namespaces))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => $translator->trans('Namespace not related with this node!')
                ]
            );
        }

        // Check namespace defined in config
        if (!in_array($request->get('namespace'), (array) explode('|', $this->getParameter('app.kevacoin.room.namespaces'))))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => $translator->trans('Namespace not listed in settings!')
                ]
            );
        }

        // Validate access to the room namespace
        if
        (
            // Ignore this rule for is moderators
            !in_array(
                $request->getClientIp(),
                (array) explode('|', $this->getParameter('app.add.post.remote.ip.moderators'))
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
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => $translator->trans('Namespace for read only!')
                ]
            );
        }

        // Deny requests from banned remote hosts
        if (in_array($request->getClientIp(), (array) explode('|', $this->getParameter('app.add.post.remote.ip.denied'))))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => $translator->trans('Access denied for this IP!')
                ]
            );
        }

        // Validate remote IP regex
        if (!preg_match($this->getParameter('app.add.post.remote.ip.regex'), $request->getClientIp()))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => $translator->trans('Access not allowed for this IP!')
                ]
            );
        }

        // Validate message regex
        if (!preg_match($this->getParameter('app.add.post.value.regex'), $request->get('message')))
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => sprintf(
                        $translator->trans('Message does not match node requirements: %s'),
                        $this->getParameter('app.add.post.value.regex')
                    )
                ]
            );
        }

        /// Validate remote IP limits
        if ($delay = (int) $memcached->get($memory['app.add.post.remote.ip.delay']))
        {
            // Error
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => sprintf(
                        $translator->trans('Please wait for %s seconds before post new message!'),
                        (int) $this->getParameter('app.add.post.remote.ip.delay') - (time() - $delay)
                    )
                ]
            );
        }

        /// Validate funds available yet
        if (1 > $client->getBalance())
        {
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'message'   => $request->get('message'),
                    'error'     => sprintf(
                        $translator->trans('Insufficient funds, wallet: %s'),
                        $this->getParameter('app.kevacoin.mine.address')
                    )
                ]
            );
        }

        // Send message to DHT
        if (
            $client->kevaPut(
                $request->get('namespace'),
                sprintf(
                    '%s@%s',
                    time(), // @TODO save timestamp as part of key to keep timing actual for the chat feature
                    $request->get('user') === 'ip' ? $request->getClientIp() : 'anon'
                ),
                $request->get('message')
            )
        )
        {
            // Register event time
            $memcached->set(
                $memory['app.add.post.remote.ip.delay'],
                time(),
                (int) $this->getParameter('app.add.post.remote.ip.delay')
            );

            // Redirect back to room
            return $this->redirectToRoute(
                'room_namespace',
                [
                    'namespace' => $request->get('namespace'),
                    'error'     => null,
                    'message'   => null
                ]
            );
        }

        // Something went wrong, return error message
        return $this->redirectToRoute(
            'room_namespace',
            [
                'namespace' => $request->get('namespace'),
                'message'   => $request->get('message'),
                'error'     => $translator->trans('Internal error! Please feedback')
            ]
        );
    }

    private function _post(array $data): ?object
    {
        if (false === preg_match('/^([\d]+)@(.*)$/', $data['key'], $matches))
        {
            return null;
        }

        if (empty($matches[1]))
        {
            return null;
        }

        if (empty($matches[2]))
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
}