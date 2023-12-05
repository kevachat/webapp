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
                'namespace' => $this->getParameter('app.kevacoin.namespace')
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

        // Get room messages
        $messages = [];

        foreach ((array) $client->kevaFilter($request->get('namespace')) as $message)
        {
            $messages[] = $message;
        }

        // Return result
        return $this->render(
            'default/room/index.html.twig',
            [
                'messages' => $messages,
                'request'  => $request
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
                'namespace' => $this->getParameter('app.kevacoin.namespace')
            ]
        );
    }
}