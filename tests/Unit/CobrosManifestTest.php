<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CobrosManifestTest extends TestCase
{
    public function test_manifest_has_separate_identity_scope_and_existing_icons(): void
    {
        $public = dirname(__DIR__, 2).'/public';
        $manifest = json_decode(file_get_contents($public.'/manifest-cobros.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('MVS CobrOS', $manifest['name']);
        $this->assertSame('CobrOS', $manifest['short_name']);
        $this->assertSame('/cobros', $manifest['id']);
        $this->assertSame('/cobros', $manifest['start_url']);
        $this->assertSame('/cobros', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#D4AF37', $manifest['theme_color']);
        $this->assertSame('#ffffff', $manifest['background_color']);
        $this->assertCount(4, $manifest['icons']);
        $variants = [];
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsWith('/icons/cobros/', $icon['src']);
            $this->assertSame('image/png', $icon['type']);
            $size = getimagesize($public.$icon['src']);
            $this->assertSame($icon['sizes'], $size[0].'x'.$size[1]);
            $this->assertSame('image/png', $size['mime']);
            $variants[] = $icon['purpose'].':'.$icon['sizes'];
        }
        $this->assertEqualsCanonicalizing(['any:192x192', 'any:512x512', 'maskable:192x192', 'maskable:512x512'], $variants);
        foreach (['images/logo-mvs-cobros.png', 'icons/cobros/favicon-32x32.png', 'icons/cobros/apple-touch-icon.png'] as $asset) {
            $this->assertSame('image/png', getimagesize($public.'/'.$asset)['mime']);
        }
        $this->assertFileDoesNotExist($public.'/images/logo-mvs-cobros.png.png');
    }
}
