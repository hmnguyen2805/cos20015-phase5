# Create or reset an app user. The password is typed hidden and never shown.
# Usage (from the phase5 folder):
#   powershell -ExecutionPolicy Bypass -File app\tools\create_user.ps1 minh Admin "Minh Nguyen"
param(
    [Parameter(Mandatory = $true)][string]$Username,
    [Parameter(Mandatory = $true)][ValidateSet('Admin', 'User')][string]$Role,
    [Parameter(Mandatory = $true)][string]$DisplayName
)
$secure = Read-Host "Password for $Username (min 10 characters)" -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:CTT_NEW_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    & 'C:\xampp\php\php.exe' (Join-Path $PSScriptRoot 'create_user.php') $Username $Role $DisplayName
} finally {
    Remove-Item Env:\CTT_NEW_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
}
