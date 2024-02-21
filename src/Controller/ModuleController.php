<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class ModuleController extends AbstractController
{
    public function info(
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

        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Get sessions registry
        $online = md5(
            sprintf(
                '%s.ModuleController::info:sessions',
                __DIR__
            )
        );

        // Drop offline sessions
        $sessions = [];

        foreach ((array) $memcached->get($online) as $ip => $time)
        {
            if (time() - $time < $this->getParameter('app.session.online.timeout'))
            {
                $sessions[$ip] = $time;
            }
        }

        // Update current session time
        $sessions[$request->getClientIp()] = time();

        // Update session registry
        $memcached->set(
            $online,
            $sessions,
            $this->getParameter('app.session.online.timeout')
        );

        // Render the template
        return $this->render(
            'default/module/info.html.twig',
            [
                'online' => count(
                    $sessions
                ),
                'wallet' =>
                [
                    'balance' => (float) $client->getBalance(),
                    'block'   => (int)   $client->getBlockCount()
                ],
                'boost' =>
                [
                    'address'  => $this->getParameter('app.kevacoin.boost.address')
                ],
                'mine' =>
                [
                    'pool' =>
                    [
                        'url' => $this->getParameter('app.kevacoin.mine.pool.url')
                    ],
                    'solo' =>
                    [
                        'url' => $this->getParameter('app.kevacoin.mine.solo.url')
                    ]
                ],
                'explorer' =>
                [
                    'url' => $this->getParameter('app.kevacoin.explorer.url')
                ],
                'maintenance' => $this->getParameter('app.maintenance')
            ]
        );
    }

    public function rooms(
        Request $request
    ): Response
    {
        // Create rooms list
        $list = [];

        foreach ((array) explode('|', $this->getParameter('app.kevacoin.room.namespaces.pinned')) as $room)
        {
            $list[] = $room;
        }

        // Append custom valid namespace to the rooms list menu
        if (!in_array($request->get('namespace'), $list) && preg_match('/^N[A-z0-9]{33}$/', $request->get('namespace')))
        {
            $list[] = $request->get('namespace');
        }

        // Render the template
        return $this->render(
            'default/module/rooms.html.twig',
            [
                'list' => array_unique(
                    $list
                ),
                'request' => $request
            ]
        );
    }

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

        // Check room own
        $rooms = [];
        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $rooms[] = $value['namespaceId'];
        }

        if (!in_array($request->get('namespace'), $rooms))
        {
            return new Response();
        }

        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Create token
        $token = crc32(
            microtime(true) + rand()
        );

        $memcached->add(
            $token,
            time()
        );

        // Check user session exist
        $username = false;

        if (!empty($request->cookies->get('KEVACHAT_SESSION')) && preg_match('/[A-z0-9]{32}/', $request->cookies->get('KEVACHAT_SESSION')))
        {
            // Check username exist for this session
            if ($value = $memcached->get($request->cookies->get('KEVACHAT_SESSION')))
            {
                $username = $value;
            }
        }

        // Format quoted message
        if (preg_match('/^[A-f0-9]{64}$/', $request->get('txid')))
        {
            $message = $request->get('txid') . PHP_EOL . $request->get('message');
        }

        else
        {
            $message = $request->get('message');
        }

        // Detect active sign version
        if (in_array((string) $request->get('sign'), ['anon', 'username']))
        {
            $sign = $request->get('sign');
        }

        else if (in_array((string) $request->cookies->get('KEVACHAT_SIGN'), ['anon', 'username']))
        {
            $sign = $request->cookies->get('KEVACHAT_SIGN');
        }

        else
        {
            $sign = 'anon';
        }

        return $this->render(
            'default/module/post.html.twig',
            [
                'mode'      => $request->get('mode'),
                'namespace' => $request->get('namespace'),
                'error'     => $request->get('error'),
                'warning'   => $request->get('warning'),
                'sign'      => $sign,
                'token'     => $token,
                'message'   => $message,
                'username'  => $username,
                'cost'      => $this->getParameter('app.add.post.cost.kva'),
                'enabled'   =>
                (
                    !in_array(
                        $request->get('namespace'),
                        explode('|', $this->getParameter('app.kevacoin.room.namespaces.readonly'))
                    )
                    &&
                    !$this->getParameter('app.maintenance')
                )
            ]
        );
    }

    public function room(
        Request $request
    ): Response
    {
        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->getParameter('app.memcached.host'),
            $this->getParameter('app.memcached.port')
        );

        // Create token
        $token = crc32(
            microtime(true) + rand()
        );

        $memcached->add(
            $token,
            time()
        );

        return $this->render(
            'default/module/room.html.twig',
            [
                'request' => $request,
                'token'   => $token,
                'cost'    => $this->getParameter('app.add.room.cost.kva')
            ]
        );
    }

    public function user(
        Request $request
    ): Response
    {
        // Check user session exist
        $username = false;

        if (!empty($request->cookies->get('KEVACHAT_SESSION')) && preg_match('/[A-z0-9]{32}/', $request->cookies->get('KEVACHAT_SESSION')))
        {
            // Connect memcached
            $memcached = new \Memcached();
            $memcached->addServer(
                $this->getParameter('app.memcached.host'),
                $this->getParameter('app.memcached.port')
            );

            // Check username exist for this session
            if ($value = $memcached->get($request->cookies->get('KEVACHAT_SESSION')))
            {
                $username = $value;
            }
        }

        return $this->render(
            'default/module/user.html.twig',
            [
                'username' => $username,
                'request'  => $request
            ]
        );
    }
}