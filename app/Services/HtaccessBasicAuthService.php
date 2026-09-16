<?php

namespace App\Services;

use App\Support\PortalPaths;

class HtaccessBasicAuthService
{
    protected const MARKER_BEGIN = '# SHAHPANEL-BASIC-AUTH-BEGIN:';

    protected const MARKER_END = '# SHAHPANEL-BASIC-AUTH-END:';

    public function htaccessPath(): string
    {
        return public_path('.htaccess');
    }

    public function htpasswdPath(string $role): string
    {
        $this->ensureSecurityDirectory();

        return $this->htpasswdFilePath($role);
    }

    protected function htpasswdFilePath(string $role): string
    {
        return storage_path('app/security/htpasswd-'.$role);
    }

    protected function ensureSecurityDirectory(): void
    {
        $dir = storage_path('app/security');

        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }

    /**
     * @return array<string, bool>
     */
    public function status(): array
    {
        $content = $this->readHtaccess();

        return [
            'admin' => $this->isApplied('admin', $content),
            'agent' => $this->isApplied('agent', $content),
            'seller' => $this->isApplied('seller', $content),
        ];
    }

    public function isApplied(string $role, ?string $content = null): bool
    {
        $content ??= $this->readHtaccess();

        if ($content === '') {
            return false;
        }

        return str_contains($content, self::MARKER_BEGIN.$role)
            && is_file($this->htpasswdFilePath($role));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function apply(string $role, string $username, string $password): array
    {
        if (! in_array($role, ['admin', 'agent', 'seller'], true)) {
            return ['ok' => false, 'message' => __('security.htaccess_invalid_role')];
        }

        $username = trim($username);
        if ($username === '' || strlen($password) < 6) {
            return ['ok' => false, 'message' => __('security.htaccess_credentials_invalid')];
        }

        $htaccessPath = $this->htaccessPath();
        if (! is_file($htaccessPath)) {
            return ['ok' => false, 'message' => __('security.htaccess_missing')];
        }

        if (! is_writable($htaccessPath)) {
            return ['ok' => false, 'message' => __('security.htaccess_not_writable')];
        }

        $content = file_get_contents($htaccessPath) ?: '';

        if ($this->isApplied($role, $content)) {
            return ['ok' => true, 'message' => __('security.htaccess_already_applied')];
        }

        $this->ensureSecurityDirectory();

        $htpasswdPath = $this->htpasswdFilePath($role);
        $written = @file_put_contents(
            $htpasswdPath,
            $username.':'.password_hash($password, PASSWORD_BCRYPT).PHP_EOL,
            LOCK_EX
        );

        if ($written === false) {
            return ['ok' => false, 'message' => __('security.htpasswd_not_writable')];
        }

        @chmod($htpasswdPath, 0640);

        $block = $this->buildBlock($role, $htpasswdPath);
        $updated = $this->injectBlock($content, $role, $block);

        if ($updated === null) {
            @unlink($htpasswdPath);

            return ['ok' => false, 'message' => __('security.htaccess_inject_failed')];
        }

        if (@file_put_contents($htaccessPath, $updated, LOCK_EX) === false) {
            @unlink($htpasswdPath);

            return ['ok' => false, 'message' => __('security.htaccess_not_writable')];
        }

        return ['ok' => true, 'message' => __('security.htaccess_applied', ['role' => __("security.portal_path_{$role}")])];
    }

    protected function readHtaccess(): string
    {
        $path = $this->htaccessPath();

        if (! is_readable($path)) {
            return '';
        }

        return (string) @file_get_contents($path);
    }

    protected function buildBlock(string $role, string $htpasswdPath): string
    {
        $path = PortalPaths::slug($role);
        $authName = match ($role) {
            'admin' => 'سامانه امور مشتریان — مدیر',
            'agent' => 'سامانه امور مشتریان — نماینده',
            'seller' => 'سامانه امور مشتریان — فروشنده',
            default => 'سامانه امور مشتریان',
        };
        $htpasswdPath = str_replace('\\', '/', $htpasswdPath);

        return implode(PHP_EOL, [
            self::MARKER_BEGIN.$role,
            '<IfModule mod_auth_basic.c>',
            '    <If "%{REQUEST_URI} =~ m#^/'.$path.'#">',
            '        AuthType Basic',
            '        AuthName "'.$authName.'"',
            '        AuthUserFile '.$htpasswdPath,
            '        Require valid-user',
            '    </If>',
            '</IfModule>',
            self::MARKER_END.$role,
            '',
        ]);
    }

    protected function injectBlock(string $content, string $role, string $block): ?string
    {
        if (str_contains($content, self::MARKER_BEGIN.$role)) {
            return $content;
        }

        $anchor = 'RewriteEngine On';
        $pos = strpos($content, $anchor);

        if ($pos !== false) {
            $insertAt = $pos + strlen($anchor);

            return substr($content, 0, $insertAt).PHP_EOL.PHP_EOL.$block.substr($content, $insertAt);
        }

        $anchor = 'RewriteRule ^ index.php';
        $pos = strpos($content, $anchor);

        if ($pos !== false) {
            return substr($content, 0, $pos).$block.PHP_EOL.substr($content, $pos);
        }

        if (str_contains($content, '<IfModule mod_rewrite.c>')) {
            $replaced = preg_replace(
                '/(<IfModule mod_rewrite\.c>\s*)/',
                '$1'.PHP_EOL.$block,
                $content,
                1
            );

            return is_string($replaced) ? $replaced : null;
        }

        return null;
    }
}
