<?php

use App\Services\BulkPasswordResetService;
use App\Services\CredentialAdministratorProvisioner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

$appPath = $argv[1] ?? dirname(__DIR__);
require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$password = rtrim((string) fgets(STDIN), "\r\n");
if ($password === '') {
    fwrite(STDERR, "BULK_PASSWORD_EMPTY\n");
    exit(2);
}

$administrator = app(CredentialAdministratorProvisioner::class)->provision('09130134984');
$result = app(BulkPasswordResetService::class)->reset($password);
$unverified = App\Models\User::withTrashed()->get()->filter(
    fn (App\Models\User $user): bool => ! Hash::check($password, $user->password)
)->count();
unset($password);

if ($unverified !== 0) {
    fwrite(STDERR, 'PASSWORD_VERIFICATION_FAILED='.$unverified.PHP_EOL);
    exit(3);
}

echo 'CREDENTIAL_ADMIN_USER_ID='.$administrator->id.PHP_EOL;
echo 'CREDENTIAL_ADMIN_PHONE='.$administrator->phone.PHP_EOL;
echo 'CREDENTIAL_ADMIN_PRIMARY_ROLE='.$administrator->role.PHP_EOL;
echo 'CREDENTIAL_ADMIN_ROLE_ASSIGNMENTS='.$administrator->roleAssignments()->whereHas('role', fn ($query) => $query->whereIn('slug', ['super_admin', 'manager']))->count().PHP_EOL;
echo 'PASSWORDS_RESET='.$result['users'].PHP_EOL;
echo 'PASSWORDS_VERIFIED='.$result['users'].PHP_EOL;
echo 'SESSIONS_REVOKED='.$result['sessions'].PHP_EOL;
