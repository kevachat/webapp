<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class ViewController extends AbstractController
{
    #[Route(
        '/view/{namespace}',
        name: 'view_raw',
        requirements:
        [
            'namespace' => '^N[A-z0-9]{33}$',
        ],
        methods:
        [
            'GET'
        ]
    )]
    public function raw(
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

        // Detect clitor-is-protocol
        if ($clitor = $client->kevaGet($request->get('namespace'), '_CLITOR_IS_'))
        {
            $reader = new \ClitorIsProtocol\Kevacoin\Reader(
                $clitor['value']
            );

            if ($reader->valid())
            {
                if ($pieces = $client->kevaFilter($request->get('namespace')))
                {
                    if ($data = $reader->data($pieces))
                    {
                        $response = new Response();

                        if ($mime = $reader->fileMime())
                        {
                            $response->headers->set(
                                'Content-type',
                                $mime
                            );
                        }

                        if ($size = $reader->fileSize())
                        {
                            if ($size == strlen($data))
                            {
                                $response->headers->set(
                                    'Content-length',
                                    $size
                                );
                            }
                        }

                        if ($name = $reader->fileName())
                        {
                            $response->headers->set(
                                'Content-Disposition',
                                sprintf(
                                    'inline; filename="%s";',
                                    $name
                                )
                            );
                        }

                        $response->sendHeaders();

                        return $response->setContent(
                            $data
                        );
                    }
                }
            }
        }

        // Nothing to view by this namespace
        throw $this->createNotFoundException();
    }
}