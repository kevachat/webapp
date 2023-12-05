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
                    'block'   => (int) $client->getBlockCount(),
                    'address' => $this->getParameter('app.kevacoin.address')
                ],
                'pool' => [
                    'url' => $this->getParameter('app.kevacoin.pool.url')
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

        return $this->render(
            'default/module/room.html.twig',
            [
                'room' => $request->get('room'),
                'list' => (array) $client->kevaListNamespaces()
            ]
        );
    }

    public function post(
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

        // Get wallet namespaces (to enable post module there)
        $namespaces = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $namespaces[] = $value['namespaceId'];
        }

        return $this->render(
            'default/module/post.html.twig',
            [
                'enabled'   => in_array($request->get('namespace'), $namespaces),
                'namespace' => $request->get('namespace'),
                'message'   => $request->get('message'),
                'user'      => $request->get('user'),
                'ip'        => $request->getClientIp()
            ]
        );
    }
}