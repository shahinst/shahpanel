@props(['id', 'title' => null])

<div id="{{ $id }}" x-data="{ open: false }" x-show="open" x-cloak
     @open-modal.window="if ($event.detail === '{{ $id }}') open = true"
     @close-modal.window="open = false"
     @keydown.escape.window="open = false"
     class="modal" :class="{ 'show': open }" role="dialog" aria-modal="true">
    <div class="modal-dialog" @click.outside="open = false">
        <div class="modal-content">
            @if ($title)
                <div class="modal-header">
                    <h3 class="modal-title">{{ $title }}</h3>
                    <button type="button" class="btn-close" @click="open = false" aria-label="Close"></button>
                </div>
            @endif
            <div class="modal-body">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
