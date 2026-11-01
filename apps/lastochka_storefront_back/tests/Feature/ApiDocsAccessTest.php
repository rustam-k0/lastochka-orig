<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Доступ к OpenAPI-документации (`/docs/api`, `/docs/api.json`) вне
 * local-окружения: Scramble спрашивает gate `viewApiDocs`, тот пускает
 * только по токену `API_DOCS_TOKEN`.
 */
class ApiDocsAccessTest extends TestCase
{
    public function test_docs_closed_when_token_not_configured(): void
    {
        config(['api_docs.token' => null]);

        $this->get('/docs/api')->assertStatus(403);
        $this->get('/docs/api?key=whatever')->assertStatus(403);
    }

    public function test_docs_closed_for_wrong_token(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $this->get('/docs/api?key=wrong')->assertStatus(403);
        $this->withHeader('X-Docs-Key', 'wrong')->get('/docs/api')->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer wrong')->get('/docs/api')->assertStatus(403);
    }

    public function test_docs_closed_without_token_in_request(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $this->get('/docs/api')->assertStatus(403);
    }

    public function test_empty_query_key_does_not_open_docs(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $this->get('/docs/api?key=')->assertStatus(403);
    }

    public function test_array_query_key_does_not_open_docs(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $this->get('/docs/api?key[]=secret-token')->assertStatus(403);
    }

    public function test_query_key_opens_spec(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $response = $this->get('/docs/api.json?key=secret-token');

        $response->assertStatus(200);
        $response->assertJsonPath('openapi', '3.1.0');
    }

    public function test_header_key_opens_spec(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $this->withHeader('X-Docs-Key', 'secret-token')
            ->get('/docs/api.json')
            ->assertStatus(200);
    }

    public function test_bearer_token_opens_spec(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $this->withHeader('Authorization', 'Bearer secret-token')
            ->get('/docs/api.json')
            ->assertStatus(200);
    }

    public function test_session_remembers_successful_check(): void
    {
        config(['api_docs.token' => 'secret-token']);

        $this->get('/docs/api.json?key=secret-token')->assertStatus(200);

        // Внутри той же сессии повторная передача токена не нужна.
        $this->get('/docs/api.json')->assertStatus(200);
    }
}
