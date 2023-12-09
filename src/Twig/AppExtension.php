<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    protected TranslatorInterface $translator;

    public function __construct(
        ContainerInterface $container,
        TranslatorInterface $translator
    )
    {
        $this->container = $container;
        $this->translator = $translator;
    }

    public function getFilters()
    {
        return
        [
            new TwigFilter(
                'format_ago',
                [
                    $this,
                    'formatAgo'
                ]
            ),
            new TwigFilter(
                'message_to_markdown',
                [
                    $this,
                    'messageToMarkdown'
                ]
            ),
            new TwigFilter(
                'url_to_markdown',
                [
                    $this,
                    'urlToMarkdown'
                ]
            ),
            new TwigFilter(
                'mention_to_markdown',
                [
                    $this,
                    'mentionToMarkdown'
                ]
            ),
            new TwigFilter(
                'keva_namespace_value',
                [
                    $this,
                    'kevaNamespaceValue'
                ]
            )
        ];
    }

    public function formatAgo(
        int $time,
    ): string
    {
        $diff = time() - $time;

        if ($diff < 1)
        {
            return $this->translator->trans('now');
        }

        $values =
        [
            365 * 24 * 60 * 60 =>
            [
                $this->translator->trans('year ago'),
                $this->translator->trans('years ago'),
                $this->translator->trans(' years ago')
            ],
            30  * 24 * 60 * 60 =>
            [
                $this->translator->trans('month ago'),
                $this->translator->trans('months ago'),
                $this->translator->trans(' months ago')
            ],
            24 * 60 * 60 =>
            [
                $this->translator->trans('day ago'),
                $this->translator->trans('days ago'),
                $this->translator->trans(' days ago')
            ],
            60 * 60 =>
            [
                $this->translator->trans('hour ago'),
                $this->translator->trans('hours ago'),
                $this->translator->trans(' hours ago')
            ],
            60 =>
            [
                $this->translator->trans('minute ago'),
                $this->translator->trans('minutes ago'),
                $this->translator->trans(' minutes ago')
            ],
            1 =>
            [
                $this->translator->trans('second ago'),
                $this->translator->trans('seconds ago'),
                $this->translator->trans(' seconds ago')
            ]
        ];

        foreach ($values as $key => $value)
        {
            $result = $diff / $key;

            if ($result >= 1)
            {
                $round = round($result);

                return sprintf(
                    '%s %s',
                    $round,
                    $this->plural(
                        $round,
                        $value
                    )
                );
            }
        }
    }

    public function messageToMarkdown(
        string $text
    ): string
    {
        $text = $this->urlToMarkdown(
            $text
        );

        $text = $this->mentionToMarkdown(
            $text
        );

        return $text;
    }

    public function urlToMarkdown(
        string $text
    ): string
    {
        return preg_replace(
            '~(https?://(?:www\.)?[^\(\s\)]+)~i',
            '[$1]($1)',
            $text
        );
    }

    public function mentionToMarkdown(
        string $text
    ): string
    {
        return preg_replace(
            '~@([A-z0-9]{64})~i',
            '[@$1](#$1)',
            $text
        );
    }

    private function plural(int $number, array $texts)
    {
        $cases = [2, 0, 1, 1, 1, 2];

        return $texts[(($number % 100) > 4 && ($number % 100) < 20) ? 2 : $cases[min($number % 10, 5)]];
    }

    // @TODO
    // this is fix of keva magic with Kevachat\Kevacoin\Client::kevaListNamespaces results (on pending transactions exist in the namespaces)
    // let's extract it from latest_KEVA_NS_ value and temporarily store results in memory cache
    public function kevaNamespaceValue(
        string $namespace
    ): string
    {
        // Validate namespace supported to continue
        if (!preg_match('/^[A-z0-9]{34}$/', $namespace))
        {
            return $namespace;
        }

        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->container->getParameter('app.kevacoin.protocol'),
            $this->container->getParameter('app.kevacoin.host'),
            $this->container->getParameter('app.kevacoin.port'),
            $this->container->getParameter('app.kevacoin.username'),
            $this->container->getParameter('app.kevacoin.password')
        );

        // Connect memcached
        $memcached = new \Memcached();
        $memcached->addServer(
            $this->container->getParameter('app.memcached.host'),
            $this->container->getParameter('app.memcached.port')
        );

        // Generate unique cache key
        $key = md5(
            sprintf(
                '%s.AppExtension::kevaNamespaceValue:%s',
                __DIR__,
                $namespace
            )
        );

        // Return cached value if exists
        if ($value = $memcached->get($key))
        {
            return $value;
        }

        // Find related room names
        $_keva_ns = [];
        foreach ((array) $client->kevaFilter($namespace) as $data)
        {
            if ($data['key'] == '_KEVA_NS_')
            {
                $_keva_ns[$data['height']] = $data['value'];
            }
        }

        // Get last by it block height
        if (!empty($_keva_ns))
        {
            $value = reset(
                $_keva_ns
            );

            if ($memcached->set($key, $value, $this->container->getParameter('app.memcached.timeout')))
            {
                return (string) $value;
            }
        }

        // Return original hash
        return $namespace;
    }
}