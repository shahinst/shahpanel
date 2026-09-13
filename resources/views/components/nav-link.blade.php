@props(['href', 'label', 'active' => false, 'indent' => false])

<a href="{{ $href }}"
   @class([
       'flex items-center rounded-xl px-3 py-2.5 text-sm transition',
       'bg-white/10 text-white font-medium' => $active,
       'text-slate-300 hover:bg-white/5 hover:text-white' => ! $active,
       'mr-3' => $indent,
   ])>
    {{ $label }}
</a>
