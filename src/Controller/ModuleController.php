<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class ModuleController extends AbstractController
{
    public function info(): Response
    {
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        return $this->render(
            'default/module/info.html.twig',
            [
                'wallet' =>
                [
                    'balance' => (float) $client->getBalance(),
                    'block'   => (int) $client->getBlockCount()
                ],
                'mine' =>
                [
                    'address' => $this->getParameter('app.kevacoin.mine.address'),
                    'pool' =>
                    [
                        'url' => $this->getParameter('app.kevacoin.mine.pool.url')
                    ],
                    'solo' =>
                    [
                        'url' => $this->getParameter('app.kevacoin.mine.solo.url')
                    ]
                ]
            ]
        );
    }

    public function room(
        Request $request
    ): Response
    {
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        $name = null;

        $list = [];

        $rooms = explode('|', $this->getParameter('app.kevacoin.room.namespaces'));

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            // Get current room namespace (could be third-party)
            if ($value['namespaceId'] == $request->get('namespace'))
            {
                $name = $value['displayName'];
            }

            // Check namespace enabled as room in .env
            if (in_array($value['namespaceId'], $rooms))
            {
                $list[$value['namespaceId']] = $value['displayName'];
            }
        }

        asort($list);

        return $this->render(
            'default/module/room.html.twig',
            [
                'room' => [
                    'name'      => $name,
                    'namespace' => $request->get('namespace')
                ],
                'list' => $list
            ]
        );
    }

    public function post(
        Request $request
    ): Response
    {
        // Connect wallet
        $client = new \Kevachat\Kevacoin\Client(
            $this->getParameter('app.kevacoin.protocol'),
            $this->getParameter('app.kevacoin.host'),
            $this->getParameter('app.kevacoin.port'),
            $this->getParameter('app.kevacoin.username'),
            $this->getParameter('app.kevacoin.password')
        );

        // Get room settings
        $rooms    = explode('|', $this->getParameter('app.kevacoin.room.namespaces'));
        $readonly = explode('|', $this->getParameter('app.kevacoin.room.namespaces.readonly'));

        // Get wallet namespaces (to enable post module there)
        $public = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            // Check namespace enabled as room in .env
            if (in_array($value['namespaceId'], $rooms) && !in_array($value['namespaceId'], $readonly))
            {
                $public[] = $value['namespaceId'];
            }
        }

        // Format quoted message
        if (preg_match('/^[A-z0-9]{64}$/', $request->get('txid')))
        {
            $message = str_replace(
                [
                    sprintf(
                        '@%s',
                        $request->get('txid')
                    )
                ],
                false,
                $request->get('message')
            );

            $message = trim(
                $message
            );

            $message = sprintf(
                '@%s%s%s',
                $request->get('txid'),
                PHP_EOL,
                $request->get('message')
            );
        }

        else
        {
            $message = $request->get('message');
        }

        return $this->render(
            'default/module/post.html.twig',
            [
                'enabled'   => in_array($request->get('namespace'), $public),
                'namespace' => $request->get('namespace'),
                'message'   => $message,
                'user'      => $request->get('user'),
                'ip'        => $request->getClientIp()
            ]
        );
    }
}