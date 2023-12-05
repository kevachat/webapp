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
        '/room/{namespace}',
        name: 'room_namespace',
        requirements:
        [
            'namespace' => '^[A-z0-9]{34}$',
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

        foreach ((array) $client->kevaFilter($request->get('namespace')) as $post)
        {
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

            // Skip values with meta keys
            if (false !== stripos($post['key'], '_KEVA_'))
            {
                continue;
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
                'feed'    => $feed,
                'request' => $request
            ]
        );
    }

    #[Route(
        '/room/{namespace}/post',
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

        // Check namespace exist for this wallet
        $namespaces = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $namespaces[] = $value['namespaceId'];
        }

        if (!in_array($request->get('namespace'), $namespaces))
        {
            exit('Namespace not related with this node!');
        }

        // @TODO

        // Redirect back to the room
        return $this->redirectToRoute(
            'room_namespace',
            [
                'namespace' => $request->get('namespace')
            ]
        );
    }
}