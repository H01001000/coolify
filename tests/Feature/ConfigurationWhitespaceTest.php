<?php

use App\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

test('request trimming preserves nested Livewire configuration data and trims identifiers', function () {
    $body = "\n  config: true\n\n";
    $request = Request::create('/livewire/update', 'POST', [
        'name' => '  config  ',
        'components' => [['updates' => [
            'file_storage_content' => $body,
            'file.content' => $body,
            'environment.value' => $body,
            'file.mount_path' => '  /etc/config.yml  ',
        ]]],
    ]);
    (new TrimStrings)->handle($request, fn ($request) => response('ok'));
    expect($request->input('name'))->toBe('config')
        ->and($request->input('components')[0]['updates'])->toBe([
            'file_storage_content' => $body,
            'file.content' => $body,
            'environment.value' => $body,
            'file.mount_path' => '/etc/config.yml',
        ]);
});
