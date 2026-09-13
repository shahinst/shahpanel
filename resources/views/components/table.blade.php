@props(['headers' => []])

<div {{ $attributes->merge(['class' => 'table-responsive']) }}>
    <table class="table table-bordered table-striped table-hover align-middle mb-0">
        @if (! empty($headers))
            <thead class="table-light">
                <tr>
                    @foreach ($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
