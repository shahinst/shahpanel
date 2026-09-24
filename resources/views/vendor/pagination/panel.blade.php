{{--
    این قالب در AppServiceProvider با Paginator::defaultView ثبت شده بود ولی
    فایلش ساخته نشده بود. نتیجه: هر صفحهٔ صفحه‌بندی‌شده به‌محض رسیدن به صفحهٔ
    دوم خطای ۵۰۰ می‌داد — روی نصب تازه دیده نمی‌شد چون هیچ جدولی بیش از یک
    صفحه داده نداشت. کلاس‌ها از resources/css/app.css می‌آیند که از قبل برای
    همین قالب نوشته شده بودند.
--}}
@if ($paginator->hasPages())
    <nav class="panel-pagination-nav" role="navigation" aria-label="{{ __('app.pagination') ?? 'Pagination' }}">
        <ul class="pagination panel-pagination">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link">&laquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">&laquo;</a>
                </li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active" aria-current="page"><span class="page-link">{{ persian_digits($page) }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}">{{ persian_digits($page) }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">&raquo;</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true"><span class="page-link">&raquo;</span></li>
            @endif
        </ul>
    </nav>
@endif
