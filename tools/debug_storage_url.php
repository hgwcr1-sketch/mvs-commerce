<?php

// Debug script to check what the HTML contains
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Simulate the test
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

Storage::fake('public');
$path = 'loyalty-portal/1/a.jpg';
Storage::disk('public')->put($path, 'content');
$url = Storage::disk('public')->url($path);
echo 'Storage::url() returns: '.var_export($url, true)."\n";
echo 'Escaped version: '.var_export(str_replace('/', '\\/', $url), true)."\n";
echo 'Storage path: '.var_export(Storage::disk('public')->path($path), true)."\n";
echo 'Config: '.var_export(config('filesystems.disks.public.url'), true)."\n";
