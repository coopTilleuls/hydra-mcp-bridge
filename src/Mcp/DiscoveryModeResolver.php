<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Event\RequestEvent;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * From 2026-07-28 on, tools/list must not change per connection, so those requests always get
 * the static mode. Older revisions get the configured legacy mode.
 */
final class DiscoveryModeResolver
{
    private const string FIRST_STATELESS_REVISION = '2026-07-28';

    private DiscoveryMode $current;

    public function __construct(
        #[Autowire(env: 'enum:'.DiscoveryMode::class.':HYDRA_LEGACY_DISCOVERY')]
        private readonly DiscoveryMode $legacyMode = DiscoveryMode::Dynamic,
    ) {
        $this->current = $legacyMode;
    }

    public function forSession(?SessionInterface $session): DiscoveryMode
    {
        $version = $session?->get('protocol_version');

        return \is_string($version) && $version >= self::FIRST_STATELESS_REVISION ? DiscoveryMode::Static : $this->legacyMode;
    }

    /**
     * Mode of the request being handled, for code that sees no session (the registry).
     */
    public function current(): DiscoveryMode
    {
        return $this->current;
    }

    #[AsEventListener]
    public function onRequest(RequestEvent $event): void
    {
        $this->current = $this->forSession($event->getSession());
    }
}
