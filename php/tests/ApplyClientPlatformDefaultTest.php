<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Events\ApplyClientPlatformDefault;
use PHPUnit\Framework\TestCase;

final class ApplyClientPlatformDefaultTest extends TestCase
{
    public function testCraftClientUnsetsWordPressDefaultSourceAndSetsPlatform(): void
    {
        $event = [
            'channel' => 'system',
            'source' => 'wordpress-plugin',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'craft');

        $this->assertSame('craft', $result['platform']);
        $this->assertArrayNotHasKey('source', $result);
    }

    public function testWordPressClientUnsetsCraftDefaultSourceAndSetsPlatform(): void
    {
        $event = [
            'channel' => 'system',
            'source' => 'craft-plugin',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'wordpress');

        $this->assertSame('wordpress', $result['platform']);
        $this->assertArrayNotHasKey('source', $result);
    }

    public function testStatamicClientUnsetsWordPressDefaultSourceAndSetsPlatform(): void
    {
        $event = [
            'channel' => 'system',
            'source' => 'wordpress-plugin',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'statamic');

        $this->assertSame('statamic', $result['platform']);
        $this->assertArrayNotHasKey('source', $result);
    }

    public function testStatamicClientUnsetsCraftDefaultSourceAndSetsPlatform(): void
    {
        $event = [
            'channel' => 'system',
            'source' => 'craft-plugin',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'statamic');

        $this->assertSame('statamic', $result['platform']);
        $this->assertArrayNotHasKey('source', $result);
    }

    public function testCraftClientUnsetsStatamicDefaultSourceAndSetsPlatform(): void
    {
        $event = [
            'channel' => 'system',
            'source' => 'statamic-addon',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'craft');

        $this->assertSame('craft', $result['platform']);
        $this->assertArrayNotHasKey('source', $result);
    }

    public function testWordPressClientUnsetsStatamicDefaultSourceAndSetsPlatform(): void
    {
        $event = [
            'channel' => 'system',
            'source' => 'statamic-addon',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'wordpress');

        $this->assertSame('wordpress', $result['platform']);
        $this->assertArrayNotHasKey('source', $result);
    }

    public function testEmptySourceSetsPlatformForKnownClient(): void
    {
        $event = ['channel' => 'system'];

        $result = ApplyClientPlatformDefault::apply($event, 'statamic');

        $this->assertSame('statamic', $result['platform']);
        $this->assertArrayNotHasKey('source', $result);
    }

    public function testSkipsWhenEventAlreadyHasPlatformHint(): void
    {
        $event = [
            'channel' => 'system',
            'source' => 'wordpress-plugin',
            'platform' => 'wordpress',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'statamic');

        $this->assertSame($event, $result);
    }

    public function testPreservesMatchingSourceForClientPlatform(): void
    {
        $event = [
            'channel' => 'forms',
            'source' => 'statamic-forms',
        ];

        $result = ApplyClientPlatformDefault::apply($event, 'statamic');

        $this->assertSame('statamic-forms', $result['source']);
        $this->assertArrayNotHasKey('platform', $result);
    }
}
