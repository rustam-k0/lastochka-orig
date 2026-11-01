<?php

namespace Tests\Feature;

use Tests\TestCase;

class WellKnownDeeplinksTest extends TestCase
{
    public function test_apple_app_site_association_is_built_from_config(): void
    {
        config()->set('deeplinks.ios.app_ids', ['G4U3V97A92.com.app.electrohub']);
        config()->set('deeplinks.ios.paths', ['/estimates/shared/*', '/catalog/product/*']);

        $response = $this->get('/.well-known/apple-app-site-association');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'applinks' => [
                    'details' => [
                        [
                            'appIDs' => ['G4U3V97A92.com.app.electrohub'],
                            'components' => [
                                ['/' => '/estimates/shared/*'],
                                ['/' => '/catalog/product/*'],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_apple_app_site_association_returns_404_when_not_configured(): void
    {
        config()->set('deeplinks.ios.app_ids', []);

        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
    }

    public function test_assetlinks_is_built_from_config(): void
    {
        config()->set('deeplinks.android.package', 'com.app.electrohub');
        config()->set('deeplinks.android.sha256_cert_fingerprints', []);

        $response = $this->get('/.well-known/assetlinks.json');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                [
                    'relation' => ['delegate_permission/common.handle_all_urls'],
                    'target' => [
                        'namespace' => 'android_app',
                        'package_name' => 'com.app.electrohub',
                        'sha256_cert_fingerprints' => [],
                    ],
                ],
            ]);
    }

    public function test_assetlinks_returns_404_when_not_configured(): void
    {
        config()->set('deeplinks.android.package', '');

        $this->get('/.well-known/assetlinks.json')->assertNotFound();
    }
}
