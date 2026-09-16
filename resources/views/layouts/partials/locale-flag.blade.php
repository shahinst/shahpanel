{{--
    پرچم یک زبان به صورت SVG درون‌خطی.

    عمداً از ایموجی پرچم استفاده نمی‌کنیم: ویندوز فونت پرچم ندارد و
    به‌جای پرچم دو حرف کد کشور را نشان می‌دهد. SVG درون‌خطی هم درخواست
    شبکه اضافه نمی‌کند و به CDN وابسته نیست — برای پنلی که ممکن است
    پشت فیلتر اجرا شود مهم است.

    پارامترها: $code (کد زبان)، $size (عرض به پیکسل، پیش‌فرض ۲۰)
--}}
@php
    $size = $size ?? 20;
    $h = (int) round($size * 0.75); // نسبت استاندارد پرچم ۴:۳
    // پرچم تزئینی است و نام زبان را لینک دربرگیرنده با aria-label می‌دهد،
    // پس از دید صفحه‌خوان پنهانش می‌کنیم تا دوبار خوانده نشود.
    $attrs = 'width="'.$size.'" height="'.$h.'" aria-hidden="true" focusable="false" '
        .'style="border-radius:2px;flex:0 0 auto;box-shadow:0 0 0 1px rgba(0,0,0,.12);vertical-align:middle;"';
@endphp

@switch($code)
    @case('fa')
        <svg {!! $attrs !!} viewBox="0 0 640 480" xmlns="http://www.w3.org/2000/svg">
            <path fill="#fff" d="M0 0h640v480H0z"/>
            <path fill="#239f40" d="M0 0h640v160H0z"/>
            <path fill="#da0000" d="M0 320h640v160H0z"/>
            <g fill="#da0000" transform="translate(320 240)">
                <path d="M0-40c-4.5 7-4.5 15 0 22 4.5-7 4.5-15 0-22z"/>
                <path d="M-3.5-16h7v34a3.5 3.5 0 0 1-7 0z"/>
                <path d="M-11-24h22v6h-22z"/>
                <path d="M-17-26c-6 7-7 19-1 27 0-11 2-19 6-23z"/>
                <path d="M17-26c6 7 7 19 1 27 0-11-2-19-6-23z"/>
                <path d="M-30-16c-7 8-7 22 0 30-2-12 0-22 5-27z"/>
                <path d="M30-16c7 8 7 22 0 30 2-12 0-22-5-27z"/>
            </g>
        </svg>
        @break

    @case('en')
        <svg {!! $attrs !!} viewBox="0 0 640 480" xmlns="http://www.w3.org/2000/svg">
            <path fill="#012169" d="M0 0h640v480H0z"/>
            <path fill="#fff" d="m75 0 244 181L562 0h78v62L400 241l240 178v61h-80L320 301 81 480H0v-60l239-178L0 64V0h75z"/>
            <path fill="#c8102e" d="m424 281 216 159v40L369 281h55zm-184 20 6 35L54 480H0l240-179zM640 0v3L391 191l2-44L590 0h50zM0 0l239 176h-60L0 42V0z"/>
            <path fill="#fff" d="M241 0v480h160V0H241zM0 160v160h640V160H0z"/>
            <path fill="#c8102e" d="M0 193v96h640v-96H0zM273 0v480h96V0h-96z"/>
        </svg>
        @break

    @case('ru')
        <svg {!! $attrs !!} viewBox="0 0 640 480" xmlns="http://www.w3.org/2000/svg">
            <path fill="#fff" d="M0 0h640v160H0z"/>
            <path fill="#0039a6" d="M0 160h640v160H0z"/>
            <path fill="#d52b1e" d="M0 320h640v160H0z"/>
        </svg>
        @break

    @case('zh')
        <svg {!! $attrs !!} viewBox="0 0 640 480" xmlns="http://www.w3.org/2000/svg">
            <path fill="#ee1c25" d="M0 0h640v480H0z"/>
            <g fill="#ff0">
                <path d="M-.6.8 0-1 .6.8-1-.3h2z" transform="matrix(71.9991 0 0 72 120 120)"/>
                <path d="M-.6.8 0-1 .6.8-1-.3h2z" transform="matrix(-12.33562 -20.5871 20.58684 -12.33577 240.3 48)"/>
                <path d="M-.6.8 0-1 .6.8-1-.3h2z" transform="matrix(-3.38573 -23.75998 23.75968 -3.38578 288 95.8)"/>
                <path d="M-.6.8 0-1 .6.8-1-.3h2z" transform="matrix(6.5991 -23.0749 23.0746 6.59919 288 168)"/>
                <path d="M-.6.8 0-1 .6.8-1-.3h2z" transform="matrix(14.9991 -18.73557 18.73533 14.99929 240 216)"/>
            </g>
        </svg>
        @break

    @default
        {{-- زبان ناشناخته: کرهٔ زمین به‌جای پرچم --}}
        <svg {!! $attrs !!} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="9"/>
            <path d="M3 12h18M12 3c2.5 2.7 2.5 15 0 18M12 3c-2.5 2.7-2.5 15 0 18"/>
        </svg>
@endswitch
