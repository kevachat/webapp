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
    public function index(): Response
    {
        return $this->redirectToRoute(
            'room_namespace',
            [
                'namespace' => $this->getParameter('app.kevacoin.room.namespace.default')
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

        // Get room feed
        $feed = [];

        foreach ((array) $client->kevaFilter($request->get('namespace')) as $post)
        {
            // Skip values with meta keys
            if (false !== stripos($post['key'], '_KEVA_'))
            {
                continue;
            }

            // Set identicon if not anonymous user
            if ($post['key'] === '@anonymous')
            {
                $icon = false;
            }

            else
            {
                $identicon = new \Jdenticon\Identicon();

                $identicon->setValue(
                    $post['key']
                );

                $identicon->setSize(12);

                $identicon->setStyle(
                    [
                        'backgroundColor' => 'rgba(255, 255, 255, 0)',
                        'padding' => 0
                    ]
                );

                $icon = $identicon->getImageDataUri('webp');
            }

            // Get more info
            if ($transaction = $client->getRawTransaction($post['txid']))
            {
                $feed[] =
                [
                  # 'key'    => $post['key'],
                    'value'  => $post['value'],
                    'height' => $post['height'],
                  # 'vout'   => $post['vout'],
                    'txid'   => $post['txid'],
                    'transaction' =>
                    [
                        'time'          => date('c', $transaction['time']),
                        'timestamp'     => $transaction['time'],
                        'confirmations' => $transaction['confirmations'],
                    ],
                    'icon' => $icon,
                    'sort' => $transaction['time'] // sort order field
                ];
            }
        }

        // Sort posts by newest on top
        array_multisort(
            array_column(
                $feed,
                'sort'
            ),
            SORT_DESC,
            $feed
        );

        // Return result
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

        // Check namespace writable
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
                    '@%s',
                    $request->get('user') === 'ip' ? $request->getClientIp() : 'anonymous'
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
}