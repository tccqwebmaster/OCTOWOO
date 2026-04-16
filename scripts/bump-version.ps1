Param(
    [string]$part = 'patch',
    [switch]$Commit
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$root = Resolve-Path (Join-Path $scriptDir '..')

# Find PHP binary
$php = 'php'

# Call the PHP script
$args = @($part)
if ($Commit.IsPresent) { $args += '--commit' }

& $php (Join-Path $scriptDir 'bump_version.php') @args
