{{-- The font itself is set by App\Support\PersianPdf (Vazirmatn for Persian). --}}
<style>
    body, table, th, td, div, span, p, h1, h2, h3 {
        direction: {{ locale_dir() }};
        text-align: {{ locale_is_rtl() ? 'right' : 'left' }};
    }
</style>
