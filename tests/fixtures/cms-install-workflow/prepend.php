<?php
namespace InstallWorkflowFixture;
class PDO {
    // Match the existing report-api shim; constructors still never connect.
    const ATTR_ERRMODE = 3, ERRMODE_EXCEPTION = 2, ATTR_TIMEOUT = 2, FETCH_ASSOC = 2;
    public function __construct(...$args) {
        if (in_array($_GET['action'] ?? '', ['cms_install_prepare','cms_install_state','cms_install_step'], true)) file_put_contents(__DIR__.'/DB_PROBE', 'unexpected');
        throw new \Exception('SYNTHETIC_DB_SECRET');
    }
}
// Fixture-only authority/expiry setup; production actions still run through HTTP.
if (($_GET['action'] ?? '') === 'fixture') {
    session_start();
    $_SESSION['migrate_auth'] = isset($_GET['weak']) ? 1 : true;
    // HTML probes use fresh sessions and let production generate its own token.
    if (!isset($_GET['html'])) $_SESSION['cms_install_csrf'] = 'installer-test-csrf';
    if (isset($_SESSION['cms_install_draft'])) {
        if (isset($_GET['expire'])) $_SESSION['cms_install_draft']['expires_at'] = 1;
        if (isset($_GET['foreign'])) $_SESSION['cms_install_draft']['app'] = 'different-app';
    }
    echo hash('sha256', serialize($_SESSION));
    exit;
}
