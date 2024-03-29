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
                'format_expire',
                [
                    $this,
                    'formatExpire'
                ]
            ),
            new TwigFilter(
                'format_bytes',
                [
                    $this,
                    'formatBytes'
                ]
            ),
            new TwigFilter(
                'format_kva',
                [
                    $this,
                    'formatKVA'
                ]
            ),
            new TwigFilter(
                'account_balance',
                [
                    $this,
                    'accountBalance'
                ]
            ),
            new TwigFilter(
                'account_address',
                [
                    $this,
                    'accountAddress'
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
                'namespace_to_markdown',
                [
                    $this,
                    'namespaceToMarkdown'
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
                    $this->_plural(
                        $round,
                        $value
                    )
                );
            }
        }
    }

    public function formatExpire(
        int $time,
    ): string
    {
        $diff = $time - time();

        if ($diff < 1)
        {
            return $this->translator->trans('expired');
        }

        $values =
        [
            365 * 24 * 60 * 60 =>
            [
                $this->translator->trans('year to expire'),
                $this->translator->trans('years to expire'),
                $this->translator->trans(' years to expire')
            ],
            30  * 24 * 60 * 60 =>
            [
                $this->translator->trans('month to expire'),
                $this->translator->trans('months to expire'),
                $this->translator->trans(' months to expire')
            ],
            24 * 60 * 60 =>
            [
                $this->translator->trans('day to expire'),
                $this->translator->trans('days to expire'),
                $this->translator->trans(' days to expire')
            ],
            60 * 60 =>
            [
                $this->translator->trans('hour to expire'),
                $this->translator->trans('hours to expire'),
                $this->translator->trans(' hours to expire')
            ],
            60 =>
            [
                $this->translator->trans('minute to expire'),
                $this->translator->trans('minutes to expire'),
                $this->translator->trans(' minutes to expire')
            ],
            1 =>
            [
                $this->translator->trans('second to expire'),
                $this->translator->trans('seconds to expire'),
                $this->translator->trans(' seconds to expire')
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
                    $this->_plural(
                        $round,
                        $value
                    )
                );
            }
        }
    }

    public function formatBytes(
        int $bytes,
        int $precision = 2
    ): string
    {
        $size = [
            $this->translator->trans('B'),
            $this->translator->trans('Kb'),
            $this->translator->trans('Mb'),
            $this->translator->trans('Gb'),
            $this->translator->trans('Tb'),
            $this->translator->trans('Pb'),
            $this->translator->trans('Eb'),
            $this->translator->trans('Zb'),
            $this->translator->trans('Yb')
        ];

        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$precision}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
    }

    public function formatKVA(
        float $amount,
        ?int $precision = 8,
        ?string $postfix = 'KVA'
    ): string
    {
        return sprintf(
            '%s %s',
            round(
                $amount,
                $precision
            ),
            $postfix
        );
    }

    public function accountBalance(
        string $account,
        ?int $confirmations = null
    ): ?float
    {
        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->container->getParameter('app.kevacoin.protocol'),
            $this->container->getParameter('app.kevacoin.host'),
            $this->container->getParameter('app.kevacoin.port'),
            $this->container->getParameter('app.kevacoin.username'),
            $this->container->getParameter('app.kevacoin.password')
        );

        return $client->getBalance(
            $account,
            $confirmations
        );
    }

    public function accountAddress(
        string $account
    ): ?string
    {
        // Connect kevacoin
        $client = new \Kevachat\Kevacoin\Client(
            $this->container->getParameter('app.kevacoin.protocol'),
            $this->container->getParameter('app.kevacoin.host'),
            $this->container->getParameter('app.kevacoin.port'),
            $this->container->getParameter('app.kevacoin.username'),
            $this->container->getParameter('app.kevacoin.password')
        );

        return $client->getAccountAddress(
            $account
        );
    }

    public function messageToMarkdown(
        string $text
    ): string
    {
        $lines = [];

        foreach (explode(PHP_EOL, $text) as $line)
        {
            $lines[] = preg_replace(
                [
                    '/^(\s?)([#]{1,6})(.*)/',
                    '/^(\s?)([*]+)(.*)/',
                    '/^(\s?)([-]+)(.*)/',
                    '/^(\s?)([`]+)(.*)/',
                    '/^(\s?)([\d]+\.)(.*)/',
                    '/^(\s?)(>)(.*)/',
                    '/(\s?)(!)(.*)/',
                    '/(\s?)(\[)(.*)/',
                    '/(\s?)(\])(.*)/',
                    '/(\s?)(\()(.*)/',
                    '/(\s?)(\))(.*)/',
                ],
                '$1\\\$2$3',
                $line
            );
        }

        $text = implode(
            PHP_EOL,
            $lines
        );

        $text = $this->urlToMarkdown(
            $text
        );

        $text = $this->mentionToMarkdown(
            $text
        );

        $text = $this->namespaceToMarkdown(
            $text
        );

        return $text;
    }

    public function urlToMarkdown(
        string $text
    ): string
    {
        return preg_replace(
            '/(https?:\/\/(?:www\.)?[^\(\s\)]+)/i',
            '[$1]($1)',
            $text
        );
    }

    public function mentionToMarkdown(
        string $text
    ): string
    {
        return preg_replace(
            '/@([A-f0-9]{64})/i',
            '[@$1](#$1)',
            $text
        );
    }

    public function namespaceToMarkdown(
        string $text
    ): string
    {
        // Search not filtered namespaces
        if (preg_match_all('/(^|\s)(N[A-z0-9]{33})/', $text, $matches))
        {
            if (empty($matches[2]))
            {
                return $text;
            }

            foreach ($matches[2] as $namespace)
            {
                // Replace with _CLITOR_IS_ value
                if ($meta = $this->_clitor($namespace))
                {
                    $text = str_replace(
                        $namespace,
                        sprintf(
                            '[%s](%s) (%s)',
                            $meta['file']['name'],
                            $this->container->get('router')->generate(
                                'view_raw',
                                [
                                    'namespace' => $namespace
                                ]
                            ),
                            $this->formatBytes(
                                $meta['file']['size']
                            )
                        ),
                        $text
                    );
                }

                // Replace with _KEVA_NS_ value
                else
                {
                    $text = str_replace(
                        $namespace,
                        sprintf(
                            '[%s](%s)',
                            $this->kevaNamespaceValue(
                                $namespace
                            ),
                            $this->container->get('router')->generate(
                                'room_namespace',
                                [
                                    'namespace' => $namespace,
                                    '_fragment' => 'latest'
                                ]
                            )
                        ),
                        $text
                    );
                }
            }
        }

        return $text;
    }

    public function kevaNamespaceValue(
        string $namespace
    ): string
    {
        // Validate namespace supported to continue
        if (!preg_match('/^N[A-z0-9]{33}$/', $namespace))
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

        // Extract value from wallet
        if ($result = $client->kevaGet($namespace, '_KEVA_NS_'))
        {
            return (string) $result['value'];
        }

        // Return original hash if no success
        return $namespace;
    }

    private function _clitor(
        string $namespace
    ): ?array
    {
        // Validate namespace supported to continue
        if (preg_match('/^N[A-z0-9]{33}$/', $namespace))
        {
            // Connect kevacoin
            $client = new \Kevachat\Kevacoin\Client(
                $this->container->getParameter('app.kevacoin.protocol'),
                $this->container->getParameter('app.kevacoin.host'),
                $this->container->getParameter('app.kevacoin.port'),
                $this->container->getParameter('app.kevacoin.username'),
                $this->container->getParameter('app.kevacoin.password')
            );

            // Get meta data by namespace
            if ($meta = $client->kevaGet($namespace, '_CLITOR_IS_'))
            {
                $reader = new \ClitorIsProtocol\Kevacoin\Reader(
                    $meta['value']
                );

                if ($reader->valid())
                {
                    return
                    [
                        'file' =>
                        [
                            'name' => $reader->fileName() ? $reader->fileName() : $namespace,
                            'size' => (int) $reader->fileSize(),
                        ]
                    ];
                }
            }
        }

        return null;
    }

    private function _plural(int $number, array $texts)
    {
        $cases = [2, 0, 1, 1, 1, 2];

        return $texts[(($number % 100) > 4 && ($number % 100) < 20) ? 2 : $cases[min($number % 10, 5)]];
    }
}