<?php

namespace Tests\Feature;

use Tests\TestCase;

class AzureFilesystemConfigTest extends TestCase
{
    public function test_disk_azure_le_as_chaves_do_env(): void
    {
        $disk = config('filesystems.disks.azure');

        $this->assertIsArray($disk);
        $this->assertSame('azure', $disk['driver']);
        $this->assertArrayHasKey('name', $disk);
        $this->assertArrayHasKey('key', $disk);
        $this->assertArrayHasKey('connection_string', $disk);
        $this->assertArrayHasKey('container', $disk);
        $this->assertArrayHasKey('url', $disk);
    }
}
