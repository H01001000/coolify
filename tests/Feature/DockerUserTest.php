<?php

test('converts the container user to a Compose string', function (string $input, string $user) {
    expect(convertDockerRunToCompose($input))->toBe(['user' => $user]);
})->with([
    'uid' => ['--user 1000', '1000'],
    'root' => ['--user=0', '0'],
    'uid and gid' => ['--user 1000:988', '1000:988'],
    'names' => ['--user=app-user:app-group', 'app-user:app-group'],
    'quoted' => ['--user "1000:988"', '1000:988'],
    'single quoted' => ["--user='app-user:app-group'", 'app-user:app-group'],
    'last value wins' => ['--user 0 --user 1000 --user 0', '0'],
]);

test('combines a primary user with supplementary groups', function () {
    expect(convertDockerRunToCompose('--user 1000:1000 --group-add 988 --group-add audio'))
        ->toMatchArray(['user' => '1000:1000', 'group_add' => ['988', 'audio']]);
});

test('ignores a missing user value', function () {
    expect(convertDockerRunToCompose('--user --init'))->toBe(['init' => true]);
});
