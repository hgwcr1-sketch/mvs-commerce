<?php

namespace App\Http\Controllers\MvsPrint;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

class MvsPrintDownloadController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $url = config('mvsprint.installer.download_url');

        abort_unless($url, 404);

        return Redirect::to($url, 302);
    }
}
