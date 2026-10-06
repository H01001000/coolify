<?php

test('converts supplementary groups to a Compose list of strings', function (string $input, array $groups) {
    expect(convertDockerRunToCompose($input))->toBe(['group_add' => $groups]);
})->with([
    'gid with space' => ['--group-add 988', ['988']],
    'gid with equals' => ['--group-add=988', ['988']],
    'root gid' => ['--group-add 0', ['0']],
    'repeated groups' => ['--group-add 988 --group-add=audio --group-add render-group', ['988', 'audio', 'render-group']],
    'duplicate groups' => ['--group-add 988 --group-add 988 --group-add audio', ['988', 'audio']],
    'quoted groups' => ['--group-add "988" --group-add=\'render-group\'', ['988', 'render-group']],
]);

test('keeps other Docker options when adding supplementary groups', function () {
    expect(convertDockerRunToCompose('--group-add 988 --cap-add SYS_ADMIN --init'))
        ->toMatchArray(['group_add' => ['988'], 'cap_add' => ['SYS_ADMIN'], 'init' => true]);
});

test('ignores a missing supplementary group value', function () {
    expect(convertDockerRunToCompose('--group-add'))->toBe([]);
});
