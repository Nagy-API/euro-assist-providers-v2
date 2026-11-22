<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * One-shot notification (rendered by <x-ui.toast>). A notice lives only for the response that
 * created it: it is cleared when the next request hydrates the component.
 */
trait HasNotices
{
    /** @var array{type: string, message: string, id: string}|null */
    #[Locked]
    public ?array $notice = null;

    public function hydrateHasNotices(): void
    {
        $this->notice = null;
    }

    protected function notify(string $message, string $type = 'success'): void
    {
        $this->notice = ['type' => $type, 'message' => $message, 'id' => bin2hex(random_bytes(4))];
    }
}
