<?php
namespace InstallDiscoveryFixture;
// Any DB attempt by discovery is observable without contacting a service.
class PDO {
    const ATTR_ERRMODE = 3, ERRMODE_EXCEPTION = 2, ATTR_TIMEOUT = 2, FETCH_ASSOC = 2;
    public function __construct(...$args) {
        if (($_GET['action'] ?? '') === 'cms_install_discover') {
            file_put_contents(__DIR__.'/DB_PROBE', 'unexpected');
        }
        throw new \Exception('SYNTHETIC_DB_SECRET');
    }
}
