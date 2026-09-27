<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminSectionAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/*
 * ساخت و تنظیم ادمین‌های دیگر به‌دست «مدیر اصلی».
 *
 * دسترسی به این کنترلر با میدل‌ور admin.section و کلید super_only در
 * config/admin_sections.php بسته شده، پس اینجا دوباره نقش را نمی‌سنجیم؛
 * چیزی که در این فایل نگهبانی می‌شود، «قفل‌نشدنِ خودِ مدیر اصلی» است — یک
 * تضمین جداگانه که هیچ میدل‌وری نمی‌تواند بدهد.
 */
class AdministratorController extends Controller
{
    public function __construct(protected AdminSectionAccessService $sections) {}

    public function index(): View
    {
        $admins = User::query()
            ->where('role', UserRole::Admin->value)
            ->orderBy('id')
            ->paginate(20);

        return view('admin.administrators.index', [
            'admins' => $admins,
            'superAdminId' => $this->sections->superAdminId(),
        ]);
    }

    public function create(): View
    {
        return view('admin.administrators.create', [
            'admin' => null,
            'availableSections' => $this->sections->availableSections(),
            'grantedKeys' => [],
            'isSuperTarget' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $admin = User::query()->create([
            'role' => UserRole::Admin,
            // ادمین بیرونِ سلسله‌مراتب فروش است، مثل حساب نصب‌کننده.
            'parent_id' => null,
            'username' => $validated['username'],
            'email' => $validated['email'],
            'full_name' => $validated['full_name'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => $validated['status'],
        ]);

        $this->applySectionPermissions($request, $admin);

        return redirect()
            ->route('admin.administrators.index')
            ->with('success', __('app.saved'));
    }

    public function edit(User $user): View
    {
        $this->abortUnlessAdmin($user);

        $isSuperTarget = $this->sections->isSuperAdmin($user);

        return view('admin.administrators.edit', [
            'admin' => $user,
            'availableSections' => $this->sections->availableSections(),
            'grantedKeys' => is_array($user->admin_section_permissions)
                ? $user->admin_section_permissions
                : [],
            'isSuperTarget' => $isSuperTarget,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->abortUnlessAdmin($user);

        $validated = $request->validate($this->rules($user), $this->messages());

        $user->username = $validated['username'];
        $user->email = $validated['email'];
        $user->full_name = $validated['full_name'];
        $user->phone = $validated['phone'] ?? null;
        $user->status = UserStatus::from($validated['status']);

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            // همان قاعدهٔ Admin\UserController: رمز که عوض شد، توکن‌های API آن
            // حساب هم باید بمیرند، وگرنه دسترسی قبلی زنده می‌ماند.
            app(\App\Services\ApiTokenService::class)->revokeAllForUser($user, 'password_reset_by_staff');
        }

        /*
         * سدّ قفل‌شدن، بخش اول: وضعیت مدیر اصلی همیشه «فعال» می‌ماند.
         *
         * تنها کسی که به این فرم می‌رسد خود مدیر اصلی است؛ اگر اشتباهی حساب
         * خودش را معلق می‌کرد، دیگر نمی‌توانست وارد پنل شود و هیچ حساب دیگری
         * هم اجازهٔ برگرداندنش را نداشت. بازگرداندن آن فقط با دستکاری مستقیم
         * دیتابیس ممکن بود.
         */
        if ($this->sections->isSuperAdmin($user)) {
            $user->status = UserStatus::Active;
        }

        $user->save();

        $this->applySectionPermissions($request, $user);

        return redirect()
            ->route('admin.administrators.index')
            ->with('success', __('app.saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->abortUnlessAdmin($user);

        // سدّ قفل‌شدن، بخش دوم: حساب مدیر اصلی حذف‌شدنی نیست — نه به‌دست
        // خودش و نه به‌دست هیچ ادمین دیگری. با رفتنِ آن حساب، «کم‌ترین id»
        // به ادمین دیگری منتقل می‌شد و صاحب پنل مالکیت پنل خودش را از دست
        // می‌داد.
        abort_if($this->sections->isSuperAdmin($user), 403, __('admins.cannot_delete_super'));

        abort_if((int) $user->id === (int) $request->user()->id, 403, __('admins.cannot_delete_self'));

        $user->delete();

        return redirect()
            ->route('admin.administrators.index')
            ->with('success', __('app.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(?User $user = null): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$user === null ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'limit_sections' => ['nullable', 'boolean'],
            // required_if تا «محدود کن ولی هیچ بخشی انتخاب نکن» از فرم بیرون
            // نرود: آرایهٔ خالی در سرویس به معنای «همه‌چیز» است، پس چنین
            // ذخیره‌ای دقیقاً برعکسِ خواستهٔ کاربر عمل می‌کرد.
            'sections' => ['array', 'required_if:limit_sections,1'],
            'sections.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'sections.required_if' => __('admins.sections_required'),
        ];
    }

    protected function applySectionPermissions(Request $request, User $admin): void
    {
        /*
         * سدّ قفل‌شدن، بخش سوم: دسترسی مدیر اصلی هرگز محدود نمی‌شود.
         *
         * حتی اگر فرم دستکاری شده باشد و فهرستی بفرستد، اینجا NULL می‌شود.
         * سرویس هم مستقل از این، برای مدیر اصلی همیشه «همه‌چیز» برمی‌گرداند؛
         * این دو یک چیز را تکرار نمی‌کنند: آن یکی «خواندن» را امن می‌کند و این
         * یکی جلوی نوشتنِ یک ردیف گمراه‌کننده در دیتابیس را می‌گیرد.
         */
        if ($this->sections->isSuperAdmin($admin)) {
            $admin->admin_section_permissions = null;
            $admin->save();

            return;
        }

        if (! $request->boolean('limit_sections')) {
            // دسترسی کامل = NULL. همان معنایی که ادمین‌های پیش از این
            // به‌روزرسانی دارند.
            $admin->admin_section_permissions = null;
            $admin->save();

            return;
        }

        $admin->admin_section_permissions = $this->sections->sanitize(
            (array) $request->input('sections', [])
        );
        $admin->save();
    }

    protected function abortUnlessAdmin(User $user): void
    {
        // این مسیرها فقط دربارهٔ ادمین‌ها هستند؛ رسیدن با شناسهٔ یک نماینده یا
        // مشتری باید ۴۰۴ بدهد، نه اینکه فرم ادمین را روی آن حساب باز کند.
        abort_unless($user->role === UserRole::Admin, 404);
    }
}
