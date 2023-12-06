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
                    'block'   => (int)   $client->getBlockCount()
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

        $list = [];

        // Get configured rooms list
        foreach ((array) explode('|', $this->getParameter('app.kevacoin.room.namespaces')) as $namespace)
        {
            $list[$namespace] = [
                'name'      => $namespace,
                'namespace' => $namespace,
                'active'    => $namespace === $request->get('namespace')
            ];
        }

        // Find related room names
        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            if (isset($list[$value['namespaceId']]))
            {
                $list[$value['namespaceId']]['name'] = $value['displayName'];
            }
        }

        return $this->render(
            'default/module/room.html.twig',
            [
                'list' => array_values(
                    $list
                ),
                'form' =>
                [
                    'namespace' =>
                    [
                        'value' => $request->get('namespace')
                    ]
                ]
            ]
        );
    }

    public function post(
        Request $request
    ): Response
    {
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
                'namespace' => $request->get('namespace'),
                'sign'      => $request->get('sign'),
                'error'     => $request->get('error'),
                'message'   => $message,
                'ip'        => $request->getClientIp(),

                'enabled'   =>
                (
                    in_array(
                        $request->get('namespace'),
                        explode('|', $this->getParameter('app.kevacoin.room.namespaces'))
                    )
                    &&
                    !in_array(
                        $request->get('namespace'),
                        explode('|', $this->getParameter('app.kevacoin.room.namespaces.readonly'))
                    )
                )
            ]
        );
    }
}