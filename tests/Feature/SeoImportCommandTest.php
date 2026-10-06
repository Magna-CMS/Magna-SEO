<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Testing\PluginTestCase;

final class SeoImportCommandTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/seo';

    public function test_it_previews_a_wxr_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wxr').'.xml';
        file_put_contents($path, <<<'XML'
        <?xml version="1.0"?>
        <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
          <channel>
            <item>
              <title>Post</title>
              <wp:post_name>post</wp:post_name>
              <wp:status>publish</wp:status>
              <wp:postmeta>
                <wp:meta_key>_yoast_wpseo_title</wp:meta_key>
                <wp:meta_value>SEO Title</wp:meta_value>
              </wp:postmeta>
            </item>
          </channel>
        </rss>
        XML);

        $this->artisan('seo:import', ['file' => $path])
            ->expectsOutputToContain('Parsed 1 posts')
            ->assertSuccessful();

        @unlink($path);
    }

    public function test_a_missing_file_fails(): void
    {
        $this->artisan('seo:import', ['file' => '/no/such/file.xml'])->assertFailed();
    }
}
