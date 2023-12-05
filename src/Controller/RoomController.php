<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

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
            if ($post['key'] === 'anonymous')
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

        // Get local namespaces
        $namespaces = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $namespaces[] = $value['namespaceId'];
        }

        // Check namespace exist for this wallet
        if (!in_array($request->get('namespace'), $namespaces))
        {
            exit('Namespace not related with this node!');
        }

        // Check namespace writable
        if (!in_array($request->get('namespace'), (array) explode('|', $this->getParameter('app.kevacoin.room.namespaces'))))
        {
            exit('Namespace not listed in settings!');
        }

        // Validate access to the room namespace
        if
        (
            // Ignore this rule for is moderators
            !in_array(
                (array) explode('|', $this->getParameter('app.add.post.remote.ip.moderators'))
            ) &&

            // Check namespace writable or user is moderator
            in_array(
                $request->get('namespace'),
                (array) explode('|', $this->getParameter('app.kevacoin.room.namespaces.readonly'))
            )
        )
        {
            exit('Namespace for read only!');
        }

        // Validate remote IP regex

        // Validate remote IP limits

        // Validate funds

        // @TODO Send message to DHT

        // Redirect back to room
        return $this->redirectToRoute(
            'room_namespace',
            [
                'namespace' => $request->get('namespace')
            ]
        );
    }
}