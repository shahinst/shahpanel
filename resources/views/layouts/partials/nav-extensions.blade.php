{{-- Side-menu entries added by modules through App\Support\PanelExtensions. --}}
@foreach (\App\Support\PanelExtensions::navItems($panel) as $item)
    <x-sidebar-item :href="$item['url']" :label="$item['label']" :icon="$item['icon']" :active="$item['active']" />
@endforeach
