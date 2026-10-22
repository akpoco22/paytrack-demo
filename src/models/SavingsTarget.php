<?php
/**
 * Retired feature compatibility stub. Savings targets were removed in Phase 4.
 */
class SavingsTarget {
    public function __construct($database_connection = null) {
        throw new RuntimeException('Savings targets are no longer supported');
    }
}
?>
