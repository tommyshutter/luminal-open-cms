<?php
/**
 * AI Provider Interface
 *
 * Common contract for all AI content generation providers.
 */
declare(strict_types=1);

namespace AIResources\Providers;

interface AIProvider
{
    /**
     * Generate content with optional tool support.
     *
     * @param string $systemPrompt System-level instructions
     * @param string $userMessage  User-level content brief
     * @param array  $tools        MCP tools converted to provider format
     * @param callable|null $toolCallback  Called when provider wants to invoke a tool: fn(name, args) => result
     * @return array {content: string, model: string, usage: array, toolCalls: array}
     */
    public function generate(string $systemPrompt, string $userMessage, array $tools = [], ?callable $toolCallback = null): array;

    /**
     * Get available models for this provider.
     *
     * @return array [{id: string, name: string, maxTokens: int}, ...]
     */
    public function getModels(): array;

    /**
     * Test provider connectivity and credentials.
     *
     * @return array {ok: bool, model: string, message: string}
     */
    public function testConnection(): array;

    /**
     * Get human-readable provider name.
     */
    public function getName(): string;

    /**
     * Override max output tokens for the next generate() call.
     */
    public function setMaxTokens(int $maxTokens): void;
}
