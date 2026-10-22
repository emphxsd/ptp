<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * В браузере $wire.upload, $wire.set и др. — встроенные функции Livewire: wire:submit="upload"
 * вызовет их, а не метод компонента, и запрос на сервер не уйдёт. PHP-тесты (call('upload')) этого не видят.
 */
class LivewireReservedNamesTest extends TestCase
{
    // aliases из vendor/livewire/livewire/dist/livewire.js
    private const RESERVED = [
        'on', 'el', 'id', 'js', 'get', 'set', 'refs', 'call', 'hook', 'watch', 'dirty', 'effect', 'commit',
        'errors', 'island', 'upload', 'entangle', 'dispatch', 'intercept', 'interceptAction', 'interceptMessage',
        'interceptRequest', 'dispatchTo', 'dispatchSelf', 'dispatchEl', 'dispatchRef', 'removeUpload',
        'cancelUpload', 'uploadMultiple',
    ];

    public function test_reserved_aliases_list_matches_livewire(): void
    {
        $script = (string) file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js'));
        preg_match('/var aliases = \{(.*?)\};/s', $script, $block);
        preg_match_all('/"(\w+)": "\$\w+"/', $block[1] ?? '', $names);

        $this->assertEqualsCanonicalizing(self::RESERVED, $names[1], 'Список псевдонимов Livewire изменился');
    }

    public function test_actions_do_not_use_reserved_livewire_names(): void
    {
        $clashes = [];
        foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            preg_match_all('/wire:(?:click|submit|change|keydown|keyup|blur|input)(?:\.[\w.-]+)?="(\w+)/', $file->getContents(), $matches);
            foreach (array_intersect($matches[1], self::RESERVED) as $action) {
                $clashes[] = $action.' — '.$file->getRelativePathname();
            }
        }

        $this->assertSame([], $clashes, 'Действие названо как встроенная функция Livewire');
    }
}
