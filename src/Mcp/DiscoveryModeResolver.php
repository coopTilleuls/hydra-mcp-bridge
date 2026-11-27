<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Groups\GroupsExtension;
use Mcp\Event\RequestEvent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A client declaring the groups extension, at initialize or in the request _meta, gets the grouped view.
 * Otherwise, from 2026-07-28 on, tools/list must not change per connection, so those requests always get
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

    public function forSession(?SessionInterface $session, ?Request $request = null): DiscoveryMode
    {
        if (self::declaresGroups($request?->getMeta()['io.modelcontextprotocol/clientCapabilities'] ?? null)
            || self::declaresGroups($session?->get('client_capabilities'))) {
            return DiscoveryMode::Groups;
        }

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
        $this->current = $this->forSession($event->getSession(), $event->getRequest());
    }

    private static function declaresGroups(mixed $capabilities): bool
    {
        $extensions = ((array) $capabilities)['extensions'] ?? null;

        return null !== $extensions && \array_key_exists(GroupsExtension::ID, (array) $extensions);
    }
}
