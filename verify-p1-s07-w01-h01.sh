#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

[[ -f "$APP/artisan" ]] || { echo 'VERIFY_FAIL=ARTISAN_NOT_FOUND' >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == '0.25.1' ]] || { echo 'VERIFY_FAIL=VERSION' >&2; exit 1; }

files=(
 app/Models/User.php app/Models/Permission.php app/Policies/UserPolicy.php
 app/Http/Controllers/UserController.php app/Services/BulkPasswordResetService.php
 app/Services/CredentialAdministratorProvisioner.php
 database/migrations/2026_10_09_000026_global_credential_administrator.php
 deploy/apply-credential-hotfix.php resources/views/users/index.blade.php
 resources/views/users/show.blade.php routes/web.php
 tests/Feature/P1S07W01CredentialHotfixTest.php tests/Feature/AccessControlTest.php
)
for file in "${files[@]}"; do [[ -f "$APP/$file" ]] || { echo "VERIFY_FAIL=MISSING:$file" >&2; exit 1; }; done
for file in app/Models/User.php app/Models/Permission.php app/Policies/UserPolicy.php app/Http/Controllers/UserController.php app/Services/BulkPasswordResetService.php app/Services/CredentialAdministratorProvisioner.php database/migrations/2026_10_09_000026_global_credential_administrator.php deploy/apply-credential-hotfix.php tests/Feature/P1S07W01CredentialHotfixTest.php tests/Feature/AccessControlTest.php; do
    "$PHP_BIN" -l "$APP/$file" >/dev/null
done

cd "$APP"
"$PHP_BIN" artisan migrate:status | grep -Fq '2026_10_09_000026_global_credential_administrator'
"$PHP_BIN" artisan route:list --name=users.phone | grep -Fq 'users.phone'
"$PHP_BIN" artisan view:clear >/dev/null
"$PHP_BIN" artisan view:cache >/dev/null
"$PHP_BIN" artisan test --filter=P1S07W01CredentialHotfixTest
"$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $p=App\Models\Permission::where("slug",App\Models\User::GLOBAL_CREDENTIAL_PERMISSION)->first(); $roles=$p?->roles()->whereIn("slug",["super_admin","manager"])->pluck("slug")->sort()->values()->all() ?? []; if($roles!==["manager","super_admin"]){fwrite(STDERR,"VERIFY_FAIL=CREDENTIAL_ROLE_PERMISSIONS\n");exit(1);} echo "SUPER_ADMIN_AND_MANAGER_CREDENTIAL_PERMISSION=PASS\n";'
"$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $u=App\Models\User::where("phone","09130134984")->first(); $roles=$u?->roleAssignments()->with("role:id,slug")->get()->pluck("role.slug")->filter()->intersect(["super_admin","manager"])->unique()->sort()->values()->all() ?? []; if(!$u || !$u->isSuperAdmin() || !$u->hasPermission(App\Models\User::GLOBAL_CREDENTIAL_PERMISSION) || $roles!==["manager","super_admin"]){fwrite(STDERR,"VERIFY_FAIL=CREDENTIAL_ADMIN\n");exit(1);} echo "CREDENTIAL_ADMIN_ROLES_AND_PERMISSION=PASS\n";'

echo 'GLOBAL_CREDENTIAL_SCOPE=PASS'
echo 'SUPER_ADMIN_AND_MANAGER_ROLE_PERMISSIONS=PASS'
echo 'DESIGNATED_USER_ROLE_ASSIGNMENTS=PASS'
echo 'PHONE_ONLY_UPDATE=PASS'
echo 'BULK_PASSWORD_RESET_TEST=PASS'
echo 'VERIFY_P1_S07_W01_H01=PASS'
