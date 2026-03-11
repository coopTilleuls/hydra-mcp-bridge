<?php

declare(strict_types=1);

namespace App\Eval;

use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Logs all MCP request/response pairs as NDJSON for evaluation.
 *
 * Writes to var/eval/{clientName}_{sessionId}.ndjson.
 * Each line is a JSON object with: ts, session, client, direction, method, and method-specific fields.
 */
#[AsEventListener(event: RequestEvent::class, method: 'onRequest')]
#[AsEventListener(event: ResponseEvent::class, method: 'onResponse')]
#[AsEventListener(event: ErrorEvent::class, method: 'onError')]
final class McpEvalLogger
{
    /** @var array<string, string> session ID → client name */
    private array $clientNames = [];

    public function __construct(
        private readonly string $evalDir,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $sessionId = (string) $event->getSession()->getId();

        // Capture client name from initialize
        if ($request instanceof InitializeRequest) {
            $this->clientNames[$sessionId] = $this->slugify($request->clientInfo->name);
        }

        $entry = [
            'ts' => (new \DateTimeImmutable())->format('c'),
            'session' => $sessionId,
            'client' => $this->resolveClientName($sessionId, $event->getSession()),
            'direction' => 'in',
            'method' => $request::getMethod(),
        ];

        if ($request instanceof CallToolRequest) {
            $entry['tool'] = $request->name;
            $entry['arguments'] = $request->arguments;
        }

        $this->write($sessionId, $entry);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $sessionId = (string) $event->getSession()->getId();

        $entry = [
            'ts' => (new \DateTimeImmutable())->format('c'),
            'session' => $sessionId,
            'client' => $this->resolveClientName($sessionId, $event->getSession()),
            'direction' => 'out',
            'method' => $request::getMethod(),
        ];

        $result = $response->result;

        if ($result instanceof ListToolsResult) {
            $entry['tool_count'] = \count($result->tools);
            $entry['tool_names'] = array_map(fn ($t) => $t->name, $result->tools);
        }

        if ($result instanceof CallToolResult) {
            $entry['tool'] = $request instanceof CallToolRequest ? $request->name : null;
            $entry['is_error'] = $result->isError;
            // Log a truncated preview of the content to keep logs manageable
            $texts = [];
            foreach ($result->content as $c) {
                if (method_exists($c, 'jsonSerialize')) {
                    $serialized = $c->jsonSerialize();
                    $text = $serialized['text'] ?? null;
                    if (null !== $text && \strlen($text) > 500) {
                        $text = substr($text, 0, 500).'…[truncated]';
                    }
                    $texts[] = $text;
                }
            }
            $entry['content_preview'] = $texts;
        }

        $this->write($sessionId, $entry);
    }

    public function onError(ErrorEvent $event): void
    {
        $request = $event->getRequest();
        $error = $event->getError();
        $sessionId = (string) $event->getSession()->getId();

        $entry = [
            'ts' => (new \DateTimeImmutable())->format('c'),
            'session' => $sessionId,
            'client' => $this->resolveClientName($sessionId, $event->getSession()),
            'direction' => 'error',
            'method' => $request::getMethod(),
            'error_code' => $error->code,
            'error_message' => $error->message,
        ];

        if ($request instanceof CallToolRequest) {
            $entry['tool'] = $request->name;
        }

        $this->write($sessionId, $entry);
    }

    private function resolveClientName(string $sessionId, \Mcp\Server\Session\SessionInterface $session): string
    {
        if (isset($this->clientNames[$sessionId])) {
            return $this->clientNames[$sessionId];
        }

        // Fallback 1: session storage (set by InitializeHandler)
        $clientInfo = $session->get('client_info');
        if (\is_array($clientInfo) && isset($clientInfo['name'])) {
            $name = $this->slugify($clientInfo['name']);
            $this->clientNames[$sessionId] = $name;

            return $name;
        }

        // Fallback 2: existing log file name on disk
        if (is_dir($this->evalDir)) {
            foreach (glob($this->evalDir.'/*_'.$sessionId.'.ndjson') as $file) {
                $name = basename($file, '_'.$sessionId.'.ndjson');
                $this->clientNames[$sessionId] = $name;

                return $name;
            }
        }

        return 'unknown';
    }

    private function write(string $sessionId, array $entry): void
    {
        if (!is_dir($this->evalDir)) {
            mkdir($this->evalDir, 0o777, true);
        }

        $clientName = $entry['client'] ?? 'unknown';
        $file = \sprintf('%s/%s_%s.ndjson', $this->evalDir, $clientName, $sessionId);

        file_put_contents($file, json_encode($entry, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n", \FILE_APPEND | \LOCK_EX);
    }

    private function slugify(string $name): string
    {
        return preg_replace('/[^a-z0-9_-]/', '_', strtolower(trim($name)));
    }
}
