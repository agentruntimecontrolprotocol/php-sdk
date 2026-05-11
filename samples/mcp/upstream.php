<?php

declare(strict_types=1);

namespace Arcp\Samples\Mcp;

/**
 * Upstream MCP server invocation.
 *
 * No first-class MCP PHP SDK exists yet; in production point this at a
 * vendored MCP-over-stdio bridge (or a HTTP/SSE shim). Reference servers
 * from the modelcontextprotocol org publish under `mcp-server-*`
 * (filesystem, git, postgres, slack, ...).
 *
 * TODO: replace with vendored bridge once an MCP PHP SDK exists.
 */
final class StdioServerParameters
{
    /** @param list<string> $args */
    public function __construct(
        public readonly string $command,
        public readonly array $args = [],
    ) {
    }
}

/**
 * Stand-in for the MCP `ClientSession`. Mirrors the small surface the
 * bridge actually touches: initialize / listTools / callTool.
 */
final class McpClientSession
{
    public static function stdio(StdioServerParameters $params): self
    {
        throw new \RuntimeException('not implemented');
    }

    public function initialize(): void
    {
        throw new \RuntimeException('not implemented');
    }

    /** @return list<array{name: string, description?: string}> */
    public function listTools(): array
    {
        throw new \RuntimeException('not implemented');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{isError?: bool, content: list<array<string, mixed>>}
     */
    public function callTool(string $tool, array $arguments): array
    {
        throw new \RuntimeException('not implemented');
    }
}

function upstreamParams(): StdioServerParameters
{
    return new StdioServerParameters('uvx', ['mcp-server-filesystem', '/srv/data']);
}
