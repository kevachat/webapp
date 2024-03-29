<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Doctrine\ORM\EntityManagerInterface;

use App\Entity\Pool;

class CrontabController extends AbstractController
{
    #[Route(
        '/crontab/pool',
        name: 'crontab_pool',
        methods:
        [
            'GET'
        ]
    )]
    public function pool(
        Request $request,
        EntityManagerInterface $entity
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
        $rooms = [];

        foreach ((array) $client->kevaListNamespaces() as $value)
        {
            $rooms[$value['namespaceId']] = mb_strtolower($value['displayName']);
        }

        // Skip room lock events
        if (empty($rooms))
        {
            return new Response(); // @TODO
        }

        // Get pending from payment pool
        foreach ($entity->getRepository(Pool::class)->findBy(
            [
                'sent'    => 0,
                'expired' => 0
            ]
        ) as $pool)
        {
            // Payment received, send to blockchain
            if ($client->getReceivedByAddress($pool->getAddress(), $this->getParameter('app.pool.confirmations')) >= $pool->getCost())
            {
                // Check physical pool balance
                if ($client->getBalance($this->getParameter('app.kevacoin.pool.account'), $this->getParameter('app.pool.confirmations')) < $pool->getCost())
                {
                    throw new Exception();
                }

                // Is room request
                else if ('_KEVA_NS_' == $pool->getKey())
                {
                    // Check room name not taken
                    if (in_array(mb_strtolower($pool->getValue()), $rooms))
                    {
                        continue; // @TODO exception
                    }

                    // Create new room record
                    if ($client->kevaNamespace($pool->getValue()))
                    {
                        // Update status
                        $pool->setSent(
                            time()
                        );

                        $entity->persist(
                            $pool
                        );

                        $entity->flush();
                    }
                }

                // Is regular key/value request
                else
                {
                    // Check namespace is valid
                    if (!isset($rooms[$pool->getNamespace()]))
                    {
                        continue; // @TODO exception
                    }

                    if ($client->kevaPut($pool->getNamespace(), $pool->getKey(), $pool->getValue()))
                    {
                        // Update status
                        $pool->setSent(
                            time()
                        );

                        $entity->persist(
                            $pool
                        );

                        $entity->flush();
                    }
                }

                // Send this amount to profit account
                $client->sendFrom(
                    $this->getParameter('app.kevacoin.pool.account'),
                    $this->getParameter('app.kevacoin.profit.address'),
                    $pool->getCost()
                );
            }

            // Record expired
            else
            {
                if (time() >= $pool->getTime() + $this->getParameter('app.pool.timeout'))
                {
                    // Update status
                    $pool->setExpired(
                        time()
                    );

                    $entity->persist(
                        $pool
                    );

                    $entity->flush();
                }
            }
        }

        return new Response(); // @TODO
    }
}